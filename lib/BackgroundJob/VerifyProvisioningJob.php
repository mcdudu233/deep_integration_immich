<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\IntegrationImmich\BackgroundJob;

use DateTimeInterface;
use OCA\IntegrationImmich\AppInfo\Application;
use OCA\IntegrationImmich\Db\SyncState;
use OCA\IntegrationImmich\Service\AdminConfigService;
use OCA\IntegrationImmich\Service\ExternalStorageProvisioner;
use OCA\IntegrationImmich\Service\ImmichUserAdminService;
use OCA\IntegrationImmich\Service\LockService;
use OCA\IntegrationImmich\Service\SyncStateService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class VerifyProvisioningJob extends QueuedJob {
	private const LOCK_TIMEOUT_SECONDS = 300;

	private ?array $lastResult = null;
	private LockService $lockService;

	public function __construct(
		ITimeFactory $timeFactory,
		private AdminConfigService $adminConfigService,
		private SyncStateService $syncStateService,
		private ExternalStorageProvisioner $externalStorageProvisioner,
		private ImmichUserAdminService $immichUserAdminService,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private LoggerInterface $logger,
		private ?object $lockFactory = null,
		?LockService $lockService = null,
	) {
		parent::__construct($timeFactory);
		$this->lockService = $lockService ?? new LockService($lockFactory, $logger);
	}

	protected function run($argument): void {
		$this->lastResult = $this->verifyFromArgument($argument);
	}

	public function verifyFromArgument(mixed $argument): array {
		$ncUid = null;
		if (is_string($argument) && trim($argument) !== '') {
			$ncUid = trim($argument);
		} elseif (is_array($argument)) {
			foreach (['ncUid', 'uid', 'userId'] as $key) {
				if (isset($argument[$key]) && is_string($argument[$key]) && trim($argument[$key]) !== '') {
					$ncUid = trim($argument[$key]);
					break;
				}
			}
		}

		if ($ncUid !== null) {
			return $this->verifyOneUser($ncUid);
		}

		return $this->verifyAllMappedUsers();
	}

	public function verifyOneUser(string $ncUid): array {
		$ncUid = trim($ncUid);
		if ($ncUid === '') {
			return $this->failedResult('one', ['VerifyProvisioningJob requires a non-empty ncUid argument.']);
		}

		try {
			return $this->lockService->withLock('integration_immich_verify_' . $ncUid, self::LOCK_TIMEOUT_SECONDS, function () use ($ncUid): array {
				return $this->verifyUsers([$ncUid], 'one', false);
			});
		} catch (\Throwable $e) {
			$error = $this->redact($e->getMessage());
			$this->logger->warning('Immich provisioning verification failed for Nextcloud user "' . $ncUid . '": ' . $error, [
				'app' => Application::APP_ID,
				'ncUid' => $ncUid,
			]);

			return $this->failedResult('one', [$error]);
		}
	}

	public function verifyAllMappedUsers(): array {
		try {
			return $this->lockService->withLock('integration_immich_verify_all', self::LOCK_TIMEOUT_SECONDS, function (): array {
				$config = $this->adminConfigService->getAdminConfig();
				return $this->verifyUsers($this->scopedUserIds($config), 'all', true, $config);
			});
		} catch (\Throwable $e) {
			$error = $this->redact($e->getMessage());
			$this->logger->warning('Immich all-user provisioning verification failed: ' . $error, [
				'app' => Application::APP_ID,
			]);

			return $this->failedResult('all', [$error]);
		}
	}

	public function getLastResult(): ?array {
		return $this->lastResult;
	}

	private function verifyUsers(array $ncUids, string $mode, bool $mappedOnly, ?array $config = null): array {
		$config ??= $this->adminConfigService->getAdminConfig();
		$immichIndex = $this->loadImmichIndex();
		$result = [
			'job' => 'verify_provisioning',
			'mode' => $mode,
			'status' => $immichIndex['errors'] === [] ? 'success' : 'failed',
			'users' => [],
			'errors' => $immichIndex['errors'],
		];

		foreach ($this->uniqueStrings($ncUids) as $ncUid) {
			$state = $this->findStateSafely($ncUid);
			if ($mappedOnly && $state === null) {
				continue;
			}

			$report = $this->verifyUser($ncUid, $config, $immichIndex, $state);
			$result['users'][$ncUid] = $report;
			$result['errors'] = array_merge($result['errors'], $report['errors']);
		}

		$result['status'] = $this->aggregateStatus($result);
		return $result;
	}

	private function verifyUser(string $ncUid, array $config, array $immichIndex, ?SyncState $state): array {
		$report = [
			'ncUid' => $ncUid,
			'scope' => $this->scopeForUser($ncUid, $config),
			'status' => 'pending',
			'mapping' => $this->mappingHealth($ncUid, $state),
			'mount' => null,
			'immich' => [
				'status' => 'unknown',
				'id' => null,
				'enabled' => null,
			],
			'quota' => $this->quotaHealth($state, $config),
			'errors' => [],
		];

		if ($state === null) {
			$report['status'] = 'missing_mapping';
			$report['errors'][] = 'No sync state mapping exists for Nextcloud user "' . $ncUid . '".';
			return $report;
		}

		$report['mount'] = $this->verifyMountSafely($ncUid);
		$report['immich'] = $this->immichHealth($state, $immichIndex);
		$report['errors'] = $this->healthErrors($report);
		$report['status'] = $this->userHealthStatus($report);

		return $report;
	}

	private function loadImmichIndex(): array {
		try {
			$index = ['byId' => [], 'errors' => []];
			foreach ($this->immichUserAdminService->listUsers() as $position => $user) {
				if (!is_array($user)) {
					continue;
				}

				$id = $this->immichUserId($user);
				if ($id === '') {
					$id = 'index:' . $position;
				}

				$index['byId'][$id][] = $user;
			}

			return $index;
		} catch (\Throwable $e) {
			$error = $this->redact($e->getMessage());
			$this->logger->warning('Immich provisioning verification could not list admin users: ' . $error, [
				'app' => Application::APP_ID,
			]);

			return ['byId' => [], 'errors' => [$error]];
		}
	}

	private function findStateSafely(string $ncUid): ?SyncState {
		try {
			return $this->syncStateService->findByUid($ncUid);
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to load Immich sync state for Nextcloud user "' . $ncUid . '": ' . $this->redact($e->getMessage()), [
				'app' => Application::APP_ID,
				'ncUid' => $ncUid,
			]);

			return null;
		}
	}

	private function verifyMountSafely(string $ncUid): array {
		try {
			return $this->externalStorageProvisioner->verifyMount($ncUid);
		} catch (\Throwable $e) {
			$error = $this->redact($e->getMessage());
			$this->logger->warning('Nextcloud Immich mount verification failed for user "' . $ncUid . '": ' . $error, [
				'app' => Application::APP_ID,
				'ncUid' => $ncUid,
			]);

			return [
				'ncUid' => $ncUid,
				'configured' => false,
				'exists' => false,
				'readable' => false,
				'read_only' => false,
				'available_only_to_uid' => false,
				'not_root_storage' => false,
				'mount_id' => null,
				'status' => 'failed',
				'errors' => [$error],
				'remediation' => '',
			];
		}
	}

	private function mappingHealth(string $ncUid, ?SyncState $state): array {
		if ($state === null) {
			return [
				'present' => false,
				'status' => 'missing',
				'ncUid' => $ncUid,
			];
		}

		return [
			'present' => true,
			'status' => 'mapped',
			'ncUid' => $state->getNcUid(),
			'immichUserId' => $state->getImmichUserId(),
			'immichEmail' => $state->getImmichEmail(),
			'storageLabel' => $state->getStorageLabel(),
			'ncMountId' => $state->getNcMountId(),
			'scopeStatus' => $state->getScopeStatus(),
			'lastSyncStatus' => $state->getLastSyncStatus(),
			'lastError' => $state->getLastError(),
		];
	}

	private function immichHealth(SyncState $state, array $immichIndex): array {
		$immichUserId = trim((string)$state->getImmichUserId());
		if ($immichUserId === '') {
			return [
				'status' => 'missing_mapping',
				'id' => null,
				'enabled' => null,
			];
		}

		$matches = $immichIndex['byId'][$immichUserId] ?? [];
		if (!is_array($matches) || $matches === []) {
			return [
				'status' => $immichIndex['errors'] === [] ? 'stale_mapping' : 'unknown',
				'id' => $immichUserId,
				'enabled' => null,
			];
		}

		if (count($matches) > 1) {
			return [
				'status' => 'duplicate_immich_users',
				'id' => $immichUserId,
				'enabled' => null,
			];
		}

		$enabled = $this->immichUserEnabled($matches[0]);
		return [
			'status' => $enabled ? 'ok' : 'disabled',
			'id' => $immichUserId,
			'enabled' => $enabled,
			'email' => $matches[0]['email'] ?? null,
			'storageLabel' => $matches[0]['storageLabel'] ?? null,
		];
	}

	private function quotaHealth(?SyncState $state, array $config): array {
		$mode = (string)($config[AdminConfigService::KEY_QUOTA_SYNC_MODE] ?? 'disabled');
		if (!in_array($mode, ['manual', 'event_scheduled'], true)) {
			return [
				'status' => 'disabled',
				'mode' => $mode,
				'lastQuotaSyncAt' => null,
				'error' => null,
			];
		}

		if ($state === null) {
			return [
				'status' => 'unavailable',
				'mode' => $mode,
				'lastQuotaSyncAt' => null,
				'error' => 'No sync state mapping exists.',
			];
		}

		if ($state->getLastSyncStatus() === SyncStateService::STATUS_QUOTA_FAILED) {
			return [
				'status' => 'failed',
				'mode' => $mode,
				'lastQuotaSyncAt' => $this->formatDateTime($state->getLastQuotaSyncAt()),
				'error' => $state->getLastError(),
			];
		}

		return [
			'status' => $state->getLastQuotaSyncAt() === null ? 'pending' : 'ok',
			'mode' => $mode,
			'lastQuotaSyncAt' => $this->formatDateTime($state->getLastQuotaSyncAt()),
			'error' => null,
		];
	}

	private function healthErrors(array $report): array {
		$errors = [];
		$mount = is_array($report['mount']) ? $report['mount'] : [];
		foreach (($mount['errors'] ?? []) as $error) {
			$errors[] = $this->redact((string)$error);
		}

		$mountStatus = (string)($mount['status'] ?? 'unknown');
		if (in_array($mountStatus, ['failed', 'unsafe_path', 'misconfigured'], true) && ($mount['errors'] ?? []) === []) {
			$errors[] = 'Nextcloud mount health is ' . $mountStatus . '.';
		}

		$immichStatus = (string)($report['immich']['status'] ?? 'unknown');
		if (in_array($immichStatus, ['stale_mapping', 'missing_mapping', 'duplicate_immich_users', 'disabled'], true)) {
			$errors[] = 'Immich user health is ' . $immichStatus . '.';
		}

		if (($report['quota']['status'] ?? '') === 'failed') {
			$errors[] = 'Quota sync failed: ' . $this->redact((string)($report['quota']['error'] ?? 'unknown error'));
		}

		return $errors;
	}

	private function userHealthStatus(array $report): string {
		if ($report['errors'] !== []) {
			return 'failed';
		}

		$mountStatus = (string)($report['mount']['status'] ?? 'unknown');
		if ($mountStatus === 'mount_pending' || ($report['quota']['status'] ?? '') === 'pending') {
			return 'pending';
		}

		if ($mountStatus === 'ok' && ($report['immich']['status'] ?? '') === 'ok') {
			return 'ok';
		}

		return 'warning';
	}

	private function aggregateStatus(array $result): string {
		if ($result['errors'] !== []) {
			return 'failed';
		}
		foreach ($result['users'] as $user) {
			if (($user['status'] ?? '') === 'failed') {
				return 'failed';
			}
		}
		foreach ($result['users'] as $user) {
			if (($user['status'] ?? '') === 'pending') {
				return 'pending';
			}
		}

		return $result['users'] === [] ? 'skipped' : 'ok';
	}

	private function scopeForUser(string $ncUid, array $config): array {
		$userExists = $this->userManager->get($ncUid) !== null;
		if (!$userExists) {
			return [
				'inScope' => false,
				'reason' => 'Nextcloud user was not found.',
			];
		}

		if (($config[AdminConfigService::KEY_USER_SCOPE_MODE] ?? 'all') !== 'groups') {
			return [
				'inScope' => true,
				'reason' => 'User is in scope.',
			];
		}

		foreach ($this->configuredGroups($config[AdminConfigService::KEY_USER_SCOPE_GROUPS] ?? []) as $groupId) {
			if ($this->groupManager->isInGroup($ncUid, $groupId)) {
				return [
					'inScope' => true,
					'reason' => 'User is in scope.',
				];
			}
		}

		return [
			'inScope' => false,
			'reason' => 'Nextcloud user is outside configured provisioning groups.',
		];
	}

	private function scopedUserIds(array $config): array {
		if (($config[AdminConfigService::KEY_USER_SCOPE_MODE] ?? 'all') === 'groups') {
			return $this->groupScopedUserIds($this->configuredGroups($config[AdminConfigService::KEY_USER_SCOPE_GROUPS] ?? []));
		}

		return $this->allUserIds();
	}

	private function allUserIds(): array {
		$users = [];
		if (method_exists($this->userManager, 'search')) {
			$result = $this->userManager->search('');
			if (is_iterable($result)) {
				foreach ($result as $user) {
					$uid = $this->uidFromUser($user);
					if ($uid !== null) {
						$users[] = $uid;
					}
				}
			}
		}

		if ($users === [] && method_exists($this->userManager, 'callForAllUsers')) {
			$this->userManager->callForAllUsers(function (object $user) use (&$users): void {
				$uid = $this->uidFromUser($user);
				if ($uid !== null) {
					$users[] = $uid;
				}
			});
		}

		return $this->uniqueStrings($users);
	}

	private function groupScopedUserIds(array $groupIds): array {
		$users = [];
		foreach ($groupIds as $groupId) {
			$group = method_exists($this->groupManager, 'get') ? $this->groupManager->get($groupId) : null;
			if (!is_object($group) || !method_exists($group, 'getUsers')) {
				continue;
			}

			foreach ($group->getUsers() as $user) {
				$uid = $this->uidFromUser($user);
				if ($uid !== null) {
					$users[] = $uid;
				}
			}
		}

		return $this->uniqueStrings($users);
	}

	private function immichUserEnabled(array $user): bool {
		if (array_key_exists('isEnabled', $user)) {
			return filter_var($user['isEnabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;
		}

		foreach (['disabledAt', 'deletedAt'] as $field) {
			if (!empty($user[$field])) {
				return false;
			}
		}

		$status = strtolower((string)($user['status'] ?? ''));
		return !in_array($status, ['disabled', 'deleted', 'inactive', 'suspended'], true);
	}

	private function immichUserId(array $user): string {
		$id = $user['id'] ?? $user['userId'] ?? '';
		return is_scalar($id) ? trim((string)$id) : '';
	}

	private function uidFromUser(mixed $user): ?string {
		if (!is_object($user) || !method_exists($user, 'getUID')) {
			return null;
		}

		$uid = trim((string)$user->getUID());
		return $uid === '' ? null : $uid;
	}

	private function configuredGroups(mixed $groups): array {
		if (!is_array($groups)) {
			return [];
		}

		$normalised = array_map(
			static fn(mixed $group): ?string => is_string($group) && trim($group) !== '' ? trim($group) : null,
			$groups,
		);

		return array_values(array_filter($normalised, static fn(?string $group): bool => $group !== null));
	}

	private function uniqueStrings(array $values): array {
		$unique = [];
		foreach ($values as $value) {
			if (!is_string($value)) {
				continue;
			}

			$value = trim($value);
			if ($value !== '') {
				$unique[$value] = true;
			}
		}

		return array_keys($unique);
	}

	private function failedResult(string $mode, array $errors): array {
		return [
			'job' => 'verify_provisioning',
			'mode' => $mode,
			'status' => 'failed',
			'users' => [],
			'errors' => $this->redactErrors($errors),
		];
	}

	private function redactErrors(array $errors): array {
		return array_values(array_map(fn(mixed $error): string => $this->redact(is_scalar($error) ? (string)$error : json_encode($error, JSON_THROW_ON_ERROR)), $errors));
	}

	private function redact(string $message): string {
		$patterns = [
			'/("(?:password|admin_api_key|apiKey|api_key|x-api-key|token|secret)"\s*:\s*")[^"]+(")/i',
			'/((?:password|admin_api_key|apiKey|api_key|x-api-key|token|secret)\s*[=:]\s*)[^\s,;}]+/i',
			'/(Bearer\s+)[A-Za-z0-9._~+\/=:-]+/i',
			'/\b[a-f0-9]{32,}\b/i',
		];

		$replacements = [
			'$1[redacted]$2',
			'$1[redacted]',
			'$1[redacted]',
			'[redacted-hex]',
		];

		return preg_replace($patterns, $replacements, $message) ?? 'Redacted error.';
	}

	private function formatDateTime(?DateTimeInterface $dateTime): ?string {
		return $dateTime?->format(DateTimeInterface::ATOM);
	}
}
