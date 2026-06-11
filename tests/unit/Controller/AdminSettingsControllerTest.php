<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\Controller;

use OCA\IntegrationImmich\Controller\AdminSettingsController;
use OCA\IntegrationImmich\Service\AdminConfigService;
use OCA\IntegrationImmich\Service\ImmichUserAdminService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Test\TestCase;

class AdminSettingsControllerTest extends TestCase {
    private AdminSettingsController $controller;
    private AdminConfigService&MockObject $adminConfigService;
    private ImmichUserAdminService&MockObject $immichUserAdminService;
    private IRequest&MockObject $request;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void {
        parent::setUp();

        $this->adminConfigService = $this->createMock(AdminConfigService::class);
        $this->immichUserAdminService = $this->createMock(ImmichUserAdminService::class);
        $this->request = $this->createMock(IRequest::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new AdminSettingsController(
            $this->request,
            $this->adminConfigService,
            $this->immichUserAdminService,
            $this->logger,
        );
    }

    public function testGetConfigReturnsMaskedAdminConfig(): void {
        $this->adminConfigService->method('getAdminConfig')->willReturn([
            'immich_base_url' => 'https://photos.example.com',
            'initial_password_policy' => 'random',
            'admin_api_key_configured' => true,
        ]);

        $response = $this->controller->getConfig();
        $data = $response->getData();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertTrue($data['success']);
        $this->assertSame('https://photos.example.com', $data['config']['immich_base_url']);
        $this->assertSame('random', $data['config']['initial_password_policy']);
        $this->assertTrue($data['config']['admin_api_key_configured']);
        $this->assertArrayNotHasKey('admin_api_key', $data['config']);
    }

    public function testGetConfigNormalizesLegacyPasswordPolicyAndRedactsCredentialFields(): void {
        $this->adminConfigService->method('getAdminConfig')->willReturn([
            'immich_base_url' => 'https://photos.example.com',
            'initial_password_policy' => 'random',
            'admin_api_key' => 'test-admin-api-key-redacted',
            'immich_admin_api_key' => 'test-immich-admin-api-key-redacted',
            'immich_username' => 'test-immich-username-redacted',
            'immich_password' => 'test-immich-password-redacted',
            'api_key' => 'test-api-key-redacted',
            'x-api-key' => 'test-x-api-key-redacted',
            'token' => 'test-token-redacted',
            'secret' => 'test-secret-redacted',
            'authorization' => 'Bearer test-bearer-redacted',
            'password' => 'test-password-redacted',
            'backup_password' => 'test-backup-password-redacted',
            'admin_api_key_configured' => true,
            'api_key_set' => true,
        ]);

        $response = $this->controller->getConfig();
        $data = $response->getData();
        $config = $data['config'];
        $encoded = json_encode($data, JSON_THROW_ON_ERROR);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertTrue($data['success']);
        $this->assertSame('random', $config['initial_password_policy']);
        $this->assertTrue($config['admin_api_key_configured']);
        $this->assertTrue($config['api_key_set']);
        $this->assertArrayNotHasKey('admin_api_key', $config);
        $this->assertSame('[redacted]', $config['immich_admin_api_key']);
        $this->assertSame('[redacted]', $config['immich_username']);
        $this->assertSame('[redacted]', $config['immich_password']);
        $this->assertSame('[redacted]', $config['api_key']);
        $this->assertSame('[redacted]', $config['x-api-key']);
        $this->assertSame('[redacted]', $config['token']);
        $this->assertSame('[redacted]', $config['secret']);
        $this->assertSame('[redacted]', $config['authorization']);
        $this->assertSame('[redacted]', $config['password']);
        $this->assertSame('[redacted]', $config['backup_password']);
        $this->assertStringNotContainsString('test-admin-api-key-redacted', $encoded);
        $this->assertStringNotContainsString('test-immich-admin-api-key-redacted', $encoded);
        $this->assertStringNotContainsString('test-immich-username-redacted', $encoded);
        $this->assertStringNotContainsString('test-immich-password-redacted', $encoded);
        $this->assertStringNotContainsString('test-api-key-redacted', $encoded);
        $this->assertStringNotContainsString('test-x-api-key-redacted', $encoded);
        $this->assertStringNotContainsString('test-token-redacted', $encoded);
        $this->assertStringNotContainsString('test-secret-redacted', $encoded);
        $this->assertStringNotContainsString('test-bearer-redacted', $encoded);
        $this->assertStringNotContainsString('test-password-redacted', $encoded);
        $this->assertStringNotContainsString('test-backup-password-redacted', $encoded);
    }

    public function testSetConfigValidatesAndSavesThroughAdminConfigService(): void {
        $currentConfig = $this->validConfig();
        $savedConfig = $currentConfig;
        $savedConfig['admin_api_key_configured'] = true;
        $this->adminConfigService->method('getAdminConfig')->willReturnOnConsecutiveCalls($currentConfig, $savedConfig);
        $this->adminConfigService->expects($this->once())
            ->method('validateAdminConfigDetails')
            ->with($this->callback(static fn(array $values): bool => $values['admin_api_key'] === 'test-api-key-redacted'
                && $values['initial_password_policy'] === 'random'))
            ->willReturn([]);
        $this->adminConfigService->expects($this->once())
            ->method('setAdminConfig')
            ->with($this->callback(static fn(array $values): bool => $values['admin_api_key'] === 'test-api-key-redacted'));
        $this->request->method('getParam')->willReturnMap($this->requestMap([
            'immich_base_url' => 'https://photos.example.com',
            'admin_api_key' => 'test-api-key-redacted',
            'provisioning_enabled' => true,
        ]));

        $response = $this->controller->setConfig();
        $encoded = json_encode($response->getData(), JSON_THROW_ON_ERROR);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertTrue($response->getData()['success']);
        $this->assertSame('random', $response->getData()['config']['initial_password_policy']);
        $this->assertStringNotContainsString('test-api-key-redacted', $encoded);
        $this->assertArrayNotHasKey('admin_api_key', $response->getData()['config']);
    }

    public function testSetConfigIgnoresBlankAdminApiKeyWhenCredentialIsAlreadyConfigured(): void {
        $currentConfig = $this->validConfig();
        $currentConfig['admin_api_key_configured'] = true;
        $savedConfig = $currentConfig;
        $savedConfig['immich_base_url'] = 'https://photos-updated.example.com';
        $this->adminConfigService->method('getAdminConfig')->willReturnOnConsecutiveCalls($currentConfig, $savedConfig);
        $this->adminConfigService->expects($this->once())
            ->method('validateAdminConfigDetails')
            ->with($this->callback(static fn(array $values): bool => !array_key_exists('admin_api_key', $values)
                && $values['immich_base_url'] === 'https://photos-updated.example.com'
                && $values['admin_api_key_configured'] === true))
            ->willReturn([]);
        $this->adminConfigService->expects($this->once())
            ->method('setAdminConfig')
            ->with($this->callback(static fn(array $values): bool => !array_key_exists('admin_api_key', $values)
                && $values['immich_base_url'] === 'https://photos-updated.example.com'));
        $this->request->method('getParam')->willReturnMap($this->requestMap([
            'immich_base_url' => 'https://photos-updated.example.com',
            'admin_api_key' => " \t ",
        ]));

        $response = $this->controller->setConfig();
        $encoded = json_encode($response->getData(), JSON_THROW_ON_ERROR);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertTrue($response->getData()['success']);
        $this->assertStringNotContainsString('stored-api-key-redacted', $encoded);
        $this->assertArrayNotHasKey('admin_api_key', $response->getData()['config']);
    }

    public function testSetConfigReturnsStructuredValidationError(): void {
        $this->adminConfigService->method('getAdminConfig')->willReturn($this->validConfig());
        $this->adminConfigService->expects($this->once())
            ->method('validateAdminConfigDetails')
            ->willReturn([
                'immich_base_url' => [
                    'field' => 'immich_base_url',
                    'code' => AdminConfigService::VALIDATION_INVALID_URL,
                    'message' => 'Immich base URL must be a valid http or https URL with a host.',
                    'params' => [
                        'allowed_schemes' => ['http', 'https'],
                        'admin_api_key' => 'test-api-key-redacted',
                        'authorization' => 'Bearer test-bearer-redacted',
                    ],
                ],
                'initial_password_policy' => [
                    'field' => 'initial_password_policy',
                    'code' => AdminConfigService::VALIDATION_INVALID_ENUM,
                    'message' => 'Initial password policy must be random.',
                    'params' => ['allowed_values' => ['random']],
                ],
            ]);
        $this->adminConfigService->expects($this->never())->method('setAdminConfig');
        $this->request->method('getParam')->willReturnMap($this->requestMap([
            'immich_base_url' => 'not-a-url',
        ]));

        $response = $this->controller->setConfig();
        $data = $response->getData();
        $encoded = json_encode($data, JSON_THROW_ON_ERROR);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertFalse($data['success']);
        $this->assertSame('admin_config_invalid', $data['error']['code']);
        $this->assertSame('Invalid admin configuration.', $data['error']['message']);
        $this->assertSame('Immich base URL must be a valid http or https URL with a host.', $data['error']['details']['fields']['immich_base_url']);
        $this->assertSame('immich_base_url', $data['error']['details']['fieldDetails'][0]['field']);
        $this->assertSame(AdminConfigService::VALIDATION_INVALID_URL, $data['error']['details']['fieldDetails'][0]['code']);
        $this->assertSame(['http', 'https'], $data['error']['details']['fieldDetails'][0]['params']['allowed_schemes']);
        $this->assertSame('Initial password policy must be random.', $data['error']['details']['fields']['initial_password_policy']);
        $this->assertSame('initial_password_policy', $data['error']['details']['fieldDetails'][1]['field']);
        $this->assertSame(AdminConfigService::VALIDATION_INVALID_ENUM, $data['error']['details']['fieldDetails'][1]['code']);
        $this->assertSame(['random'], $data['error']['details']['fieldDetails'][1]['params']['allowed_values']);
        $this->assertSame('[redacted]', $data['error']['details']['fieldDetails'][0]['params']['admin_api_key']);
        $this->assertSame('[redacted]', $data['error']['details']['fieldDetails'][0]['params']['authorization']);
        $this->assertStringNotContainsString('test-api-key-redacted', $encoded);
        $this->assertStringNotContainsString('test-bearer-redacted', $encoded);
    }

    public function testSetConfigPersistenceFailureUsesSaveFailedCodeAndRedacts(): void {
        $this->adminConfigService->method('getAdminConfig')->willReturn($this->validConfig());
        $this->adminConfigService->method('validateAdminConfigDetails')->willReturn([]);
        $this->adminConfigService->expects($this->once())
            ->method('setAdminConfig')
            ->willThrowException(new \RuntimeException('write failed with admin_api_key=test-api-key-redacted'));
        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('admin_api_key=[redacted]'),
                $this->callback(static fn(array $context): bool => ($context['app'] ?? '') !== '')
            );
        $this->request->method('getParam')->willReturnMap($this->requestMap([
            'immich_base_url' => 'https://photos.example.com',
        ]));

        $response = $this->controller->setConfig();
        $data = $response->getData();
        $encoded = json_encode($data, JSON_THROW_ON_ERROR);

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        $this->assertFalse($data['success']);
        $this->assertSame('admin_config_save_failed', $data['error']['code']);
        $this->assertSame('Failed to save admin configuration.', $data['error']['message']);
        $this->assertStringNotContainsString('test-api-key-redacted', $encoded);
    }

