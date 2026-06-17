<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\IntegrationImmich\Mount;

use OCA\IntegrationImmich\AppInfo\Application;
use OCA\IntegrationImmich\Db\SyncState;
use OCA\IntegrationImmich\Service\PathTemplateService;
use OCA\IntegrationImmich\Service\SyncStateService;
use OCP\Files\Config\IUserMountCache;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * App-owned adapter that fulfils the ExternalStorageProvisioner storage-service contract
 * without depending on the files_external app. Mount records live in oc_sync_state and the
 * actual filesystem mounts are emitted by ImmichLibraryMountProvider.
 */
class BuiltinExternalStorageService {
	private const BACKEND_LABEL = 'deep_integration_immich:builtin_local';

	public function __construct(
		private SyncStateService $syncStateService,
		private PathTemplateService $pathTemplateService,
		private IUserManager $userManager,
		private LoggerInterface $logger,
		private ?IUserMountCache $userMountCache = null,
	) {
	}

	/**
	 * @return list<BuiltinStorageMount>
	 */
	public function getUserStorages(string $ncUid): array {
		$state = $this->syncStateService->findByUid($ncUid);
		if ($state === null) {
			return [];
		}

		$mount = $this->buildMountForState($state);
		return $mount === null ? [] : [$mount];
	}

	public function findLocalMountForUser(string $ncUid): ?BuiltinStorageMount {
		$mounts = $this->getUserStorages($ncUid);
		return $mounts === [] ? null : $mounts[0];
	}

	public function createOrUpdateLocalMount(string $uid, string $mountName, string $targetPath, bool $readOnly, ?int $knownMountId): BuiltinStorageMount {
		if (!$readOnly) {
			throw new \InvalidArgumentException('Immich library mounts must be read-only.');
		}

		$this->assertUidIsUsable($uid);

		$mountId = $knownMountId ?? $this->allocateMountId($uid);

		$this->syncStateService->getOrCreateForUid($uid);
		$this->syncStateService->updateMapping($uid, ['ncMountId' => $mountId]);

		$this->refreshUserCache($uid);

		return new BuiltinStorageMount(
			$mountId,
			$this->normalizeMountPoint($mountName),
			$targetPath,
			$uid,
			$readOnly,
		);
	}

	private function buildMountForState(SyncState $state): ?BuiltinStorageMount {
		$mountId = $state->getNcMountId();
		if ($mountId === null || $mountId < 1) {
			return null;
		}

		return new BuiltinStorageMount(
			$mountId,
			'',
			'',
			$state->getNcUid(),
			true,
		);
	}

	private function allocateMountId(string $ncUid): int {
		// Mount ids only need to be stable for app-internal lookups. Hashing the uid keeps the
		// value collision-resistant across users while staying small enough to fit in int4 columns
		// without persisting an additional sequence.
		$hash = substr(hash('sha256', $ncUid), 0, 8);
		$id = (int)hexdec($hash);
		if ($id < 1) {
			$id = 1;
		}
		// Reserve the int4 positive range so the value fits the existing nc_mount_id schema.
		return $id & 0x7fffffff;
	}

	private function assertUidIsUsable(string $ncUid): void {
		$user = $this->userManager->get($ncUid);
		if ($user === null) {
			throw new \InvalidArgumentException('Cannot create a built-in Immich mount for a missing Nextcloud user "' . $ncUid . '".');
		}
	}

	private function refreshUserCache(string $ncUid): void {
		if ($this->userMountCache === null) {
			return;
		}

		try {
			$user = $this->userManager->get($ncUid);
			if ($user === null) {
				return;
			}

			$this->userMountCache->removeUserMounts($user);
		} catch (\Throwable $e) {
			$this->logger->debug('Failed to refresh user mount cache for "' . $ncUid . '": ' . $e->getMessage(), [
				'app' => Application::APP_ID,
				'ncUid' => $ncUid,
			]);
		}
	}

	private function normalizeMountPoint(string $mountName): string {
		$mountName = trim($mountName, '/');
		return '/' . $mountName;
	}

	public static function backendLabel(): string {
		return self::BACKEND_LABEL;
	}
}
