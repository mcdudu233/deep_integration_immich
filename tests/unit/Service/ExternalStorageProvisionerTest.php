<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\Service;

use OCA\IntegrationImmich\Db\SyncState;
use OCA\IntegrationImmich\Service\AdminConfigService;
use OCA\IntegrationImmich\Service\CapabilityService;
use OCA\IntegrationImmich\Service\ExternalStorageProvisioner;
use OCA\IntegrationImmich\Service\PathTemplateService;
use OCA\IntegrationImmich\Service\SyncStateService;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class ExternalStorageProvisionerTest extends TestCase {
	private AdminConfigService&MockObject $adminConfigService;
	private CapabilityService&MockObject $capabilityService;
	private SyncStateService&MockObject $syncStateService;
	private FakeExternalStorageConfigService $mounts;
	/** @var array<string, array{exists?: bool, readable?: bool, link?: bool}> */
	private array $filesystem = [];

	protected function setUp(): void {
		parent::setUp();

		$this->adminConfigService = $this->createMock(AdminConfigService::class);
		$this->capabilityService = $this->createMock(CapabilityService::class);
		$this->syncStateService = $this->createMock(SyncStateService::class);
		$this->mounts = new FakeExternalStorageConfigService();
		$this->filesystem = [];
	}

	public function testVerifyOnlySuccessPersistsMountIdWhenMappingExists(): void {
		$this->mockConfig(false, false);
		$state = $this->state('alice', 'alice');
		$this->syncStateService->method('findByUid')->with('alice')->willReturn($state);
		$this->syncStateService->method('findByMountId')->with(42)->willReturn(null);
		$this->syncStateService->expects($this->once())->method('updateMapping')->with('alice', ['ncMountId' => 42]);
		$this->markPath('/srv/immich/originals/library/alice', true, true);
		$this->markPath('/mnt/immich-library/alice', true, true);
		$this->mounts->mounts[] = new FakeStorageMount(42, '/Immich Photos', '/mnt/immich-library/alice', ['readonly' => true], ['alice'], []);

		$result = $this->service()->verifyMount('alice');

		$this->assertSame('ok', $result['status']);
		$this->assertSame('/Immich Photos', $result['mount_name']);
		$this->assertSame('/mnt/immich-library/alice', $result['target_path']);
		$this->assertTrue($result['configured']);
		$this->assertTrue($result['exists']);
		$this->assertTrue($result['readable']);
		$this->assertTrue($result['read_only']);
		$this->assertTrue($result['available_only_to_uid']);
		$this->assertTrue($result['not_root_storage']);
		$this->assertTrue($result['target_matches']);
		$this->assertSame(42, $result['mount_id']);
	}

	public function testVerifyDoesNotPersistMountIdOwnedByDifferentUser(): void {
		$this->mockConfig(false, false);
		$state = $this->state('alice', 'alice');
		$owner = $this->state('bob', 'bob');
		$this->syncStateService->method('findByUid')->with('alice')->willReturn($state);
		$this->syncStateService->method('findByMountId')->with(42)->willReturn($owner);
		$this->syncStateService->expects($this->never())->method('updateMapping');
		$this->markPath('/srv/immich/originals/library/alice', true, true);
		$this->markPath('/mnt/immich-library/alice', true, true);
		$this->mounts->mounts[] = new FakeStorageMount(42, '/Immich Photos', '/mnt/immich-library/alice', ['readonly' => true], ['alice'], []);

		$result = $this->service()->verifyMount('alice');

		$this->assertSame('ok', $result['status']);
		$this->assertSame(42, $result['mount_id']);
	}

	public function testProvisionMountDefaultsToVerifyOnlyWhenAutoCreateDisabled(): void {
		$this->mockConfig(false, false);
		$this->syncStateService->method('findByUid')->with('alice')->willReturn($this->state('alice', 'alice'));
		$this->syncStateService->expects($this->never())->method('getOrCreateForUid');
		$this->syncStateService->expects($this->never())->method('updateMapping');
		$this->markPath('/srv/immich/originals/library/alice', true, true);
		$this->markPath('/mnt/immich-library/alice', true, true);

		$result = $this->service()->provisionMount('alice');

		$this->assertSame('template_verification_required', $result['status']);
		$this->assertFalse($result['configured']);
		$this->assertSame([], $this->mounts->created);
	}

	public function testPendingMountUsesStorageLabelTemplateWhenMappingIsMissing(): void {
		$this->mockConfig(false, false, 'library-{uid}');
		$this->syncStateService->method('findByUid')->with('alice')->willReturn(null);
		$this->markPath('/srv/immich/originals/library/library-alice', true, true);
		$this->markPath('/mnt/immich-library/library-alice', true, true);

		$result = $this->service()->verifyMount('alice');

		$this->assertSame('template_verification_required', $result['status']);
		$this->assertSame('/srv/immich/originals/library/library-alice', $result['host_path']);
		$this->assertSame('/mnt/immich-library/library-alice', $result['target_path']);
		$this->assertSame([], $this->mounts->created);
	}

	public function testMountPathUsesStoredStorageLabelNotImmichUserId(): void {
		$this->mockConfig(true, false);
		$this->mockAutoCreateCapability(true);
		$state = $this->state('alice', 'alice');
		$state->setImmichUserId('550e8400-e29b-41d4-a716-446655440000');
		$this->syncStateService->method('findByUid')->with('alice')->willReturn($state);
		$this->syncStateService->method('findByMountId')->with(99)->willReturn(null);
		$this->syncStateService->expects($this->once())->method('getOrCreateForUid')->with('alice');
		$this->syncStateService->expects($this->once())->method('updateMapping')->with('alice', ['ncMountId' => 99]);
		$this->markPath('/srv/immich/originals/library/alice', true, true);
		$this->markPath('/mnt/immich-library/alice', true, true);

		$result = $this->service()->provisionMount('alice');

		$this->assertSame('ok', $result['status']);
		$this->assertSame('/Immich Photos', $result['mount_name']);
		$this->assertSame('/mnt/immich-library/alice', $result['target_path']);
		$this->assertSame('/mnt/immich-library/alice', $this->mounts->created[0]['targetPath']);
		$this->assertStringNotContainsString('550e8400', $this->mounts->created[0]['targetPath']);
		$this->assertSame(['alice'], $this->mounts->mounts[0]->getApplicableUsers());
		$this->assertSame([], $this->mounts->mounts[0]->getApplicableGroups());
		$this->assertTrue($this->mounts->created[0]['readOnly']);
	}

	public function testUnsafePathFromUidIsRejectedBeforeMountLookup(): void {
		$this->adminConfigService->method('getAdminConfig')->willReturn([
			AdminConfigService::KEY_HOST_PATH_TEMPLATE => '/srv/immich/originals/library/{storageLabel}',
			AdminConfigService::KEY_NC_VISIBLE_PATH_TEMPLATE => '/mnt/immich-library/{uid}',
			AdminConfigService::KEY_MOUNT_NAME_TEMPLATE => 'Immich/{uid}',
			AdminConfigService::KEY_EXTERNAL_STORAGE_AUTO_CREATE => false,
			AdminConfigService::KEY_MKDIR_POLICY_ENABLED => false,
		]);
		$this->syncStateService->method('findByUid')->with('../alice')->willReturn($this->state('../alice', 'alice'));

		$result = $this->service()->verifyMount('../alice');

		$this->assertSame('unsafe_path', $result['status']);
		$this->assertFalse($result['configured']);
		$this->assertNotSame([], $result['errors']);
		$this->assertSame([], $this->mounts->created);
	}

	public function testMissingFolderIsPendingWhenMkdirPolicyDisabled(): void {
		$this->mockConfig(true, false);
		$this->mockAutoCreateCapability(true);
		$this->syncStateService->method('findByUid')->with('alice')->willReturn($this->state('alice', 'alice'));
		$this->markPath('/srv/immich/originals/library/alice', false, false);
		$this->markPath('/mnt/immich-library/alice', false, false);

		$result = $this->service()->provisionMount('alice');

		$this->assertSame('mount_pending', $result['status']);
		$this->assertFalse($result['exists']);
		$this->assertFalse($result['configured']);
		$this->assertSame([], $this->mounts->created);
	}

	public function testSameNamedMountWithWrongTargetIsMisconfigured(): void {
		$this->mockConfig(false, false);
		$this->syncStateService->method('findByUid')->with('alice')->willReturn($this->state('alice', 'alice'));
		$this->markPath('/srv/immich/originals/library/alice', true, true);
		$this->markPath('/mnt/immich-library/alice', true, true);
		$this->mounts->mounts[] = new FakeStorageMount(42, '/Immich Photos', '/mnt/immich-library/bob', ['readonly' => true], ['alice'], []);

		$result = $this->service()->verifyMount('alice');

		$this->assertSame('misconfigured', $result['status']);
		$this->assertFalse($result['target_matches']);
		$this->assertStringContainsString('/mnt/immich-library/alice', $result['remediation']);
	}

	public function testMountAtDifferentRootNameIsNotAcceptedByTargetOnly(): void {
		$this->mockConfig(false, false);
		$this->syncStateService->method('findByUid')->with('alice')->willReturn($this->state('alice', 'alice'));
		$this->markPath('/srv/immich/originals/library/alice', true, true);
		$this->markPath('/mnt/immich-library/alice', true, true);
		$this->mounts->mounts[] = new FakeStorageMount(42, '/Photos', '/mnt/immich-library/alice', ['readonly' => true], ['alice'], []);

		$result = $this->service()->verifyMount('alice');

		$this->assertSame('template_verification_required', $result['status']);
		$this->assertFalse($result['configured']);
	}

	public function testGlobalMountIsMisconfiguredEvenWhenReadOnlyAndTargetMatches(): void {
		$this->mockConfig(false, false);
		$this->syncStateService->method('findByUid')->with('alice')->willReturn($this->state('alice', 'alice'));
		$this->markPath('/srv/immich/originals/library/alice', true, true);
		$this->markPath('/mnt/immich-library/alice', true, true);
		$this->mounts->mounts[] = new FakeStorageMount(42, '/Immich Photos', '/mnt/immich-library/alice', ['readonly' => true], [], []);

		$result = $this->service()->verifyMount('alice');

		$this->assertSame('misconfigured', $result['status']);
		$this->assertFalse($result['available_only_to_uid']);
	}

	public function testAutoCreateUnavailableReturnsManualRemediation(): void {
		$this->mockConfig(true, false);
		$this->mockAutoCreateCapability(false);
		$this->syncStateService->method('findByUid')->with('alice')->willReturn($this->state('alice', 'alice'));
		$this->markPath('/srv/immich/originals/library/alice', true, true);
		$this->markPath('/mnt/immich-library/alice', true, true);

		$result = $this->service()->provisionMount('alice');

		$this->assertSame('manual_setup_required', $result['status']);
		$this->assertStringContainsString('missing', implode('; ', $result['errors']));
		$this->assertStringContainsString('configure manually', $result['remediation']);
		$this->assertSame([], $this->mounts->created);
	}

	public function testBlockedLocalBackendReturnsManualSetupRequired(): void {
		$this->mounts = new FakeExternalStorageConfigService(false);
		$this->mockConfig(true, false);
		$this->mockAutoCreateCapability(true);
		$this->syncStateService->method('findByUid')->with('alice')->willReturn($this->state('alice', 'alice'));
		$this->markPath('/srv/immich/originals/library/alice', true, true);
		$this->markPath('/mnt/immich-library/alice', true, true);

		$result = $this->service()->provisionMount('alice');

		$this->assertSame('manual_setup_required', $result['status']);
		$this->assertStringContainsString('Local backend', $result['remediation']);
		$this->assertStringContainsString('/Immich Photos', $result['remediation']);
		$this->assertStringContainsString('/mnt/immich-library/alice', $result['remediation']);
		$this->assertStringContainsString('user "alice"', $result['remediation']);
		$this->assertSame([], $this->mounts->created);
	}

	public function testMkdirPolicyCreatesEmptyDirectoryThenReadOnlyUserScopedMount(): void {
		$this->mockConfig(true, true);
		$this->mockAutoCreateCapability(true);
		$this->syncStateService->method('findByUid')->with('alice')->willReturn($this->state('alice', 'alice'));
		$this->syncStateService->method('findByMountId')->with(99)->willReturn(null);
		$this->syncStateService->expects($this->once())->method('getOrCreateForUid')->with('alice');
		$this->syncStateService->expects($this->once())->method('updateMapping')->with('alice', ['ncMountId' => 99]);
		$this->markPath('/srv/immich/originals/library/alice', false, false);
		$this->markPath('/mnt/immich-library/alice', false, false);

		$result = $this->service()->provisionMount('alice');

		$this->assertSame('ok', $result['status']);
		$this->assertTrue($result['configured']);
		$this->assertTrue($result['exists']);
		$this->assertSame([
			[
				'uid' => 'alice',
				'mountName' => '/Immich Photos',
				'targetPath' => '/mnt/immich-library/alice',
				'readOnly' => true,
				'knownMountId' => null,
			],
		], $this->mounts->created);
	}

	public function testExistingSymlinkSegmentIsRejectedAsUnsafePath(): void {
		$this->mockConfig(false, false);
		$this->syncStateService->method('findByUid')->with('alice')->willReturn($this->state('alice', 'alice'));
		$this->filesystem['/mnt'] = ['exists' => true, 'readable' => true, 'link' => false];
		$this->filesystem['/mnt/immich-library'] = ['exists' => true, 'readable' => true, 'link' => true];

		$result = $this->service()->verifyMount('alice');

		$this->assertSame('unsafe_path', $result['status']);
		$this->assertStringContainsString('symbolic links', implode('; ', $result['errors']));
	}

	private function service(): ExternalStorageProvisioner {
		return new ExternalStorageProvisioner(
			$this->adminConfigService,
			$this->capabilityService,
			new PathTemplateService(),
			$this->syncStateService,
			$this->createMock(LoggerInterface::class),
			$this->mounts,
			function (string $operation, string $path): bool {
				$path = str_replace('\\', '/', $path);
				return match ($operation) {
					'exists' => (bool)($this->filesystem[$path]['exists'] ?? false),
					'readable' => (bool)($this->filesystem[$path]['readable'] ?? false),
					'is_link' => (bool)($this->filesystem[$path]['link'] ?? false),
					'mkdir' => $this->markPath($path, true, true),
					default => false,
				};
			},
		);
	}

	private function mockConfig(bool $autoCreate, bool $mkdir, string $storageLabelTemplate = '{uid}'): void {
		$this->adminConfigService->method('getAdminConfig')->willReturn([
			AdminConfigService::KEY_STORAGE_LABEL_TEMPLATE => $storageLabelTemplate,
			AdminConfigService::KEY_HOST_PATH_TEMPLATE => '/srv/immich/originals/library/{storageLabel}',
			AdminConfigService::KEY_NC_VISIBLE_PATH_TEMPLATE => '/mnt/immich-library/{storageLabel}',
			AdminConfigService::KEY_MOUNT_NAME_TEMPLATE => 'Immich Photos',
			AdminConfigService::KEY_EXTERNAL_STORAGE_AUTO_CREATE => $autoCreate,
			AdminConfigService::KEY_MKDIR_POLICY_ENABLED => $mkdir,
		]);
	}

	private function mockAutoCreateCapability(bool $supported): void {
		$this->capabilityService->method('getCapabilities')->willReturn([
			'nextcloudExternalStorageAutoCreate' => [
				'supported' => $supported,
				'reason' => $supported ? 'available' : 'missing',
				'remediation' => $supported ? '' : 'configure manually',
			],
		]);
	}

	private function state(string $uid, string $storageLabel): SyncState {
		$state = new SyncState();
		$state->setNcUid($uid);
		$state->setStorageLabel($storageLabel);
		return $state;
	}

	private function markPath(string $path, bool $exists, bool $readable): bool {
		$this->filesystem[str_replace('\\', '/', $path)] = [
			'exists' => $exists,
			'readable' => $readable,
			'link' => false,
		];
		return true;
	}
}

