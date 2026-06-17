<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\Mount;

use OCA\IntegrationImmich\Db\SyncState;
use OCA\IntegrationImmich\Mount\BuiltinExternalStorageService;
use OCA\IntegrationImmich\Service\AdminConfigService;
use OCA\IntegrationImmich\Service\PathTemplateService;
use OCA\IntegrationImmich\Service\SyncStateService;
use OCP\Files\Config\IUserMountCache;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class BuiltinExternalStorageServiceTest extends TestCase {
	private SyncStateService&MockObject $syncStateService;
	private IUserManager&MockObject $userManager;
	private IUserMountCache&MockObject $userMountCache;
	private AdminConfigService&MockObject $adminConfigService;

	protected function setUp(): void {
		parent::setUp();

		$this->syncStateService = $this->createMock(SyncStateService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->userMountCache = $this->createMock(IUserMountCache::class);
		$this->adminConfigService = $this->createMock(AdminConfigService::class);
	}

	public function testCreateOrUpdateLocalMountAllocatesStableIdForUser(): void {
		$user = $this->createMock(IUser::class);
		$this->userManager->method('get')->with('alice')->willReturn($user);
		$this->syncStateService->expects($this->once())->method('getOrCreateForUid')->with('alice');
		$this->syncStateService->expects($this->once())->method('updateMapping')->with('alice', $this->callback(function (array $fields): bool {
			$this->assertArrayHasKey('ncMountId', $fields);
			$this->assertIsInt($fields['ncMountId']);
			$this->assertGreaterThan(0, $fields['ncMountId']);
			return true;
		}));
		$this->userMountCache->expects($this->once())->method('removeUserMounts')->with($user);

		$service = $this->service();

		$mount = $service->createOrUpdateLocalMount('alice', '/Immich Photos', '/mnt/immich-library/alice', true, null);

		$this->assertSame('/Immich Photos', $mount->getMountPoint());
		$this->assertSame(['datadir' => '/mnt/immich-library/alice'], $mount->getBackendOptions());
		$this->assertSame(['alice'], $mount->getApplicableUsers());
		$this->assertSame([], $mount->getApplicableGroups());
		$this->assertSame(['readonly' => true], $mount->getMountOptions());
		$this->assertGreaterThan(0, $mount->getId());
	}

	public function testCreateOrUpdateLocalMountReusesKnownMountId(): void {
		$user = $this->createMock(IUser::class);
		$this->userManager->method('get')->with('alice')->willReturn($user);
		$this->syncStateService->expects($this->once())->method('updateMapping')->with('alice', ['ncMountId' => 4242]);

		$mount = $this->service()->createOrUpdateLocalMount('alice', '/Immich Photos', '/mnt/immich-library/alice', true, 4242);

		$this->assertSame(4242, $mount->getId());
	}

	public function testCreateOrUpdateLocalMountRejectsWritableMount(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('read-only');

		$this->service()->createOrUpdateLocalMount('alice', '/Immich Photos', '/mnt/immich-library/alice', false, null);
	}

	public function testCreateOrUpdateLocalMountRejectsUnknownUser(): void {
		$this->userManager->method('get')->with('ghost')->willReturn(null);

		$this->expectException(\InvalidArgumentException::class);

		$this->service()->createOrUpdateLocalMount('ghost', '/Immich Photos', '/mnt/immich-library/ghost', true, null);
	}

	public function testGetUserStoragesReturnsEmptyWhenNoMapping(): void {
		$this->syncStateService->method('findByUid')->with('alice')->willReturn(null);

		$this->assertSame([], $this->service()->getUserStorages('alice'));
	}

	public function testGetUserStoragesReturnsEmptyWhenMountIdMissing(): void {
		$state = $this->state('alice', 'alice', null);
		$this->syncStateService->method('findByUid')->with('alice')->willReturn($state);

		$this->assertSame([], $this->service()->getUserStorages('alice'));
	}

	public function testGetUserStoragesReturnsBuiltinMountWhenMappingExists(): void {
		$state = $this->state('alice', 'alice', 17);
		$this->syncStateService->method('findByUid')->with('alice')->willReturn($state);
		$this->adminConfigService->method('getAdminConfig')->willReturn([
			AdminConfigService::KEY_HOST_PATH_TEMPLATE => '/srv/immich/originals/library/{storageLabel}',
			AdminConfigService::KEY_NC_VISIBLE_PATH_TEMPLATE => '/mnt/immich-library/{storageLabel}',
			AdminConfigService::KEY_MOUNT_NAME_TEMPLATE => 'Immich Photos',
			AdminConfigService::KEY_STORAGE_LABEL_TEMPLATE => '{uid}',
		]);

		$mounts = $this->service()->getUserStorages('alice');

		$this->assertCount(1, $mounts);
		$this->assertSame(17, $mounts[0]->getId());
		$this->assertSame('/Immich Photos', $mounts[0]->getMountPoint());
		$this->assertSame(['datadir' => '/mnt/immich-library/alice'], $mounts[0]->getBackendOptions());
		$this->assertSame(['alice'], $mounts[0]->getApplicableUsers());
		$this->assertSame(['readonly' => true], $mounts[0]->getMountOptions());
	}

	public function testGetUserStoragesFallsBackToHostPathWhenNcVisibleTemplateIsEmpty(): void {
		$state = $this->state('alice', 'alice', 17);
		$this->syncStateService->method('findByUid')->with('alice')->willReturn($state);
		$this->adminConfigService->method('getAdminConfig')->willReturn([
			AdminConfigService::KEY_HOST_PATH_TEMPLATE => '/srv/immich/originals/library/{storageLabel}',
			AdminConfigService::KEY_NC_VISIBLE_PATH_TEMPLATE => '',
			AdminConfigService::KEY_MOUNT_NAME_TEMPLATE => 'Immich Photos',
			AdminConfigService::KEY_STORAGE_LABEL_TEMPLATE => '{uid}',
		]);

		$mounts = $this->service()->getUserStorages('alice');

		$this->assertCount(1, $mounts);
		$this->assertSame(['datadir' => '/srv/immich/originals/library/alice'], $mounts[0]->getBackendOptions());
	}

	private function service(): BuiltinExternalStorageService {
		return new BuiltinExternalStorageService(
			$this->syncStateService,
			new PathTemplateService(),
			$this->userManager,
			$this->createMock(LoggerInterface::class),
			$this->adminConfigService,
			$this->userMountCache,
		);
	}

	private function state(string $uid, string $storageLabel, ?int $mountId): SyncState {
		$state = new SyncState();
		$state->setNcUid($uid);
		$state->setStorageLabel($storageLabel);
		$state->setNcMountId($mountId);
		return $state;
	}
}
