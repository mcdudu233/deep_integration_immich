<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\Controller;

use DateTimeImmutable;
use OCA\IntegrationImmich\BackgroundJob\ReconcileUsersJob;
use OCA\IntegrationImmich\BackgroundJob\SyncQuotaJob;
use OCA\IntegrationImmich\BackgroundJob\VerifyProvisioningJob;
use OCA\IntegrationImmich\Controller\AdminProvisioningController;
use OCA\IntegrationImmich\Db\SyncState;
use OCA\IntegrationImmich\Service\ExternalStorageProvisioner;
use OCA\IntegrationImmich\Service\ImmichUserAdminService;
use OCA\IntegrationImmich\Service\ProvisioningService;
use OCA\IntegrationImmich\Service\QuotaSyncService;
use OCA\IntegrationImmich\Service\SyncStateService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Test\TestCase;

class AdminProvisioningControllerTest extends TestCase {
    private AdminProvisioningController $controller;
	private ProvisioningService&MockObject $provisioningService;
	private SyncStateService&MockObject $syncStateService;
	private IJobList&MockObject $jobList;
	private ReconcileUsersJob&MockObject $reconcileUsersJob;
	private SyncQuotaJob&MockObject $syncQuotaJob;
	private VerifyProvisioningJob&MockObject $verifyProvisioningJob;
    private ExternalStorageProvisioner&MockObject $externalStorageProvisioner;
    private QuotaSyncService&MockObject $quotaSyncService;
    private ImmichUserAdminService&MockObject $immichUserAdminService;
    private IRequest&MockObject $request;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void {
        parent::setUp();

        $this->provisioningService = $this->createMock(ProvisioningService::class);
		$this->syncStateService = $this->createMock(SyncStateService::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->reconcileUsersJob = $this->createMock(ReconcileUsersJob::class);
		$this->syncQuotaJob = $this->createMock(SyncQuotaJob::class);
		$this->verifyProvisioningJob = $this->createMock(VerifyProvisioningJob::class);
        $this->externalStorageProvisioner = $this->createMock(ExternalStorageProvisioner::class);
        $this->quotaSyncService = $this->createMock(QuotaSyncService::class);
        $this->immichUserAdminService = $this->createMock(ImmichUserAdminService::class);
        $this->request = $this->createMock(IRequest::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new AdminProvisioningController(
            $this->request,
			$this->provisioningService,
			$this->syncStateService,
			$this->jobList,
			$this->reconcileUsersJob,
			$this->syncQuotaJob,
			$this->verifyProvisioningJob,
			$this->externalStorageProvisioner,
			$this->quotaSyncService,
			$this->immichUserAdminService,
			$this->logger,
		);
    }

    public function testDryRunReturnsProvisioningPlanWithoutQueueing(): void {
        $plan = ['ncUid' => 'alice', 'action' => 'created', 'dryRun' => true, 'errors' => []];
        $this->provisioningService->expects($this->once())
            ->method('reconcileUser')
            ->with('alice', true)
            ->willReturn($plan);
        $this->jobList->expects($this->never())->method('add');

        $response = $this->controller->dryRun(' alice ');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertTrue($response->getData()['success']);
		$this->assertSame($plan, $response->getData()['plan']);
	}

	public function testDryRunAllReturnsScopedPlanWithoutQueueing(): void {
		$plan = ['job' => 'reconcile_users', 'mode' => 'all', 'dryRun' => true, 'users' => []];
		$this->reconcileUsersJob->expects($this->once())
			->method('reconcileAllScopedUsers')
			->with(true)
			->willReturn($plan);
		$this->jobList->expects($this->never())->method('add');

		$response = $this->controller->dryRunAll();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
		$this->assertSame($plan, $response->getData()['plan']);
	}

	public function testReconcileOneQueuesReconcileJobForUser(): void {
        $this->jobList->expects($this->once())
            ->method('add')
            ->with(ReconcileUsersJob::class, ['ncUid' => 'alice']);

        $response = $this->controller->reconcileOne('alice');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(1, $response->getData()['count']);
        $this->assertSame(ReconcileUsersJob::class, $response->getData()['queued'][0]['job']);
    }

    public function testReconcileAllQueuesAggregateReconcileJob(): void {
        $this->jobList->expects($this->once())
            ->method('add')
            ->with(ReconcileUsersJob::class, null);

        $response = $this->controller->reconcileAll();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('all_scoped_users', $response->getData()['scope']);
    }

	public function testRecomputeQuotaOneSyncsQuotaForUser(): void {
		$this->syncQuotaJob->expects($this->once())
			->method('syncForUser')
			->with('alice')
			->willReturn(['ncUid' => 'alice', 'status' => 'active', 'action' => 'updated']);

		$response = $this->controller->recomputeQuotaOne('alice');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('updated', $response->getData()['results'][0]['action']);
	}

	public function testRecomputeQuotaAllQueuesQuotaJobsForMappedUsers(): void {
		$this->syncStateService->expects($this->once())
			->method('listMappedStates')
            ->with(500, 0)
            ->willReturn([
                $this->state('alice', 'immich-alice'),
                $this->state('bob', 'immich-bob'),
		]);
		$this->syncQuotaJob->expects($this->never())->method('syncForUser');
		$queued = [];
		$this->jobList->expects($this->exactly(2))
			->method('add')
			->willReturnCallback(function (string $jobClass, array $argument) use (&$queued): void {
				$queued[] = [$jobClass, $argument];
			});

        $response = $this->controller->recomputeQuotaAll();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(2, $response->getData()['count']);
		$this->assertSame('mapped_users', $response->getData()['scope']);
		$this->assertSame([
			[SyncQuotaJob::class, ['ncUid' => 'alice']],
			[SyncQuotaJob::class, ['ncUid' => 'bob']],
		], $queued);
		$this->assertSame(SyncQuotaJob::class, $response->getData()['queued'][0]['job']);
		$this->assertSame(['ncUid' => 'alice'], $response->getData()['queued'][0]['argument']);
	}

    public function testListSyncStateReturnsPaginatedStates(): void {
        $this->request->method('getParam')->willReturnMap([
            ['limit', 50, '2'],
            ['offset', 0, '0'],
        ]);
        $this->syncStateService->expects($this->once())
            ->method('listStates')
            ->with(3, 0)
            ->willReturn([
                $this->state('alice', 'immich-alice'),
                $this->state('bob', 'immich-bob'),
                $this->state('carol', 'immich-carol'),
            ]);
        $this->externalStorageProvisioner->method('verifyMount')->willReturnCallback(fn(string $uid): array => [
            'status' => 'ok',
            'mount_id' => 42,
            'mount_name' => '/Immich Photos',
            'read_only' => true,
		]);
		$this->immichUserAdminService->expects($this->never())->method('getUserQuotaUsage');
		$this->quotaSyncService->expects($this->never())->method('computeQuotaDetails');
		$this->quotaSyncService->method('computeNextcloudQuotaSnapshot')->willReturnCallback(fn(string $uid): array => [
			'ncQuota' => 1000,
			'ncUsed' => $uid === 'alice' ? 700 : 500,
			'ncRemaining' => $uid === 'alice' ? 300 : 500,
			'reserve' => 100,
			'unlimited' => false,
			'error' => null,
		]);

        $response = $this->controller->listSyncState();
        $data = $response->getData();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertCount(2, $data['sync_state']);
        $this->assertSame('alice', $data['sync_state'][0]['ncUid']);
        $this->assertSame('ok', $data['sync_state'][0]['mount']['status']);
		$this->assertSame('stale', $data['sync_state'][0]['quota']['status']);
		$this->assertSame(300, $data['sync_state'][0]['quota']['ncRemaining']);
        $this->assertTrue($data['pagination']['has_more']);
        $this->assertSame(2, $data['pagination']['limit']);
    }

    public function testListSyncStateRejectsInvalidPaginationBeforeMapperCall(): void {
        $this->request->method('getParam')->willReturnMap([
            ['limit', 50, '0'],
            ['offset', 0, '0'],
        ]);
        $this->syncStateService->expects($this->never())->method('listStates');

        $response = $this->controller->listSyncState();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('invalid_pagination', $response->getData()['error']['code']);
    }

    public function testVerifyHealthReturnsJobHealthReport(): void {
        $health = ['job' => 'verify_provisioning', 'status' => 'ok', 'users' => ['alice' => ['status' => 'ok']]];
        $this->verifyProvisioningJob->expects($this->once())
            ->method('verifyOneUser')
            ->with('alice')
            ->willReturn($health);

        $response = $this->controller->verifyHealth('alice');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($health, $response->getData()['health']);
    }

    public function testServiceErrorsAreStructuredAndRedacted(): void {
        $this->provisioningService->method('reconcileUser')
            ->willThrowException(new \RuntimeException('failed with api_key=secret-admin-key'));

        $response = $this->controller->dryRun('alice');
        $encoded = json_encode($response->getData(), JSON_THROW_ON_ERROR);

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        $this->assertSame('dry_run_failed', $response->getData()['error']['code']);
        $this->assertStringNotContainsString('secret-admin-key', $encoded);
        $this->assertStringContainsString('api_key=[redacted]', $encoded);
    }

    public function testAdminMethodsRequireAdminAndMutatingMethodsKeepCsrf(): void {
        $reflection = new ReflectionClass(AdminProvisioningController::class);
		$methods = [
			'dryRun',
			'dryRunAll',
			'reconcileOne',
            'reconcileAll',
            'recomputeQuotaOne',
            'recomputeQuotaAll',
            'listSyncState',
            'verifyHealth',
        ];
        foreach ($methods as $methodName) {
            $this->assertNotEmpty($reflection->getMethod($methodName)->getAttributes(AdminRequired::class), $methodName . ' must require admin.');
        }

		$this->assertNotEmpty($reflection->getMethod('dryRun')->getAttributes(NoCSRFRequired::class));
		$this->assertNotEmpty($reflection->getMethod('dryRunAll')->getAttributes(NoCSRFRequired::class));
		$this->assertNotEmpty($reflection->getMethod('listSyncState')->getAttributes(NoCSRFRequired::class));
        foreach (['reconcileOne', 'reconcileAll', 'recomputeQuotaOne', 'recomputeQuotaAll', 'verifyHealth'] as $methodName) {
            $this->assertSame([], $reflection->getMethod($methodName)->getAttributes(NoCSRFRequired::class));
        }
    }

    private function state(string $uid, string $immichUserId): SyncState {
        $state = new SyncState();
        $state->setNcUid($uid);
        $state->setImmichUserId($immichUserId);
        $state->setImmichEmail($uid . '@example.test');
        $state->setStorageLabel($uid);
        $state->setScopeStatus(SyncStateService::STATUS_ACTIVE);
        $state->setLastSyncStatus(SyncStateService::STATUS_ACTIVE);
        $state->setCreatedAt(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $state->setUpdatedAt(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        return $state;
    }
}
