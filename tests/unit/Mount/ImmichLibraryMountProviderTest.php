<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\Mount;

use OCA\IntegrationImmich\Db\SyncState;
use OCA\IntegrationImmich\Mount\ImmichLibraryMountProvider;
use OCA\IntegrationImmich\Service\AdminConfigService;
use OCA\IntegrationImmich\Service\PathTemplateService;
use OCA\IntegrationImmich\Service\SyncStateService;
use OCP\Files\Storage\IStorageFactory;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class ImmichLibraryMountProviderTest extends TestCase {
	private AdminConfigService&MockObject $adminConfigService;
	private SyncStateService&MockObject $syncStateService;
	/** @var array<string, bool> */
	private array $existingPaths = [];

	protected function setUp(): void {
		parent::setUp();

		$this->adminConfigService = $this->createMock(AdminConfigService::class);
		$this->syncStateService = $this->createMock(SyncStateService::class);
		$this->existingPaths = [];
	}

	public function testReturnsEmptyWhenNoSyncStateExists(): void {
		$this->syncStateService->method('findByUid')->with('alice')->willReturn(null);

		$this->assertSame([], $this->provider()->getMountsForUser($this->user('alice'), $this->factory()));
	}

	public function testReturnsEmptyWhenAutoCreateDisabled(): void {
		$this->syncStateService->method('findByUid')->with('alice')->willReturn($this->state('alice', 'alice'));
		$this->mockConfig(false);

		$this->assertSame([], $this->provider()->getMountsForUser($this->user('alice'), $this->factory()));
	}

	public function testKeepsMountWhenAutoCreateDisabledButMountIdPersisted(): void {
		// Once provisioning has run and persisted a mount id, toggling the admin auto-create flag
		// off later must not silently hide the mount; otherwise the user's quota keeps counting
		// bytes from a folder they can no longer browse in Files.
		$state = $this->state('alice', 'alice');
		$state->setNcMountId(42);
		$this->syncStateService->method('findByUid')->with('alice')->willReturn($state);
		$this->mockConfig(false);
		$this->existingPaths['/mnt/immich-library/alice'] = true;

		$mounts = $this->provider()->getMountsForUser($this->user('alice'), $this->factory());

		$this->assertCount(1, $mounts);
		$this->assertSame('/alice/files/Immich Photos/', $mounts[0]->getMountPoint());
		$this->assertSame(42, $mounts[0]->getMountId());
	}

	public function testReturnsEmptyWhenTargetPathDoesNotExist(): void {
		$this->syncStateService->method('findByUid')->with('alice')->willReturn($this->state('alice', 'alice'));
		$this->mockConfig(true);
		// no path marked as existing

		$this->assertSame([], $this->provider()->getMountsForUser($this->user('alice'), $this->factory()));
	}

	public function testKeepsMountWhenPathStatFailsButMountIdPersisted(): void {
		// Transient path-stat failures (perm flips, container restarts, bind-mount drops) must
		// not hide a previously working mount, because the filecache still drives quota off the
		// existing storage rows. Surfacing the mount lets Files render it (and Local storage will
		// raise its own visible error if reads truly fail).
		$state = $this->state('alice', 'alice');
		$state->setNcMountId(99);
		$this->syncStateService->method('findByUid')->with('alice')->willReturn($state);
		$this->mockConfig(true);
		// no path marked as existing

		$mounts = $this->provider()->getMountsForUser($this->user('alice'), $this->factory());

		$this->assertCount(1, $mounts);
		$this->assertSame('/alice/files/Immich Photos/', $mounts[0]->getMountPoint());
	}

	public function testReturnsEmptyWhenScopeIsDisabled(): void {
		$state = $this->state('alice', 'alice');
		$state->setScopeStatus(SyncStateService::STATUS_DISABLED);
		$this->syncStateService->method('findByUid')->with('alice')->willReturn($state);
		$this->mockConfig(true);
		$this->existingPaths['/mnt/immich-library/alice'] = true;

		$this->assertSame([], $this->provider()->getMountsForUser($this->user('alice'), $this->factory()));
	}

	public function testInjectsReadOnlyMountAtUserFilesRoot(): void {
		$state = $this->state('alice', 'alice');
		$state->setNcMountId(99);
		$this->syncStateService->method('findByUid')->with('alice')->willReturn($state);
		$this->mockConfig(true);
		$this->existingPaths['/mnt/immich-library/alice'] = true;

		$mounts = $this->provider()->getMountsForUser($this->user('alice'), $this->factory());

		$this->assertCount(1, $mounts);
		$mount = $mounts[0];
		$this->assertSame('/alice/files/Immich Photos/', $mount->getMountPoint());
		$this->assertSame(99, $mount->getMountId());
		$this->assertTrue($mount->getOption('readonly', false));
		$this->assertFalse($mount->getOption('enable_sharing', true));
	}

	public function testUsesStorageLabelFromMappingNotImmichUuid(): void {
		$state = $this->state('alice', 'alice');
		$state->setImmichUserId('550e8400-e29b-41d4-a716-446655440000');
		$this->syncStateService->method('findByUid')->with('alice')->willReturn($state);
		$this->mockConfig(true);
		$this->existingPaths['/mnt/immich-library/alice'] = true;

		$mounts = $this->provider()->getMountsForUser($this->user('alice'), $this->factory());

		$this->assertCount(1, $mounts);
		$this->assertSame('/alice/files/Immich Photos/', $mounts[0]->getMountPoint());
	}

	public function testReturnsEmptyForUnsafePathTemplate(): void {
		$state = $this->state('alice', 'alice');
		$this->syncStateService->method('findByUid')->with('alice')->willReturn($state);
		$this->adminConfigService->method('getAdminConfig')->willReturn([
			AdminConfigService::KEY_EXTERNAL_STORAGE_AUTO_CREATE => true,
			AdminConfigService::KEY_NC_VISIBLE_PATH_TEMPLATE => '/mnt/immich-library/../escape/{uid}',
			AdminConfigService::KEY_MOUNT_NAME_TEMPLATE => 'Immich Photos',
			AdminConfigService::KEY_STORAGE_LABEL_TEMPLATE => '{uid}',
		]);

		$this->assertSame([], $this->provider()->getMountsForUser($this->user('alice'), $this->factory()));
	}

	public function testFallsBackToHostPathWhenNcVisibleTemplateIsEmpty(): void {
		$state = $this->state('alice', 'alice');
		$state->setNcMountId(7);
		$this->syncStateService->method('findByUid')->with('alice')->willReturn($state);
		$this->adminConfigService->method('getAdminConfig')->willReturn([
			AdminConfigService::KEY_EXTERNAL_STORAGE_AUTO_CREATE => true,
			AdminConfigService::KEY_NC_VISIBLE_PATH_TEMPLATE => '',
			AdminConfigService::KEY_HOST_PATH_TEMPLATE => '/srv/immich/originals/library/{storageLabel}',
			AdminConfigService::KEY_MOUNT_NAME_TEMPLATE => 'Immich Photos',
			AdminConfigService::KEY_STORAGE_LABEL_TEMPLATE => '{uid}',
		]);
		$this->existingPaths['/srv/immich/originals/library/alice'] = true;

		$mounts = $this->provider()->getMountsForUser($this->user('alice'), $this->factory());

		$this->assertCount(1, $mounts);
		$this->assertSame('/alice/files/Immich Photos/', $mounts[0]->getMountPoint());
	}

	private function provider(): ImmichLibraryMountProvider {
		return new ImmichLibraryMountProvider(
			$this->adminConfigService,
			$this->syncStateService,
			new PathTemplateService(),
			$this->createMock(LoggerInterface::class),
			fn (string $path): bool => $this->existingPaths[str_replace('\\', '/', $path)] ?? false,
		);
	}

	private function mockConfig(bool $autoCreate): void {
		$this->adminConfigService->method('getAdminConfig')->willReturn([
			AdminConfigService::KEY_EXTERNAL_STORAGE_AUTO_CREATE => $autoCreate,
			AdminConfigService::KEY_NC_VISIBLE_PATH_TEMPLATE => '/mnt/immich-library/{storageLabel}',
			AdminConfigService::KEY_MOUNT_NAME_TEMPLATE => 'Immich Photos',
			AdminConfigService::KEY_STORAGE_LABEL_TEMPLATE => '{uid}',
		]);
	}

	private function factory(): IStorageFactory {
		return $this->createMock(IStorageFactory::class);
	}

	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	private function state(string $uid, string $storageLabel): SyncState {
		$state = new SyncState();
		$state->setNcUid($uid);
		$state->setStorageLabel($storageLabel);
		return $state;
	}
}
