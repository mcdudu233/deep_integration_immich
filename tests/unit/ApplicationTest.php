<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit;

use OCA\IntegrationImmich\AppInfo\Application;
use OCA\IntegrationImmich\BackgroundJob\ReconcileUsersJob;
use OCA\IntegrationImmich\BackgroundJob\ScheduleQuotaSyncJob;
use OCA\IntegrationImmich\BackgroundJob\VerifyProvisioningJob;
use OCA\IntegrationImmich\Service\AdminConfigService;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class ApplicationTest extends TestCase {

	public function testAppId(): void {
		$this->assertEquals('deep_integration_immich', Application::APP_ID);
	}

	public function testAppCanBeInstantiated(): void {
		$app = new Application();
		$this->assertInstanceOf(Application::class, $app);
	}

    public function testRegisterTimedJobsAddsSchedulerButNotPerUserQuotaJobs(): void {
        $app = new Application();
        $jobList = $this->createMock(IJobList::class);
        $adminConfigService = $this->createMock(AdminConfigService::class);
        $adminConfigService->method('getAdminConfig')->willReturn([
            AdminConfigService::KEY_PROVISIONING_ENABLED => true,
            AdminConfigService::KEY_QUOTA_SYNC_MODE => 'event_scheduled',
        ]);
        $queued = [];
        $jobList->method('has')->willReturn(false);
        $jobList->expects($this->exactly(3))
            ->method('add')
            ->willReturnCallback(function (string $jobClass, mixed $argument) use (&$queued): void {
                $queued[] = [$jobClass, $argument];
            });

        $app->registerTimedJobs($jobList, $adminConfigService);

        $this->assertSame([
            [ReconcileUsersJob::class, null],
            [VerifyProvisioningJob::class, null],
            [ScheduleQuotaSyncJob::class, null],
        ], $queued);
    }
}