    public function testValidateConnectionReturnsRedactedSuccess(): void {
        $this->immichUserAdminService->expects($this->once())
            ->method('validateAdminConnection')
            ->willReturn([
                'success' => true,
                'data' => ['token' => 'raw-token-redacted'],
            ]);

        $response = $this->controller->validateConnection();
        $data = $response->getData();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertTrue($data['success']);
        $this->assertSame('[redacted]', $data['validation']['data']['token']);
    }

    public function testValidateConnectionPassesSubmittedCredentials(): void {
        $this->request->method('getParam')->willReturnMap([
            ['immich_base_url', null, null],
            ['server_url', null, 'https://candidate.example.com'],
            ['admin_api_key', null, null],
            ['api_key', null, 'candidate-api-key-redacted'],
        ]);
        $this->immichUserAdminService->expects($this->once())
            ->method('validateAdminConnection')
            ->with('https://candidate.example.com', 'candidate-api-key-redacted')
            ->willReturn(['success' => true, 'data' => ['probe' => 'GET /api/admin/users']]);

        $response = $this->controller->validateConnection();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }

    public function testValidateConnectionFailureIsStructuredAndRedacted(): void {
        $this->immichUserAdminService->method('validateAdminConnection')->willReturn([
            'success' => false,
            'error' => 'Immich failed with api_key=test-api-key-redacted',
        ]);

        $response = $this->controller->validateConnection();
        $encoded = json_encode($response->getData(), JSON_THROW_ON_ERROR);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('connection_validation_failed', $response->getData()['error']['code']);
        $this->assertSame('Connection validation failed.', $response->getData()['error']['message']);
        $this->assertSame('Immich failed with api_key=[redacted]', $response->getData()['error']['details']['detail']);
        $this->assertStringNotContainsString('test-api-key-redacted', $encoded);
        $this->assertStringContainsString('api_key=[redacted]', $encoded);
    }

