<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\BackgroundJob;

use OCA\IntegrationImmich\BackgroundJob\ProvisionNextcloudMountJob;
use OCA\IntegrationImmich\Service\ExternalStorageProvisioner;
use OCA\IntegrationImmich\Service\SyncStateService;
use PHPUnit\Framework\MockObject\MockObject;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class ProvisionNextcloudMountJobTest extends TestCase {
	private ExternalStorageProvisioner&MockObject $provisioner;
	private SyncStateService&MockObject $syncStateService;

	protected function setUp(): void {
		parent::setUp();

		$this->provisioner = $this->createMock(ExternalStorageProvisioner::class);
		$this->syncStateService = $this->createMock(SyncStateService::class);
	}

	public function testRunProvisionsMountAndPersistsMountId(): void {
		$this->provisioner->expects($this->once())->method('provisionMount')->with('alice')->willReturn([
			'status' => 'ok',
			'mount_id' => 42,
			'errors' => [],
		]);
		$this->syncStateService->expects($this->once())->method('getOrCreateForUid')->with('alice');
		$this->syncStateService->expects($this->once())->method('updateMapping')->with('alice', [
			'lastSyncStatus' => SyncStateService::STATUS_ACTIVE,
			'lastError' => null,
			'ncMountId' => 42,
		]);

		$this->job()->execute(['ncUid' => 'alice']);
	}

	public function testRunPersistsPendingStatusWhenMountIsMissing(): void {
		$this->provisioner->expects($this->once())->method('provisionMount')->with('alice')->willReturn([
			'status' => 'template_verification_required',
			'mount_id' => null,
			'errors' => ['Create a read-only Local mount.'],
		]);
		$this->syncStateService->expects($this->once())->method('getOrCreateForUid')->with('alice');
		$this->syncStateService->expects($this->once())->method('updateMapping')->with('alice', [
			'lastSyncStatus' => SyncStateService::STATUS_MOUNT_PENDING,
			'lastError' => 'Create a read-only Local mount.',
		]);

		$this->job()->execute('alice');
	}

	private function job(): TestableProvisionNextcloudMountJob {
		return new TestableProvisionNextcloudMountJob(
			$this->createMock(ITimeFactory::class),
			$this->provisioner,
			$this->syncStateService,
			$this->createMock(LoggerInterface::class),
		);
	}
}

final class TestableProvisionNextcloudMountJob extends ProvisionNextcloudMountJob {
	public function execute(mixed $argument): void {
		$this->run($argument);
	}
}
