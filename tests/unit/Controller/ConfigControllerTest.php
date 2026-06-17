<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\Controller;

use OCA\IntegrationImmich\Controller\ConfigController;
use OCA\IntegrationImmich\Db\SyncState;
use OCA\IntegrationImmich\Service\ActionPolicyService;
use OCA\IntegrationImmich\Service\AdminConfigService;
use OCA\IntegrationImmich\Service\ImmichService;
use OCA\IntegrationImmich\Service\ImmichUserAdminService;
use OCA\IntegrationImmich\Service\SyncStateService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class ConfigControllerTest extends TestCase {

	private ConfigController $controller;
	private ImmichService&MockObject $immichService;
	private ActionPolicyService&MockObject $actionPolicyService;
	private AdminConfigService&MockObject $adminConfigService;
	private SyncStateService&MockObject $syncStateService;
	private ImmichUserAdminService&MockObject $immichUserAdminService;
	private ICrypto&MockObject $crypto;
	private IRequest&MockObject $request;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();

		$this->immichService = $this->createMock(ImmichService::class);
		$this->actionPolicyService = $this->createMock(ActionPolicyService::class);
		$this->adminConfigService = $this->createMock(AdminConfigService::class);
		$this->syncStateService = $this->createMock(SyncStateService::class);
		$this->immichUserAdminService = $this->createMock(ImmichUserAdminService::class);
		$this->crypto = $this->createMock(ICrypto::class);
		$this->request = $this->createMock(IRequest::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->actionPolicyService->method('getCapabilityFlags')->with('testuser')->willReturn([
			'exportCopyEnabled' => false,
			'importToImmichEnabled' => false,
			'immichDeleteEnabled' => false,
			'mirrorMountPaths' => [],
		]);
		$this->adminConfigService->method('getAdminConfig')->willReturn([
			AdminConfigService::KEY_IMMICH_BROWSING_MODE => AdminConfigService::BROWSING_MODE_PERSONAL,
		]);
		$this->adminConfigService->method('getImmichBaseUrl')->willReturn('https://photos.example.com');
		$this->crypto->method('decrypt')->willReturnCallback(static function (string $value): string {
			if (str_starts_with($value, 'encrypted:')) {
				return substr($value, strlen('encrypted:'));
			}

			throw new \RuntimeException('not encrypted');
		});
		$this->crypto->method('encrypt')->willReturnCallback(static fn(string $value): string => 'encrypted:' . $value);

		$this->controller = new ConfigController(
			$this->request,
			$this->immichService,
			$this->actionPolicyService,
			$this->adminConfigService,
			$this->syncStateService,
			$this->immichUserAdminService,
			$this->crypto,
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
		$this->assertFalse($data['admin_managed_connection']['enabled']);
	}

	public function testGetConfigReturnsAdminManagedMappedConnectionDefaults(): void {
		$state = new SyncState();
		$state->setNcUid('testuser');
		$state->setImmichUserId('immich-user');
		$state->setImmichUsername('alice@immich.local');
		$state->setImmichPassword('generated-password');
		$state->setImmichApiKey('encrypted:user-api-key');
		$this->adminConfigService->method('getAdminConfig')->willReturn([
			AdminConfigService::KEY_IMMICH_BROWSING_MODE => AdminConfigService::BROWSING_MODE_ADMIN_MANAGED,
		]);
		$this->syncStateService->method('findByUid')->with('testuser')->willReturn($state);

		$response = $this->controller->getConfig();
		$connection = $response->getData()['admin_managed_connection'];

		$this->assertTrue($connection['enabled']);
		$this->assertTrue($connection['mapped']);
		$this->assertSame('https://photos.example.com', $connection['server_url']);
		$this->assertSame('alice@immich.local', $connection['username']);
		$this->assertSame('generated-password', $connection['password']);
		$this->assertSame('user-api-key', $connection['api_key']);
		$this->assertTrue($connection['api_key_set']);
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

	public function testSetAdminManagedConnectionValidatesBothCredentialsBeforeSaving(): void {
		$state = new SyncState();
		$state->setNcUid('testuser');
		$state->setImmichUserId('immich-user');
		$state->setImmichApiKey('encrypted:old-api-key');
		$this->request->method('getParam')->willReturnMap([
			['immich_username', null, 'alice@immich.local'],
			['immich_password', null, 'generated-password'],
			['immich_api_key', null, 'new-api-key'],
			['immich_username', '', 'alice@immich.local'],
			['immich_password', '', 'generated-password'],
			['immich_api_key', '', 'new-api-key'],
		]);
		$this->syncStateService->method('findByUid')->with('testuser')->willReturn($state);
		$this->immichUserAdminService->expects($this->once())
			->method('validateUserLogin')
			->with('alice@immich.local', 'generated-password')
			->willReturn(['success' => true]);
		$this->immichUserAdminService->expects($this->once())
			->method('validateUserApiKey')
			->with('new-api-key')
			->willReturn(['success' => true]);
		$this->syncStateService->expects($this->once())
			->method('updateMapping')
			->with('testuser', [
				'immichUsername' => 'alice@immich.local',
				'immichPassword' => 'generated-password',
				'immichApiKey' => 'encrypted:new-api-key',
			]);

		$response = $this->controller->setConfig();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}

	public function testSetAdminManagedConnectionRejectsInvalidApiKeyWithoutSaving(): void {
		$state = new SyncState();
		$state->setNcUid('testuser');
		$state->setImmichUserId('immich-user');
		$this->request->method('getParam')->willReturnMap([
			['immich_username', null, 'alice@immich.local'],
			['immich_password', null, 'generated-password'],
			['immich_api_key', null, 'bad-api-key'],
			['immich_username', '', 'alice@immich.local'],
			['immich_password', '', 'generated-password'],
			['immich_api_key', '', 'bad-api-key'],
		]);
		$this->syncStateService->method('findByUid')->with('testuser')->willReturn($state);
		$this->immichUserAdminService->method('validateUserLogin')->willReturn(['success' => true]);
		$this->immichUserAdminService->method('validateUserApiKey')->willReturn([
			'success' => false,
			'error' => 'Invalid api_key=bad-api-key',
		]);
		$this->syncStateService->expects($this->never())->method('updateMapping');

		$response = $this->controller->setConfig();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('immich_api_key_validation_failed', $data['error']['code']);
		$this->assertSame('Invalid api_key=[redacted]', $data['error']['details']['detail']);
	}

	public function testSetAdminManagedConnectionBindsExistingImmichUserWhenNoMapping(): void {
		$createdState = new SyncState();
		$createdState->setNcUid('testuser');
		$boundState = new SyncState();
		$boundState->setNcUid('testuser');
		$boundState->setImmichUserId('immich-existing');
		$boundState->setImmichEmail('alice@immich.local');

		$this->request->method('getParam')->willReturnMap([
			['immich_username', null, 'alice@immich.local'],
			['immich_password', null, 'generated-password'],
			['immich_api_key', null, 'pre-existing-key'],
			['immich_username', '', 'alice@immich.local'],
			['immich_password', '', 'generated-password'],
			['immich_api_key', '', 'pre-existing-key'],
		]);
		$this->syncStateService->method('findByUid')
			->with('testuser')
			->willReturnOnConsecutiveCalls(null, $createdState, $boundState);
		$this->immichUserAdminService->method('validateUserLogin')->willReturn(['success' => true]);
		$this->immichUserAdminService->method('validateUserApiKey')->willReturn(['success' => true]);
		$this->immichUserAdminService->method('findUserByApiKey')->with('pre-existing-key')->willReturn([
			'success' => true,
			'user' => [
				'id' => 'immich-existing',
				'email' => 'alice@immich.local',
				'name' => 'Alice',
				'storageLabel' => 'alice',
			],
		]);
		$this->syncStateService->method('findByImmichUserId')->with('immich-existing')->willReturn(null);
		$this->syncStateService->expects($this->once())
			->method('getOrCreateForUid')
			->with('testuser')
			->willReturn($createdState);

		$updateCalls = [];
		$this->syncStateService->expects($this->exactly(2))
			->method('updateMapping')
			->willReturnCallback(function (string $uid, array $fields) use (&$updateCalls): void {
				$updateCalls[] = $fields;
			});

		$response = $this->controller->setConfig();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
		$this->assertCount(2, $updateCalls);
		$this->assertSame('immich-existing', $updateCalls[0]['immichUserId']);
		$this->assertSame('alice@immich.local', $updateCalls[0]['immichEmail']);
		$this->assertSame('alice', $updateCalls[0]['storageLabel']);
		$this->assertSame(SyncStateService::STATUS_ACTIVE, $updateCalls[0]['scopeStatus']);
		$this->assertSame('alice@immich.local', $updateCalls[1]['immichUsername']);
		$this->assertSame('generated-password', $updateCalls[1]['immichPassword']);
		$this->assertSame('encrypted:pre-existing-key', $updateCalls[1]['immichApiKey']);
	}

	public function testSetAdminManagedConnectionRefusesToHijackExistingMapping(): void {
		$existingOwner = new SyncState();
		$existingOwner->setNcUid('someone-else');
		$existingOwner->setImmichUserId('immich-existing');

		$this->request->method('getParam')->willReturnMap([
			['immich_username', null, 'alice@immich.local'],
			['immich_password', null, 'generated-password'],
			['immich_api_key', null, 'pre-existing-key'],
			['immich_username', '', 'alice@immich.local'],
			['immich_password', '', 'generated-password'],
			['immich_api_key', '', 'pre-existing-key'],
		]);
		$this->syncStateService->method('findByUid')->with('testuser')->willReturn(null);
		$this->immichUserAdminService->method('validateUserLogin')->willReturn(['success' => true]);
		$this->immichUserAdminService->method('validateUserApiKey')->willReturn(['success' => true]);
		$this->immichUserAdminService->method('findUserByApiKey')->willReturn([
			'success' => true,
			'user' => [
				'id' => 'immich-existing',
				'email' => 'alice@immich.local',
				'name' => 'Alice',
				'storageLabel' => 'alice',
			],
		]);
		$this->syncStateService->method('findByImmichUserId')->with('immich-existing')->willReturn($existingOwner);
		$this->syncStateService->expects($this->never())->method('updateMapping');

		$response = $this->controller->setConfig();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('immich_user_already_mapped', $data['error']['code']);
	}

}
