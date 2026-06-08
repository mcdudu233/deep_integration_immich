<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use OCA\IntegrationImmich\AppInfo\Application;
use OCA\IntegrationImmich\BackgroundJob\ProvisionImmichUserJob;
use OCA\IntegrationImmich\BackgroundJob\ProvisionNextcloudMountJob;
use OCA\IntegrationImmich\BackgroundJob\ReconcileUsersJob;
use OCA\IntegrationImmich\BackgroundJob\SyncQuotaJob;
use OCA\IntegrationImmich\Db\SyncState;
use OCA\IntegrationImmich\Service\AdminConfigService;
use OCA\IntegrationImmich\Service\ImmichUserAdminService;
use OCA\IntegrationImmich\Service\LockService;
use OCA\IntegrationImmich\Service\PathTemplateService;
use OCA\IntegrationImmich\Service\ProvisioningService;
use OCA\IntegrationImmich\Service\SyncStateService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class ReconcileUsersJobTest extends TestCase {
	private AdminConfigService&MockObject $adminConfigService;
	private ProvisioningService&MockObject $provisioningService;
	private SyncStateService&MockObject $syncStateService;
	private ImmichUserAdminService&MockObject $immichUserAdminService;
	private PathTemplateService&MockObject $pathTemplateService;
	private IJobList&MockObject $jobList;
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
		$this->provisioningService = $this->createMock(ProvisioningService::class);
		$this->syncStateService = $this->createMock(SyncStateService::class);
		$this->immichUserAdminService = $this->createMock(ImmichUserAdminService::class);
		$this->pathTemplateService = $this->createMock(PathTemplateService::class);
		$this->pathTemplateService->method('sanitizeStorageLabel')->willReturnCallback(static fn(string $value): string => $value);
		$this->pathTemplateService->method('expandStorageLabelTemplate')->willReturnCallback(static fn(string $template, string $uid): string => strtr($template, [
			'{uid}' => $uid,
			'{storageLabel}' => $uid,
		]));
		$this->jobList = $this->createMock(IJobList::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->lockService = $this->createMock(LockService::class);
		$this->lockService->method('withLock')->willReturnCallback(static fn(string $key, int $timeout, callable $callback): mixed => $callback());
	}

	public function testDryRunOneUserPlansWithoutQueueing(): void {
		$this->adminConfig[AdminConfigService::KEY_QUOTA_SYNC_MODE] = 'manual';
		$this->userManager->method('get')->with('alice')->willReturn($this->user('alice'));
		$this->syncStateService->method('findByUid')->with('alice')->willReturn(null);
		$this->syncStateService->method('findByStorageLabel')->with('alice')->willReturn(null);
		$this->immichUserAdminService->method('listUsers')->willReturn([]);
		$this->provisioningService->expects($this->once())
			->method('reconcileUser')
			->with('alice', true)
			->willReturn([
				'ncUid' => 'alice',
				'action' => 'created',
				'immichUserId' => null,
				'storageLabel' => 'alice',
				'quotaSet' => 4096,
				'errors' => [],
				'dryRun' => true,
			]);
		$this->jobList->expects($this->never())->method('add');

		$result = $this->job()->runJob(['ncUid' => 'alice', 'dryRun' => true]);

		$this->assertSame('reconcile_users', $result['job']);
		$this->assertSame('one', $result['mode']);
		$this->assertTrue($result['dryRun']);
		$this->assertSame('planned', $result['status']);
		$this->assertSame('would_queue', $result['users']['alice']['action']);
		$this->assertSame([
			ProvisionImmichUserJob::class,
			ProvisionNextcloudMountJob::class,
			SyncQuotaJob::class,
		], $result['users']['alice']['wouldQueue']);
		$this->assertSame([], $result['queued']);
	}

	public function testGroupScopedAllUsersQueuesPerUserJobs(): void {
		$this->adminConfig[AdminConfigService::KEY_USER_SCOPE_MODE] = 'groups';
		$this->adminConfig[AdminConfigService::KEY_USER_SCOPE_GROUPS] = ['staff'];
		$this->adminConfig[AdminConfigService::KEY_QUOTA_SYNC_MODE] = 'manual';
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$this->user('alice')]);
		$this->groupManager->method('get')->with('staff')->willReturn($group);
		$this->groupManager->method('isInGroup')->with('alice', 'staff')->willReturn(true);
		$this->userManager->method('get')->with('alice')->willReturn($this->user('alice'));
		$state = $this->state('alice', null, SyncStateService::STATUS_PENDING, SyncStateService::STATUS_PENDING, null, null);
		$this->syncStateService->method('findByUid')->with('alice')->willReturn($state);
		$this->syncStateService->method('findByStorageLabel')->with('alice')->willReturn($state);
		$this->immichUserAdminService->method('listUsers')->willReturn([]);
		$this->provisioningService->expects($this->never())->method('reconcileUser');
		$queued = [];
		$this->jobList->expects($this->exactly(3))
			->method('add')
			->willReturnCallback(function (string $jobClass, array $argument) use (&$queued): void {
				$queued[] = [$jobClass, $argument];
			});

		$result = $this->job()->reconcileAllScopedUsers(false);

		$this->assertSame('queued', $result['status']);
		$this->assertSame([
			[ProvisionImmichUserJob::class, ['ncUid' => 'alice']],
			[ProvisionNextcloudMountJob::class, ['ncUid' => 'alice']],
			[SyncQuotaJob::class, ['ncUid' => 'alice']],
		], $queued);
		$this->assertSame('queued', $result['users']['alice']['status']);
		$this->assertSame([], $result['conflicts']);
	}

	public function testDuplicateImmichUsersRecordConflictWithoutQueueing(): void {
		$this->userManager->method('get')->with('alice')->willReturn($this->user('alice', 'alice@example.test'));
		$state = $this->state('alice', 'immich-alice', SyncStateService::STATUS_ACTIVE, SyncStateService::STATUS_ACTIVE, 42, new DateTimeImmutable());
		$state->setImmichEmail('alice@example.test');
		$this->syncStateService->method('findByUid')->with('alice')->willReturn($state);
		$this->syncStateService->method('findByImmichUserId')->with('immich-alice')->willReturn($state);
		$this->syncStateService->method('findByStorageLabel')->with('alice')->willReturn($state);
		$this->immichUserAdminService->method('listUsers')->willReturn([
			['id' => 'immich-alice', 'email' => 'alice@example.test', 'storageLabel' => 'alice'],
			['id' => 'immich-duplicate', 'email' => 'alice+duplicate@example.test', 'storageLabel' => 'alice'],
		]);
		$this->jobList->expects($this->never())->method('add');
		$this->syncStateService->expects($this->once())
			->method('updateMapping')
			->with('alice', $this->callback(function (array $fields): bool {
				$this->assertSame(SyncStateService::STATUS_FAILED, $fields['lastSyncStatus']);
				$this->assertStringContainsString('Duplicate Immich users', $fields['lastError']);
				return true;
			}));

		$result = $this->job()->reconcileOneUser('alice');

		$this->assertSame('conflict', $result['status']);
		$this->assertSame('conflict', $result['users']['alice']['status']);
		$this->assertStringContainsString('Duplicate Immich users', implode('; ', $result['conflicts']));
	}

	public function testStaleMappingBlocksAutoRepair(): void {
		$this->userManager->method('get')->with('alice')->willReturn($this->user('alice'));
		$state = $this->state('alice', 'missing-immich-user', SyncStateService::STATUS_ACTIVE, SyncStateService::STATUS_ACTIVE, 42, new DateTimeImmutable());
		$this->syncStateService->method('findByUid')->with('alice')->willReturn($state);
		$this->syncStateService->method('findByImmichUserId')->with('missing-immich-user')->willReturn($state);
		$this->syncStateService->method('findByStorageLabel')->with('alice')->willReturn($state);
		$this->immichUserAdminService->method('listUsers')->willReturn([]);
		$this->jobList->expects($this->never())->method('add');
		$this->syncStateService->expects($this->once())->method('updateMapping');

		$result = $this->job()->reconcileOneUser('alice');

		$this->assertSame('conflict', $result['status']);
		$this->assertStringContainsString('was not found in Immich', implode('; ', $result['conflicts']));
	}

	public function testListUsersExceptionIsRedacted(): void {
		$rawSecret = '0123456789abcdef0123456789abcdef';
		$this->userManager->method('search')->with('')->willReturn([$this->user('alice')]);
		$this->userManager->method('get')->with('alice')->willReturn($this->user('alice'));
		$this->immichUserAdminService->method('listUsers')->willThrowException(new \RuntimeException('Immich failed with api_key=' . $rawSecret));
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				$this->callback(function (string $message) use ($rawSecret): bool {
					$this->assertStringNotContainsString($rawSecret, $message);
					return str_contains($message, '[redacted');
				}),
				$this->callback(static fn(array $context): bool => ($context['app'] ?? '') === Application::APP_ID)
			);

		$result = $this->job()->reconcileAllScopedUsers(false);
		$encoded = json_encode($result, JSON_THROW_ON_ERROR);

		$this->assertSame('failed', $result['status']);
		$this->assertStringNotContainsString($rawSecret, $encoded);
		$this->assertStringContainsString('[redacted', $encoded);
	}

	public function testOutOfScopeMappedUserUsesDisablePolicyAndRecordsUnsupportedFailure(): void {
		$this->adminConfig = $this->config([
			AdminConfigService::KEY_USER_SCOPE_MODE => 'groups',
			AdminConfigService::KEY_USER_SCOPE_GROUPS => ['staff'],
		]);
		$this->userManager->method('get')->with('alice')->willReturn($this->user('alice'));
		$this->groupManager->method('isInGroup')->with('alice', 'staff')->willReturn(false);
		$state = $this->state('alice', 'immich-alice', SyncStateService::STATUS_ACTIVE, SyncStateService::STATUS_ACTIVE, 42, new DateTimeImmutable());
		$this->syncStateService->method('findByUid')->with('alice')->willReturn($state);
		$this->immichUserAdminService->expects($this->never())->method('deleteUser');
		$this->immichUserAdminService->expects($this->once())
			->method('disableUser')
			->with('immich-alice')
			->willThrowException(new \RuntimeException('Immich admin API does not expose a non-destructive disable/suspend field for this version.'));
		$this->syncStateService->expects($this->once())
			->method('updateMapping')
			->with('alice', $this->callback(function (array $fields): bool {
				$this->assertSame(SyncStateService::STATUS_OUT_OF_SCOPE, $fields['scopeStatus']);
				$this->assertSame(SyncStateService::STATUS_FAILED, $fields['lastSyncStatus']);
				$this->assertStringContainsString('disable/suspend is pending', $fields['lastError']);
				$this->assertStringContainsString('does not expose', $fields['lastError']);
				return true;
			}));

		$result = $this->job()->reconcileOneUser('alice');

		$this->assertSame('failed', $result['status']);
		$this->assertSame('disable_pending', $result['users']['alice']['action']);
		$this->assertStringContainsString('disable/suspend is pending', implode('; ', $result['errors']));
	}

	public function testDeleteOptInDeletesMappedMissingUserAndMarksMappingDeleted(): void {
		$this->adminConfig = $this->config([
			AdminConfigService::KEY_DELETE_DISABLE_POLICY => 'delete_opt_in',
		]);
		$this->userManager->method('get')->with('alice')->willReturn(null);
		$state = $this->state('alice', 'immich-alice', SyncStateService::STATUS_DELETED, SyncStateService::STATUS_DELETED, 42, new DateTimeImmutable());
		$this->syncStateService->method('findByUid')->with('alice')->willReturn($state);
		$this->immichUserAdminService->expects($this->never())->method('disableUser');
		$this->immichUserAdminService->expects($this->once())
			->method('deleteUser')
			->with('immich-alice')
			->willReturn(['status' => 'ok', 'token' => 'secret-delete-response']);
		$this->syncStateService->expects($this->once())
			->method('updateMapping')
			->with('alice', [
				'scopeStatus' => SyncStateService::STATUS_DELETED,
				'lastSyncStatus' => SyncStateService::STATUS_DELETED,
				'lastError' => null,
			]);

		$result = $this->job()->reconcileOneUser('alice');
		$encoded = json_encode($result, JSON_THROW_ON_ERROR);

		$this->assertSame('success', $result['status']);
		$this->assertSame('deleted', $result['users']['alice']['action']);
		$this->assertStringNotContainsString('secret-delete-response', $encoded);
	}

	public function testDisabledNextcloudUserUsesDisablePolicy(): void {
		$this->userManager->method('get')->with('alice')->willReturn($this->user('alice', '', false));
		$state = $this->state('alice', 'immich-alice', SyncStateService::STATUS_ACTIVE, SyncStateService::STATUS_ACTIVE, 42, new DateTimeImmutable());
		$this->syncStateService->method('findByUid')->with('alice')->willReturn($state);
		$this->immichUserAdminService->expects($this->once())
			->method('disableUser')
			->with('immich-alice')
			->willReturn(['id' => 'immich-alice']);
		$this->syncStateService->expects($this->once())
			->method('updateMapping')
			->with('alice', [
				'scopeStatus' => SyncStateService::STATUS_DISABLED,
				'lastSyncStatus' => SyncStateService::STATUS_DISABLED,
				'lastError' => null,
			]);

		$result = $this->job()->reconcileOneUser('alice');

		$this->assertSame('success', $result['status']);
		$this->assertSame('disabled', $result['users']['alice']['action']);
	}

	private function job(): TestableReconcileUsersJob {
		return new TestableReconcileUsersJob(
			$this->createMock(ITimeFactory::class),
			$this->adminConfigService,
			$this->provisioningService,
			$this->syncStateService,
			$this->immichUserAdminService,
			$this->pathTemplateService,
			$this->jobList,
			$this->userManager,
			$this->groupManager,
			$this->logger,
			null,
			$this->lockService,
		);
	}

	private function config(array $overrides = []): array {
		return array_merge([
			AdminConfigService::KEY_PROVISIONING_ENABLED => true,
			AdminConfigService::KEY_USER_SCOPE_MODE => 'all',
			AdminConfigService::KEY_USER_SCOPE_GROUPS => [],
			AdminConfigService::KEY_STORAGE_LABEL_TEMPLATE => '{uid}',
			AdminConfigService::KEY_EMAIL_TEMPLATE => '{uid}@immich.local',
			AdminConfigService::KEY_QUOTA_SYNC_MODE => 'disabled',
		], $overrides);
	}

	private function user(string $uid, string $email = '', bool $enabled = true): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getEMailAddress')->willReturn($email);
		if (method_exists($user, 'isEnabled')) {
			$user->method('isEnabled')->willReturn($enabled);
		}
		return $user;
	}

	private function state(string $uid, ?string $immichUserId, string $scopeStatus, string $lastSyncStatus, ?int $mountId, ?DateTimeImmutable $lastQuotaSyncAt): SyncState {
		$state = new SyncState();
		$state->setNcUid($uid);
		$state->setImmichUserId($immichUserId);
		$state->setStorageLabel($uid);
		$state->setScopeStatus($scopeStatus);
		$state->setLastSyncStatus($lastSyncStatus);
		$state->setNcMountId($mountId);
		$state->setLastQuotaSyncAt($lastQuotaSyncAt);
		return $state;
	}
}

final class TestableReconcileUsersJob extends ReconcileUsersJob {
	public function runJob(mixed $argument): array {
		$this->run($argument);
		return $this->getLastResult() ?? [];
	}
}
