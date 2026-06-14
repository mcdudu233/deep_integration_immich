<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\IntegrationImmich\BackgroundJob;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\IntegrationImmich\AppInfo\Application;
use OCA\IntegrationImmich\Db\SyncState;
use OCA\IntegrationImmich\Service\AdminConfigService;
use OCA\IntegrationImmich\Service\ImmichUserAdminService;
use OCA\IntegrationImmich\Service\QuotaSyncService;
use OCA\IntegrationImmich\Service\SyncStateService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\IRootFolder;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class SyncQuotaJob extends QueuedJob {
    private ?array $lastResult = null;

    public function __construct(
        ITimeFactory $timeFactory,
        private AdminConfigService $adminConfigService,
        private SyncStateService $syncStateService,
        private ImmichUserAdminService $immichUserAdminService,
        private QuotaSyncService $quotaSyncService,
        private IUserManager $userManager,
        private IRootFolder $rootFolder,
        private LoggerInterface $logger,
    ) {
        parent::__construct($timeFactory);
    }

    protected function run($argument): void {
        try {
            $this->lastResult = $this->syncForUser($this->extractNcUid($argument));
        } catch (\Throwable $e) {
            $error = $this->redactError($e->getMessage());
            $this->logger->warning('Immich quota sync job failed before a user could be resolved: ' . $error, [
                'app' => Application::APP_ID,
            ]);
            $this->lastResult = $this->baseResult('', new DateTimeImmutable(), [], SyncStateService::STATUS_QUOTA_FAILED, 'skipped', $error);
        }
    }

    public function syncForUser(string $ncUid): array {
        $lastSyncAt = new DateTimeImmutable();
        $config = [];
        $result = $this->baseResult($ncUid, $lastSyncAt, $config);

        try {
            $config = $this->adminConfigService->getAdminConfig();
            $result = $this->baseResult($ncUid, $lastSyncAt, $config);

            if (!$this->isQuotaSyncEnabled($config)) {
                $result['status'] = 'skipped';
                $result['action'] = 'disabled';
                return $result;
            }

            $state = $this->syncStateService->findByUid($ncUid);
            if ($state === null) {
                return $this->fail($result, null, 'No sync state mapping exists for Nextcloud user "' . $ncUid . '".');
            }

            $immichUserId = $this->mappedImmichUserId($state);
            $result['immich_user_id'] = $immichUserId;
            if ($immichUserId === null) {
                return $this->fail($result, $ncUid, 'No Immich user mapping exists for Nextcloud user "' . $ncUid . '".');
            }

			$immichQuotaState = $this->immichUserAdminService->getUserQuotaState($immichUserId);
			if (!$immichQuotaState['found']) {
				return $this->fail($result, $ncUid, 'Mapped Immich user "' . $immichUserId . '" was not found.');
			}

			$immichUsage = $immichQuotaState['quotaUsageInBytes'];
			$result['immich_usage'] = $immichUsage;
			if ($immichUsage === null) {
				return $this->fail($result, $ncUid, 'Immich quota usage is unavailable for mapped user "' . $immichUserId . '".');
            }

            $quotaDetails = $this->quotaSyncService->computeQuotaDetails($ncUid, $immichUsage);
            $computedQuota = $quotaDetails['computedImmichQuota'];
            $result = array_merge($result, $this->quotaDetailsResult($quotaDetails));

            $quotaError = $this->quotaSyncService->getLastError();
            if ($computedQuota === null && $quotaError !== null) {
                return $this->fail($result, $ncUid, $quotaError);
            }

            if ($computedQuota === null && !$this->quotaSyncService->wasLastQuotaUnlimited()) {
                return $this->fail($result, $ncUid, 'Immich quota computation returned no quota.');
            }

            if ($computedQuota !== null && $computedQuota < $immichUsage) {
                return $this->fail($result, $ncUid, 'Computed Immich quota is below current Immich usage.');
            }

			$currentQuota = $immichQuotaState['quotaSizeInBytes'];
			$result['current_immich_quota'] = $currentQuota;

			$quotaChanged = $currentQuota !== $computedQuota;
            if ($quotaChanged) {
                $this->immichUserAdminService->updateUser($immichUserId, [
                    'quotaSizeInBytes' => $computedQuota,
                ]);
            }

            $this->persistSuccess($ncUid, $lastSyncAt);

            $result['status'] = SyncStateService::STATUS_ACTIVE;
            $result['action'] = $quotaChanged ? 'updated' : 'unchanged';
            $result['updated'] = $quotaChanged;
            $result['last_sync_at'] = $this->formatDateTime($lastSyncAt);

            return $result;
        } catch (\Throwable $e) {
            return $this->fail($result, $ncUid, $e->getMessage());
        }
    }

    public function getLastResult(): ?array {
        return $this->lastResult;
    }

    private function extractNcUid(mixed $argument): string {
        $ncUid = is_array($argument) ? (string)($argument['ncUid'] ?? '') : (string)$argument;
        $ncUid = trim($ncUid);
        if ($ncUid === '') {
            throw new \InvalidArgumentException('SyncQuotaJob requires a non-empty ncUid argument.');
        }

        return $ncUid;
    }

    private function baseResult(string $ncUid, DateTimeInterface $lastSyncAt, array $config, string $status = 'pending', string $action = 'pending', ?string $error = null): array {
        return [
            'ncUid' => $ncUid,
            'status' => $status,
            'action' => $action,
            'updated' => false,
            'quota_sync_mode' => (string)($config[AdminConfigService::KEY_QUOTA_SYNC_MODE] ?? 'disabled'),
            'immich_user_id' => null,
            'current_immich_quota' => null,
            'nc_quota' => null,
            'nc_used' => null,
            'immich_usage' => null,
            'non_immich_used' => null,
            'reserve' => $this->reserveBytes($config),
            'computed_immich_quota' => null,
            'last_sync_at' => $this->formatDateTime($lastSyncAt),
            'error' => $error,
        ];
    }

    private function isQuotaSyncEnabled(array $config): bool {
        return in_array((string)($config[AdminConfigService::KEY_QUOTA_SYNC_MODE] ?? 'disabled'), ['manual', 'event_scheduled'], true);
    }

    private function mappedImmichUserId(SyncState $state): ?string {
        $immichUserId = trim((string)$state->getImmichUserId());
        return $immichUserId === '' ? null : $immichUserId;
    }

    private function quotaDetailsResult(array $quotaDetails): array {
        return [
            'nc_quota' => $quotaDetails['ncQuota'] ?? null,
            'nc_used' => $quotaDetails['ncUsed'] ?? null,
            'nc_remaining' => $quotaDetails['ncRemaining'] ?? null,
            'immich_usage' => $quotaDetails['immichUsage'] ?? null,
            'immich_available' => $quotaDetails['immichAvailable'] ?? null,
            'non_immich_used' => $quotaDetails['nonImmichUsed'] ?? null,
            'reserve' => $quotaDetails['reserve'] ?? 0,
            'computed_immich_quota' => $quotaDetails['computedImmichQuota'] ?? null,
            'usage_refresh' => $quotaDetails['usageRefresh'] ?? null,
        ];
    }

    private function nextcloudQuotaBytes(string $ncUid): ?int {
        try {
            $user = $this->userManager->get($ncUid);
            if ($user === null) {
                return null;
            }

            return $this->parseQuota($user->getQuota());
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseQuota(mixed $quota): ?int {
        if (is_int($quota)) {
            return $quota > 0 ? $quota : null;
        }

        if (!is_string($quota)) {
            return null;
        }

        $quota = trim($quota);
        if ($quota === '' || in_array(strtolower($quota), ['none', 'unlimited', '-1'], true)) {
            return null;
        }

        if (preg_match('/^\d+$/', $quota) === 1) {
            $bytes = (int)$quota;
            return $bytes > 0 ? $bytes : null;
        }

        if (preg_match('/^(\d+(?:\.\d+)?)\s*([kmgtp])i?b?$/i', $quota, $matches) === 1) {
            $bytes = (int)round((float)$matches[1] * $this->quotaUnitMultiplier(strtolower($matches[2])));
            return $bytes > 0 ? $bytes : null;
        }

        return null;
    }

    private function quotaUnitMultiplier(string $unit): int {
        return match ($unit) {
            'k' => 1024,
            'm' => 1024 ** 2,
            'g' => 1024 ** 3,
            't' => 1024 ** 4,
            'p' => 1024 ** 5,
            default => 1,
        };
    }

    private function nextcloudUsedBytes(string $ncUid): ?int {
        try {
            $usage = $this->rootFolder->getUserFolder($ncUid)->getSize();
            return is_numeric($usage) ? (int)$usage : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function persistSuccess(string $ncUid, DateTimeInterface $lastSyncAt): void {
        $this->syncStateService->updateMapping($ncUid, [
            'lastSyncStatus' => SyncStateService::STATUS_ACTIVE,
            'lastError' => null,
            'lastQuotaSyncAt' => $lastSyncAt,
        ]);
    }

    private function fail(array $result, ?string $ncUid, string $error): array {
        $error = $this->redactError($error);

        if ($ncUid !== null && $ncUid !== '') {
            $this->persistQuotaFailure($ncUid, $error);
        }

        $this->logger->warning('Immich quota sync failed for Nextcloud user "' . $result['ncUid'] . '": ' . $error, [
            'app' => Application::APP_ID,
            'ncUid' => $result['ncUid'],
        ]);

        $result['status'] = SyncStateService::STATUS_QUOTA_FAILED;
        $result['action'] = 'skipped';
        $result['updated'] = false;
        $result['error'] = $error;

        return $result;
    }

    private function persistQuotaFailure(string $ncUid, string $error): void {
        try {
            $this->syncStateService->updateMapping($ncUid, [
                'lastSyncStatus' => SyncStateService::STATUS_QUOTA_FAILED,
                'lastError' => $error,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to persist Immich quota sync failure for Nextcloud user "' . $ncUid . '": ' . $this->redactError($e->getMessage()), [
                'app' => Application::APP_ID,
                'ncUid' => $ncUid,
            ]);
        }
    }

    private function reserveBytes(array $config): int {
        $reserve = $config[AdminConfigService::KEY_QUOTA_RESERVE_BYTES] ?? 0;
        if (is_int($reserve)) {
            return max(0, $reserve);
        }

        if (is_string($reserve) && preg_match('/^\d+$/', trim($reserve)) === 1) {
            return (int)trim($reserve);
        }

        return 0;
    }

    private function redactError(string $message): string {
        $message = preg_replace('/([?&](?:api[_-]?key|token|password)=)[^&\s]+/i', '$1[redacted]', $message) ?? $message;
        return preg_replace('/\b(api[_-]?key|token|password|authorization)(\s*[=:]\s*)[^\s,;}]+/i', '$1$2[redacted]', $message) ?? $message;
    }

    private function formatDateTime(DateTimeInterface $dateTime): string {
        return $dateTime->format(DateTimeInterface::ATOM);
    }
}
