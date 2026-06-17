<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\Listener;

use OCA\IntegrationImmich\BackgroundJob\ProvisionImmichUserJob;
use OCA\IntegrationImmich\BackgroundJob\ReconcileUsersJob;
use OCA\IntegrationImmich\BackgroundJob\SyncImmichUserJob;
use OCA\IntegrationImmich\BackgroundJob\SyncQuotaJob;
use OCA\IntegrationImmich\Listener\AccountUpdatedListener;
use OCA\IntegrationImmich\Listener\GroupMembershipListener;
use OCA\IntegrationImmich\Listener\UserChangedListener;
use OCA\IntegrationImmich\Listener\UserCreatedListener;
use OCA\IntegrationImmich\Listener\UserDeletedListener;
use OCA\IntegrationImmich\Service\AdminConfigService;
use OCA\IntegrationImmich\Service\ExternalStorageProvisioner;
use OCA\IntegrationImmich\Service\ImmichUserAdminService;
use OCA\IntegrationImmich\Service\ProvisioningService;
use OCA\IntegrationImmich\Service\SyncStateService;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionClass;
use Test\TestCase;

class LifecycleListenersTest extends TestCase {
    private AdminConfigService&MockObject $adminConfigService;
    private IJobList&MockObject $jobList;

    protected function setUp(): void {
        parent::setUp();

        $this->adminConfigService = $this->createMock(AdminConfigService::class);
        $this->jobList = $this->createMock(IJobList::class);
    }

    public function testUserCreatedListenerEnqueuesProvisioningJob(): void {
        $this->adminConfigService->method('getAdminConfig')->willReturn($this->config());
        $this->jobList->expects($this->once())
            ->method('add')
            ->with(ProvisionImmichUserJob::class, ['ncUid' => 'alice']);

        $listener = new UserCreatedListener($this->adminConfigService, $this->jobList);

        $listener->handle(new ListenerUidEvent('alice'));
    }

    public function testUserCreatedListenerSkipsWhenProvisioningDisabled(): void {
        $this->adminConfigService->method('getAdminConfig')->willReturn($this->config([
            AdminConfigService::KEY_PROVISIONING_ENABLED => false,
        ]));
        $this->jobList->expects($this->never())->method('add');

        $listener = new UserCreatedListener($this->adminConfigService, $this->jobList);

        $listener->handle(new ListenerUidEvent('alice'));
    }

    public function testUserChangedListenerRoutesProfileChangesToImmichUserSync(): void {
        $this->adminConfigService->method('getAdminConfig')->willReturn($this->config());
        $this->jobList->expects($this->once())
            ->method('add')
            ->with(SyncImmichUserJob::class, ['ncUid' => 'alice']);

        $listener = new UserChangedListener($this->adminConfigService, $this->jobList);

        $listener->handle(new ListenerUserChangedEvent(new ListenerUser('alice'), 'eMailAddress'));
    }

    public function testUserChangedListenerRoutesEnabledFeatureToReconcile(): void {
        $this->jobList->expects($this->once())
            ->method('add')
            ->with(ReconcileUsersJob::class, ['ncUid' => 'alice']);

        $listener = new UserChangedListener($this->adminConfigService, $this->jobList);

        $listener->handle(new ListenerUserChangedEvent(new ListenerUser('alice'), 'enabled'));
    }

    public function testUserChangedListenerRoutesQuotaFeatureToQuotaSync(): void {
        $this->adminConfigService->method('getAdminConfig')->willReturn($this->config());
        $this->jobList->expects($this->once())
            ->method('add')
            ->with(SyncQuotaJob::class, ['ncUid' => 'alice']);

        $listener = new UserChangedListener($this->adminConfigService, $this->jobList);

        $listener->handle(new ListenerUserChangedEvent(new ListenerUser('alice'), 'quota'));
    }

    public function testUserChangedListenerRoutesGetQuotaEventToQuotaSync(): void {
        $this->adminConfigService->method('getAdminConfig')->willReturn($this->config());
        $this->jobList->expects($this->once())
            ->method('add')
            ->with(SyncQuotaJob::class, ['ncUid' => 'alice']);

        $listener = new UserChangedListener($this->adminConfigService, $this->jobList);

        $listener->handle(new ListenerGetQuotaEvent(new ListenerUser('alice')));
    }

    public function testUserChangedListenerIgnoresUnrelatedFeature(): void {
        $this->adminConfigService->method('getAdminConfig')->willReturn($this->config());
        $this->jobList->expects($this->never())->method('add');

        $listener = new UserChangedListener($this->adminConfigService, $this->jobList);

        $listener->handle(new ListenerUserChangedEvent(new ListenerUser('alice'), 'managers'));
    }

    public function testAccountUpdatedListenerEnqueuesUserSyncForIdentityKeys(): void {
        $this->adminConfigService->method('getAdminConfig')->willReturn($this->config());
        $this->jobList->expects($this->once())
            ->method('add')
            ->with(SyncImmichUserJob::class, ['ncUid' => 'alice']);

        $listener = new AccountUpdatedListener($this->adminConfigService, $this->jobList);

        $listener->handle(new ListenerAccountUpdatedEvent(new ListenerUser('alice'), [
            'displayname' => ['value' => 'Alice Example'],
        ]));
    }

