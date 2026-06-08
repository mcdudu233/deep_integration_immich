<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\BackgroundJob;

use OCA\IntegrationImmich\AppInfo\Application;
use OCA\IntegrationImmich\BackgroundJob\SyncImmichUserJob;
use OCA\IntegrationImmich\Service\AdminConfigService;
use OCA\IntegrationImmich\Service\ProvisioningService;
use OCA\IntegrationImmich\Service\SyncStateService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class SyncImmichUserJobTest extends TestCase {
    private ITimeFactory&MockObject $timeFactory;
    private AdminConfigService&MockObject $adminConfigService;
    private ProvisioningService&MockObject $provisioningService;
    private SyncStateService&MockObject $syncStateService;
    private IUserManager&MockObject $userManager;
    private IGroupManager&MockObject $groupManager;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void {
        parent::setUp();

        $this->timeFactory = $this->createMock(ITimeFactory::class);
        $this->adminConfigService = $this->createMock(AdminConfigService::class);
        $this->provisioningService = $this->createMock(ProvisioningService::class);
        $this->syncStateService = $this->createMock(SyncStateService::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testSyncRunsReconcileWithSafeUpdatesFlag(): void {
        $this->adminConfigService->method('getAdminConfig')->willReturn($this->config());
        $this->userManager->method('get')->with('alice')->willReturn($this->user());
        $this->provisioningService->expects($this->once())
            ->method('reconcileUser')
            ->with('alice', false)
            ->willReturn([
                'action' => 'updated',
                'immichUserId' => 'immich-alice',
                'storageLabel' => 'alice',
                'quotaSet' => 8192,
                'errors' => [],
            ]);

        $result = $this->job()->runJob(['ncUid' => 'alice']);

        $this->assertSame('sync_immich_user', $result['job']);
        $this->assertSame('success', $result['status']);
        $this->assertSame('updated', $result['action']);
        $this->assertTrue($result['safeUpdatesOnly']);
        $this->assertSame('immich-alice', $result['immichUserId']);
        $this->assertSame('alice', $result['storageLabel']);
        $this->assertSame(8192, $result['quotaSet']);
    }

    public function testMissingUserSkipsBeforeProvisioningAndPersistsFailedStatus(): void {
        $this->adminConfigService->method('getAdminConfig')->willReturn($this->config());
        $this->userManager->method('get')->with('missing')->willReturn(null);
        $this->provisioningService->expects($this->never())->method('reconcileUser');
        $this->syncStateService->expects($this->once())
            ->method('updateStatus')
            ->with('missing', SyncStateService::STATUS_FAILED, SyncStateService::STATUS_FAILED, 'Nextcloud user was not found.');

        $result = $this->job()->runJob(['ncUid' => 'missing']);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('Nextcloud user was not found.', $result['reason']);
        $this->assertSame(['Nextcloud user was not found.'], $result['errors']);
        $this->assertTrue($result['safeUpdatesOnly']);
    }

    public function testDisabledProvisioningSkipsWithoutCallingProvisioningService(): void {
        $this->adminConfigService->method('getAdminConfig')->willReturn($this->config([
            AdminConfigService::KEY_PROVISIONING_ENABLED => false,
        ]));
        $this->userManager->expects($this->never())->method('get');
        $this->provisioningService->expects($this->never())->method('reconcileUser');
        $this->syncStateService->expects($this->once())
            ->method('updateStatus')
            ->with('alice', SyncStateService::STATUS_OUT_OF_SCOPE, SyncStateService::STATUS_OUT_OF_SCOPE, null);

        $result = $this->job()->runJob('alice');

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('Provisioning is disabled.', $result['reason']);
        $this->assertSame([], $result['errors']);
    }

    public function testReturnedErrorsAreRedactedAndLogged(): void {
        $rawPassword = 'fedcba9876543210fedcba9876543210';
        $this->adminConfigService->method('getAdminConfig')->willReturn($this->config());
        $this->userManager->method('get')->with('alice')->willReturn($this->user());
        $this->provisioningService->method('reconcileUser')->willReturn([
            'action' => 'skipped',
            'immichUserId' => null,
            'storageLabel' => 'alice',
            'quotaSet' => null,
            'errors' => ['Remote echoed password=' . $rawPassword . ' api_key=secret-admin-key'],
        ]);
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->callback(function (string $message) use ($rawPassword): bool {
                    $this->assertStringNotContainsString($rawPassword, $message);
                    $this->assertStringNotContainsString('secret-admin-key', $message);
                    return str_contains($message, '[redacted]');
                }),
                $this->callback(fn(array $context): bool => ($context['app'] ?? '') === Application::APP_ID
                    && ($context['ncUid'] ?? '') === 'alice'
                    && ($context['safeUpdatesOnly'] ?? false) === true)
            );

        $result = $this->job()->runJob(['ncUid' => 'alice']);
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);

        $this->assertSame('failed', $result['status']);
        $this->assertStringNotContainsString($rawPassword, $encoded);
        $this->assertStringNotContainsString('secret-admin-key', $encoded);
        $this->assertStringContainsString('[redacted]', $encoded);
        $this->assertTrue($result['safeUpdatesOnly']);
    }

    private function job(): TestableSyncImmichUserJob {
        return new TestableSyncImmichUserJob(
            $this->timeFactory,
            $this->adminConfigService,
            $this->provisioningService,
            $this->syncStateService,
            $this->userManager,
            $this->groupManager,
            $this->logger,
        );
    }

    private function config(array $overrides = []): array {
        return array_merge([
            AdminConfigService::KEY_PROVISIONING_ENABLED => true,
            AdminConfigService::KEY_USER_SCOPE_MODE => 'all',
            AdminConfigService::KEY_USER_SCOPE_GROUPS => [],
        ], $overrides);
    }

    private function user(): IUser&MockObject {
        return $this->createMock(IUser::class);
    }
}

final class TestableSyncImmichUserJob extends SyncImmichUserJob {
    public function runJob(mixed $argument): array {
        $this->run($argument);
        return $this->getLastResult() ?? [];
    }
}
