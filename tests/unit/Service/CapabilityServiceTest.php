<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\Service;

use OCA\IntegrationImmich\Service\AdminConfigService;
use OCA\IntegrationImmich\Service\CapabilityService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class CapabilityServiceTest extends TestCase {
	private AdminConfigService&MockObject $adminConfigService;
	private IClientService&MockObject $clientService;
	private IClient&MockObject $client;

    protected function setUp(): void {
        parent::setUp();

		$this->adminConfigService = $this->createMock(AdminConfigService::class);
		$this->clientService = $this->createMock(IClientService::class);
		$this->client = $this->createMock(IClient::class);
        $this->clientService->method('newClient')->willReturn($this->client);
    }

    public function testAllCapabilitiesSupported(): void {
        $this->mockAdminConfig('https://photos.example.com', 'secret-admin-key');
        $this->client->expects($this->once())
            ->method('get')
            ->with('https://photos.example.com/api/admin/users', $this->callback(function (array $options): bool {
                $this->assertSame('secret-admin-key', $options['headers']['x-api-key']);
                return true;
            }))
            ->willReturn($this->response(200));

        $service = $this->newService(
            $this->infoXml(true),
            $this->allSymbolsAvailable()
        );

        $capabilities = $service->getCapabilities();

        $this->assertTrue($capabilities['nextcloudDependencyRange']['supported']);
        $this->assertSame('27', $capabilities['nextcloudDependencyRange']['minVersion']);
        $this->assertSame('34', $capabilities['nextcloudDependencyRange']['maxVersion']);
        $this->assertTrue($capabilities['phpRuntime']['supported']);
        $this->assertTrue($capabilities['immichAdminUsers']['supported']);
        $this->assertTrue($capabilities['immichQuota']['supported']);
        $this->assertTrue($capabilities['nextcloudExternalStorageAutoCreate']['supported']);
        $this->assertTrue($capabilities['nextcloudEvents']['supported']);
        $this->assertTrue($capabilities['adminSettings']['supported']);
        $this->assertTrue($capabilities['safeProxyBrowsing']['supported']);
        $this->assertCapabilitiesDoNotExposeSecret($capabilities, 'secret-admin-key');
    }

    public function testMissingImmichAdminRouteReturnsBlockingCapability(): void {
        $this->mockAdminConfig('https://photos.example.com', 'secret-admin-key');
        $this->client->expects($this->once())
            ->method('get')
            ->with('https://photos.example.com/api/admin/users', $this->callback(function (array $options): bool {
                return ($options['headers']['x-api-key'] ?? '') === 'secret-admin-key';
            }))
            ->willReturn($this->response(404));

        $service = $this->newService($this->infoXml(true), $this->allSymbolsAvailable());

        $capabilities = $service->getCapabilities();

        $this->assertFalse($capabilities['immichAdminUsers']['supported']);
        $this->assertSame('blocking', $capabilities['immichAdminUsers']['severity']);
        $this->assertStringContainsString('GET /api/admin/users', $capabilities['immichAdminUsers']['reason']);
        $this->assertFalse($capabilities['immichQuota']['supported']);
        $this->assertCapabilitiesDoNotExposeSecret($capabilities, 'secret-admin-key');
    }

    public function testMissingExternalStorageAutoCreateApiReturnsBlockingCapability(): void {
        $this->mockAdminConfig('https://photos.example.com', 'secret-admin-key');
        $this->client->method('get')->willReturn($this->response(200));

        $symbols = $this->allSymbolsAvailable();
        $symbols['OCP\\Files\\External\\IExternalMountProvider'] = false;
        $symbols['OC\\Files\\External\\Service\\DBConfigService'] = false;

        $service = $this->newService($this->infoXml(true), $symbols);

        $capabilities = $service->getCapabilities();

        $this->assertFalse($capabilities['nextcloudExternalStorageAutoCreate']['supported']);
        $this->assertSame('blocking', $capabilities['nextcloudExternalStorageAutoCreate']['severity']);
        $this->assertStringContainsString('external-storage provisioning API', $capabilities['nextcloudExternalStorageAutoCreate']['reason']);
    }

    public function testMissingOptionalEventClassReturnsWarningWithoutFatalError(): void {
        $this->mockAdminConfig('https://photos.example.com', 'secret-admin-key');
        $this->client->method('get')->willReturn($this->response(200));

        $symbols = $this->allSymbolsAvailable();
        $symbols['OCP\\Group\\Events\\UserRemovedEvent'] = false;

        $service = $this->newService($this->infoXml(true), $symbols);

        $capabilities = $service->getCapabilities();

        $this->assertFalse($capabilities['nextcloudEvents']['supported']);
        $this->assertSame('warning', $capabilities['nextcloudEvents']['severity']);
        $this->assertSame([], $capabilities['nextcloudEvents']['missingRequired']);
        $this->assertSame(['OCP\\Group\\Events\\UserRemovedEvent'], $capabilities['nextcloudEvents']['missingOptional']);
    }

    public function testAdminCredentialsAbsentSkipsImmichProbeAndDisablesSafeProxy(): void {
        $this->mockAdminConfig('', '');
        $this->client->expects($this->never())->method('get');
        $this->client->expects($this->never())->method('post');

        $service = $this->newService($this->infoXml(true), $this->allSymbolsAvailable());

        $capabilities = $service->getCapabilities();

        $this->assertFalse($capabilities['immichAdminUsers']['supported']);
        $this->assertSame('Admin credentials not configured', $capabilities['immichAdminUsers']['reason']);
        $this->assertFalse($capabilities['immichQuota']['supported']);
        $this->assertFalse($capabilities['safeProxyBrowsing']['supported']);
        $this->assertSame('Admin credentials not configured', $capabilities['safeProxyBrowsing']['reason']);
    }

    public function testAdminSettingsMustBeRegisteredInInfoXml(): void {
        $this->mockAdminConfig('https://photos.example.com', 'secret-admin-key');
        $this->client->method('get')->willReturn($this->response(200));

        $service = $this->newService($this->infoXml(false), $this->allSymbolsAvailable());

        $capabilities = $service->getCapabilities();

        $this->assertFalse($capabilities['adminSettings']['supported']);
        $this->assertSame('blocking', $capabilities['adminSettings']['severity']);
        $this->assertStringContainsString('not registered', $capabilities['adminSettings']['reason']);
    }

	private function newService(string $infoXmlPath, array $symbols): CapabilityService {
		return new CapabilityService(
			$this->adminConfigService,
			$this->clientService,
			$infoXmlPath,
			$symbols,
		);
	}

	private function mockAdminConfig(string $url, string $apiKey): void {
		$this->adminConfigService->method('getImmichBaseUrl')->willReturn($url);
		$this->adminConfigService->method('getAdminApiKey')->willReturn($apiKey);
	}

    private function response(int $statusCode): IResponse&MockObject {
        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        return $response;
    }

    private function infoXml(bool $withAdminSettings): string {
        $settings = $withAdminSettings
            ? '<settings><admin>OCA\\IntegrationImmich\\Settings\\AdminSettings</admin></settings>'
            : '<settings><personal>OCA\\IntegrationImmich\\Settings\\PersonalSettings</personal></settings>';

        $path = tempnam(sys_get_temp_dir(), 'integration-immich-info-');
        $this->assertIsString($path);
        file_put_contents($path, '<?xml version="1.0"?><info><dependencies><nextcloud min-version="27" max-version="34"/><php min-version="8.2"/></dependencies>' . $settings . '</info>');

        return $path;
    }

    private function allSymbolsAvailable(): array {
        return [
            'OCP\\User\\Events\\UserCreatedEvent' => true,
            'OCP\\User\\Events\\UserChangedEvent' => true,
            'OCP\\User\\Events\\UserDeletedEvent' => true,
            'OCP\\Accounts\\UserUpdatedEvent' => true,
            'OCP\\Group\\Events\\UserAddedEvent' => true,
            'OCP\\Group\\Events\\UserRemovedEvent' => true,
            'OCP\\Files\\External\\IExternalMountProvider' => true,
            'OC\\Files\\External\\Service\\DBConfigService' => true,
            'OCA\\IntegrationImmich\\Service\\SyncStateService' => true,
        ];
    }

    private function assertCapabilitiesDoNotExposeSecret(array $capabilities, string $secret): void {
        $this->assertStringNotContainsString($secret, json_encode($capabilities, JSON_THROW_ON_ERROR));
    }
}