    public function testAdminMethodsRequireAdminAndMutatingMethodsKeepCsrf(): void {
        $reflection = new ReflectionClass(AdminSettingsController::class);
        foreach (['getConfig', 'setConfig', 'validateConnection'] as $methodName) {
            $this->assertNotEmpty($reflection->getMethod($methodName)->getAttributes(AdminRequired::class), $methodName . ' must require admin.');
        }

        $this->assertNotEmpty($reflection->getMethod('getConfig')->getAttributes(NoCSRFRequired::class));
        $this->assertSame([], $reflection->getMethod('setConfig')->getAttributes(NoCSRFRequired::class));
        $this->assertSame([], $reflection->getMethod('validateConnection')->getAttributes(NoCSRFRequired::class));
    }

    private function validConfig(): array {
        return [
            'immich_base_url' => 'https://photos.example.com',
            'provisioning_enabled' => false,
            'user_scope_mode' => 'all',
            'user_scope_groups' => [],
            'storage_label_template' => '{uid}',
            'email_template' => '{uid}@immich.local',
            'initial_password_policy' => 'random',
            'host_path_template' => '/srv/immich/originals/library/{storageLabel}',
            'nc_visible_path_template' => '/mnt/immich-library/{storageLabel}',
            'mount_name_template' => 'Immich Photos',
            'mkdir_policy_enabled' => false,
            'quota_sync_mode' => 'disabled',
            'quota_reserve_bytes' => 268435456,
            'delete_disable_policy' => 'disable_suspend',
            'external_storage_auto_create' => false,
            'admin_api_key_configured' => false,
            'immich_username' => '[redacted]',
            'immich_password' => '[redacted]',
        ];
    }

    private function requestMap(array $values): array {
        $keys = [
            'immich_base_url',
            'admin_api_key',
            'provisioning_enabled',
            'user_scope_mode',
            'user_scope_groups',
            'storage_label_template',
            'email_template',
            'initial_password_policy',
            'host_path_template',
            'nc_visible_path_template',
            'mount_name_template',
            'mkdir_policy_enabled',
            'quota_sync_mode',
            'quota_reserve_bytes',
            'delete_disable_policy',
            'external_storage_auto_create',
            'delete_opt_in_confirmed',
        ];

        return array_map(static fn(string $key): array => [$key, null, $values[$key] ?? null], $keys);
    }
}
