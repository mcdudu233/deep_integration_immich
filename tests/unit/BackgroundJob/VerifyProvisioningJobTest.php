<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use OCA\IntegrationImmich\AppInfo\Application;
use OCA\IntegrationImmich\BackgroundJob\VerifyProvisioningJob;
use OCA\IntegrationImmich\Db\SyncState;
use OCA\IntegrationImmich\Service\AdminConfigService;
use OCA\IntegrationImmich\Service\ExternalStorageProvisioner;
use OCA\IntegrationImmich\Service\ImmichUserAdminService;
use OCA\IntegrationImmich\Service\LockService;
use OCA\IntegrationImmich\Service\SyncStateService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class VerifyProvisioningJobTest extends TestCase {
	private AdminConfigService&MockObject $adminConfigService;
	private SyncStateService&MockObject $syncStateService;
	private ExternalStorageProvisioner&MockObject $externalStorageProvisioner;
	private ImmichUserAdminService&MockObject $immichUserAdminService;
	private IUserManager&MockObject $userManager;
	private IGroupManager&MockObject $groupManager;
	private LoggerInterface&MockObject $logger;
	private LockService&MockObject $lockService;
	private array $adminConfig;

	protected function setUp(): void {
		parent::setUp();

		$this->adminConfig = $this->config();
		$this->adminConfigService = $this->createMock(AdminConfigService::class);
		$this->adminConfigService->method('getAdminConfig')->willReturnCallback(fn(): array => $this->adminConfig);
		$this->syncStateService = $this->createMock(SyncStateService::class);
		$this->externalStorageProvisioner = $this->createMock(ExternalStorageProvisioner::class);
		$this->immichUserAdminService = $this->createMock(ImmichUserAdminService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->lockService = $this->createMock(LockService::class);
		$this->lockService->method('withLock')->willReturnCallback(static fn(string $key, int $timeout, callable $callback): mixed => $callback());
	}

	public function testVerifyOneReturnsOkHealthReport(): void {
		$this->adminConfig[AdminConfigService::KEY_QUOTA_SYNC_MODE] = 'manual';
		$lastQuotaSyncAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
		$state = $this->state('alice', 'immich-alice', SyncStateService::STATUS_ACTIVE, 42, $lastQuotaSyncAt);
		$this->syncStateService->method('findByUid')->with('alice')->willReturn($state);
		$this->userManager->method('get')->with('alice')->willReturn($this->user('alice'));
		$this->immichUserAdminService->method('listUsers')->willReturn([
			['id' => 'immich-alice', 'email' => 'alice@example.test', 'storageLabel' => 'alice', 'isEnabled' => true],
		]);
		$this->externalStorageProvisioner->expects($this->once())
			->method('verifyMount')
			->with('alice')
			->willReturn($this->mountHealth('alice', 'ok'));

		$result = $this->job()->runJob(['ncUid' => 'alice']);

		$this->assertSame('verify_provisioning', $result['job']);
		$this->assertSame('ok', $result['status']);
		$this->assertSame('ok', $result['users']['alice']['status']);
		$this->assertTrue($result['users']['alice']['mount']['read_only']);
		$this->assertSame('ok', $result['users']['alice']['immich']['status']);
		$this->assertSame('ok', $result['users']['alice']['quota']['status']);
		$this->assertSame($lastQuotaSyncAt->format(\DateTimeInterface::ATOM), $result['users']['alice']['quota']['lastQuotaSyncAt']);
	}

	public function testVerifyDetectsStaleMapping(): void {
		$state = $this->state('alice', 'missing-immich-user', SyncStateService::STATUS_ACTIVE, 42, new DateTimeImmutable());
		$this->syncStateService->method('findByUid')->with('alice')->willReturn($state);
		$this->userManager->method('get')->with('alice')->willReturn($this->user('alice'));
		$this->immichUserAdminService->method('listUsers')->willReturn([]);
		$this->externalStorageProvisioner->method('verifyMount')->with('alice')->willReturn($this->mountHealth('alice', 'ok'));

		$result = $this->job()->verifyOneUser('alice');

		$this->assertSame('failed', $result['status']);
		$this->assertSame('stale_mapping', $result['users']['alice']['immich']['status']);
		$this->assertStringContainsString('stale_mapping', implode('; ', $result['users']['alice']['errors']));
	}

	public function testVerifyDetectsDisabledImmichUserAndQuotaFailure(): void {
		$state = $this->state('alice', 'immich-alice', SyncStateService::STATUS_QUOTA_FAILED, 42, null);
		$state->setLastError('quota computation failed');
		$this->syncStateService->method('findByUid')->with('alice')->willReturn($state);
		$this->userManager->method('get')->with('alice')->willReturn($this->user('alice'));
		$this->immichUserAdminService->method('listUsers')->willReturn([
			['id' => 'immich-alice', 'email' => 'alice@example.test', 'storageLabel' => 'alice', 'isEnabled' => false],
		]);
		$this->externalStorageProvisioner->method('verifyMount')->with('alice')->willReturn($this->mountHealth('alice', 'ok'));
		$this->adminConfig[AdminConfigService::KEY_QUOTA_SYNC_MODE] = 'event_scheduled';

		$result = $this->job()->verifyOneUser('alice');

		$this->assertSame('failed', $result['status']);
		$this->assertSame('disabled', $result['users']['alice']['immich']['status']);
		$this->assertSame('failed', $result['users']['alice']['quota']['status']);
	}

	public function testVerifyAllOnlyChecksMappedScopedUsers(): void {
		$this->adminConfig[AdminConfigService::KEY_USER_SCOPE_MODE] = 'groups';
		$this->adminConfig[AdminConfigService::KEY_USER_SCOPE_GROUPS] = ['staff'];
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$this->user('alice'), $this->user('bob')]);
		$this->groupManager->method('get')->with('staff')->willReturn($group);
		$this->groupManager->method('isInGroup')->with('alice', 'staff')->willReturn(true);
		$this->userManager->method('get')->with('alice')->willReturn($this->user('alice'));
		$state = $this->state('alice', 'immich-alice', SyncStateService::STATUS_ACTIVE, 42, new DateTimeImmutable());
		$this->syncStateService->method('findByUid')->willReturnMap([
			['alice', $state],
			['bob', null],
		]);
		$this->immichUserAdminService->method('listUsers')->willReturn([
			['id' => 'immich-alice', 'isEnabled' => true],
		]);
		$this->externalStorageProvisioner->expects($this->once())->method('verifyMount')->with('alice')->willReturn($this->mountHealth('alice', 'ok'));

		$result = $this->job()->verifyAllMappedUsers();

		$this->assertArrayHasKey('alice', $result['users']);
		$this->assertArrayNotHasKey('bob', $result['users']);
		$this->assertSame('ok', $result['status']);
	}

	public function testMountVerificationExceptionIsRedacted(): void {
		$rawSecret = 'secret-admin-key';
		$state = $this->state('alice', 'immich-alice', SyncStateService::STATUS_ACTIVE, 42, new DateTimeImmutable());
		$this->syncStateService->method('findByUid')->with('alice')->willReturn($state);
		$this->userManager->method('get')->with('alice')->willReturn($this->user('alice'));
		$this->immichUserAdminService->method('listUsers')->willReturn([
			['id' => 'immich-alice', 'isEnabled' => true],
		]);
		$this->externalStorageProvisioner->method('verifyMount')->willThrowException(new \RuntimeException('mount failed with api_key=' . $rawSecret));
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				$this->callback(function (string $message) use ($rawSecret): bool {
					$this->assertStringNotContainsString($rawSecret, $message);
					return str_contains($message, 'api_key=[redacted]');
				}),
				$this->callback(static fn(array $context): bool => ($context['app'] ?? '') === Application::APP_ID && ($context['ncUid'] ?? '') === 'alice')
			);

		$result = $this->job()->verifyOneUser('alice');
		$encoded = json_encode($result, JSON_THROW_ON_ERROR);

		$this->assertSame('failed', $result['status']);
		$this->assertStringNotContainsString($rawSecret, $encoded);
		$this->assertStringContainsString('api_key=[redacted]', $encoded);
	}

	private function job(): TestableVerifyProvisioningJob {
		return new TestableVerifyProvisioningJob(
			$this->createMock(ITimeFactory::class),
			$this->adminConfigService,
			$this->syncStateService,
			$this->externalStorageProvisioner,
			$this->immichUserAdminService,
			$this->userManager,
			$this->groupManager,
			$this->logger,
			null,
			$this->lockService,
		);
	}

	private function config(array $overrides = []): array {
		return array_merge([
			AdminConfigService::KEY_USER_SCOPE_MODE => 'all',
			AdminConfigService::KEY_USER_SCOPE_GROUPS => [],
			AdminConfigService::KEY_QUOTA_SYNC_MODE => 'disabled',
		], $overrides);
	}

	private function user(string $uid): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	private function state(string $uid, string $immichUserId, string $lastSyncStatus, int $mountId, ?DateTimeImmutable $lastQuotaSyncAt): SyncState {
		$state = new SyncState();
		$state->setNcUid($uid);
		$state->setImmichUserId($immichUserId);
		$state->setStorageLabel($uid);
		$state->setScopeStatus(SyncStateService::STATUS_ACTIVE);
		$state->setLastSyncStatus($lastSyncStatus);
		$state->setNcMountId($mountId);
		$state->setLastQuotaSyncAt($lastQuotaSyncAt);
		return $state;
	}

	private function mountHealth(string $uid, string $status): array {
		return [
			'ncUid' => $uid,
			'configured' => $status === 'ok',
			'exists' => true,
			'readable' => true,
			'read_only' => $status === 'ok',
			'available_only_to_uid' => $status === 'ok',
			'not_root_storage' => $status === 'ok',
			'mount_id' => 42,
			'status' => $status,
			'errors' => [],
			'remediation' => '',
		];
	}
}

final class TestableVerifyProvisioningJob extends VerifyProvisioningJob {
	public function runJob(mixed $argument): array {
		$this->run($argument);
		return $this->getLastResult() ?? [];
	}
}
