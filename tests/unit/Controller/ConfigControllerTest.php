<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\Controller;

use OCA\IntegrationImmich\Controller\ConfigController;
use OCA\IntegrationImmich\Service\ActionPolicyService;
use OCA\IntegrationImmich\Service\ImmichService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class ConfigControllerTest extends TestCase {

	private ConfigController $controller;
	private ImmichService&MockObject $immichService;
	private ActionPolicyService&MockObject $actionPolicyService;
	private IRequest&MockObject $request;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();

		$this->immichService = $this->createMock(ImmichService::class);
		$this->actionPolicyService = $this->createMock(ActionPolicyService::class);
		$this->request = $this->createMock(IRequest::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->actionPolicyService->method('getCapabilityFlags')->with('testuser')->willReturn([
			'exportCopyEnabled' => false,
			'importToImmichEnabled' => false,
			'immichDeleteEnabled' => false,
			'mirrorMountPaths' => [],
		]);

		$this->controller = new ConfigController(
			$this->request,
			$this->immichService,
			$this->actionPolicyService,
			'testuser',
			$this->logger,
		);
	}

	public function testGetConfigReturnsServerUrlAndKeyStatus(): void {
		$this->immichService->method('getServerUrl')->willReturn('https://photos.example.com');
		$this->immichService->method('getApiKey')->willReturn('abcdefgh');

		$response = $this->controller->getConfig();
		$data = $response->getData();

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertEquals('https://photos.example.com', $data['server_url']);
		$this->assertTrue($data['api_key_set']);
		$this->assertSame(false, $data['actionCapabilities']['exportCopyEnabled']);
		$this->assertArrayNotHasKey('api_key_masked', $data);
	}

	public function testGetConfigReturnsApiKeyNotSetWhenEmpty(): void {
		$this->immichService->method('getServerUrl')->willReturn('');
		$this->immichService->method('getApiKey')->willReturn('');

		$response = $this->controller->getConfig();
		$data = $response->getData();

		$this->assertFalse($data['api_key_set']);
		$this->assertArrayNotHasKey('api_key_masked', $data);
	}

	public function testSetConfigSavesUrlAndKey(): void {
		$this->request->method('getParam')->willReturnMap([
			['server_url', null, 'https://photos.example.com'],
			['api_key', null, 'test-api-key-redacted'],
			['validate', false, false],
		]);

		$this->immichService->expects($this->once())->method('setServerUrl')->with('https://photos.example.com');
		$this->immichService->expects($this->once())->method('setApiKey')->with('test-api-key-redacted');

		$response = $this->controller->setConfig();

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}

	public function testSetConfigDoesNotOverwriteKeyWhenEmpty(): void {
		$this->request->method('getParam')->willReturnMap([
			['server_url', null, 'https://photos.example.com'],
			['api_key', null, ''],
			['validate', false, false],
		]);

		$this->immichService->expects($this->once())->method('setServerUrl');
		$this->immichService->expects($this->never())->method('setApiKey');

		$this->controller->setConfig();
	}

	public function testSetConfigWithValidationSuccessful(): void {
		$this->request->method('getParam')->willReturnMap([
			['server_url', null, null],
			['api_key', null, null],
			['validate', false, true],
		]);

		$this->immichService->method('validateConnection')->willReturn([
			'success' => true,
			'data' => ['token' => 'raw-token-redacted'],
		]);

		$response = $this->controller->setConfig();
		$data = $response->getData();
		$encoded = json_encode($data, JSON_THROW_ON_ERROR);

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($data['success']);
		$this->assertSame('[redacted]', $data['validation']['data']['token']);
		$this->assertStringNotContainsString('raw-token-redacted', $encoded);
	}

	public function testSetConfigWithValidationFailed(): void {
		$this->request->method('getParam')->willReturnMap([
			['server_url', null, null],
			['api_key', null, null],
			['validate', false, true],
		]);

		$this->immichService->method('validateConnection')->willReturn([
			'success' => false,
			'error' => 'Connection refused with api_key=test-api-key-redacted',
		]);

		$response = $this->controller->setConfig();
		$data = $response->getData();
		$encoded = json_encode($data, JSON_THROW_ON_ERROR);

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertFalse($data['success']);
		$this->assertSame('connection_validation_failed', $data['error']['code']);
		$this->assertSame('Connection validation failed.', $data['error']['message']);
		$this->assertSame('Connection refused with api_key=[redacted]', $data['error']['details']['detail']);
		$this->assertSame('Connection refused with api_key=[redacted]', $data['detail']);
		$this->assertStringNotContainsString('test-api-key-redacted', $encoded);
	}

}
