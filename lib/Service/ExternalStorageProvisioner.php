<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\IntegrationImmich\Service;

use OCA\IntegrationImmich\AppInfo\Application;
use OCA\IntegrationImmich\Db\SyncState;
use OCA\IntegrationImmich\Mount\BuiltinExternalStorageService;
use OCP\Files\Config\IMountProviderCollection;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class ExternalStorageProvisioner {
	private const EXTERNAL_MOUNT_POINT_INTERFACE = 'OCP\\Files\\External\\IExternalMountPoint';
	private const STORAGE_CONFIG_CLASS = 'OCA\\Files_External\\Lib\\StorageConfig';

	/** @var callable|null */
	private $filesystem;

	/**
	 * @param object|null $externalStorageConfigService Optional adapter around Nextcloud files_external APIs.
	 * @param callable|null $filesystem Test seam receiving (string $operation, string $path, mixed ...$args): mixed.
	 */
	public function __construct(
		private AdminConfigService $adminConfigService,
		private CapabilityService $capabilityService,
		private PathTemplateService $pathTemplateService,
		private SyncStateService $syncStateService,
		private LoggerInterface $logger,
		private ?object $externalStorageConfigService = null,
		?callable $filesystem = null,
		private ?IMountProviderCollection $mountProviderCollection = null,
		private ?IUserManager $userManager = null,
		private ?BuiltinExternalStorageService $builtinStorageService = null,
	) {
		$this->filesystem = $filesystem;
		$this->externalStorageConfigService ??= $this->builtinStorageService ?? new NextcloudExternalStorageConfigService();
	}

	public function verifyMount(string $ncUid): array {
		$result = $this->inspectMount($ncUid);
		if (is_int($result['mount_id'])) {
			$this->persistMountIdIfMappingExists($ncUid, $result['mount_id']);
		}

		return $result;
	}

	public function provisionMount(string $ncUid): array {
		$result = $this->inspectMount($ncUid);
		if ($result['status'] === 'ok') {
			$this->persistMountId($ncUid, $result['mount_id']);
			return $result;
		}

		$config = $this->adminConfigService->getAdminConfig();
		if (!$this->boolConfig($config, AdminConfigService::KEY_EXTERNAL_STORAGE_AUTO_CREATE)) {
			$result['status'] = $result['configured'] ? $result['status'] : 'template_verification_required';
			$result['remediation'] = 'Create a per-user read-only Local External Storage mount from the configured template, scoped only to this Nextcloud user, then rerun verification.';
			return $result;
		}

		$capability = $this->externalStorageAutoCreateCapability();
		if (!($capability['supported'] ?? false)) {
			$result['status'] = 'manual_setup_required';
			$result['errors'][] = (string)($capability['reason'] ?? 'Nextcloud external storage auto-create capability is unavailable.');
			$result['remediation'] = (string)($capability['remediation'] ?? $this->manualSetupRemediation($ncUid, (string)$result['mount_name'], (string)$result['target_path']));
			return $result;
		}

		if (!$result['exists']) {
			if (!$this->boolConfig($config, AdminConfigService::KEY_MKDIR_POLICY_ENABLED)) {
				$result['status'] = 'mount_pending';
				$result['remediation'] = 'The Immich library folder does not exist yet. Upload through Immich first, then rerun mount provisioning or configure the read-only mount manually.';
				return $result;
			}

			try {
				$this->createDirectory($result['target_path']);
			} catch (\Throwable $e) {
				$result['status'] = 'mount_pending';
				$result['errors'][] = 'Failed to create pending library directory: ' . $e->getMessage();
				return $result;
			}
		}

		try {
			$mount = $this->createOrUpdateLocalMount($ncUid, (string)$result['mount_name'], (string)$result['target_path'], $result['mount_id']);
			if ($mount === null) {
				$result['status'] = 'manual_setup_required';
				$result['errors'][] = 'No stable writable Nextcloud external-storage API adapter is available to create or update Local mounts.';
				$result['remediation'] = $this->manualSetupRemediation($ncUid, (string)$result['mount_name'], (string)$result['target_path']);
				return $result;
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Nextcloud external storage mount provisioning failed for user "' . $ncUid . '": ' . $e->getMessage(), [
				'app' => Application::APP_ID,
				'ncUid' => $ncUid,
			]);

			$result['status'] = 'manual_setup_required';
			$result['errors'][] = $e->getMessage();
			$result['remediation'] = $this->manualSetupRemediation($ncUid, (string)$result['mount_name'], (string)$result['target_path']);
			return $result;
		}

		$result = $this->inspectMount($ncUid, $mount);
		if (is_int($result['mount_id'])) {
			$this->persistMountId($ncUid, $result['mount_id']);
		}

		return $result;
	}

	private function inspectMount(string $ncUid, ?object $knownMount = null): array {
		$result = $this->emptyResult($ncUid);

		try {
			$state = $this->syncStateService->findByUid($ncUid);
			$config = $this->adminConfigService->getAdminConfig();
			$storageLabel = $this->storageLabelForUid($ncUid, $state, $config);

			$hostPath = $this->expandAndValidateConfiguredPath((string)($config[AdminConfigService::KEY_HOST_PATH_TEMPLATE] ?? ''), $ncUid, $storageLabel);
			$targetPath = $this->expandAndValidateConfiguredPath((string)($config[AdminConfigService::KEY_NC_VISIBLE_PATH_TEMPLATE] ?? ''), $ncUid, $storageLabel);
			$mountName = $this->expandMountName((string)($config[AdminConfigService::KEY_MOUNT_NAME_TEMPLATE] ?? 'Immich Photos'), $ncUid, $storageLabel);

			$this->assertNoExistingSymlinkSegment($hostPath);
			$this->assertNoExistingSymlinkSegment($targetPath);

			$result['host_path'] = $hostPath;
			$result['target_path'] = $targetPath;
			$result['mount_name'] = $mountName;
			$result['exists'] = $this->pathExists($targetPath);
			$result['readable'] = $result['exists'] && $this->pathReadable($targetPath);
		} catch (\Throwable $e) {
			$result['status'] = 'unsafe_path';
			$result['errors'][] = $e->getMessage();
			return $result;
		}

		$mount = $knownMount ?? $this->findMatchingMount($ncUid, (string)$result['mount_name'], (string)$result['target_path']);
		if ($mount === null) {
			$result['status'] = $result['exists'] ? 'template_verification_required' : 'mount_pending';
			$result['remediation'] = $result['exists']
				? 'Create a read-only Local External Storage mount for this path and scope it only to the matching user.'
				: 'The Immich library folder is pending; wait for Immich to create it, then rerun verification.';
			return $result;
		}

		$result['configured'] = true;
		$result['mount_id'] = $this->mountId($mount);
		$result['target_matches'] = $this->mountTargetMatches($mount, (string)$result['target_path']);
		$result['read_only'] = $this->mountIsReadOnly($mount);
		$result['available_only_to_uid'] = $this->mountIsOnlyAvailableToUid($mount, $ncUid);
		$result['not_root_storage'] = $this->mountIsNotRootStorage($mount);

		if ($result['exists'] && $result['readable'] && $result['target_matches'] && $result['read_only'] && $result['available_only_to_uid'] && $result['not_root_storage']) {
			$result['status'] = 'ok';
		} elseif (!$result['exists']) {
			$result['status'] = 'mount_pending';
		} else {
			$result['status'] = 'misconfigured';
			$result['remediation'] = 'Adjust the Local External Storage mount so it is named exactly "' . (string)$result['mount_name'] . '", points to "' . (string)$result['target_path'] . '", is read-only, is not root storage, and is available only to this user.';
		}

		return $result;
	}

	private function emptyResult(string $ncUid): array {
		return [
			'ncUid' => $ncUid,
			'configured' => false,
			'exists' => false,
			'readable' => false,
			'read_only' => false,
			'available_only_to_uid' => false,
			'not_root_storage' => false,
			'target_matches' => false,
			'mount_id' => null,
			'status' => 'unknown',
			'errors' => [],
			'remediation' => '',
			'host_path' => null,
			'target_path' => null,
			'mount_name' => null,
		];
	}

	private function storageLabelForUid(string $ncUid, ?SyncState $state, array $config): string {
		$label = trim((string)($state?->getStorageLabel() ?? ''));
		if ($label !== '') {
			return $this->pathTemplateService->sanitizeStorageLabel($label);
		}

		$template = (string)($config[AdminConfigService::KEY_STORAGE_LABEL_TEMPLATE] ?? '{uid}');
		return $this->pathTemplateService->expandStorageLabelTemplate($template, $ncUid);
	}

	private function expandAndValidateConfiguredPath(string $template, string $ncUid, string $storageLabel): string {
		$template = trim($template);
		if ($template === '') {
			throw new \InvalidArgumentException('External storage path templates must be configured before mount verification.');
		}

		$expanded = $this->pathTemplateService->expandPathTemplate($template, $ncUid, $storageLabel);
		$this->pathTemplateService->validatePathUnderBase($expanded, $this->basePathFromTemplate($template));

		return $this->normalizePath($expanded);
	}

	private function expandMountName(string $template, string $ncUid, string $storageLabel): string {
		$mountName = $this->pathTemplateService->expandPathTemplate($template, $ncUid, $storageLabel);
		$mountName = '/' . trim($mountName, '/');
		if ($mountName === '/') {
			throw new \InvalidArgumentException('External storage mount name must not be root storage.');
		}

		return $mountName;
	}

	private function basePathFromTemplate(string $template): string {
		$template = str_replace('\\', '/', trim($template));
		$placeholderPosition = strpos($template, '{');
		$prefix = $placeholderPosition === false ? $template : substr($template, 0, $placeholderPosition);
		$prefix = rtrim($prefix, '/');

		if ($prefix === '' || preg_match('/\A[A-Za-z]:\z/', $prefix) === 1) {
			throw new \InvalidArgumentException('Path template must include a non-root base path before placeholders.');
		}

		if ($placeholderPosition === false) {
			$directory = dirname($prefix);
			return $directory === '.' ? $prefix : $directory;
		}

		return $prefix;
	}

	private function findMatchingMount(string $ncUid, string $mountName, string $targetPath): ?object {
		foreach ($this->mountCandidates($ncUid) as $mount) {
			if (!is_object($mount)) {
				continue;
			}

			$mountTarget = $this->mountTargetPath($mount);
			$mountPoint = $this->normalizeMountPoint((string)$this->callMethod($mount, 'getMountPoint', ''));
			if ($mountTarget !== null && $this->pathsEqual($mountTarget, $targetPath) && $mountPoint === $mountName) {
				return $mount;
			}

			if ($mountPoint === $mountName && $this->mountLooksLocal($mount)) {
				return $mount;
			}
		}

		return null;
	}

	/** @return list<object> */
	private function mountCandidates(string $ncUid): array {
		$service = $this->externalStorageConfigService;
		if ($service === null) {
			return $this->mountProviderCandidates($ncUid);
		}

		if (method_exists($service, 'findLocalMountForUser')) {
			$mount = $service->findLocalMountForUser($ncUid);
			return is_object($mount) ? [$mount] : $this->mountProviderCandidates($ncUid);
		}

		$candidates = [];
		foreach (['getUserStorages' => [$ncUid], 'getMountsForUser' => [$ncUid], 'listMountsForUser' => [$ncUid], 'getAdminStorages' => [], 'getAllStorages' => [], 'getStorages' => []] as $method => $arguments) {
			if (!method_exists($service, $method)) {
				continue;
			}

			try {
				$storages = $service->{$method}(...$arguments);
			} catch (\ArgumentCountError) {
				continue;
			}

			if (is_iterable($storages)) {
				foreach ($storages as $storage) {
					if (is_object($storage)) {
						$candidates[] = $storage;
					}
				}
			}
		}

		return $candidates !== [] ? $candidates : $this->mountProviderCandidates($ncUid);
	}

	/** @return list<object> */
	private function mountProviderCandidates(string $ncUid): array {
		if ($this->mountProviderCollection === null || $this->userManager === null) {
			return [];
		}

		$user = $this->userManager->get($ncUid);
		if ($user === null) {
			return [];
		}

		try {
			$mounts = $this->mountProviderCollection->getMountsForUser($user);
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to inspect Nextcloud mounts for user "' . $ncUid . '": ' . $e->getMessage(), [
				'app' => Application::APP_ID,
				'ncUid' => $ncUid,
			]);
			return [];
		}

		return array_values(array_filter(is_array($mounts) ? $mounts : [], static fn(mixed $mount): bool => is_object($mount)));
	}

	private function createOrUpdateLocalMount(string $ncUid, string $mountName, string $targetPath, ?int $knownMountId): ?object {
		$service = $this->externalStorageConfigService;
		if ($service === null) {
			return null;
		}

		if (method_exists($service, 'createOrUpdateLocalMount')) {
			$mount = $service->createOrUpdateLocalMount($ncUid, $mountName, $targetPath, true, $knownMountId);
			return is_object($mount) ? $mount : null;
		}

		if (method_exists($service, 'updateLocalMount')) {
			$mount = $service->updateLocalMount($ncUid, $mountName, $targetPath, true, $knownMountId);
			return is_object($mount) ? $mount : null;
		}

		if (interface_exists(self::EXTERNAL_MOUNT_POINT_INTERFACE) || class_exists(self::STORAGE_CONFIG_CLASS)) {
			$this->logger->warning('Nextcloud external-storage symbols are present, but no stable app adapter was injected for Local mount creation.', [
				'app' => Application::APP_ID,
				'ncUid' => $ncUid,
			]);
		}

		return null;
	}

	private function manualSetupRemediation(string $ncUid, string $mountName, string $targetPath): string {
		return 'Enable the built-in Immich library mount provider in this app, or enable the Nextcloud External storage app with the Local backend available, then create or update a read-only Local mount named "' . $mountName . '" pointing to "' . $targetPath . '" and make it applicable only to user "' . $ncUid . '" with no applicable groups.';
	}

	private function mountTargetPath(object $mount): ?string {
		$options = $this->callMethod($mount, 'getBackendOptions', []);
		if (is_array($options)) {
			foreach (['datadir', 'directory', 'path'] as $key) {
				if (isset($options[$key]) && is_string($options[$key]) && trim($options[$key]) !== '') {
					return $this->normalizePath($options[$key]);
				}
			}
		}

		$storage = $this->callMethod($mount, 'getStorage', null);
		if (is_object($storage)) {
			foreach (['getSourcePath', 'getDatadir', 'getDataDir'] as $method) {
				$path = $this->callMethod($storage, $method, null);
				if (is_string($path) && trim($path) !== '') {
					return $this->normalizePath($path);
				}
			}
		}

		return null;
	}

	private function mountLooksLocal(object $mount): bool {
		if ($this->mountTargetPath($mount) !== null) {
			return true;
		}

		$backend = $this->callMethod($mount, 'getBackend', null);
		if (is_object($backend)) {
			foreach (['getIdentifier', 'getClass', '__toString'] as $method) {
				if (method_exists($backend, $method) && stripos((string)$backend->{$method}(), 'local') !== false) {
					return true;
				}
			}
		}

		return is_string($backend) && stripos($backend, 'local') !== false;
	}

	private function mountIsReadOnly(object $mount): bool {
		$options = $this->callMethod($mount, 'getMountOptions', []);
		if (!is_array($options)) {
			$options = [];
		}

		foreach (['readonly', 'read_only', 'readOnly'] as $key) {
			if (array_key_exists($key, $options)) {
				return filter_var($options[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
			}
		}


		return filter_var($this->mountOption($mount, 'readonly', false), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
	}

	private function mountTargetMatches(object $mount, string $targetPath): bool {
		$mountTarget = $this->mountTargetPath($mount);
		return $mountTarget !== null && $this->pathsEqual($mountTarget, $targetPath);
	}

	private function mountIsOnlyAvailableToUid(object $mount, string $ncUid): bool {
		$users = $this->callMethod($mount, 'getApplicableUsers', null);
		$groups = $this->callMethod($mount, 'getApplicableGroups', []);

		if (!is_array($users)) {
			$users = [];
		}

		$users = array_values(array_map('strval', $users));
		$groups = is_array($groups) ? array_values($groups) : [];

		if ($users === [$ncUid] && $groups === []) {
			return true;
		}

		$mountPoint = $this->normalizeMountPoint((string)$this->callMethod($mount, 'getMountPoint', ''));
		return str_starts_with($mountPoint, '/' . trim($ncUid, '/') . '/files/') && $groups === [];
	}

	private function mountIsNotRootStorage(object $mount): bool {
		$mountPoint = $this->normalizeMountPoint((string)$this->callMethod($mount, 'getMountPoint', ''));
		return $mountPoint !== '/' && $mountPoint !== '';
	}

	private function mountId(object $mount): ?int {
		$id = $this->callMethod($mount, 'getId', null);
		return is_numeric($id) ? (int)$id : null;
	}

	private function callMethod(object $object, string $method, mixed $default): mixed {
		if (!method_exists($object, $method)) {
			return $default;
		}

		try {
			return $object->{$method}();
		} catch (\Throwable) {
			return $default;
		}
	}

	private function mountOption(object $mount, string $key, mixed $default): mixed {
		if (!method_exists($mount, 'getOption')) {
			return $default;
		}

		try {
			return $mount->getOption($key, $default);
		} catch (\Throwable) {
			return $default;
		}
	}

	private function externalStorageAutoCreateCapability(): array {
		$capabilities = $this->capabilityService->getCapabilities();
		$capability = $capabilities['nextcloudExternalStorageAutoCreate'] ?? null;

		return is_array($capability) ? $capability : [
			'supported' => false,
			'reason' => 'Nextcloud external storage auto-create capability is absent.',
			'remediation' => 'Configure mounts manually and use verification mode.',
		];
	}

	private function persistMountId(string $ncUid, mixed $mountId): void {
		if (!is_int($mountId)) {
			return;
		}

		if (!$this->mountIdMayBePersisted($ncUid, $mountId)) {
			return;
		}

		$this->syncStateService->getOrCreateForUid($ncUid);
		$this->syncStateService->updateMapping($ncUid, ['ncMountId' => $mountId]);
	}

	private function persistMountIdIfMappingExists(string $ncUid, int $mountId): void {
		try {
			if ($this->syncStateService->findByUid($ncUid) !== null && $this->mountIdMayBePersisted($ncUid, $mountId)) {
				$this->syncStateService->updateMapping($ncUid, ['ncMountId' => $mountId]);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to persist verified Nextcloud mount id for user "' . $ncUid . '": ' . $e->getMessage(), [
				'app' => Application::APP_ID,
				'ncUid' => $ncUid,
			]);
		}
	}

	private function mountIdMayBePersisted(string $ncUid, int $mountId): bool {
		$owner = $this->syncStateService->findByMountId($mountId);
		if ($owner === null || $owner->getNcUid() === $ncUid) {
			return true;
		}

		$this->logger->warning('Verified Nextcloud mount id ' . $mountId . ' for user "' . $ncUid . '" is already mapped to user "' . $owner->getNcUid() . '"; leaving sync state unchanged.', [
			'app' => Application::APP_ID,
			'ncUid' => $ncUid,
		]);
		return false;
	}

	private function pathExists(string $path): bool {
		return (bool)$this->filesystemCall('exists', $path, file_exists(...));
	}

	private function pathReadable(string $path): bool {
		return (bool)$this->filesystemCall('readable', $path, is_readable(...));
	}

	private function pathIsLink(string $path): bool {
		return (bool)$this->filesystemCall('is_link', $path, is_link(...));
	}

	private function createDirectory(string $path): void {
		if ($this->pathExists($path)) {
			return;
		}

		$created = $this->filesystemCall('mkdir', $path, static fn (string $directory): bool => mkdir($directory, 0750, true));
		if ($created !== true && !$this->pathExists($path)) {
			throw new \RuntimeException('mkdir returned false for ' . $path);
		}
	}

	private function filesystemCall(string $operation, string $path, callable $fallback): mixed {
		if (is_callable($this->filesystem)) {
			return ($this->filesystem)($operation, $path);
		}

		return $fallback($path);
	}

	private function assertNoExistingSymlinkSegment(string $path): void {
		$path = $this->normalizePath($path);
		$segments = explode('/', $path);
		$current = '';

		foreach ($segments as $index => $segment) {
			if ($segment === '') {
				$current = '/';
				continue;
			}

			if ($index === 0 && preg_match('/\A[A-Za-z]:\z/', $segment) === 1) {
				$current = $segment . '/';
				continue;
			}

			$current = rtrim($current, '/') . '/' . $segment;
			if (!$this->pathExists($current)) {
				return;
			}

			if ($this->pathIsLink($current)) {
				throw new \InvalidArgumentException('External storage path must not traverse symbolic links.');
			}
		}
	}

	private function normalizePath(string $path): string {
		$path = str_replace('\\', '/', $path);
		$prefix = '';
		$remainder = $path;

		if (preg_match('/\A[A-Za-z]:\//', $path) === 1) {
			$prefix = substr($path, 0, 3);
			$remainder = substr($path, 3);
		} elseif (str_starts_with($path, '/')) {
			$prefix = '/';
			$remainder = ltrim($path, '/');
		}

		$segments = [];
		foreach (explode('/', $remainder) as $segment) {
			if ($segment === '' || $segment === '.') {
				continue;
			}
			if ($segment === '..') {
				throw new \InvalidArgumentException('Path must not contain traversal segments.');
			}
			$segments[] = $segment;
		}

		return match (true) {
			$prefix === '/' => '/' . implode('/', $segments),
			$prefix !== '' => $prefix . implode('/', $segments),
			default => implode('/', $segments),
		};
	}

	private function normalizeMountPoint(string $mountPoint): string {
		$mountPoint = trim(str_replace('\\', '/', $mountPoint));
		return '/' . trim($mountPoint, '/');
	}

	private function pathsEqual(string $left, string $right): bool {
		return rtrim($this->normalizePath($left), '/') === rtrim($this->normalizePath($right), '/');
	}

	private function boolConfig(array $config, string $key): bool {
		return filter_var($config[$key] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
	}
}