    public function testAccountUpdatedListenerIgnoresNonIdentityKeys(): void {
        $this->adminConfigService->method('getAdminConfig')->willReturn($this->config());
        $this->jobList->expects($this->never())->method('add');

        $listener = new AccountUpdatedListener($this->adminConfigService, $this->jobList);

        $listener->handle(new ListenerAccountUpdatedEvent(new ListenerUser('alice'), [
            'phone' => ['value' => '+49 123'],
        ]));
    }

    public function testGroupMembershipListenerEnqueuesUserReconcileForScopedGroup(): void {
        $this->adminConfigService->method('getAdminConfig')->willReturn($this->config([
            AdminConfigService::KEY_USER_SCOPE_MODE => 'groups',
            AdminConfigService::KEY_USER_SCOPE_GROUPS => ['staff', 'family'],
        ]));
        $this->jobList->expects($this->once())
            ->method('add')
            ->with(ReconcileUsersJob::class, ['ncUid' => 'alice']);

        $listener = new GroupMembershipListener($this->adminConfigService, $this->jobList);

        $listener->handle(new ListenerGroupMembershipEvent(new ListenerGroup('family'), new ListenerUser('alice')));
    }

    public function testGroupMembershipListenerIgnoresUnscopedGroup(): void {
        $this->adminConfigService->method('getAdminConfig')->willReturn($this->config([
            AdminConfigService::KEY_USER_SCOPE_MODE => 'groups',
            AdminConfigService::KEY_USER_SCOPE_GROUPS => ['staff'],
        ]));
        $this->jobList->expects($this->never())->method('add');

        $listener = new GroupMembershipListener($this->adminConfigService, $this->jobList);

        $listener->handle(new ListenerGroupMembershipEvent(new ListenerGroup('family'), new ListenerUser('alice')));
    }

    public function testGroupMembershipListenerIgnoresAllUsersScope(): void {
        $this->adminConfigService->method('getAdminConfig')->willReturn($this->config([
            AdminConfigService::KEY_USER_SCOPE_MODE => 'all',
        ]));
        $this->jobList->expects($this->never())->method('add');

        $listener = new GroupMembershipListener($this->adminConfigService, $this->jobList);

        $listener->handle(new ListenerGroupMembershipEvent(new ListenerGroup('family'), new ListenerUser('alice')));
    }

    public function testUserDeletedListenerDeletesSyncStateAndDisablesImmichUser(): void {
        $syncStateService = $this->createMock(SyncStateService::class);
        $immichUserAdminService = $this->createMock(ImmichUserAdminService::class);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $this->adminConfigService->method('getAdminConfig')->willReturn($this->config());
        $this->adminConfigService->method('allowsDestructiveUserDelete')->willReturn(false);
        $state = $this->state('alice', 'immich-alice');
        $syncStateService->expects($this->once())->method('findByUid')->with('alice')->willReturn($state);
        $immichUserAdminService->expects($this->once())->method('disableUser')->with('immich-alice');
        $immichUserAdminService->expects($this->never())->method('deleteUser');
        $syncStateService->expects($this->once())->method('deleteByUid')->with('alice')->willReturn(true);

        $listener = new UserDeletedListener($this->adminConfigService, $syncStateService, $immichUserAdminService, $logger);

        $listener->handle(new ListenerUidEvent('alice'));
    }

    public function testUserDeletedListenerDeletesImmichUserWhenDestructivePolicyEnabled(): void {
        $syncStateService = $this->createMock(SyncStateService::class);
        $immichUserAdminService = $this->createMock(ImmichUserAdminService::class);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $this->adminConfigService->method('getAdminConfig')->willReturn($this->config());
        $this->adminConfigService->method('allowsDestructiveUserDelete')->willReturn(true);
        $state = $this->state('alice', 'immich-alice');
        $syncStateService->expects($this->once())->method('findByUid')->with('alice')->willReturn($state);
        $immichUserAdminService->expects($this->once())->method('deleteUser')->with('immich-alice');
        $immichUserAdminService->expects($this->never())->method('disableUser');
        $syncStateService->expects($this->once())->method('deleteByUid')->with('alice')->willReturn(true);

        $listener = new UserDeletedListener($this->adminConfigService, $syncStateService, $immichUserAdminService, $logger);

        $listener->handle(new ListenerUidEvent('alice'));
    }

    public function testUserDeletedListenerStillDropsSyncStateWhenImmichCleanupFails(): void {
        $syncStateService = $this->createMock(SyncStateService::class);
        $immichUserAdminService = $this->createMock(ImmichUserAdminService::class);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $this->adminConfigService->method('getAdminConfig')->willReturn($this->config());
        $this->adminConfigService->method('allowsDestructiveUserDelete')->willReturn(false);
        $state = $this->state('alice', 'immich-alice');
        $syncStateService->expects($this->once())->method('findByUid')->with('alice')->willReturn($state);
        $immichUserAdminService->expects($this->once())
            ->method('disableUser')
            ->with('immich-alice')
            ->willThrowException(new \RuntimeException('Immich admin endpoint unreachable'));
        $syncStateService->expects($this->once())->method('deleteByUid')->with('alice')->willReturn(true);

        $listener = new UserDeletedListener($this->adminConfigService, $syncStateService, $immichUserAdminService, $logger);

        $listener->handle(new ListenerUidEvent('alice'));
    }

