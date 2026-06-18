<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\IntegrationImmich\Mount;

use OC\Files\Mount\MountPoint;
use OC\Files\Storage\Local;
use OC\Files\Storage\Wrapper\PermissionsMask;
use OCA\IntegrationImmich\AppInfo\Application;
use OCA\IntegrationImmich\Db\SyncState;
use OCA\IntegrationImmich\Service\AdminConfigService;
use OCA\IntegrationImmich\Service\PathTemplateService;
use OCA\IntegrationImmich\Service\SyncStateService;
use OCP\Constants;
use OCP\Files\Config\IMountProvider;
use OCP\Files\Storage\IStorageFactory;
use OCP\IUser;
use Psr\Log\LoggerInterface;

/**
 * Built-in mount provider that surfaces each Immich user's personal library folder as a
 * read-only mount inside Nextcloud without requiring the files_external app.
 */
class ImmichLibraryMountProvider implements IMountProvider {
	private const READ_ONLY_MASK = Constants::PERMISSION_READ;
	private const MOUNT_PROVIDER_NAME = 'deep_integration_immich:library';

	/** @var callable */
	private $pathExists;

	/**
	 * @param callable|null $pathExists Test seam mapping (string $path): bool.
	 */
	public function __construct(
		private AdminConfigService $adminConfigService,
		private SyncStateService $syncStateService,
		private PathTemplateService $pathTemplateService,
		private LoggerInterface $logger,
		?callable $pathExists = null,
	) {
		$this->pathExists = $pathExists ?? static fn (string $path): bool => file_exists($path);
	}

	/**
	 * @return list<\OCP\Files\Mount\IMountPoint>
	 */
	public function getMountsForUser(IUser $user, IStorageFactory $loader): array {
		$ncUid = $user->getUID();

		try {
			$descriptor = $this->buildDescriptor($ncUid);
		} catch (\Throwable $e) {
			$this->logger->debug('Skipping Immich library mount for user "' . $ncUid . '": ' . $e->getMessage(), [
				'app' => Application::APP_ID,
				'ncUid' => $ncUid,
			]);
			return [];
		}

		if ($descriptor === null) {
			return [];
		}

		$targetExists = ($this->pathExists)($descriptor['target_path']);
		// Only suppress the mount when the Immich library folder has never appeared AND no prior
		// provisioning persisted a mount id. Once mount_id is on record we keep emitting the
		// mount so the Files UI stays consistent with the filecache rows that already drive the
		// user's quota; transient path-stat failures (perm flips, container restarts) must not
		// silently hide a previously working mount.
		if (!$targetExists && $descriptor['mount_id'] === null) {
			$this->logger->debug('Immich library mount target does not exist yet for user "' . $ncUid . '": ' . $descriptor['target_path'], [
				'app' => Application::APP_ID,
				'ncUid' => $ncUid,
			]);
			return [];
		}

		if (!$targetExists) {
			$this->logger->warning('Immich library mount target is missing for user "' . $ncUid . '" but a mount id is persisted; surfacing the mount so the Files UI matches the recorded state. Path: ' . $descriptor['target_path'], [
				'app' => Application::APP_ID,
				'ncUid' => $ncUid,
			]);
		}

		$mountPoint = '/' . $ncUid . '/files' . $descriptor['mount_name'];
		$mountId = $descriptor['mount_id'];

		try {
			$storage = new Local(['datadir' => $descriptor['target_path']]);
			$storage = new PermissionsMask([
				'storage' => $storage,
				'mask' => self::READ_ONLY_MASK,
			]);
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to instantiate Immich library storage for user "' . $ncUid . '": ' . $e->getMessage(), [
				'app' => Application::APP_ID,
				'ncUid' => $ncUid,
			]);
			return [];
		}

		return [
			new MountPoint(
				$storage,
				$mountPoint,
				null,
				$loader,
				[
					'readonly' => true,
					'enable_sharing' => false,
					'previews' => true,
				],
				$mountId,
				self::MOUNT_PROVIDER_NAME,
			),
		];
	}

	/**
	 * @return array{target_path: string, mount_name: string, mount_id: int|null}|null
	 */
	private function buildDescriptor(string $ncUid): ?array {
		$state = $this->syncStateService->findByUid($ncUid);
		if ($state === null) {
			return null;
		}

		if (!$this->scopeIsActive($state)) {
			return null;
		}

		$config = $this->adminConfigService->getAdminConfig();
		$autoCreateEnabled = filter_var($config[AdminConfigService::KEY_EXTERNAL_STORAGE_AUTO_CREATE] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
		// Keep emitting the mount when a previous provisioning run already persisted a mount id,
		// even if the admin later disables auto-create. Hiding a working mount silently leaves the
		// user's quota counting bytes from a folder they can no longer browse in Files.
		if (!$autoCreateEnabled && $state->getNcMountId() === null) {
			return null;
		}

		$pathTemplate = trim((string)($config[AdminConfigService::KEY_NC_VISIBLE_PATH_TEMPLATE] ?? ''));
		$hostTemplate = trim((string)($config[AdminConfigService::KEY_HOST_PATH_TEMPLATE] ?? ''));
		$mountTemplate = trim((string)($config[AdminConfigService::KEY_MOUNT_NAME_TEMPLATE] ?? 'Immich Photos'));

		// In built-in mode the Nextcloud runtime reads from the same host path that Immich writes,
		// so fall back to the host template when no separate NC-visible mount has been configured.
		$effectivePathTemplate = $pathTemplate !== '' ? $pathTemplate : $hostTemplate;
		if ($effectivePathTemplate === '' || $mountTemplate === '') {
			return null;
		}

		$storageLabel = $this->storageLabelForState($state, $config, $ncUid);

		$targetPath = $this->pathTemplateService->expandPathTemplate($effectivePathTemplate, $ncUid, $storageLabel);
		$mountName = $this->normalizeMountName(
			$this->pathTemplateService->expandPathTemplate($mountTemplate, $ncUid, $storageLabel),
		);

		if ($mountName === '/' || $mountName === '') {
			throw new \InvalidArgumentException('Immich library mount must not target the user root.');
		}

		return [
			'target_path' => $targetPath,
			'mount_name' => $mountName,
			'mount_id' => $state->getNcMountId(),
		];
	}

	private function scopeIsActive(SyncState $state): bool {
		$scope = $state->getScopeStatus();
		return $scope !== SyncStateService::STATUS_DISABLED
			&& $scope !== SyncStateService::STATUS_DELETED
			&& $scope !== SyncStateService::STATUS_OUT_OF_SCOPE;
	}

	private function storageLabelForState(SyncState $state, array $config, string $ncUid): string {
		$label = trim((string)$state->getStorageLabel());
		if ($label !== '') {
			return $this->pathTemplateService->sanitizeStorageLabel($label);
		}

		$template = (string)($config[AdminConfigService::KEY_STORAGE_LABEL_TEMPLATE] ?? '{uid}');
		return $this->pathTemplateService->expandStorageLabelTemplate($template, $ncUid);
	}

	private function normalizeMountName(string $expanded): string {
		$expanded = trim(str_replace('\\', '/', $expanded), '/');
		if ($expanded === '') {
			throw new \InvalidArgumentException('Immich library mount name must not be empty.');
		}

		// Mount names like "Immich/{uid}" are admin-controlled subfolders, leave nested.
		// Mount names like "Immich Photos" become "/Immich Photos" inside the user files root.
		return '/' . $expanded;
	}
}
