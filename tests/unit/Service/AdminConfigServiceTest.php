<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\Service;

use OCA\IntegrationImmich\AppInfo\Application;
use OCA\IntegrationImmich\Service\AdminConfigService;
use OCP\IConfig;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class AdminConfigServiceTest extends TestCase {
    private AdminConfigService $service;
    private IConfig&MockObject $config;
    private ICrypto&MockObject $crypto;
    private LoggerInterface&MockObject $logger;
    private array $appValues = [];

    protected function setUp(): void {
        parent::setUp();

        $this->appValues = [];
        $this->config = $this->createMock(IConfig::class);
        $this->crypto = $this->createMock(ICrypto::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->config->method('getAppValue')
            ->willReturnCallback(function (string $app, string $key, string $default): string {
                $this->assertSame(Application::APP_ID, $app);
                return $this->appValues[$key] ?? $default;
            });
        $this->config->method('setAppValue')
            ->willReturnCallback(function (string $app, string $key, string $value): void {
                $this->assertSame(Application::APP_ID, $app);
                $this->appValues[$key] = $value;
            });

        $this->crypto->method('encrypt')
            ->willReturnCallback(fn(string $value): string => 'encrypted:' . $value);
        $this->crypto->method('decrypt')
            ->willReturnCallback(function (string $value): string {
                if (!str_starts_with($value, 'encrypted:')) {
                    throw new \RuntimeException('Not encrypted');
                }

                return substr($value, strlen('encrypted:'));
            });

        $this->service = new AdminConfigService(
            $this->config,
            $this->crypto,
            $this->logger,
        );
    }

    public function testValidSaveLoadStoresEncryptedKeyAndReturnsNormalisedConfig(): void {
        $this->service->setAdminConfig([
            'immich_base_url' => 'https://photos.example.com/',
            'admin_api_key' => 'test-api-key-redacted',
            'immich_browsing_mode' => 'personal',
            'provisioning_enabled' => true,
            'user_scope_mode' => 'groups',
            'user_scope_groups' => ['staff', 'family'],
            'storage_label_template' => '{uid}',
            'email_template' => '{uid}@immich.local',
            'initial_password_policy' => 'sso_oidc',
            'host_path_template' => '/srv/immich/originals/library/{storageLabel}',
            'nc_visible_path_template' => '/mnt/immich-library/{storageLabel}',
            'mount_name_template' => 'Immich/{uid}',
            'mkdir_policy_enabled' => false,
            'quota_sync_mode' => 'event_scheduled',
            'quota_reserve_bytes' => 512,
            'delete_disable_policy' => 'disable_suspend',
            'external_storage_auto_create' => true,
        ]);

        $this->assertSame('encrypted:test-api-key-redacted', $this->appValues['admin_api_key']);

        $config = $this->service->getAdminConfig();

        $this->assertSame('https://photos.example.com', $config['immich_base_url']);
        $this->assertSame('personal', $config['immich_browsing_mode']);
        $this->assertTrue($config['provisioning_enabled']);
        $this->assertSame('groups', $config['user_scope_mode']);
        $this->assertSame(['staff', 'family'], $config['user_scope_groups']);
        $this->assertSame('/srv/immich/originals/library/{storageLabel}', $config['host_path_template']);
        $this->assertSame('/mnt/immich-library/{storageLabel}', $config['nc_visible_path_template']);
        $this->assertSame('Immich/{uid}', $config['mount_name_template']);
        $this->assertSame('sso_oidc', $config['initial_password_policy']);
        $this->assertSame('sso_oidc', $this->service->getInitialPasswordPolicy());
        $this->assertSame('event_scheduled', $config['quota_sync_mode']);
        $this->assertSame(512, $config['quota_reserve_bytes']);
        $this->assertTrue($config['external_storage_auto_create']);
        $this->assertTrue($config['admin_api_key_configured']);
        $this->assertTrue($this->service->isConfigured());
    }

    public function testDefaultBrowsingModeIsAdminManaged(): void {
        $config = $this->service->getAdminConfig();

        $this->assertSame(AdminConfigService::BROWSING_MODE_ADMIN_MANAGED, $config[AdminConfigService::KEY_IMMICH_BROWSING_MODE]);
    }

    public function testPersonalBrowsingModeDisablesCentralProvisioningFeatures(): void {
        $this->service->setAdminConfig([
            'immich_browsing_mode' => AdminConfigService::BROWSING_MODE_PERSONAL,
            'provisioning_enabled' => true,
            'mkdir_policy_enabled' => true,
            'external_storage_auto_create' => true,
            'quota_sync_mode' => 'event_scheduled',
            'host_path_template' => '',
            'nc_visible_path_template' => '',
        ]);

        $config = $this->service->getAdminConfig();

        $this->assertSame(AdminConfigService::BROWSING_MODE_PERSONAL, $config[AdminConfigService::KEY_IMMICH_BROWSING_MODE]);
        $this->assertFalse($config['provisioning_enabled']);
        $this->assertFalse($config['mkdir_policy_enabled']);
        $this->assertFalse($config['external_storage_auto_create']);
        $this->assertSame('disabled', $config['quota_sync_mode']);
    }

    public function testConnectionOnlySaveAllowsEmptyPathTemplatesWhenPathFeaturesAreDisabled(): void {
        $this->service->setAdminConfig([
            'immich_base_url' => 'https://photos.example.com',
            'admin_api_key' => 'test-api-key-redacted',
            'provisioning_enabled' => false,
            'mkdir_policy_enabled' => false,
            'external_storage_auto_create' => false,
            'host_path_template' => '',
            'nc_visible_path_template' => '',
        ]);

        $config = $this->service->getAdminConfig();

        $this->assertSame('https://photos.example.com', $config['immich_base_url']);
        $this->assertSame('', $config['host_path_template']);
        $this->assertSame('', $config['nc_visible_path_template']);
        $this->assertTrue($config['admin_api_key_configured']);
        $this->assertTrue($this->service->isConfigured());
        $this->assertSame('encrypted:test-api-key-redacted', $this->appValues['admin_api_key']);
    }

    public function testDisabledProvisioningIgnoresStalePathFeatureFlagsForBlankTemplates(): void {
        $errors = $this->service->validateAdminConfig([
            'provisioning_enabled' => false,
            'mkdir_policy_enabled' => true,
            'external_storage_auto_create' => true,
            'host_path_template' => ' ',
            'nc_visible_path_template' => "\t",
        ]);

        $this->assertArrayNotHasKey('host_path_template', $errors);
        $this->assertArrayNotHasKey('nc_visible_path_template', $errors);

        $this->service->setAdminConfig([
            'provisioning_enabled' => false,
            'mkdir_policy_enabled' => true,
            'external_storage_auto_create' => true,
            'host_path_template' => ' ',
            'nc_visible_path_template' => "\t",
        ]);

        $config = $this->service->getAdminConfig();

        $this->assertFalse($config['provisioning_enabled']);
        $this->assertFalse($config['mkdir_policy_enabled']);
        $this->assertFalse($config['external_storage_auto_create']);
        $this->assertSame('', $config['host_path_template']);
        $this->assertSame('', $config['nc_visible_path_template']);
    }

    public function testPathTemplatesAreRequiredWhenProvisioningIsEnabled(): void {
        $hostErrors = $this->service->validateAdminConfig([
            'provisioning_enabled' => true,
            'host_path_template' => '',
            'nc_visible_path_template' => '/mnt/immich-library/{storageLabel}',
        ]);
        $nextcloudPathErrors = $this->service->validateAdminConfig([
            'provisioning_enabled' => true,
            'host_path_template' => '/srv/immich/originals/library/{storageLabel}',
            'nc_visible_path_template' => '',
        ]);

        $this->assertArrayHasKey('host_path_template', $hostErrors);
        $this->assertArrayNotHasKey('nc_visible_path_template', $hostErrors);
        $this->assertArrayNotHasKey('host_path_template', $nextcloudPathErrors);
        $this->assertArrayHasKey('nc_visible_path_template', $nextcloudPathErrors);
    }

    public function testExternalStorageAutoCreateWithEnabledProvisioningRequiresPathTemplates(): void {
        $errors = $this->service->validateAdminConfig([
            'provisioning_enabled' => true,
            'external_storage_auto_create' => true,
            'host_path_template' => '',
            'nc_visible_path_template' => '',
        ]);

        $this->assertArrayHasKey('host_path_template', $errors);
        $this->assertArrayHasKey('nc_visible_path_template', $errors);
    }

    public function testGetAdminConfigMasksAdminApiKey(): void {
        $this->appValues['immich_base_url'] = 'https://photos.example.com';
        $this->appValues['admin_api_key'] = 'encrypted:test-api-key-redacted';

        $config = $this->service->getAdminConfig();

        $this->assertArrayNotHasKey('admin_api_key', $config);
        $this->assertTrue($config['admin_api_key_configured']);
        $this->assertStringNotContainsString('test-api-key-redacted', json_encode($config, JSON_THROW_ON_ERROR));
    }

    public function testInvalidUrlIsRejected(): void {
        $errors = $this->service->validateAdminConfig([
            'immich_base_url' => 'ftp://photos.example.com',
        ]);

        $this->assertArrayHasKey('immich_base_url', $errors);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->setAdminConfig([
            'immich_base_url' => 'photos.example.com',
        ]);
    }

    public function testNegativeReserveIsRejected(): void {
        $errors = $this->service->validateAdminConfig([
            'quota_reserve_bytes' => -1,
        ]);

        $this->assertArrayHasKey('quota_reserve_bytes', $errors);
    }

    public function testValidationDetailsExposeStableCodesAndLegacyMessages(): void {
        $details = $this->service->validateAdminConfigDetails([
            'immich_base_url' => 'ftp://photos.example.com',
            'immich_browsing_mode' => 'team_shared',
            'user_scope_mode' => 'department',
            'user_scope_groups' => ['staff', ''],
            'storage_label_template' => '{uid',
            'email_template' => '{uid}@immich.local',
            'provisioning_enabled' => true,
            'host_path_template' => '',
            'nc_visible_path_template' => '/mnt/immich-library/../escape/{storageLabel}',
            'mount_name_template' => 'Immich/{displayName}',
            'external_storage_auto_create' => 'sometimes',
            'quota_sync_mode' => 'realtime',
            'initial_password_policy' => 'show_plaintext_password',
            'quota_reserve_bytes' => -1,
            'delete_disable_policy' => 'delete_opt_in',
            'admin_api_key' => 'test-api-key-redacted',
        ]);
        $messages = $this->service->validateAdminConfig([
            'immich_base_url' => 'ftp://photos.example.com',
            'immich_browsing_mode' => 'team_shared',
            'user_scope_mode' => 'department',
            'user_scope_groups' => ['staff', ''],
            'provisioning_enabled' => true,
            'host_path_template' => '',
            'nc_visible_path_template' => '/mnt/immich-library/../escape/{storageLabel}',
            'external_storage_auto_create' => 'sometimes',
            'quota_reserve_bytes' => -1,
            'delete_disable_policy' => 'delete_opt_in',
        ]);
        $encodedDetails = json_encode($details, JSON_THROW_ON_ERROR);

        $this->assertSame(AdminConfigService::VALIDATION_INVALID_URL, $details['immich_base_url']['code']);
        $this->assertSame(AdminConfigService::VALIDATION_INVALID_ENUM, $details['immich_browsing_mode']['code']);
        $this->assertSame(AdminConfigService::VALIDATION_INVALID_ENUM, $details['user_scope_mode']['code']);
        $this->assertSame(AdminConfigService::VALIDATION_INVALID_GROUP_LIST, $details['user_scope_groups']['code']);
        $this->assertSame(AdminConfigService::VALIDATION_INVALID_TEMPLATE, $details['storage_label_template']['code']);
        $this->assertSame(AdminConfigService::VALIDATION_MISSING_PATH_TEMPLATE, $details['host_path_template']['code']);
        $this->assertSame(AdminConfigService::VALIDATION_INVALID_PATH_TEMPLATE, $details['nc_visible_path_template']['code']);
        $this->assertSame(AdminConfigService::VALIDATION_UNSUPPORTED_TEMPLATE_PLACEHOLDER, $details['mount_name_template']['code']);
        $this->assertSame(AdminConfigService::VALIDATION_INVALID_BOOLEAN, $details['external_storage_auto_create']['code']);
        $this->assertSame(AdminConfigService::VALIDATION_INVALID_ENUM, $details['quota_sync_mode']['code']);
        $this->assertSame(AdminConfigService::VALIDATION_INVALID_ENUM, $details['initial_password_policy']['code']);
        $this->assertSame(AdminConfigService::VALIDATION_INVALID_QUOTA_RESERVE, $details['quota_reserve_bytes']['code']);
        $this->assertSame(AdminConfigService::VALIDATION_DELETE_OPT_IN_CONFIRMATION_REQUIRED, $details['delete_disable_policy']['code']);
        $this->assertSame('immich_base_url', $details['immich_base_url']['field']);
        $this->assertSame('Immich base URL must be a valid http or https URL with a host.', $details['immich_base_url']['message']);
        $this->assertSame(['http', 'https'], $details['immich_base_url']['params']['allowed_schemes']);
        $this->assertSame('Immich base URL must be a valid http or https URL with a host.', $messages['immich_base_url']);
        $this->assertSame('Immich browsing mode must be personal or admin_managed.', $messages['immich_browsing_mode']);
        $this->assertIsString($messages['host_path_template']);
        $this->assertStringNotContainsString('test-api-key-redacted', $encodedDetails);
    }

    public function testPlaintextLegacyKeyFallsBackAndIsReEncryptedOnNextSave(): void {
        $this->appValues['immich_base_url'] = 'https://photos.example.com';
        $this->appValues['admin_api_key'] = 'legacy-api-key-redacted';
        $this->appValues['host_path_template'] = '/srv/immich/originals/library/{storageLabel}';
        $this->appValues['nc_visible_path_template'] = '/mnt/immich-library/{storageLabel}';

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('legacy plaintext'),
                $this->callback(fn(array $context): bool => ($context['app'] ?? '') === Application::APP_ID)
            );

        $this->service->setAdminConfig([
            'quota_reserve_bytes' => 1024,
        ]);

        $this->assertSame('encrypted:legacy-api-key-redacted', $this->appValues['admin_api_key']);
    }

    public function testMissingKeyReturnsFalseConfigured(): void {
        $this->appValues['immich_base_url'] = 'https://photos.example.com';

        $this->assertFalse($this->service->isConfigured());

        $config = $this->service->getAdminConfig();
        $this->assertFalse($config['admin_api_key_configured']);
    }

    public function testDeleteOptInRequiresExplicitConfirmationOnlyOnTransition(): void {
        $errors = $this->service->validateAdminConfig([
            'delete_disable_policy' => 'delete_opt_in',
        ]);

        $this->assertArrayHasKey('delete_disable_policy', $errors);

        $errors = $this->service->validateAdminConfig([
            'delete_disable_policy' => 'delete_opt_in',
            AdminConfigService::DELETE_OPT_IN_CONFIRMATION_FLAG => true,
        ]);

        $this->assertArrayNotHasKey('delete_disable_policy', $errors);

        $this->appValues['delete_disable_policy'] = 'delete_opt_in';

        $errors = $this->service->validateAdminConfig([
            'delete_disable_policy' => 'delete_opt_in',
            'quota_reserve_bytes' => 1024,
        ]);

        $this->assertArrayNotHasKey('delete_disable_policy', $errors);

        $this->service->setAdminConfig([
            'quota_reserve_bytes' => 1024,
        ]);

        $this->assertSame('delete_opt_in', $this->appValues['delete_disable_policy']);
    }

    public function testBlankAdminApiKeyPreservesStoredCredential(): void {
        $this->appValues['immich_base_url'] = 'https://photos.example.com';
        $this->appValues['admin_api_key'] = 'encrypted:stored-api-key-redacted';

        $this->service->setAdminConfig([
            'immich_base_url' => 'https://photos-updated.example.com',
            'admin_api_key' => " \t ",
        ]);

        $config = $this->service->getAdminConfig();
        $encoded = json_encode($config, JSON_THROW_ON_ERROR);

        $this->assertSame('encrypted:stored-api-key-redacted', $this->appValues['admin_api_key']);
        $this->assertSame('stored-api-key-redacted', $this->service->getAdminApiKey());
        $this->assertTrue($config['admin_api_key_configured']);
        $this->assertStringNotContainsString('stored-api-key-redacted', $encoded);
    }

    public function testTemplateValidationRejectsTraversalAndUnsupportedPlaceholders(): void {
        $traversalErrors = $this->service->validateAdminConfig([
            'host_path_template' => '/srv/immich/../library/{storageLabel}',
        ]);
        $windowsTraversalDetails = $this->service->validateAdminConfigDetails([
            'provisioning_enabled' => true,
            'host_path_template' => 'C:\\immich\\..\\library\\{storageLabel}',
            'nc_visible_path_template' => '/mnt/immich-library/{storageLabel}',
        ]);
        $windowsTraversalMessages = $this->service->validateAdminConfig([
            'provisioning_enabled' => true,
            'host_path_template' => 'C:\\immich\\..\\library\\{storageLabel}',
            'nc_visible_path_template' => '/mnt/immich-library/{storageLabel}',
        ]);
        $placeholderErrors = $this->service->validateAdminConfig([
            'mount_name_template' => 'Immich/{displayName}',
        ]);

        $this->assertArrayHasKey('host_path_template', $traversalErrors);
        $this->assertSame(AdminConfigService::VALIDATION_INVALID_PATH_TEMPLATE, $windowsTraversalDetails['host_path_template']['code']);
        $this->assertSame('Template must not contain path traversal segments.', $windowsTraversalMessages['host_path_template']);
        $this->assertArrayHasKey('mount_name_template', $placeholderErrors);
    }

    public function testInvalidGroupsAndBooleanValuesAreRejected(): void {
        $groupErrors = $this->service->validateAdminConfig([
            'user_scope_groups' => ['staff', ''],
        ]);
        $booleanErrors = $this->service->validateAdminConfig([
            'external_storage_auto_create' => 'sometimes',
        ]);
        $passwordPolicyErrors = $this->service->validateAdminConfig([
            'initial_password_policy' => 'show_plaintext_password',
        ]);

        $this->assertArrayHasKey('user_scope_groups', $groupErrors);
        $this->assertArrayHasKey('external_storage_auto_create', $booleanErrors);
        $this->assertArrayHasKey('initial_password_policy', $passwordPolicyErrors);
    }

    public function testActionPolicyFlagsPersistAsAdminConfigBooleans(): void {
        $this->service->setAdminConfig([
            'immich_base_url' => 'https://photos.example.com',
            'admin_api_key' => 'test-api-key-redacted',
            'host_path_template' => '/srv/immich/originals/library/{storageLabel}',
            'nc_visible_path_template' => '/mnt/immich-library/{storageLabel}',
            'export_copy_enabled' => true,
            'import_to_immich_enabled' => '1',
            'immich_delete_enabled' => 'yes',
        ]);

        $config = $this->service->getAdminConfig();

        $this->assertTrue($config['export_copy_enabled']);
        $this->assertTrue($config['import_to_immich_enabled']);
        $this->assertTrue($config['immich_delete_enabled']);
        $this->assertTrue($this->service->isExportCopyEnabled());
        $this->assertTrue($this->service->isImportToImmichEnabled());
        $this->assertTrue($this->service->isImmichDeleteEnabled());
        $this->assertSame('1', $this->appValues['export_copy_enabled']);
        $this->assertSame('1', $this->appValues['import_to_immich_enabled']);
        $this->assertSame('1', $this->appValues['immich_delete_enabled']);
    }
}