    public function testUserDeletedListenerSkipsImmichCleanupWhenNoMappingExists(): void {
        $syncStateService = $this->createMock(SyncStateService::class);
        $immichUserAdminService = $this->createMock(ImmichUserAdminService::class);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $syncStateService->expects($this->once())->method('findByUid')->with('alice')->willReturn(null);
        $immichUserAdminService->expects($this->never())->method('disableUser');
        $immichUserAdminService->expects($this->never())->method('deleteUser');
        $syncStateService->expects($this->once())->method('deleteByUid')->with('alice')->willReturn(false);

        $listener = new UserDeletedListener($this->adminConfigService, $syncStateService, $immichUserAdminService, $logger);

        $listener->handle(new ListenerUidEvent('alice'));
    }

    public function testListenersDoNotInjectImmichOrExternalStorageServices(): void {
        $forbidden = [
            ProvisioningService::class,
            ImmichUserAdminService::class,
            ExternalStorageProvisioner::class,
        ];

        $deletedListenerAllowances = [
            ImmichUserAdminService::class,
        ];

        foreach ([
            UserCreatedListener::class,
            UserChangedListener::class,
            GroupMembershipListener::class,
            AccountUpdatedListener::class,
        ] as $listenerClass) {
            $constructor = (new ReflectionClass($listenerClass))->getConstructor();
            $types = [];
            foreach ($constructor?->getParameters() ?? [] as $parameter) {
                $type = $parameter->getType();
                if ($type !== null) {
                    $types[] = (string)$type;
                }
            }

            foreach ($forbidden as $forbiddenType) {
                $this->assertNotContains($forbiddenType, $types, $listenerClass . ' must not call orchestration services directly.');
            }
        }

        $constructor = (new ReflectionClass(UserDeletedListener::class))->getConstructor();
        $types = [];
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            if ($type !== null) {
                $types[] = (string)$type;
            }
        }
        foreach ($forbidden as $forbiddenType) {
            if (in_array($forbiddenType, $deletedListenerAllowances, true)) {
                continue;
            }
            $this->assertNotContains($forbiddenType, $types, UserDeletedListener::class . ' may only depend on ImmichUserAdminService among orchestration services.');
        }
    }

    private function config(array $overrides = []): array {
        return array_merge([
            AdminConfigService::KEY_PROVISIONING_ENABLED => true,
            AdminConfigService::KEY_USER_SCOPE_MODE => 'all',
            AdminConfigService::KEY_USER_SCOPE_GROUPS => [],
        ], $overrides);
    }

    private function state(string $ncUid, string $immichUserId): \OCA\IntegrationImmich\Db\SyncState {
        $state = new \OCA\IntegrationImmich\Db\SyncState();
        $state->setNcUid($ncUid);
        $state->setImmichUserId($immichUserId);
        $state->setStorageLabel($ncUid);
        return $state;
    }
}

final class ListenerUidEvent extends Event {
    public function __construct(private string $uid) {
        parent::__construct();
    }

    public function getUid(): string {
        return $this->uid;
    }
}

final class ListenerUserChangedEvent extends Event {
    public function __construct(
        private ListenerUser $user,
        private string $feature,
    ) {
        parent::__construct();
    }

    public function getUser(): ListenerUser {
        return $this->user;
    }

    public function getFeature(): string {
        return $this->feature;
    }
}

final class ListenerGetQuotaEvent extends Event {
    private ?string $quota = null;

    public function __construct(private ListenerUser $user) {
        parent::__construct();
    }

    public function getUser(): ListenerUser {
        return $this->user;
    }

    public function getQuota(): ?string {
        return $this->quota;
    }

    public function setQuota(string $quota): void {
        $this->quota = $quota;
    }
}

final class ListenerAccountUpdatedEvent extends Event {
    public function __construct(
        private ListenerUser $user,
        private array $data,
    ) {
        parent::__construct();
    }

    public function getUser(): ListenerUser {
        return $this->user;
    }

    public function getData(): array {
        return $this->data;
    }
}

final class ListenerGroupMembershipEvent extends Event {
    public function __construct(
        private ListenerGroup $group,
        private ListenerUser $user,
    ) {
        parent::__construct();
    }

    public function getGroup(): ListenerGroup {
        return $this->group;
    }

    public function getUser(): ListenerUser {
        return $this->user;
    }
}

final class ListenerUser {
    public function __construct(private string $uid) {
    }

    public function getUID(): string {
        return $this->uid;
    }
}

final class ListenerGroup {
    public function __construct(private string $gid) {
    }

    public function getGID(): string {
        return $this->gid;
    }
}
