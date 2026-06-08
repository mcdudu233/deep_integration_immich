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

    public function testUserDeletedListenerMarksMappingInactiveWithoutDeletingAssets(): void {
        $syncStateService = $this->createMock(SyncStateService::class);
        $this->adminConfigService->method('getAdminConfig')->willReturn($this->config());
        $syncStateService->expects($this->once())
            ->method('updateMapping')
            ->with('alice', [
                'scopeStatus' => SyncStateService::STATUS_DELETED,
                'lastSyncStatus' => SyncStateService::STATUS_DELETED,
                'lastError' => null,
            ]);
        $this->jobList->expects($this->once())
            ->method('has')
            ->with(ReconcileUsersJob::class, ['ncUid' => 'alice'])
            ->willReturn(false);
        $this->jobList->expects($this->once())
            ->method('add')
            ->with(ReconcileUsersJob::class, ['ncUid' => 'alice']);

        $listener = new UserDeletedListener($this->adminConfigService, $this->jobList, $syncStateService);

        $listener->handle(new ListenerUidEvent('alice'));
    }

    public function testUserDeletedListenerStillQueuesReconcileWhenProvisioningDisabled(): void {
        $syncStateService = $this->createMock(SyncStateService::class);
        $syncStateService->expects($this->once())->method('updateMapping');
        $this->jobList->expects($this->once())->method('has')->willReturn(false);
        $this->jobList->expects($this->once())
            ->method('add')
            ->with(ReconcileUsersJob::class, ['ncUid' => 'alice']);

        $listener = new UserDeletedListener($this->adminConfigService, $this->jobList, $syncStateService);

        $listener->handle(new ListenerUidEvent('alice'));
    }

    public function testListenersDoNotInjectImmichOrExternalStorageServices(): void {
        $forbidden = [
            ProvisioningService::class,
            ImmichUserAdminService::class,
            ExternalStorageProvisioner::class,
        ];

        foreach ([
            UserCreatedListener::class,
            UserChangedListener::class,
            UserDeletedListener::class,
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
    }

    private function config(array $overrides = []): array {
        return array_merge([
            AdminConfigService::KEY_PROVISIONING_ENABLED => true,
            AdminConfigService::KEY_USER_SCOPE_MODE => 'all',
            AdminConfigService::KEY_USER_SCOPE_GROUPS => [],
        ], $overrides);
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