final class FakeExternalStorageConfigService {
	/** @var list<FakeStorageMount> */
	public array $mounts = [];
	/** @var list<array{uid: string, mountName: string, targetPath: string, readOnly: bool, knownMountId: int|null}> */
	public array $created = [];

	public function __construct(private bool $allowCreate = true) {
	}

	/** @return list<FakeStorageMount> */
	public function getUserStorages(string $uid): array {
		return $this->mounts;
	}

	public function createOrUpdateLocalMount(string $uid, string $mountName, string $targetPath, bool $readOnly, ?int $knownMountId): FakeStorageMount {
		if (!$this->allowCreate) {
			throw new \RuntimeException('Local backend is blocked by the current Nextcloud external storage configuration.');
		}

		$this->created[] = compact('uid', 'mountName', 'targetPath', 'readOnly', 'knownMountId');
		$mount = new FakeStorageMount($knownMountId ?? 99, $mountName, $targetPath, ['readonly' => $readOnly], [$uid], []);
		$this->mounts[] = $mount;
		return $mount;
	}
}

final class FakeStorageMount {
	public function __construct(
		private int $id,
		private string $mountPoint,
		private string $targetPath,
		private array $mountOptions,
		private array $users,
		private array $groups,
	) {
	}

	public function getId(): int {
		return $this->id;
	}

	public function getMountPoint(): string {
		return $this->mountPoint;
	}

	public function getBackendOptions(): array {
		return ['datadir' => $this->targetPath];
	}

	public function getMountOptions(): array {
		return $this->mountOptions;
	}

	public function getApplicableUsers(): array {
		return $this->users;
	}

	public function getApplicableGroups(): array {
		return $this->groups;
	}
}
