<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\BackgroundJob;

use OCA\IntegrationImmich\AppInfo\Application;
use OCA\IntegrationImmich\BackgroundJob\ProvisionImmichUserJob;
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

class ProvisionImmichUserJobTest extends TestCase {
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

    public function testRunsProvisioningForAllUsersScope(): void {
        $this->adminConfigService->method('getAdminConfig')->willReturn($this->config());
        $this->userManager->method('get')->with('alice')->willReturn($this->user());
        $this->groupManager->expects($this->never())->method('isInGroup');
        $this->syncStateService->expects($this->never())->method('updateStatus');
        $this->provisioningService->expects($this->once())
            ->method('reconcileUser')
            ->with('alice', false)
            ->willReturn([
                'ncUid' => 'alice',
                'action' => 'created',
                'immichUserId' => 'immich-alice',
                'storageLabel' => 'alice',
                'quotaSet' => 4096,
                'errors' => [],
                'dryRun' => false,
            ]);

        $result = $this->job()->runJob(['ncUid' => 'alice']);

        $this->assertSame('provision_immich_user', $result['job']);
        $this->assertSame('alice', $result['ncUid']);
        $this->assertSame('success', $result['status']);
        $this->assertSame('created', $result['action']);
        $this->assertSame('immich-alice', $result['immichUserId']);
        $this->assertSame('alice', $result['storageLabel']);
        $this->assertSame(4096, $result['quotaSet']);
        $this->assertSame([], $result['errors']);
        $this->assertStringNotContainsString('password', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testSkipsOutOfScopeGroupUserBeforeProvisioning(): void {
        $this->adminConfigService->method('getAdminConfig')->willReturn($this->config([
            AdminConfigService::KEY_USER_SCOPE_MODE => 'groups',
            AdminConfigService::KEY_USER_SCOPE_GROUPS => ['staff', 'family'],
        ]));
        $this->userManager->method('get')->with('bob')->willReturn($this->user());
        $this->groupManager->expects($this->exactly(2))
            ->method('isInGroup')
            ->willReturnMap([
                ['bob', 'staff', false],
                ['bob', 'family', false],
            ]);
        $this->provisioningService->expects($this->never())->method('reconcileUser');
        $this->syncStateService->expects($this->once())
            ->method('updateStatus')
            ->with('bob', SyncStateService::STATUS_OUT_OF_SCOPE, SyncStateService::STATUS_OUT_OF_SCOPE, null);

        $result = $this->job()->runJob(['ncUid' => 'bob']);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('skipped', $result['action']);
        $this->assertSame('Nextcloud user is outside configured provisioning groups.', $result['reason']);
        $this->assertSame([], $result['errors']);
    }

    public function testGroupScopedMemberRunsProvisioning(): void {
        $this->adminConfigService->method('getAdminConfig')->willReturn($this->config([
            AdminConfigService::KEY_USER_SCOPE_MODE => 'groups',
            AdminConfigService::KEY_USER_SCOPE_GROUPS => ['staff', 'family'],
        ]));
        $this->userManager->method('get')->with('alice')->willReturn($this->user());
        $this->groupManager->expects($this->exactly(2))
            ->method('isInGroup')
            ->willReturnMap([
                ['alice', 'staff', false],
                ['alice', 'family', true],
            ]);
        $this->provisioningService->expects($this->once())
            ->method('reconcileUser')
            ->with('alice', false)
            ->willReturn([
                'action' => 'unchanged',
                'immichUserId' => 'immich-alice',
                'storageLabel' => 'alice',
                'quotaSet' => null,
                'errors' => [],
            ]);

        $result = $this->job()->runJob('alice');

        $this->assertSame('success', $result['status']);
        $this->assertSame('unchanged', $result['action']);
    }

    public function testExceptionResultAndLogAreRedacted(): void {
        $rawPassword = '0123456789abcdef0123456789abcdef';
        $this->adminConfigService->method('getAdminConfig')->willReturn($this->config());
        $this->userManager->method('get')->with('alice')->willReturn($this->user());
        $this->provisioningService->method('reconcileUser')
            ->willThrowException(new \RuntimeException('{"password":"' . $rawPassword . '","x-api-key":"secret-admin-key"}'));
        $this->syncStateService->expects($this->once())
            ->method('updateStatus')
            ->with(
                'alice',
                SyncStateService::STATUS_FAILED,
                SyncStateService::STATUS_FAILED,
                $this->callback(function (string $error) use ($rawPassword): bool {
                    $this->assertStringNotContainsString($rawPassword, $error);
                    $this->assertStringNotContainsString('secret-admin-key', $error);
                    $this->assertStringContainsString('[redacted]', $error);
                    return true;
                })
            );
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->callback(function (string $message) use ($rawPassword): bool {
                    $this->assertStringNotContainsString($rawPassword, $message);
                    $this->assertStringNotContainsString('secret-admin-key', $message);
                    return str_contains($message, '[redacted]');
                }),
                $this->callback(fn(array $context): bool => ($context['app'] ?? '') === Application::APP_ID && ($context['ncUid'] ?? '') === 'alice')
            );

        $result = $this->job()->runJob(['ncUid' => 'alice']);
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);

        $this->assertSame('failed', $result['status']);
        $this->assertStringNotContainsString($rawPassword, $encoded);
        $this->assertStringNotContainsString('secret-admin-key', $encoded);
        $this->assertStringContainsString('[redacted]', $encoded);
    }

    private function job(): TestableProvisionImmichUserJob {
        return new TestableProvisionImmichUserJob(
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

final class TestableProvisionImmichUserJob extends ProvisionImmichUserJob {
    public function runJob(mixed $argument): array {
        $this->run($argument);
        return $this->getLastResult() ?? [];
    }
}
