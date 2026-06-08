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
            'admin_api_key_configured' => true,
        ]);

        $response = $this->controller->getConfig();
        $data = $response->getData();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertTrue($data['success']);
        $this->assertSame('https://photos.example.com', $data['config']['immich_base_url']);
        $this->assertTrue($data['config']['admin_api_key_configured']);
        $this->assertArrayNotHasKey('admin_api_key', $data['config']);
    }

    public function testSetConfigValidatesAndSavesThroughAdminConfigService(): void {
        $currentConfig = $this->validConfig();
        $savedConfig = $currentConfig;
        $savedConfig['admin_api_key_configured'] = true;
        $this->adminConfigService->method('getAdminConfig')->willReturnOnConsecutiveCalls($currentConfig, $savedConfig);
        $this->adminConfigService->expects($this->once())
            ->method('validateAdminConfig')
            ->with($this->callback(static fn(array $values): bool => $values['admin_api_key'] === 'secret-admin-key'))
            ->willReturn([]);
        $this->adminConfigService->expects($this->once())
            ->method('setAdminConfig')
            ->with($this->callback(static fn(array $values): bool => $values['admin_api_key'] === 'secret-admin-key'));
        $this->request->method('getParam')->willReturnMap($this->requestMap([
            'immich_base_url' => 'https://photos.example.com',
            'admin_api_key' => 'secret-admin-key',
            'provisioning_enabled' => true,
        ]));

        $response = $this->controller->setConfig();
        $encoded = json_encode($response->getData(), JSON_THROW_ON_ERROR);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertTrue($response->getData()['success']);
        $this->assertStringNotContainsString('secret-admin-key', $encoded);
        $this->assertArrayNotHasKey('admin_api_key', $response->getData()['config']);
    }

    public function testSetConfigReturnsStructuredValidationError(): void {
        $this->adminConfigService->method('getAdminConfig')->willReturn($this->validConfig());
        $this->adminConfigService->expects($this->once())
            ->method('validateAdminConfig')
            ->willReturn(['immich_base_url' => 'URL is invalid.']);
        $this->adminConfigService->expects($this->never())->method('setAdminConfig');
        $this->request->method('getParam')->willReturnMap($this->requestMap([
            'immich_base_url' => 'not-a-url',
        ]));

        $response = $this->controller->setConfig();
        $data = $response->getData();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertFalse($data['success']);
        $this->assertSame('invalid_admin_config', $data['error']['code']);
        $this->assertSame('URL is invalid.', $data['error']['details']['fields']['immich_base_url']);
    }

    public function testValidateConnectionReturnsRedactedSuccess(): void {
        $this->immichUserAdminService->expects($this->once())
            ->method('validateAdminConnection')
            ->willReturn([
                'success' => true,
                'data' => ['token' => 'raw-token'],
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
            ['api_key', null, 'candidate-secret'],
        ]);
        $this->immichUserAdminService->expects($this->once())
            ->method('validateAdminConnection')
            ->with('https://candidate.example.com', 'candidate-secret')
            ->willReturn(['success' => true, 'data' => ['probe' => 'GET /api/admin/users']]);

        $response = $this->controller->validateConnection();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }

    public function testValidateConnectionFailureIsStructuredAndRedacted(): void {
        $this->immichUserAdminService->method('validateAdminConnection')->willReturn([
            'success' => false,
            'error' => 'Immich failed with api_key=secret-admin-key',
        ]);

        $response = $this->controller->validateConnection();
        $encoded = json_encode($response->getData(), JSON_THROW_ON_ERROR);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('connection_validation_failed', $response->getData()['error']['code']);
        $this->assertStringNotContainsString('secret-admin-key', $encoded);
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
