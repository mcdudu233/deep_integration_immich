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
            'admin_api_key' => 'secret-admin-key',
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

        $this->assertSame('encrypted:secret-admin-key', $this->appValues['admin_api_key']);

        $config = $this->service->getAdminConfig();

        $this->assertSame('https://photos.example.com', $config['immich_base_url']);
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

    public function testConnectionOnlySaveAllowsEmptyPathTemplatesWhenPathFeaturesAreDisabled(): void {
        $this->service->setAdminConfig([
            'immich_base_url' => 'https://photos.example.com',
            'admin_api_key' => 'secret-admin-key',
        ]);

        $config = $this->service->getAdminConfig();

        $this->assertSame('https://photos.example.com', $config['immich_base_url']);
        $this->assertSame('', $config['host_path_template']);
        $this->assertSame('', $config['nc_visible_path_template']);
        $this->assertTrue($config['admin_api_key_configured']);
        $this->assertTrue($this->service->isConfigured());
        $this->assertSame('encrypted:secret-admin-key', $this->appValues['admin_api_key']);
    }

    public function testPathTemplatesAreRequiredWhenPathDependentFeaturesAreEnabled(): void {
        foreach (['provisioning_enabled', 'mkdir_policy_enabled', 'external_storage_auto_create'] as $flag) {
            $errors = $this->service->validateAdminConfig([
                $flag => true,
                'host_path_template' => '',
                'nc_visible_path_template' => '',
            ]);

            $this->assertArrayHasKey('host_path_template', $errors, $flag);
            $this->assertArrayHasKey('nc_visible_path_template', $errors, $flag);
        }
    }

    public function testGetAdminConfigMasksAdminApiKey(): void {
        $this->appValues['immich_base_url'] = 'https://photos.example.com';
        $this->appValues['admin_api_key'] = 'encrypted:secret-admin-key';

        $config = $this->service->getAdminConfig();

        $this->assertArrayNotHasKey('admin_api_key', $config);
        $this->assertTrue($config['admin_api_key_configured']);
        $this->assertStringNotContainsString('secret-admin-key', json_encode($config, JSON_THROW_ON_ERROR));
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

    public function testPlaintextLegacyKeyFallsBackAndIsReEncryptedOnNextSave(): void {
        $this->appValues['immich_base_url'] = 'https://photos.example.com';
        $this->appValues['admin_api_key'] = 'legacy-plain-key';
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

        $this->assertSame('encrypted:legacy-plain-key', $this->appValues['admin_api_key']);
    }

    public function testMissingKeyReturnsFalseConfigured(): void {
        $this->appValues['immich_base_url'] = 'https://photos.example.com';

        $this->assertFalse($this->service->isConfigured());

        $config = $this->service->getAdminConfig();
        $this->assertFalse($config['admin_api_key_configured']);
    }

    public function testDeleteOptInRequiresExplicitConfirmation(): void {
        $errors = $this->service->validateAdminConfig([
            'delete_disable_policy' => 'delete_opt_in',
        ]);

        $this->assertArrayHasKey('delete_disable_policy', $errors);

        $errors = $this->service->validateAdminConfig([
            'delete_disable_policy' => 'delete_opt_in',
            AdminConfigService::DELETE_OPT_IN_CONFIRMATION_FLAG => true,
        ]);

        $this->assertArrayNotHasKey('delete_disable_policy', $errors);
    }

    public function testTemplateValidationRejectsTraversalAndUnsupportedPlaceholders(): void {
        $traversalErrors = $this->service->validateAdminConfig([
            'host_path_template' => '/srv/immich/../library/{storageLabel}',
        ]);
        $placeholderErrors = $this->service->validateAdminConfig([
            'mount_name_template' => 'Immich/{displayName}',
        ]);

        $this->assertArrayHasKey('host_path_template', $traversalErrors);
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
            'admin_api_key' => 'secret-admin-key',
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
