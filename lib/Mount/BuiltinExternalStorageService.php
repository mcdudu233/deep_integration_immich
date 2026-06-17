<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\IntegrationImmich\Mount;

use OCA\IntegrationImmich\AppInfo\Application;
use OCA\IntegrationImmich\Db\SyncState;
use OCA\IntegrationImmich\Service\AdminConfigService;
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
		private ?AdminConfigService $adminConfigService = null,
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

		$descriptor = $this->descriptorForState($state);
		if ($descriptor === null) {
			// We have a persisted mount id but no admin config to rebuild paths from.
			// Returning an opaque entry still lets ExternalStorageProvisioner detect that the
			// mount exists; callers that need the path information will fall back to inspectMount.
			return new BuiltinStorageMount(
				$mountId,
				'',
				'',
				$state->getNcUid(),
				true,
			);
		}

		return new BuiltinStorageMount(
			$mountId,
			$descriptor['mount_point'],
			$descriptor['target_path'],
			$state->getNcUid(),
			true,
		);
	}

	/**
	 * @return array{mount_point: string, target_path: string}|null
	 */
	private function descriptorForState(SyncState $state): ?array {
		if ($this->adminConfigService === null) {
			return null;
		}

		try {
			$config = $this->adminConfigService->getAdminConfig();
			$storageLabel = $this->storageLabelForState($state, $config);

			$mountTemplate = trim((string)($config[AdminConfigService::KEY_MOUNT_NAME_TEMPLATE] ?? 'Immich Photos'));
			$ncVisible = trim((string)($config[AdminConfigService::KEY_NC_VISIBLE_PATH_TEMPLATE] ?? ''));
			$hostPath = trim((string)($config[AdminConfigService::KEY_HOST_PATH_TEMPLATE] ?? ''));
			$pathTemplate = $ncVisible !== '' ? $ncVisible : $hostPath;
			if ($mountTemplate === '' || $pathTemplate === '') {
				return null;
			}

			$mountName = $this->normalizeMountPoint(
				$this->pathTemplateService->expandPathTemplate($mountTemplate, $state->getNcUid(), $storageLabel),
			);
			$targetPath = $this->pathTemplateService->expandPathTemplate($pathTemplate, $state->getNcUid(), $storageLabel);

			return [
				'mount_point' => $mountName,
				'target_path' => $targetPath,
			];
		} catch (\Throwable $e) {
			$this->logger->debug('Failed to rebuild built-in mount descriptor for user "' . $state->getNcUid() . '": ' . $e->getMessage(), [
				'app' => Application::APP_ID,
				'ncUid' => $state->getNcUid(),
			]);
			return null;
		}
	}

	private function storageLabelForState(SyncState $state, array $config): string {
		$label = trim((string)$state->getStorageLabel());
		if ($label !== '') {
			return $this->pathTemplateService->sanitizeStorageLabel($label);
		}

		$template = (string)($config[AdminConfigService::KEY_STORAGE_LABEL_TEMPLATE] ?? '{uid}');
		return $this->pathTemplateService->expandStorageLabelTemplate($template, $state->getNcUid());
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
