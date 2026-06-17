<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\BackgroundJob;

use DateTimeInterface;
use OCA\IntegrationImmich\BackgroundJob\SyncQuotaJob;
use OCA\IntegrationImmich\Db\SyncState;
use OCA\IntegrationImmich\Service\AdminConfigService;
use OCA\IntegrationImmich\Service\ImmichUserAdminService;
use OCA\IntegrationImmich\Service\QuotaSyncService;
use OCA\IntegrationImmich\Service\SyncStateService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class SyncQuotaJobTest extends TestCase {
    private AdminConfigService&MockObject $adminConfigService;
    private SyncStateService&MockObject $syncStateService;
    private ImmichUserAdminService&MockObject $immichUserAdminService;
    private QuotaSyncService&MockObject $quotaSyncService;
    private IUserManager&MockObject $userManager;
    private IRootFolder&MockObject $rootFolder;
    private LoggerInterface&MockObject $logger;
    private array $adminConfig;

    protected function setUp(): void {
        parent::setUp();

        $this->adminConfig = [
            AdminConfigService::KEY_QUOTA_SYNC_MODE => 'manual',
            AdminConfigService::KEY_QUOTA_RESERVE_BYTES => 100,
        ];
        $this->adminConfigService = $this->createMock(AdminConfigService::class);
        $this->adminConfigService->method('getAdminConfig')->willReturnCallback(fn(): array => $this->adminConfig);
        $this->syncStateService = $this->createMock(SyncStateService::class);
        $this->immichUserAdminService = $this->createMock(ImmichUserAdminService::class);
        $this->quotaSyncService = $this->createMock(QuotaSyncService::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->rootFolder = $this->createMock(IRootFolder::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testFiniteQuotaUpdatesChangedImmichQuotaAndReturnsAccountingResult(): void {
        $this->syncStateService->method('findByUid')->with('alice')->willReturn($this->state('alice', 'immich-alice'));
        $this->mockNextcloudAccounting('1000', 700);
        $this->immichUserAdminService->method('getUserQuotaState')->with('immich-alice')->willReturn($this->quotaState(200, 300));
        $this->quotaSyncService->expects($this->once())->method('computeQuotaDetails')->with('alice', 200)->willReturn($this->quotaDetails(1000, 700, 200, 500, 100, 400));
        $this->quotaSyncService->method('getLastError')->willReturn(null);
        $this->quotaSyncService->method('wasLastQuotaUnlimited')->willReturn(false);
        $this->immichUserAdminService->expects($this->once())
            ->method('updateUserQuota')
            ->with('immich-alice', 400);
        $this->syncStateService->expects($this->once())
            ->method('updateMapping')
            ->with('alice', $this->callback(function (array $fields): bool {
                $this->assertSame(SyncStateService::STATUS_ACTIVE, $fields['lastSyncStatus']);
                $this->assertNull($fields['lastError']);
                $this->assertInstanceOf(DateTimeInterface::class, $fields['lastQuotaSyncAt']);
                return true;
            }));

        $result = $this->job()->syncForUser('alice');

        $this->assertSame(SyncStateService::STATUS_ACTIVE, $result['status']);
        $this->assertSame('updated', $result['action']);
        $this->assertTrue($result['updated']);
        $this->assertSame(1000, $result['nc_quota']);
        $this->assertSame(700, $result['nc_used']);
        $this->assertSame(200, $result['immich_usage']);
        $this->assertSame(500, $result['non_immich_used']);
        $this->assertSame(100, $result['reserve']);
        $this->assertSame(400, $result['computed_immich_quota']);
        $this->assertNotEmpty($result['last_sync_at']);
    }

    public function testUnlimitedNextcloudQuotaMapsToImmichUnlimitedWithoutZeroQuota(): void {
        $this->syncStateService->method('findByUid')->with('alice')->willReturn($this->state('alice', 'immich-alice'));
        $this->mockNextcloudAccounting('none', 700);
        $this->immichUserAdminService->method('getUserQuotaState')->with('immich-alice')->willReturn($this->quotaState(200, 900));
        $this->quotaSyncService->method('computeQuotaDetails')->with('alice', 200)->willReturn($this->quotaDetails(null, 700, 200, 500, 100, null, true));
        $this->quotaSyncService->method('getLastError')->willReturn(null);
        $this->quotaSyncService->method('wasLastQuotaUnlimited')->willReturn(true);
        $this->immichUserAdminService->expects($this->once())
            ->method('updateUserQuota')
            ->with('immich-alice', null);
        $this->syncStateService->expects($this->once())->method('updateMapping');

        $result = $this->job()->syncForUser('alice');

        $this->assertSame(SyncStateService::STATUS_ACTIVE, $result['status']);
        $this->assertSame('updated', $result['action']);
        $this->assertNull($result['nc_quota']);
        $this->assertNull($result['computed_immich_quota']);
        $this->assertNotSame(0, $result['computed_immich_quota']);
    }

    public function testInvalidQuotaMarksQuotaFailedAndLeavesImmichQuotaUnchanged(): void {
        $this->syncStateService->method('findByUid')->with('alice')->willReturn($this->state('alice', 'immich-alice'));
        $this->mockNextcloudAccounting('default', 700);
        $this->immichUserAdminService->method('getUserQuotaState')->with('immich-alice')->willReturn($this->quotaState(200, null));
        $this->quotaSyncService->method('computeQuotaDetails')->with('alice', 200)->willReturn($this->quotaDetails(null, 700, 200, 500, 100, null, false, 'Nextcloud quota must be a finite byte value or unlimited.'));
        $this->quotaSyncService->method('getLastError')->willReturn('Nextcloud quota must be a finite byte value or unlimited.');
        $this->immichUserAdminService->expects($this->never())->method('updateUser');
		$this->immichUserAdminService->expects($this->never())->method('updateUserQuota');
        $this->syncStateService->expects($this->once())
            ->method('updateMapping')
            ->with('alice', $this->callback(function (array $fields): bool {
                $this->assertSame(SyncStateService::STATUS_QUOTA_FAILED, $fields['lastSyncStatus']);
                $this->assertSame('Nextcloud quota must be a finite byte value or unlimited.', $fields['lastError']);
                return true;
            }));

        $result = $this->job()->syncForUser('alice');

        $this->assertSame(SyncStateService::STATUS_QUOTA_FAILED, $result['status']);
        $this->assertSame('skipped', $result['action']);
        $this->assertFalse($result['updated']);
        $this->assertNull($result['computed_immich_quota']);
        $this->assertSame('Nextcloud quota must be a finite byte value or unlimited.', $result['error']);
    }

    public function testOverQuotaComputedLimitIsAllowedWhenItEqualsCurrentImmichUsage(): void {
        $this->syncStateService->method('findByUid')->with('alice')->willReturn($this->state('alice', 'immich-alice'));
        $this->mockNextcloudAccounting('1000', 1200);
        $this->immichUserAdminService->method('getUserQuotaState')->with('immich-alice')->willReturn($this->quotaState(500, 800));
        $this->quotaSyncService->method('computeQuotaDetails')->with('alice', 500)->willReturn($this->quotaDetails(1000, 1200, 500, 700, 100, 500));
        $this->quotaSyncService->method('getLastError')->willReturn(null);
        $this->quotaSyncService->method('wasLastQuotaUnlimited')->willReturn(false);
        $this->immichUserAdminService->expects($this->once())
            ->method('updateUserQuota')
            ->with('immich-alice', 500);
        $this->syncStateService->expects($this->once())->method('updateMapping');

        $result = $this->job()->syncForUser('alice');

        $this->assertSame(500, $result['immich_usage']);
        $this->assertSame(700, $result['non_immich_used']);
        $this->assertSame(500, $result['computed_immich_quota']);
        $this->assertGreaterThanOrEqual($result['immich_usage'], $result['computed_immich_quota']);
    }

    public function testUnchangedQuotaDoesNotCallImmichUpdateButRecordsSyncSuccess(): void {
        $this->syncStateService->method('findByUid')->with('alice')->willReturn($this->state('alice', 'immich-alice'));
        $this->mockNextcloudAccounting('1000', 700);
        $this->immichUserAdminService->method('getUserQuotaState')->with('immich-alice')->willReturn($this->quotaState(200, 400));
        $this->quotaSyncService->method('computeQuotaDetails')->with('alice', 200)->willReturn($this->quotaDetails(1000, 700, 200, 500, 100, 400));
        $this->quotaSyncService->method('getLastError')->willReturn(null);
        $this->quotaSyncService->method('wasLastQuotaUnlimited')->willReturn(false);
        $this->immichUserAdminService->expects($this->never())->method('updateUser');
		$this->immichUserAdminService->expects($this->never())->method('updateUserQuota');
        $this->syncStateService->expects($this->once())->method('updateMapping');

        $result = $this->job()->syncForUser('alice');

        $this->assertSame('unchanged', $result['action']);
        $this->assertFalse($result['updated']);
        $this->assertSame(400, $result['computed_immich_quota']);
    }

    public function testRefusesComputedQuotaBelowCurrentImmichUsage(): void {
        $this->syncStateService->method('findByUid')->with('alice')->willReturn($this->state('alice', 'immich-alice'));
        $this->mockNextcloudAccounting('1000', 700);
        $this->immichUserAdminService->method('getUserQuotaState')->with('immich-alice')->willReturn($this->quotaState(200, null));
        $this->quotaSyncService->method('computeQuotaDetails')->with('alice', 200)->willReturn($this->quotaDetails(1000, 700, 200, 500, 100, 199));
        $this->quotaSyncService->method('getLastError')->willReturn(null);
        $this->quotaSyncService->method('wasLastQuotaUnlimited')->willReturn(false);
        $this->immichUserAdminService->expects($this->never())->method('updateUser');
		$this->immichUserAdminService->expects($this->never())->method('updateUserQuota');
        $this->syncStateService->expects($this->once())
            ->method('updateMapping')
            ->with('alice', $this->callback(function (array $fields): bool {
                $this->assertSame(SyncStateService::STATUS_QUOTA_FAILED, $fields['lastSyncStatus']);
                $this->assertSame('Computed Immich quota is below current Immich usage.', $fields['lastError']);
                return true;
            }));

        $result = $this->job()->syncForUser('alice');

        $this->assertSame(SyncStateService::STATUS_QUOTA_FAILED, $result['status']);
        $this->assertSame('Computed Immich quota is below current Immich usage.', $result['error']);
    }

    public function testMissingMappingSkipsQuotaSyncBeforeImmichCalls(): void {
        $this->syncStateService->method('findByUid')->with('alice')->willReturn(null);
        $this->immichUserAdminService->expects($this->never())->method('getUserQuotaState');
        $this->quotaSyncService->expects($this->never())->method('computeQuotaDetails');
        $this->immichUserAdminService->expects($this->never())->method('updateUser');
		$this->immichUserAdminService->expects($this->never())->method('updateUserQuota');
        $this->syncStateService->expects($this->never())->method('updateMapping');

        $result = $this->job()->syncForUser('alice');

        $this->assertSame(SyncStateService::STATUS_QUOTA_FAILED, $result['status']);
        $this->assertStringContainsString('No sync state mapping exists', $result['error']);
    }

    public function testDisabledQuotaModeSkipsWithoutMappingOrImmichCalls(): void {
        $this->adminConfig[AdminConfigService::KEY_QUOTA_SYNC_MODE] = 'disabled';
        $this->syncStateService->expects($this->never())->method('findByUid');
        $this->immichUserAdminService->expects($this->never())->method('getUserQuotaState');
        $this->quotaSyncService->expects($this->never())->method('computeQuotaDetails');

        $result = $this->job()->syncForUser('alice');

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('disabled', $result['action']);
    }

    public function testExceptionsAreRedactedAndPersistedAsQuotaFailure(): void {
        $this->syncStateService->method('findByUid')->with('alice')->willReturn($this->state('alice', 'immich-alice'));
        $this->immichUserAdminService->method('getUserQuotaState')->willThrowException(new \RuntimeException('Immich failed with api_key=super-secret'));
        $this->syncStateService->expects($this->once())
            ->method('updateMapping')
            ->with('alice', $this->callback(function (array $fields): bool {
                $this->assertSame(SyncStateService::STATUS_QUOTA_FAILED, $fields['lastSyncStatus']);
                $this->assertSame('Immich failed with api_key=[redacted]', $fields['lastError']);
                return true;
            }));

        $result = $this->job()->syncForUser('alice');

        $this->assertSame(SyncStateService::STATUS_QUOTA_FAILED, $result['status']);
        $this->assertSame('Immich failed with api_key=[redacted]', $result['error']);
        $this->assertStringNotContainsString('super-secret', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testRunStoresStructuredResultForNcUidArgument(): void {
        $this->adminConfig[AdminConfigService::KEY_QUOTA_SYNC_MODE] = 'disabled';
        $job = $this->job();

        $job->runForTest(['ncUid' => 'alice']);

        $this->assertSame('alice', $job->getLastResult()['ncUid']);
        $this->assertSame('disabled', $job->getLastResult()['action']);
    }

    private function job(): TestableSyncQuotaJob {
        return new TestableSyncQuotaJob(
            $this->createMock(ITimeFactory::class),
            $this->adminConfigService,
            $this->syncStateService,
            $this->immichUserAdminService,
            $this->quotaSyncService,
            $this->userManager,
            $this->rootFolder,
            $this->logger,
        );
    }

    private function mockNextcloudAccounting(int|string $quota, int $used): void {
        $this->userManager->method('get')->with('alice')->willReturn($this->userWithQuota($quota));
        $folder = $this->createMock(Folder::class);
        $folder->method('getSize')->willReturn($used);
        $this->rootFolder->method('getUserFolder')->with('alice')->willReturn($folder);
    }

    private function userWithQuota(int|string $quota): IUser&MockObject {
        $user = $this->createMock(IUser::class);
        $user->method('getQuota')->willReturn($quota);
        return $user;
    }

    private function quotaDetails(?int $ncQuota, ?int $ncUsed, int $immichUsage, ?int $nonImmichUsed, int $reserve, ?int $computedImmichQuota, bool $unlimited = false, ?string $error = null): array {
        return [
            'ncQuota' => $ncQuota,
            'ncUsed' => $ncUsed,
            'ncRemaining' => $ncQuota === null || $ncUsed === null ? null : max(0, $ncQuota - max($ncUsed, $immichUsage)),
            'immichUsage' => $immichUsage,
            'immichAvailable' => $computedImmichQuota === null ? null : max(0, $computedImmichQuota - $immichUsage),
            'nonImmichUsed' => $nonImmichUsed,
            'reserve' => $reserve,
            'computedImmichQuota' => $computedImmichQuota,
            'unlimited' => $unlimited,
            'error' => $error,
        ];
    }

    private function quotaState(?int $usage, ?int $quota): array {
        return [
            'found' => true,
            'quotaUsageInBytes' => $usage,
            'quotaSizeInBytes' => $quota,
        ];
    }

    private function state(string $ncUid, ?string $immichUserId): SyncState {
        $state = new SyncState();
        $state->setNcUid($ncUid);
        $state->setImmichUserId($immichUserId);
        $state->setStorageLabel($ncUid);
        return $state;
    }
}

class TestableSyncQuotaJob extends SyncQuotaJob {
    public function runForTest(mixed $argument): void {
        $this->run($argument);
    }
}
