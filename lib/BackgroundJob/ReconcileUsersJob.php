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
use OCA\IntegrationImmich\Service\ImmichUserAdminService;
use OCA\IntegrationImmich\Service\LockService;
use OCA\IntegrationImmich\Service\PathTemplateService;
use OCA\IntegrationImmich\Service\ProvisioningService;
use OCA\IntegrationImmich\Service\SyncStateService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class ReconcileUsersJob extends QueuedJob {
	private const LOCK_TIMEOUT_SECONDS = 300;

	private ?array $lastResult = null;
	private LockService $lockService;

	public function __construct(
		ITimeFactory $timeFactory,
		private AdminConfigService $adminConfigService,
		private ProvisioningService $provisioningService,
		private SyncStateService $syncStateService,
		private ImmichUserAdminService $immichUserAdminService,
		private PathTemplateService $pathTemplateService,
		private IJobList $jobList,
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
		$this->lastResult = $this->reconcileFromArgument($argument);
	}

	public function reconcileFromArgument(mixed $argument): array {
		$parsed = $this->parseArgument($argument);
		if ($parsed['ncUid'] !== null) {
			return $this->reconcileOneUser($parsed['ncUid'], $parsed['dryRun']);
		}

		return $this->reconcileAllScopedUsers($parsed['dryRun']);
	}

	public function reconcileOneUser(string $ncUid, bool $dryRun = false): array {
		$ncUid = trim($ncUid);
		if ($ncUid === '') {
			return $this->failedResult('one', $dryRun, ['ReconcileUsersJob requires a non-empty ncUid argument.']);
		}

		try {
			return $this->lockService->withLock('integration_immich_reconcile_' . $ncUid, self::LOCK_TIMEOUT_SECONDS, function () use ($ncUid, $dryRun): array {
				return $this->reconcileUsers([$ncUid], 'one', $dryRun);
			});
		} catch (\Throwable $e) {
			$error = $this->redact($e->getMessage());
			$this->logger->warning('Immich user reconcile job failed for Nextcloud user "' . $ncUid . '": ' . $error, [
				'app' => Application::APP_ID,
				'ncUid' => $ncUid,
			]);

			return $this->failedResult('one', $dryRun, [$error]);
		}
	}

	public function reconcileAllScopedUsers(bool $dryRun = false): array {
		try {
			return $this->lockService->withLock('integration_immich_reconcile_all', self::LOCK_TIMEOUT_SECONDS, function () use ($dryRun): array {
				$config = $this->adminConfigService->getAdminConfig();
				return $this->reconcileUsers($this->reconcileCandidateUserIds($config), 'all', $dryRun, $config);
			});
		} catch (\Throwable $e) {
			$error = $this->redact($e->getMessage());
			$this->logger->warning('Immich all-user reconcile job failed: ' . $error, [
				'app' => Application::APP_ID,
			]);

			return $this->failedResult('all', $dryRun, [$error]);
		}
	}

	public function getLastResult(): ?array {
		return $this->lastResult;
	}

	private function reconcileUsers(array $ncUids, string $mode, bool $dryRun, ?array $config = null): array {
		$config ??= $this->adminConfigService->getAdminConfig();
		$ncUids = $this->uniqueStrings($ncUids);
		$result = [
			'job' => 'reconcile_users',
			'mode' => $mode,
			'dryRun' => $dryRun,
			'status' => 'success',
			'users' => [],
			'queued' => [],
			'conflicts' => [],
			'errors' => [],
		];

		if ($ncUids === []) {
			$result['status'] = 'skipped';
			return $result;
		}

		$scopes = [];
		$needsImmichIndex = false;
		foreach ($ncUids as $ncUid) {
			$scope = $this->scopeForUser($ncUid, $config);
			$scopes[$ncUid] = $scope;
			$needsImmichIndex = $needsImmichIndex || $scope['inScope'];
		}

		$immichIndex = $this->emptyImmichIndex();
		if ($needsImmichIndex) {
			$immichIndex = $this->loadImmichIndex();
		}
		if ($immichIndex['errors'] !== []) {
			$result['status'] = 'failed';
			$result['errors'] = $immichIndex['errors'];
			return $result;
		}

		foreach ($ncUids as $ncUid) {
			$scope = $scopes[$ncUid];
			$userResult = $scope['inScope']
				? $this->reconcileScopedUser($ncUid, $config, $immichIndex, $dryRun)
				: $this->reconcileInactiveUser($ncUid, $config, $scope, $dryRun);
			$result['users'][$ncUid] = $userResult;
			$result['queued'] = array_merge($result['queued'], $userResult['queued']);
			$result['conflicts'] = array_merge($result['conflicts'], $userResult['conflicts']);
			$result['errors'] = array_merge($result['errors'], $userResult['errors']);
		}

		$result['status'] = $this->aggregateStatus($result);
		return $result;
	}

	private function reconcileInactiveUser(string $ncUid, array $config, array $scope, bool $dryRun): array {
		$report = $this->emptyUserReport($ncUid, $dryRun);
		$report['status'] = 'skipped';
		$report['action'] = 'skipped';
		$report['reason'] = $scope['reason'];
		$report['errors'] = [];

		try {
			$state = $this->syncStateService->findByUid($ncUid);
			$report['mapping'] = $this->stateSummary($state);
			$targetScopeStatus = $this->inactiveScopeStatus($scope);
			$immichUserId = trim((string)$state?->getImmichUserId());

			if ($state === null || $immichUserId === '') {
				if (!$dryRun) {
					$this->persistStatusIfPossible($ncUid, $targetScopeStatus, $targetScopeStatus, $scope['error']);
				}

				return $report;
			}

			$report['immich'] = [
				'matches' => [[
					'id' => $immichUserId,
					'email' => $state->getImmichEmail(),
					'storageLabel' => $state->getStorageLabel(),
				]],
				'exists' => true,
			];
			$report['policy'] = (string)($config[AdminConfigService::KEY_DELETE_DISABLE_POLICY] ?? 'disable_suspend');

			if ($report['policy'] === 'delete_opt_in') {
				return $this->deleteInactiveImmichUser($report, $state, $targetScopeStatus, $dryRun);
			}

			return $this->disableInactiveImmichUser($report, $state, $targetScopeStatus, $dryRun);
		} catch (\Throwable $e) {
			$error = $this->redact($e->getMessage());
			if (!$dryRun) {
				$this->persistMappingFailure($ncUid, $error);
			}

			$this->logger->warning('Immich inactive-user reconcile failed for Nextcloud user "' . $ncUid . '": ' . $error, [
				'app' => Application::APP_ID,
				'ncUid' => $ncUid,
			]);

			$report['status'] = 'failed';
			$report['action'] = 'skipped';
			$report['errors'] = [$error];
			return $report;
		}
	}

	private function deleteInactiveImmichUser(array $report, SyncState $state, string $targetScopeStatus, bool $dryRun): array {
		$immichUserId = (string)$state->getImmichUserId();
		if ($dryRun) {
			$report['status'] = 'planned';
			$report['action'] = 'would_delete';
			return $report;
		}

		try {
			$this->immichUserAdminService->deleteUser($immichUserId);
			$this->syncStateService->updateMapping($state->getNcUid(), [
				'scopeStatus' => $targetScopeStatus,
				'lastSyncStatus' => SyncStateService::STATUS_DELETED,
				'lastError' => null,
			]);
		} catch (\Throwable $e) {
			$error = $this->redact('Immich destructive delete failed: ' . $e->getMessage());
			$this->syncStateService->updateMapping($state->getNcUid(), [
				'scopeStatus' => $targetScopeStatus,
				'lastSyncStatus' => SyncStateService::STATUS_FAILED,
				'lastError' => $error,
			]);
			$report['status'] = 'failed';
			$report['action'] = 'delete_failed';
			$report['errors'] = [$error];
			return $report;
		}

		$report['status'] = 'success';
		$report['action'] = 'deleted';
		return $report;
	}

	private function disableInactiveImmichUser(array $report, SyncState $state, string $targetScopeStatus, bool $dryRun): array {
		$immichUserId = (string)$state->getImmichUserId();
		if ($dryRun) {
			$report['status'] = 'planned';
			$report['action'] = 'would_disable';
			return $report;
		}

		try {
			$this->immichUserAdminService->disableUser($immichUserId);
			$this->syncStateService->updateMapping($state->getNcUid(), [
				'scopeStatus' => $targetScopeStatus,
				'lastSyncStatus' => SyncStateService::STATUS_DISABLED,
				'lastError' => null,
			]);
		} catch (\Throwable $e) {
			$error = $this->redact('Non-destructive Immich disable/suspend is pending: ' . $e->getMessage());
			$this->syncStateService->updateMapping($state->getNcUid(), [
				'scopeStatus' => $targetScopeStatus,
				'lastSyncStatus' => SyncStateService::STATUS_FAILED,
				'lastError' => $error,
			]);
			$report['status'] = 'failed';
			$report['action'] = 'disable_pending';
			$report['errors'] = [$error];
			return $report;
		}

		$report['status'] = 'success';
		$report['action'] = 'disabled';
		return $report;
	}

	private function reconcileScopedUser(string $ncUid, array $config, array $immichIndex, bool $dryRun): array {
		$report = $this->emptyUserReport($ncUid, $dryRun);

		$scope = $this->scopeForUser($ncUid, $config);
		if (!$scope['inScope']) {
			return $this->reconcileInactiveUser($ncUid, $config, $scope, $dryRun);
		}

		try {
			$state = $this->syncStateService->findByUid($ncUid);
			$report['mapping'] = $this->stateSummary($state);
			$storageLabel = $this->storageLabelForUid($ncUid, $state, $config);
			$email = $this->emailForUid($ncUid, $storageLabel, $state, $config);
			$conflicts = $this->mappingConflicts($ncUid, $state, $storageLabel);
			$matches = $this->matchingImmichUsers($state, $email, $storageLabel, $immichIndex);
			$conflicts = array_merge($conflicts, $this->immichConflicts($ncUid, $state, $email, $storageLabel, $matches, $immichIndex));

			$report['immich'] = [
				'matches' => $this->immichEntrySummaries($matches),
				'exists' => $matches !== [],
			];

			if ($conflicts !== []) {
				$report['status'] = 'conflict';
				$report['action'] = 'blocked';
				$report['conflicts'] = $this->redactErrors($conflicts);
				if (!$dryRun) {
					$this->persistMappingFailure($ncUid, implode('; ', $report['conflicts']));
				}

				return $report;
			}

			$jobs = $this->jobsNeeded($state, $matches, $config);
			if ($dryRun) {
				$report['wouldQueue'] = $jobs;
				$report['dryRunPlan'] = $this->provisioningService->reconcileUser($ncUid, true);
			} else {
				$report['queued'] = $this->enqueueJobs($ncUid, $jobs);
			}

			$report['status'] = $jobs === [] ? 'success' : ($dryRun ? 'planned' : 'queued');
			$report['action'] = $jobs === [] ? 'unchanged' : ($dryRun ? 'would_queue' : 'queued');
			return $report;
		} catch (\Throwable $e) {
			$error = $this->redact($e->getMessage());
			if (!$dryRun) {
				$this->persistMappingFailure($ncUid, $error);
			}

			$this->logger->warning('Immich reconcile failed for Nextcloud user "' . $ncUid . '": ' . $error, [
				'app' => Application::APP_ID,
				'ncUid' => $ncUid,
			]);

			$report['status'] = 'failed';
			$report['action'] = 'skipped';
			$report['errors'] = [$error];
			return $report;
		}
	}

	private function parseArgument(mixed $argument): array {
		$dryRun = false;
		$ncUid = null;

		if (is_string($argument)) {
			$ncUid = trim($argument);
		} elseif (is_array($argument)) {
			$dryRun = filter_var($argument['dryRun'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
			foreach (['ncUid', 'uid', 'userId'] as $key) {
				if (isset($argument[$key]) && is_string($argument[$key]) && trim($argument[$key]) !== '') {
					$ncUid = trim($argument[$key]);
					break;
				}
			}
		}

		return [
			'ncUid' => $ncUid === '' ? null : $ncUid,
			'dryRun' => $dryRun,
		];
	}

	private function loadImmichIndex(): array {
		try {
			return $this->indexImmichUsers($this->immichUserAdminService->listUsers());
		} catch (\Throwable $e) {
			$error = $this->redact($e->getMessage());
			$this->logger->warning('Immich reconcile could not list admin users: ' . $error, [
				'app' => Application::APP_ID,
			]);

			return [
				'byId' => [],
				'byEmail' => [],
				'byStorageLabel' => [],
				'errors' => [$error],
			];
		}
	}

	private function emptyImmichIndex(): array {
		return [
			'byId' => [],
			'byEmail' => [],
			'byStorageLabel' => [],
			'errors' => [],
		];
	}

	private function indexImmichUsers(array $users): array {
		$index = [
			'byId' => [],
			'byEmail' => [],
			'byStorageLabel' => [],
			'errors' => [],
		];

		foreach ($users as $position => $user) {
			if (!is_array($user)) {
				continue;
			}

			$id = $this->immichUserId($user);
			$email = strtolower(trim((string)($user['email'] ?? '')));
			$storageLabel = trim((string)($user['storageLabel'] ?? ''));
			$entry = [
				'key' => $id !== '' ? 'id:' . $id : 'index:' . $position,
				'id' => $id,
				'email' => $email,
				'storageLabel' => $storageLabel,
				'user' => $user,
			];

			if ($id !== '') {
				$index['byId'][$id][] = $entry;
			}
			if ($email !== '') {
				$index['byEmail'][$email][] = $entry;
			}
			if ($storageLabel !== '') {
				$index['byStorageLabel'][$storageLabel][] = $entry;
			}
		}

		return $index;
	}

	private function matchingImmichUsers(?SyncState $state, string $email, string $storageLabel, array $immichIndex): array {
		$matches = [];
		$immichUserId = trim((string)$state?->getImmichUserId());

		foreach ($this->entriesByKey($immichIndex, 'byId', $immichUserId) as $entry) {
			$matches[$entry['key']] = $entry;
		}
		foreach ($this->entriesByKey($immichIndex, 'byEmail', strtolower($email)) as $entry) {
			$matches[$entry['key']] = $entry;
		}
		foreach ($this->entriesByKey($immichIndex, 'byStorageLabel', $storageLabel) as $entry) {
			$matches[$entry['key']] = $entry;
		}

		return array_values($matches);
	}

	private function immichConflicts(string $ncUid, ?SyncState $state, string $email, string $storageLabel, array $matches, array $immichIndex): array {
		$conflicts = [];
		$immichUserId = trim((string)$state?->getImmichUserId());

		if ($immichUserId !== '' && $this->entriesByKey($immichIndex, 'byId', $immichUserId) === []) {
			$conflicts[] = 'Stored Immich user mapping for Nextcloud user "' . $ncUid . '" was not found in Immich.';
		}

		if ($immichUserId !== '' && count($this->entriesByKey($immichIndex, 'byId', $immichUserId)) > 1) {
			$conflicts[] = 'Duplicate Immich users share mapped id "' . $immichUserId . '" for Nextcloud user "' . $ncUid . '".';
		}

		if ($email !== '' && count($this->entriesByKey($immichIndex, 'byEmail', strtolower($email))) > 1) {
			$conflicts[] = 'Duplicate Immich users match email for Nextcloud user "' . $ncUid . '".';
		}

		if ($storageLabel !== '' && count($this->entriesByKey($immichIndex, 'byStorageLabel', $storageLabel)) > 1) {
			$conflicts[] = 'Duplicate Immich users match storage label "' . $storageLabel . '" for Nextcloud user "' . $ncUid . '".';
		}

		$distinctIds = $this->distinctImmichIds($matches);
		if (count($distinctIds) > 1) {
			$conflicts[] = 'Multiple Immich users match Nextcloud user "' . $ncUid . '" by stored mapping, email, or storage label.';
		}

		return $conflicts;
	}

	private function mappingConflicts(string $ncUid, ?SyncState $state, string $storageLabel): array {
		$conflicts = [];
		$immichUserId = trim((string)$state?->getImmichUserId());

		if ($immichUserId !== '') {
			$owner = $this->syncStateService->findByImmichUserId($immichUserId);
			if ($owner !== null && $owner->getNcUid() !== $ncUid) {
				$conflicts[] = 'Stored Immich user id "' . $immichUserId . '" is already mapped to Nextcloud user "' . $owner->getNcUid() . '".';
			}
		}

		$labelOwner = $this->syncStateService->findByStorageLabel($storageLabel);
		if ($labelOwner !== null && $labelOwner->getNcUid() !== $ncUid) {
			$conflicts[] = 'Storage label "' . $storageLabel . '" is already mapped to Nextcloud user "' . $labelOwner->getNcUid() . '".';
		}

		return $conflicts;
	}

	private function jobsNeeded(?SyncState $state, array $matches, array $config): array {
		$jobs = [];
		$lastSyncStatus = (string)$state?->getLastSyncStatus();
		$scopeStatus = (string)$state?->getScopeStatus();
		$immichUserId = trim((string)$state?->getImmichUserId());

		if ($state === null
			|| $immichUserId === ''
			|| $matches === []
			|| $lastSyncStatus === SyncStateService::STATUS_PENDING
			|| $lastSyncStatus === SyncStateService::STATUS_FAILED
			|| $scopeStatus !== SyncStateService::STATUS_ACTIVE) {
			$jobs[] = ProvisionImmichUserJob::class;
		}

		if ($state === null || $state->getNcMountId() === null || $lastSyncStatus === SyncStateService::STATUS_MOUNT_PENDING) {
			$jobs[] = ProvisionNextcloudMountJob::class;
		}

		if ($this->quotaSyncEnabled($config)
			&& ($state === null
				|| $immichUserId === ''
				|| $state->getLastQuotaSyncAt() === null
				|| $lastSyncStatus === SyncStateService::STATUS_QUOTA_FAILED
				|| in_array(ProvisionImmichUserJob::class, $jobs, true))) {
			$jobs[] = SyncQuotaJob::class;
		}

		return $jobs;
	}

	private function enqueueJobs(string $ncUid, array $jobs): array {
		$queued = [];
		foreach ($jobs as $jobClass) {
			$this->jobList->add($jobClass, ['ncUid' => $ncUid]);
			$queued[] = [
				'job' => $jobClass,
				'ncUid' => $ncUid,
			];
		}

		return $queued;
	}

	private function scopedUserIds(array $config): array {
		if (($config[AdminConfigService::KEY_USER_SCOPE_MODE] ?? 'all') === 'groups') {
			return $this->groupScopedUserIds($this->configuredGroups($config[AdminConfigService::KEY_USER_SCOPE_GROUPS] ?? []));
		}

		return $this->allUserIds();
	}

	private function reconcileCandidateUserIds(array $config): array {
		$uids = $this->provisioningEnabled($config) ? $this->scopedUserIds($config) : [];
		foreach ($this->mappedUserIds() as $mappedUid) {
			$uids[] = $mappedUid;
		}

		return $this->uniqueStrings($uids);
	}

	private function mappedUserIds(): array {
		try {
			return $this->uniqueStrings(array_map(
				static fn(SyncState $state): string => $state->getNcUid(),
				$this->syncStateService->listMappedStates(),
			));
		} catch (\Throwable $e) {
			$this->logger->warning('Immich reconcile could not list mapped sync states: ' . $this->redact($e->getMessage()), [
				'app' => Application::APP_ID,
			]);
			return [];
		}
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

	private function scopeForUser(string $ncUid, array $config): array {
		if (!$this->provisioningEnabled($config)) {
			return [
				'inScope' => false,
				'reason' => 'Provisioning is disabled.',
				'error' => null,
				'scopeStatus' => SyncStateService::STATUS_OUT_OF_SCOPE,
			];
		}

		$user = $this->userManager->get($ncUid);
		if ($user === null) {
			return [
				'inScope' => false,
				'reason' => 'Nextcloud user was not found.',
				'error' => 'Nextcloud user was not found.',
				'scopeStatus' => SyncStateService::STATUS_DELETED,
			];
		}

		if ($this->userIsDisabled($user)) {
			return [
				'inScope' => false,
				'reason' => 'Nextcloud user is disabled.',
				'error' => null,
				'scopeStatus' => SyncStateService::STATUS_DISABLED,
			];
		}

		if (($config[AdminConfigService::KEY_USER_SCOPE_MODE] ?? 'all') !== 'groups') {
			return [
				'inScope' => true,
				'reason' => 'User is in scope.',
				'error' => null,
				'scopeStatus' => SyncStateService::STATUS_ACTIVE,
			];
		}

		foreach ($this->configuredGroups($config[AdminConfigService::KEY_USER_SCOPE_GROUPS] ?? []) as $groupId) {
			if ($this->groupManager->isInGroup($ncUid, $groupId)) {
				return [
					'inScope' => true,
					'reason' => 'User is in scope.',
					'error' => null,
					'scopeStatus' => SyncStateService::STATUS_ACTIVE,
				];
			}
		}

		return [
			'inScope' => false,
			'reason' => 'Nextcloud user is outside configured provisioning groups.',
			'error' => null,
			'scopeStatus' => SyncStateService::STATUS_OUT_OF_SCOPE,
		];
	}

	private function inactiveScopeStatus(array $scope): string {
		$status = (string)($scope['scopeStatus'] ?? SyncStateService::STATUS_OUT_OF_SCOPE);
		return in_array($status, [SyncStateService::STATUS_DELETED, SyncStateService::STATUS_DISABLED, SyncStateService::STATUS_OUT_OF_SCOPE], true)
			? $status
			: SyncStateService::STATUS_OUT_OF_SCOPE;
	}

	private function userIsDisabled(object $user): bool {
		if (!method_exists($user, 'isEnabled')) {
			return false;
		}

		try {
			return $user->isEnabled() === false;
		} catch (\Throwable) {
			return false;
		}
	}

	private function storageLabelForUid(string $ncUid, ?SyncState $state, array $config): string {
		$label = trim((string)$state?->getStorageLabel());
		if ($label !== '') {
			return $this->pathTemplateService->sanitizeStorageLabel($label);
		}

		$template = (string)($config[AdminConfigService::KEY_STORAGE_LABEL_TEMPLATE] ?? '{uid}');
		return $this->pathTemplateService->expandStorageLabelTemplate($template, $ncUid);
	}

	private function emailForUid(string $ncUid, string $storageLabel, ?SyncState $state, array $config): string {
		$mappedEmail = trim((string)$state?->getImmichEmail());
		if ($mappedEmail !== '') {
			return $mappedEmail;
		}

		$user = $this->userManager->get($ncUid);
		$email = is_object($user) && method_exists($user, 'getEMailAddress') ? trim((string)$user->getEMailAddress()) : '';
		if ($email !== '') {
			return $email;
		}

		$template = (string)($config[AdminConfigService::KEY_EMAIL_TEMPLATE] ?? '{uid}@immich.local');
		return strtr($template, [
			'{uid}' => $ncUid,
			'{storageLabel}' => $storageLabel,
		]);
	}

	private function stateSummary(?SyncState $state): ?array {
		if ($state === null) {
			return null;
		}

		return [
			'ncUid' => $state->getNcUid(),
			'immichUserId' => $state->getImmichUserId(),
			'immichEmail' => $state->getImmichEmail(),
			'storageLabel' => $state->getStorageLabel(),
			'ncMountId' => $state->getNcMountId(),
			'scopeStatus' => $state->getScopeStatus(),
			'lastSyncStatus' => $state->getLastSyncStatus(),
			'lastError' => $state->getLastError(),
			'lastQuotaSyncAt' => $this->formatDateTime($state->getLastQuotaSyncAt()),
		];
	}

	private function emptyUserReport(string $ncUid, bool $dryRun): array {
		return [
			'ncUid' => $ncUid,
			'dryRun' => $dryRun,
			'status' => 'pending',
			'action' => 'none',
			'mapping' => null,
			'immich' => [
				'matches' => [],
				'exists' => false,
			],
			'queued' => [],
			'wouldQueue' => [],
			'conflicts' => [],
			'errors' => [],
		];
	}

	private function failedResult(string $mode, bool $dryRun, array $errors): array {
		return [
			'job' => 'reconcile_users',
			'mode' => $mode,
			'dryRun' => $dryRun,
			'status' => 'failed',
			'users' => [],
			'queued' => [],
			'conflicts' => [],
			'errors' => $this->redactErrors($errors),
		];
	}

	private function aggregateStatus(array $result): string {
		if ($result['errors'] !== []) {
			return 'failed';
		}
		if ($result['conflicts'] !== []) {
			return 'conflict';
		}
		if ($result['queued'] !== []) {
			return 'queued';
		}
		foreach ($result['users'] as $userResult) {
			if (($userResult['status'] ?? '') === 'planned') {
				return 'planned';
			}
		}

		return $result['users'] === [] ? 'skipped' : 'success';
	}

	private function persistMappingFailure(string $ncUid, string $error): void {
		try {
			if ($this->syncStateService->findByUid($ncUid) === null) {
				return;
			}

			$this->syncStateService->updateMapping($ncUid, [
				'lastSyncStatus' => SyncStateService::STATUS_FAILED,
				'lastError' => $error,
			]);
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to persist Immich reconcile failure for Nextcloud user "' . $ncUid . '": ' . $this->redact($e->getMessage()), [
				'app' => Application::APP_ID,
				'ncUid' => $ncUid,
			]);
		}
	}

	private function persistStatusIfPossible(string $ncUid, string $scopeStatus, string $lastSyncStatus, ?string $error): void {
		try {
			$this->syncStateService->updateStatus($ncUid, $scopeStatus, $lastSyncStatus, $error === null ? null : $this->redact($error));
		} catch (\Throwable) {
			// A mapping may not exist yet. The structured reconcile report remains the source of truth.
		}
	}

	private function entriesByKey(array $index, string $bucket, string $key): array {
		if ($key === '') {
			return [];
		}

		$entries = $index[$bucket][$key] ?? [];
		return is_array($entries) ? $entries : [];
	}

	private function distinctImmichIds(array $entries): array {
		$ids = [];
		foreach ($entries as $entry) {
			$id = (string)($entry['id'] ?? '');
			$ids[$id !== '' ? $id : (string)$entry['key']] = true;
		}

		return array_keys($ids);
	}

	private function immichEntrySummaries(array $entries): array {
		return array_values(array_map(static fn(array $entry): array => [
			'id' => $entry['id'],
			'email' => $entry['email'],
			'storageLabel' => $entry['storageLabel'],
		], $entries));
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

	private function provisioningEnabled(array $config): bool {
		return ($config[AdminConfigService::KEY_PROVISIONING_ENABLED] ?? false) === true;
	}

	private function quotaSyncEnabled(array $config): bool {
		return in_array((string)($config[AdminConfigService::KEY_QUOTA_SYNC_MODE] ?? 'disabled'), ['manual', 'event_scheduled'], true);
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
