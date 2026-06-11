<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\IntegrationImmich\BackgroundJob;

use OCA\IntegrationImmich\AppInfo\Application;
use OCA\IntegrationImmich\Service\AdminConfigService;
use OCA\IntegrationImmich\Service\ProvisioningService;
use OCA\IntegrationImmich\Service\SyncStateService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class ProvisionImmichUserJob extends QueuedJob {
    private ?array $lastResult = null;

    public function __construct(
        ITimeFactory $timeFactory,
        private AdminConfigService $adminConfigService,
        private ProvisioningService $provisioningService,
        private SyncStateService $syncStateService,
        private IUserManager $userManager,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
        private IJobList $jobList,
    ) {
        parent::__construct($timeFactory);
    }

    protected function run($argument): void {
        try {
            $this->lastResult = $this->provisionForUser($this->extractNcUid($argument));
        } catch (\Throwable $e) {
            $error = $this->redact($e->getMessage());
            $this->logger->warning('Immich user provisioning job failed before a user could be resolved: ' . $error, [
                'app' => Application::APP_ID,
            ]);
            $this->lastResult = $this->failureResult('', $error);
        }
    }

    public function provisionForUser(string $ncUid): array {
        $ncUid = trim($ncUid);
        if ($ncUid === '') {
            return $this->failureResult('', 'ProvisionImmichUserJob requires a non-empty ncUid argument.');
        }

        try {
            $scope = $this->scopeForUser($ncUid);
            if (!$scope['inScope']) {
                $status = $scope['error'] === null ? SyncStateService::STATUS_OUT_OF_SCOPE : SyncStateService::STATUS_FAILED;
                $this->persistStatusIfPossible($ncUid, $status, $status, $scope['error']);

                return $this->skippedResult($ncUid, $scope['reason'], $scope['error']);
            }

            $result = $this->provisioningService->reconcileUser($ncUid, false);
            $normalised = $this->normaliseReconcileResult($ncUid, $result);
            if ($normalised['status'] === 'success' && ($normalised['immichUserId'] ?? null) !== null) {
                $normalised['queued'] = $this->enqueueFollowUpJobs($ncUid);
            }

            return $normalised;
        } catch (\Throwable $e) {
            $error = $this->redact($e->getMessage());
            $this->persistStatusIfPossible($ncUid, SyncStateService::STATUS_FAILED, SyncStateService::STATUS_FAILED, $error);
            $this->logger->warning('Immich user provisioning job failed for Nextcloud user "' . $ncUid . '": ' . $error, [
                'app' => Application::APP_ID,
                'ncUid' => $ncUid,
            ]);

            return $this->failureResult($ncUid, $error);
        }
    }

    public function getLastResult(): ?array {
        return $this->lastResult;
    }

    private function extractNcUid(mixed $argument): string {
        $ncUid = '';
        if (is_array($argument) && isset($argument['ncUid']) && is_string($argument['ncUid'])) {
            $ncUid = $argument['ncUid'];
        } elseif (is_string($argument)) {
            $ncUid = $argument;
        }

        $ncUid = trim($ncUid);
        if ($ncUid === '') {
            throw new \InvalidArgumentException('ProvisionImmichUserJob requires a non-empty ncUid argument.');
        }

        return $ncUid;
    }

    /**
     * @return array{inScope: bool, reason: string, error: ?string}
     */
    private function scopeForUser(string $ncUid): array {
        $config = $this->adminConfigService->getAdminConfig();
        if (($config[AdminConfigService::KEY_PROVISIONING_ENABLED] ?? false) !== true) {
            return [
                'inScope' => false,
                'reason' => 'Provisioning is disabled.',
                'error' => null,
            ];
        }

        if ($this->userManager->get($ncUid) === null) {
            return [
                'inScope' => false,
                'reason' => 'Nextcloud user was not found.',
                'error' => 'Nextcloud user was not found.',
            ];
        }

        if (($config[AdminConfigService::KEY_USER_SCOPE_MODE] ?? 'all') !== 'groups') {
            return [
                'inScope' => true,
                'reason' => 'User is in scope.',
                'error' => null,
            ];
        }

        foreach ($this->configuredGroups($config[AdminConfigService::KEY_USER_SCOPE_GROUPS] ?? []) as $groupId) {
            if ($this->groupManager->isInGroup($ncUid, $groupId)) {
                return [
                    'inScope' => true,
                    'reason' => 'User is in scope.',
                    'error' => null,
                ];
            }
        }

        return [
            'inScope' => false,
            'reason' => 'Nextcloud user is outside configured provisioning groups.',
            'error' => null,
        ];
    }

    /**
     * @return string[]
     */
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

    private function persistStatusIfPossible(string $ncUid, string $scopeStatus, string $lastSyncStatus, ?string $error): void {
        try {
            $this->syncStateService->updateStatus($ncUid, $scopeStatus, $lastSyncStatus, $error);
        } catch (\Throwable) {
            // A mapping may not exist yet. The structured job result remains the retry-safe source of truth.
        }
    }

    private function normaliseReconcileResult(string $ncUid, array $result): array {
        $errors = $this->redactErrors($result['errors'] ?? []);
        if ($errors !== []) {
            $this->logger->warning('Immich user provisioning job completed with errors for Nextcloud user "' . $ncUid . '": ' . implode('; ', $errors), [
                'app' => Application::APP_ID,
                'ncUid' => $ncUid,
            ]);
        }

        return [
            'job' => 'provision_immich_user',
            'ncUid' => $ncUid,
            'status' => $this->statusFromReconcile($result, $errors),
            'action' => (string)($result['action'] ?? 'unknown'),
            'immichUserId' => $result['immichUserId'] ?? null,
            'storageLabel' => $result['storageLabel'] ?? null,
            'quotaSet' => $result['quotaSet'] ?? null,
            'queued' => [],
            'errors' => $errors,
        ];
    }

    private function enqueueFollowUpJobs(string $ncUid): array {
        $jobs = [ProvisionNextcloudMountJob::class];
        if ($this->isQuotaSyncEnabled()) {
            $jobs[] = SyncQuotaJob::class;
        }

        $queued = [];
        foreach ($jobs as $jobClass) {
            $argument = ['ncUid' => $ncUid];
            if (!$this->jobList->has($jobClass, $argument)) {
                $this->jobList->add($jobClass, $argument);
            }
            $queued[] = [
                'job' => $jobClass,
                'ncUid' => $ncUid,
            ];
        }

        return $queued;
    }

    private function isQuotaSyncEnabled(): bool {
        try {
            $config = $this->adminConfigService->getAdminConfig();
        } catch (\Throwable) {
            return false;
        }

        return in_array((string)($config[AdminConfigService::KEY_QUOTA_SYNC_MODE] ?? 'disabled'), ['manual', 'event_scheduled'], true);
    }

    private function statusFromReconcile(array $result, array $errors): string {
        if ($errors !== []) {
            return 'failed';
        }

        return ($result['action'] ?? null) === 'skipped' ? 'skipped' : 'success';
    }

    private function skippedResult(string $ncUid, string $reason, ?string $error): array {
        return [
            'job' => 'provision_immich_user',
            'ncUid' => $ncUid,
            'status' => 'skipped',
            'action' => 'skipped',
            'reason' => $reason,
            'errors' => $error === null ? [] : [$this->redact($error)],
        ];
    }

    private function failureResult(?string $ncUid, string $error): array {
        return [
            'job' => 'provision_immich_user',
            'ncUid' => $ncUid,
            'status' => 'failed',
            'action' => 'skipped',
            'errors' => [$this->redact($error)],
        ];
    }

    private function redactErrors(mixed $errors): array {
        if (!is_array($errors)) {
            return [];
        }

        return array_values(array_map(fn(mixed $error): string => $this->redact(is_scalar($error) ? (string)$error : json_encode($error, JSON_THROW_ON_ERROR)), $errors));
    }

    private function redact(string $message): string {
        $patterns = [
            '/("(?:password|admin_api_key|apiKey|api_key|x-api-key|token|secret)"\s*:\s*")[^"]+(")/i',
            '/((?:password|admin_api_key|apiKey|api_key|x-api-key|token|secret)\s*[=:]\s*)[^\s,;]+/i',
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
}
