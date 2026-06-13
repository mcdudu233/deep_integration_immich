<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\Service;

use OCA\IntegrationImmich\Db\SyncState;
use OCA\IntegrationImmich\Service\AdminConfigService;
use OCA\IntegrationImmich\Service\ImmichUserAdminService;
use OCA\IntegrationImmich\Service\LockService;
use OCA\IntegrationImmich\Service\PathTemplateService;
use OCA\IntegrationImmich\Service\ProvisioningService;
use OCA\IntegrationImmich\Service\QuotaSyncService;
use OCA\IntegrationImmich\Service\SyncStateService;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class ProvisioningServiceTest extends TestCase {
    private ImmichUserAdminService&MockObject $immichUserAdminService;
    private SyncStateService&MockObject $syncStateService;
    private PathTemplateService $pathTemplateService;
    private AdminConfigService&MockObject $adminConfigService;
    private IUserManager&MockObject $userManager;
    private ICrypto&MockObject $crypto;
    private QuotaSyncService&MockObject $quotaSyncService;
    private TestLockService $lockService;
    private array $adminConfig;

    protected function setUp(): void {
        parent::setUp();

        $this->immichUserAdminService = $this->createMock(ImmichUserAdminService::class);
        $this->syncStateService = $this->createMock(SyncStateService::class);
        $this->pathTemplateService = new PathTemplateService();
        $this->adminConfigService = $this->createMock(AdminConfigService::class);
		$this->adminConfig = [
			AdminConfigService::KEY_STORAGE_LABEL_TEMPLATE => '{uid}',
			AdminConfigService::KEY_EMAIL_TEMPLATE => '{uid}@immich.local',
			AdminConfigService::KEY_QUOTA_SYNC_MODE => 'manual',
		];
        $this->adminConfigService->method('getAdminConfig')->willReturnCallback(fn(): array => $this->adminConfig);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->crypto = $this->createMock(ICrypto::class);
        $this->crypto->method('encrypt')->willReturnCallback(static fn(string $value): string => 'encrypted:' . $value);
        $this->quotaSyncService = $this->createMock(QuotaSyncService::class);
        $this->lockService = new TestLockService();
    }

    public function testDryRunComputesPlanWithoutImmichCallsOrPersistence(): void {
        $this->userManager->method('get')->with('alice')->willReturn($this->user(null, 'Alice'));
        $this->syncStateService->expects($this->once())->method('findByUid')->with('alice')->willReturn(null);
        $this->syncStateService->expects($this->never())->method('getOrCreateForUid');
        $this->syncStateService->expects($this->never())->method('updateMapping');
        $this->immichUserAdminService->expects($this->never())->method('findUserForNcUid');
        $this->immichUserAdminService->expects($this->never())->method('createUser');
        $this->immichUserAdminService->expects($this->never())->method('updateUser');
        $this->quotaSyncService->expects($this->once())->method('computeQuota')->with('alice', 0)->willReturn(1234);
        $this->quotaSyncService->method('getLastError')->willReturn(null);

        $result = $this->service()->reconcileUser('alice', true);

        $this->assertSame('deep_integration_immich_provision_alice', $this->lockService->lastKey);
        $this->assertSame(60, $this->lockService->lastTimeout);
        $this->assertTrue($this->lockService->released);
        $this->assertSame('created', $result['action']);
        $this->assertNull($result['immichUserId']);
        $this->assertSame('alice', $result['storageLabel']);
        $this->assertSame(1234, $result['quotaSet']);
        $this->assertTrue($result['dryRun']);
    }

    public function testCreatesImmichUserWithFallbackEmailInitialQuotaAndMapping(): void {
        $state = $this->state('alice');
        $this->userManager->method('get')->with('alice')->willReturn($this->user(null, 'Alice Example'));
        $this->syncStateService->method('getOrCreateForUid')->with('alice')->willReturn($state);
        $this->quotaSyncService->expects($this->once())->method('computeQuota')->with('alice', 0)->willReturn(9876);
        $this->quotaSyncService->method('getLastError')->willReturn(null);
        $this->immichUserAdminService->method('findUserForNcUid')->with('alice', 'alice@immich.local', 'alice')->willReturn(null);
        $this->immichUserAdminService->expects($this->once())
            ->method('createUserWithCredentials')
            ->with($this->callback(function (array $fields): bool {
                $this->assertSame('alice@immich.local', $fields['email']);
                $this->assertSame('Alice Example', $fields['name']);
                $this->assertSame('alice', $fields['storageLabel']);
                $this->assertSame(9876, $fields['quotaSizeInBytes']);
                return true;
            }))
            ->willReturn(['id' => 'immich-alice', 'password' => 'generated-password']);
        $this->immichUserAdminService->expects($this->once())
            ->method('createUserApiKey')
            ->with('alice@immich.local', 'generated-password')
            ->willReturn('user-api-key');
        $mappingUpdates = 0;
        $this->syncStateService->expects($this->exactly(2))
            ->method('updateMapping')
            ->with('alice', $this->callback(function (array $fields) use (&$mappingUpdates): bool {
                $mappingUpdates++;
                $this->assertSame('immich-alice', $fields['immichUserId']);
                $this->assertSame('alice@immich.local', $fields['immichEmail']);
                $this->assertSame('alice@immich.local', $fields['immichUsername']);
                $this->assertSame('generated-password', $fields['immichPassword']);
                if ($mappingUpdates === 1) {
                    $this->assertArrayNotHasKey('immichApiKey', $fields);
                } else {
                    $this->assertSame('encrypted:user-api-key', $fields['immichApiKey']);
                }
                $this->assertSame('alice', $fields['storageLabel']);
                $this->assertSame(SyncStateService::STATUS_ACTIVE, $fields['scopeStatus']);
                $this->assertSame(SyncStateService::STATUS_ACTIVE, $fields['lastSyncStatus']);
                $this->assertArrayHasKey('lastQuotaSyncAt', $fields);
                return true;
            }));

        $result = $this->service()->reconcileUser('alice');

        $this->assertSame('created', $result['action']);
        $this->assertSame('immich-alice', $result['immichUserId']);
        $this->assertSame(9876, $result['quotaSet']);
		$this->assertSame([], $result['errors']);
	}

	public function testUsesConfiguredStorageLabelTemplateForNewMapping(): void {
		$this->adminConfig[AdminConfigService::KEY_STORAGE_LABEL_TEMPLATE] = 'nc-{uid}';
		$state = $this->state('alice', null, null, '');
		$this->userManager->method('get')->with('alice')->willReturn($this->user(null, 'Alice Example'));
		$this->syncStateService->method('getOrCreateForUid')->with('alice')->willReturn($state);
		$this->quotaSyncService->method('computeQuota')->willReturn(9876);
		$this->quotaSyncService->method('getLastError')->willReturn(null);
		$this->immichUserAdminService->method('findUserForNcUid')->with('alice', 'alice@immich.local', 'nc-alice')->willReturn(null);
		$this->immichUserAdminService->expects($this->once())
			->method('createUserWithCredentials')
			->with($this->callback(function (array $fields): bool {
				$this->assertSame('nc-alice', $fields['storageLabel']);
				return true;
			}))
			->willReturn(['id' => 'immich-alice', 'password' => 'generated-password']);
		$this->immichUserAdminService->method('createUserApiKey')->willReturn('user-api-key');
		$this->syncStateService->expects($this->exactly(2))
			->method('updateMapping')
			->with('alice', $this->callback(function (array $fields): bool {
				$this->assertSame('nc-alice', $fields['storageLabel']);
				return true;
			}));

		$result = $this->service()->reconcileUser('alice');

		$this->assertSame('nc-alice', $result['storageLabel']);
	}

	public function testDefaultTemplateKeepsAdminUidAsStorageLabel(): void {
		$this->userManager->method('get')->with('admin')->willReturn($this->user(null, 'Admin'));
		$this->syncStateService->expects($this->once())->method('findByUid')->with('admin')->willReturn(null);
		$this->quotaSyncService->method('computeQuota')->with('admin', 0)->willReturn(1234);
		$this->quotaSyncService->method('getLastError')->willReturn(null);

		$result = $this->service()->reconcileUser('admin', true);

		$this->assertSame('admin', $result['storageLabel']);
		$this->assertSame('admin', $result['storage_label']);
		$this->assertSame('admin', $result['nc_uid']);
		$this->assertNull($result['immich_user_id']);
	}

	public function testDefaultTemplateKeepsE2eUidAsStorageLabel(): void {
		$this->userManager->method('get')->with('immich-e2e-test')->willReturn($this->user(null, 'Immich E2E'));
		$this->syncStateService->expects($this->once())->method('findByUid')->with('immich-e2e-test')->willReturn(null);
		$this->quotaSyncService->method('computeQuota')->with('immich-e2e-test', 0)->willReturn(1234);
		$this->quotaSyncService->method('getLastError')->willReturn(null);

		$result = $this->service()->reconcileUser('immich-e2e-test', true);

		$this->assertSame('immich-e2e-test', $result['storageLabel']);
		$this->assertSame('immich-e2e-test', $result['storage_label']);
		$this->assertSame('immich-e2e-test', $result['nc_uid']);
		$this->assertNull($result['immich_user_id']);
	}

	public function testUuidLikeStoredStorageLabelRequiresExplicitRepairBeforeImmichLookup(): void {
		$uuid = '550e8400-e29b-41d4-a716-446655440000';
		$state = $this->state('alice', 'immich-alice', 'alice@example.com', $uuid);
		$this->userManager->method('get')->with('alice')->willReturn($this->user('alice@example.com', 'Alice'));
		$this->syncStateService->method('getOrCreateForUid')->with('alice')->willReturn($state);
		$this->immichUserAdminService->expects($this->never())->method('findUserForNcUid');
		$this->immichUserAdminService->expects($this->never())->method('createUser');
		$this->immichUserAdminService->expects($this->never())->method('updateUser');
		$this->syncStateService->expects($this->once())
			->method('updateMapping')
			->with('alice', $this->callback(function (array $fields): bool {
				$this->assertSame(SyncStateService::STATUS_FAILED, $fields['lastSyncStatus']);
				$this->assertStringContainsString('repair or migration is required', $fields['lastError']);
				return true;
			}));

		$result = $this->service()->reconcileUser('alice');

		$this->assertSame('skipped', $result['action']);
		$this->assertSame('alice', $result['storage_label']);
		$this->assertStringContainsString('repair or migration is required', $result['errors'][0]);
	}

	public function testNonUuidStoredStorageLabelRequiresExplicitRepairBeforeImmichLookup(): void {
		$state = $this->state('alice', 'immich-alice', 'alice@example.com', 'old-label');
		$this->userManager->method('get')->with('alice')->willReturn($this->user('alice@example.com', 'Alice'));
		$this->syncStateService->method('getOrCreateForUid')->with('alice')->willReturn($state);
		$this->immichUserAdminService->expects($this->never())->method('findUserForNcUid');
		$this->immichUserAdminService->expects($this->never())->method('createUser');
		$this->immichUserAdminService->expects($this->never())->method('updateUser');
		$this->immichUserAdminService->expects($this->never())->method('getUserQuotaUsage');
		$this->syncStateService->expects($this->once())
			->method('updateMapping')
			->with('alice', $this->callback(function (array $fields): bool {
				$this->assertSame(SyncStateService::STATUS_FAILED, $fields['lastSyncStatus']);
				$this->assertStringContainsString('expected Nextcloud UID-derived label', $fields['lastError']);
				$this->assertStringContainsString('repair or migration is required', $fields['lastError']);
				return true;
			}));

		$result = $this->service()->reconcileUser('alice');

		$this->assertSame('skipped', $result['action']);
		$this->assertSame('alice', $result['storage_label']);
		$this->assertNull($result['immich_user_id']);
		$this->assertStringContainsString('expected Nextcloud UID-derived label', $result['errors'][0]);
	}

	public function testUpdatesExistingImmichUserAndQuotaWhenSafe(): void {
        $state = $this->state('alice', 'immich-alice', 'old@example.com', 'alice');
        $this->userManager->method('get')->with('alice')->willReturn($this->user('alice@example.com', 'Alice Updated'));
        $this->syncStateService->method('getOrCreateForUid')->with('alice')->willReturn($state);
        $this->quotaSyncService->expects($this->once())->method('computeQuota')->with('alice', 200)->willReturn(900);
        $this->quotaSyncService->method('getLastError')->willReturn(null);
        $this->immichUserAdminService->method('findUserForNcUid')->willReturn([
            'id' => 'immich-alice',
            'email' => 'old@example.com',
            'name' => 'Alice Old',
            'storageLabel' => 'alice',
        ]);
        $this->immichUserAdminService->method('getUserQuotaUsage')->with('immich-alice')->willReturn(200);
        $state->setImmichApiKey('existing-user-api-key');
        $this->immichUserAdminService->expects($this->once())
            ->method('updateUser')
            ->with('immich-alice', $this->callback(function (array $fields): bool {
                $this->assertSame('alice@example.com', $fields['email']);
                $this->assertSame('Alice Updated', $fields['name']);
                $this->assertSame(900, $fields['quotaSizeInBytes']);
                $this->assertArrayNotHasKey('storageLabel', $fields);
                return true;
            }))
            ->willReturn(['id' => 'immich-alice']);
        $this->syncStateService->expects($this->once())->method('updateMapping');

        $result = $this->service()->reconcileUser('alice');

        $this->assertSame('updated', $result['action']);
        $this->assertSame(900, $result['quotaSet']);
    }

    public function testExistingImmichUserWithMissingUsageSkipsQuotaUpdate(): void {
        $state = $this->state('alice', 'immich-alice', 'old@example.com', 'alice');
        $this->userManager->method('get')->with('alice')->willReturn($this->user('alice@example.com', 'Alice Updated'));
        $this->syncStateService->method('getOrCreateForUid')->with('alice')->willReturn($state);
        $this->quotaSyncService->expects($this->once())->method('computeQuota')->with('alice', null)->willReturn(null);
        $this->quotaSyncService->method('getLastError')->willReturn('Immich quota usage is unavailable.');
        $this->immichUserAdminService->method('findUserForNcUid')->willReturn([
            'id' => 'immich-alice',
            'email' => 'old@example.com',
            'name' => 'Alice Old',
            'storageLabel' => 'alice',
        ]);
        $this->immichUserAdminService->method('getUserQuotaUsage')->with('immich-alice')->willReturn(null);
        $this->immichUserAdminService->expects($this->never())->method('updateUser');
        $this->syncStateService->expects($this->once())
            ->method('updateMapping')
            ->with('alice', $this->callback(function (array $fields): bool {
                $this->assertSame(SyncStateService::STATUS_QUOTA_FAILED, $fields['lastSyncStatus']);
                $this->assertSame('Immich quota usage is unavailable.', $fields['lastError']);
                return true;
            }));

        $result = $this->service()->reconcileUser('alice');

        $this->assertSame('skipped', $result['action']);
        $this->assertSame(['Immich quota usage is unavailable.'], $result['errors']);
    }

    public function testQuotaFailureSkipsProvisioningAndMarksState(): void {
        $state = $this->state('alice');
        $this->userManager->method('get')->with('alice')->willReturn($this->user('alice@example.com', 'Alice'));
        $this->syncStateService->method('getOrCreateForUid')->with('alice')->willReturn($state);
        $this->immichUserAdminService->method('findUserForNcUid')->with('alice', 'alice@example.com', 'alice')->willReturn(null);
        $this->quotaSyncService->method('computeQuota')->with('alice', 0)->willReturn(null);
        $this->quotaSyncService->method('getLastError')->willReturn('Nextcloud quota is unavailable.');
        $this->immichUserAdminService->expects($this->never())->method('createUser');
        $this->syncStateService->expects($this->once())
            ->method('updateMapping')
            ->with('alice', $this->callback(function (array $fields): bool {
                $this->assertSame(SyncStateService::STATUS_QUOTA_FAILED, $fields['lastSyncStatus']);
                $this->assertSame('Nextcloud quota is unavailable.', $fields['lastError']);
                return true;
            }));

        $result = $this->service()->reconcileUser('alice');

        $this->assertSame('skipped', $result['action']);
        $this->assertSame(['Nextcloud quota is unavailable.'], $result['errors']);
    }

    public function testRefusesImmichStorageLabelChangeEvenWhenNoAssetsExist(): void {
        $state = $this->state('alice', 'immich-alice', 'alice@example.com', 'alice');
        $state->setImmichApiKey('existing-user-api-key');
        $this->userManager->method('get')->with('alice')->willReturn($this->user('alice@example.com', 'Alice'));
        $this->syncStateService->method('getOrCreateForUid')->with('alice')->willReturn($state);
        $this->quotaSyncService->method('computeQuota')->willReturn(1000);
        $this->quotaSyncService->method('getLastError')->willReturn(null);
        $this->immichUserAdminService->method('findUserForNcUid')->willReturn([
            'id' => 'immich-alice',
            'email' => 'alice@example.com',
            'name' => 'Alice',
            'storageLabel' => 'old-label',
        ]);
        $this->immichUserAdminService->method('getUserQuotaUsage')->with('immich-alice')->willReturn(0);
        $this->immichUserAdminService->expects($this->never())->method('updateUser');
        $this->syncStateService->expects($this->once())
            ->method('updateMapping')
            ->with('alice', $this->callback(function (array $fields): bool {
                $this->assertSame(SyncStateService::STATUS_FAILED, $fields['lastSyncStatus']);
				$this->assertSame('Storage label mismatch requires explicit repair or migration before provisioning can continue.', $fields['lastError']);
                return true;
            }));

        $result = $this->service()->reconcileUser('alice');

        $this->assertSame('skipped', $result['action']);
		$this->assertSame(['Storage label mismatch requires explicit repair or migration before provisioning can continue.'], $result['errors']);
    }

    public function testExistingMappingIsIdempotentWhenNoSafeUpdatesAreNeeded(): void {
        $this->adminConfig[AdminConfigService::KEY_QUOTA_SYNC_MODE] = 'disabled';
        $state = $this->state('alice', 'immich-alice', 'alice@example.com', 'alice');
        $this->userManager->method('get')->with('alice')->willReturn($this->user('alice@example.com', 'Alice'));
        $this->syncStateService->method('getOrCreateForUid')->with('alice')->willReturn($state);
        $this->immichUserAdminService->method('findUserForNcUid')->with('alice', 'alice@example.com', 'alice')->willReturn([
            'id' => 'immich-alice',
            'email' => 'alice@example.com',
            'name' => 'Alice',
            'storageLabel' => 'alice',
        ]);
        $this->immichUserAdminService->method('getUserQuotaUsage')->with('immich-alice')->willReturn(0);
        $this->quotaSyncService->expects($this->never())->method('computeQuota');
        $this->immichUserAdminService->expects($this->never())->method('updateUser');
        $this->syncStateService->expects($this->once())
            ->method('updateMapping')
            ->with('alice', $this->callback(function (array $fields): bool {
                $this->assertSame('immich-alice', $fields['immichUserId']);
                $this->assertSame(SyncStateService::STATUS_ACTIVE, $fields['lastSyncStatus']);
                $this->assertArrayNotHasKey('lastQuotaSyncAt', $fields);
                return true;
            }));

        $result = $this->service()->reconcileUser('alice');

        $this->assertSame('unchanged', $result['action']);
        $this->assertNull($result['quotaSet']);
        $this->assertSame([], $result['errors']);
    }

    public function testDuplicateImmichConflictIsPersistedAsFailedMapping(): void {
        $state = $this->state('alice');
        $this->userManager->method('get')->with('alice')->willReturn($this->user('alice@example.com', 'Alice'));
        $this->syncStateService->method('getOrCreateForUid')->with('alice')->willReturn($state);
        $this->immichUserAdminService->method('findUserForNcUid')
            ->willThrowException(new \RuntimeException('Duplicate Immich users match Nextcloud user "alice" by email/storage label.'));
        $this->immichUserAdminService->expects($this->never())->method('createUser');
        $this->syncStateService->expects($this->once())
            ->method('updateMapping')
            ->with('alice', $this->callback(function (array $fields): bool {
                $this->assertSame(SyncStateService::STATUS_FAILED, $fields['lastSyncStatus']);
                $this->assertStringContainsString('Duplicate Immich users', $fields['lastError']);
                return true;
            }));

        $result = $this->service()->reconcileUser('alice');

        $this->assertSame('skipped', $result['action']);
        $this->assertStringContainsString('Duplicate Immich users', $result['errors'][0]);
    }

    public function testLockIsReleasedWhenProvisioningThrows(): void {
        $this->userManager->method('get')->with('alice')->willReturn($this->user('alice@example.com', 'Alice'));
        $this->syncStateService->method('getOrCreateForUid')->willThrowException(new \RuntimeException('mapping failed'));

        $result = $this->service()->reconcileUser('alice');

        $this->assertTrue($this->lockService->released);
        $this->assertSame('skipped', $result['action']);
        $this->assertSame(['mapping failed'], $result['errors']);
    }

    private function service(): ProvisioningService {
        return new ProvisioningService(
            $this->immichUserAdminService,
            $this->syncStateService,
            $this->pathTemplateService,
            $this->adminConfigService,
            $this->userManager,
            $this->crypto,
            $this->createMock(LoggerInterface::class),
            new \stdClass(),
            $this->quotaSyncService,
            $this->lockService,
        );
    }

    private function user(?string $email, string $displayName): IUser&MockObject {
        $user = $this->createMock(IUser::class);
        $user->method('getEMailAddress')->willReturn($email);
        $user->method('getDisplayName')->willReturn($displayName);
        return $user;
    }

    private function state(string $uid, ?string $immichUserId = null, ?string $email = null, string $storageLabel = 'alice'): SyncState {
        $state = new SyncState();
        $state->setNcUid($uid);
        $state->setImmichUserId($immichUserId);
        $state->setImmichEmail($email);
        $state->setStorageLabel($storageLabel);
        return $state;
    }
}

final class TestLockService extends LockService {
    public ?string $lastKey = null;
    public ?int $lastTimeout = null;
    public bool $released = false;

    public function __construct() {
    }

    public function withLock(string $key, int $timeoutSeconds, callable $callback): mixed {
        $this->lastKey = $key;
        $this->lastTimeout = $timeoutSeconds;
        $this->released = false;

        try {
            return $callback();
        } finally {
            $this->released = true;
        }
    }
}
