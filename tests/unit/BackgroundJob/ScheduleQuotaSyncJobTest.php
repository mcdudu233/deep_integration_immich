<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\BackgroundJob;

use OCA\IntegrationImmich\BackgroundJob\ScheduleQuotaSyncJob;
use OCA\IntegrationImmich\BackgroundJob\SyncQuotaJob;
use OCA\IntegrationImmich\Db\SyncState;
use OCA\IntegrationImmich\Service\AdminConfigService;
use OCA\IntegrationImmich\Service\SyncStateService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class ScheduleQuotaSyncJobTest extends TestCase {
    private AdminConfigService&MockObject $adminConfigService;
    private SyncStateService&MockObject $syncStateService;
    private IJobList&MockObject $jobList;
    private array $adminConfig;

    protected function setUp(): void {
        parent::setUp();

        $this->adminConfig = [
            AdminConfigService::KEY_PROVISIONING_ENABLED => true,
            AdminConfigService::KEY_QUOTA_SYNC_MODE => 'event_scheduled',
        ];
        $this->adminConfigService = $this->createMock(AdminConfigService::class);
        $this->adminConfigService->method('getAdminConfig')->willReturnCallback(fn(): array => $this->adminConfig);
        $this->adminConfigService->method('getQuotaSyncIntervalSeconds')->willReturn(900);
        $this->syncStateService = $this->createMock(SyncStateService::class);
        $this->jobList = $this->createMock(IJobList::class);
    }

    public function testSchedulesMappedUsersAndSkipsDuplicateJobs(): void {
        $pages = [];
        $this->syncStateService->expects($this->exactly(2))
            ->method('listMappedStates')
            ->willReturnCallback(function (int $limit, int $offset) use (&$pages): array {
                $pages[] = [$limit, $offset];
                if ($offset !== 0) {
                    return [];
                }

                return [
                $this->state('alice', 'immich-alice'),
                $this->state('bob', 'immich-bob'),
                $this->state('unmapped', null),
                ];
            });
        $this->jobList->method('has')
            ->willReturnCallback(static fn(string $class, array $argument): bool => $class === SyncQuotaJob::class && ($argument['ncUid'] ?? '') === 'bob');
        $this->jobList->expects($this->once())
            ->method('add')
            ->with(SyncQuotaJob::class, ['ncUid' => 'alice']);

        $result = $this->job()->scheduleMappedUsers();

        $this->assertSame('queued', $result['status']);
        $this->assertSame(['alice'], $result['queued']);
        $this->assertSame(['bob'], $result['skipped']);
        $this->assertSame([[500, 0], [500, 500]], $pages);
    }

    public function testSkipsWhenProvisioningOrScheduledModeDisabled(): void {
        $this->adminConfig[AdminConfigService::KEY_QUOTA_SYNC_MODE] = 'manual';
        $this->syncStateService->expects($this->never())->method('listMappedStates');
        $this->jobList->expects($this->never())->method('add');

        $result = $this->job()->scheduleMappedUsers();

        $this->assertSame('skipped', $result['status']);
        $this->assertSame([], $result['queued']);
    }

    public function testRunStoresLastResult(): void {
        $this->adminConfig[AdminConfigService::KEY_PROVISIONING_ENABLED] = false;
        $job = $this->job();

        $job->runForTest(null);

        $this->assertSame('schedule_quota_sync', $job->getLastResultForTest()['job']);
        $this->assertSame('skipped', $job->getLastResultForTest()['status']);
    }

    private function job(): TestableScheduleQuotaSyncJob {
        return new TestableScheduleQuotaSyncJob(
            $this->createMock(ITimeFactory::class),
            $this->adminConfigService,
            $this->syncStateService,
            $this->jobList,
            $this->createMock(LoggerInterface::class),
        );
    }

    private function state(string $ncUid, ?string $immichUserId): SyncState {
        $state = new SyncState();
        $state->setNcUid($ncUid);
        $state->setImmichUserId($immichUserId);
        return $state;
    }
}

class TestableScheduleQuotaSyncJob extends ScheduleQuotaSyncJob {
    public function runForTest(mixed $argument): void {
        $this->run($argument);
    }

    public function getLastResultForTest(): ?array {
        return $this->lastResult;
    }
}
