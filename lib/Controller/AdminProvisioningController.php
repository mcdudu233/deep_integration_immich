<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\IntegrationImmich\Controller;

use DateTimeInterface;
use OCA\IntegrationImmich\AppInfo\Application;
use OCA\IntegrationImmich\BackgroundJob\ReconcileUsersJob;
use OCA\IntegrationImmich\BackgroundJob\SyncQuotaJob;
use OCA\IntegrationImmich\BackgroundJob\VerifyProvisioningJob;
use OCA\IntegrationImmich\Db\SyncState;
use OCA\IntegrationImmich\Service\ProvisioningService;
use OCA\IntegrationImmich\Service\ExternalStorageProvisioner;
use OCA\IntegrationImmich\Service\ImmichUserAdminService;
use OCA\IntegrationImmich\Service\QuotaSyncService;
use OCA\IntegrationImmich\Service\SyncStateService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class AdminProvisioningController extends Controller {
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 200;
    private const QUOTA_QUEUE_PAGE_SIZE = 500;

    public function __construct(
        IRequest $request,
        private ProvisioningService $provisioningService,
		private SyncStateService $syncStateService,
		private IJobList $jobList,
		private ReconcileUsersJob $reconcileUsersJob,
		private SyncQuotaJob $syncQuotaJob,
		private VerifyProvisioningJob $verifyProvisioningJob,
		private ExternalStorageProvisioner $externalStorageProvisioner,
		private QuotaSyncService $quotaSyncService,
		private ImmichUserAdminService $immichUserAdminService,
		private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[AdminRequired]
    #[NoCSRFRequired]
    public function dryRun(string $ncUid): JSONResponse {
        $ncUid = $this->normaliseUid($ncUid);
        if ($ncUid === null) {
            return $this->invalidUidResponse();
        }

        try {
            return $this->success([
                'plan' => $this->redact($this->provisioningService->reconcileUser($ncUid, true)),
            ]);
        } catch (\Throwable $e) {
            return $this->serviceErrorResponse('dry_run_failed', 'Dry-run provisioning failed.', $e);
		}
	}

	#[AdminRequired]
	#[NoCSRFRequired]
	public function dryRunAll(): JSONResponse {
		try {
			return $this->success([
				'plan' => $this->redact($this->reconcileUsersJob->reconcileAllScopedUsers(true)),
			]);
		} catch (\Throwable $e) {
			return $this->serviceErrorResponse('dry_run_failed', 'Dry-run provisioning failed.', $e);
		}
	}

	#[AdminRequired]
	public function reconcileOne(string $ncUid): JSONResponse {
        $ncUid = $this->normaliseUid($ncUid);
        if ($ncUid === null) {
            return $this->invalidUidResponse();
        }

        try {
            $queued = $this->queueJob(ReconcileUsersJob::class, ['ncUid' => $ncUid]);
            return $this->success([
                'queued' => [$queued],
                'count' => 1,
            ]);
        } catch (\Throwable $e) {
            return $this->serviceErrorResponse('reconcile_queue_failed', 'Failed to queue reconcile job.', $e);
        }
    }

    #[AdminRequired]
    public function reconcileAll(): JSONResponse {
        try {
            $queued = $this->queueJob(ReconcileUsersJob::class, null);
            return $this->success([
                'queued' => [$queued],
                'count' => 1,
                'scope' => 'all_scoped_users',
            ]);
        } catch (\Throwable $e) {
            return $this->serviceErrorResponse('reconcile_queue_failed', 'Failed to queue scoped-user reconcile job.', $e);
        }
    }

    #[AdminRequired]
    public function recomputeQuotaOne(string $ncUid): JSONResponse {
        $ncUid = $this->normaliseUid($ncUid);
        if ($ncUid === null) {
            return $this->invalidUidResponse();
        }

        try {
            $result = $this->syncQuotaJob->syncForUser($ncUid);
            return $this->success([
                'results' => [$result],
                'count' => 1,
            ]);
        } catch (\Throwable $e) {
            return $this->serviceErrorResponse('quota_sync_failed', 'Failed to sync Immich quota.', $e);
        }
    }

	#[AdminRequired]
	public function recomputeQuotaAll(): JSONResponse {
		try {
			$queued = [];
			$offset = 0;
			do {
				$states = $this->syncStateService->listMappedStates(self::QUOTA_QUEUE_PAGE_SIZE, $offset);
				foreach ($states as $state) {
                    $ncUid = $this->normaliseUid($state->getNcUid());
                    if ($ncUid === null) {
                        continue;
                    }

					$queued[] = $this->queueJob(SyncQuotaJob::class, ['ncUid' => $ncUid]);
				}

                $stateCount = count($states);
                $offset += self::QUOTA_QUEUE_PAGE_SIZE;
            } while ($stateCount === self::QUOTA_QUEUE_PAGE_SIZE);

			return $this->success([
				'queued' => $queued,
				'count' => count($queued),
				'scope' => 'mapped_users',
			]);
		} catch (\Throwable $e) {
            return $this->serviceErrorResponse('quota_sync_failed', 'Failed to sync Immich quotas for mapped users.', $e);
        }
    }

    #[AdminRequired]
    #[NoCSRFRequired]
    public function listSyncState(): JSONResponse {
        try {
            $limit = $this->intParam('limit', self::DEFAULT_LIMIT, 1, self::MAX_LIMIT);
            $offset = $this->intParam('offset', 0, 0, PHP_INT_MAX);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse('invalid_pagination', $e->getMessage(), Http::STATUS_BAD_REQUEST);
        }

        try {
            $states = $this->syncStateService->listStates($limit + 1, $offset);
            $hasMore = count($states) > $limit;
            $states = array_slice($states, 0, $limit);

            return $this->success([
                'sync_state' => array_map(fn(SyncState $state): array => $this->stateToArray($state), $states),
                'pagination' => [
                    'limit' => $limit,
                    'offset' => $offset,
                    'count' => count($states),
                    'has_more' => $hasMore,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->serviceErrorResponse('sync_state_list_failed', 'Failed to list sync state.', $e);
        }
    }

    #[AdminRequired]
    public function verifyHealth(string $ncUid): JSONResponse {
        $ncUid = $this->normaliseUid($ncUid);
        if ($ncUid === null) {
            return $this->invalidUidResponse();
        }

        try {
            return $this->success([
                'health' => $this->redact($this->verifyProvisioningJob->verifyOneUser($ncUid)),
            ]);
        } catch (\Throwable $e) {
            return $this->serviceErrorResponse('health_verification_failed', 'Failed to verify provisioning health.', $e);
        }
    }

    private function queueJob(string $jobClass, mixed $argument): array {
        $this->jobList->add($jobClass, $argument);

        return [
            'job' => $jobClass,
            'argument' => $argument,
        ];
    }

    private function intParam(string $name, int $default, int $min, int $max): int {
        $value = $this->request->getParam($name, $default);
        if (is_int($value)) {
            $intValue = $value;
        } elseif (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            $intValue = (int)trim($value);
        } else {
            throw new \InvalidArgumentException('Parameter "' . $name . '" must be an integer.');
        }

        if ($intValue < $min || $intValue > $max) {
            throw new \InvalidArgumentException('Parameter "' . $name . '" must be between ' . $min . ' and ' . $max . '.');
        }

        return $intValue;
    }

    private function normaliseUid(string $ncUid): ?string {
        $ncUid = trim($ncUid);
        if ($ncUid === '' || preg_match('/\0/', $ncUid) === 1) {
            return null;
        }

        return $ncUid;
    }

    private function stateToArray(SyncState $state): array {
        $data = $state->jsonSerialize();
        $data['mount'] = $this->mountSummary($state);
        $data['quota'] = $this->quotaSummary($state);

        return $this->redact($data);
    }

    private function mountSummary(SyncState $state): array {
        try {
            return $this->externalStorageProvisioner->verifyMount((string)$state->getNcUid());
        } catch (\Throwable $e) {
            return [
                'status' => 'unavailable',
                'mount_id' => $state->getNcMountId(),
                'mount_name' => null,
                'read_only' => null,
                'error' => $this->redactString($e->getMessage()),
            ];
        }
    }

    private function quotaSummary(SyncState $state): array {
        $immichUserId = trim((string)$state->getImmichUserId());
        if ($immichUserId === '') {
            return [
                'status' => 'unavailable',
                'warningCode' => 'quota_needs_mapping',
            ];
        }

		$status = 'ok';
		$warningCode = null;
		$lastError = $this->redactString((string)($state->getLastError() ?? '')) ?: null;
		$details = $this->quotaSyncService->computeNextcloudQuotaSnapshot((string)$state->getNcUid());
		if ($state->getLastSyncStatus() === SyncStateService::STATUS_QUOTA_FAILED) {
			$status = 'failed';
			$warningCode = 'quota_unavailable';
		} elseif (($details['error'] ?? null) !== null) {
			$status = 'failed';
			$warningCode = 'quota_unavailable';
			$lastError = $this->redactString((string)$details['error']);
		} elseif (($details['unlimited'] ?? false) === true) {
			$status = 'unlimited';
			$warningCode = 'quota_unlimited';
		} elseif ($state->getLastQuotaSyncAt() === null) {
			$status = 'stale';
			$warningCode = 'quota_stale';
        }

        return [
			'status' => $status,
			'mode' => null,
			'ncQuota' => $details['ncQuota'] ?? null,
			'ncUsed' => $details['ncUsed'] ?? null,
			'ncRemaining' => $details['ncRemaining'] ?? null,
			'immichUsage' => null,
			'immichAvailable' => null,
			'computedImmichQuota' => null,
			'reserve' => $details['reserve'] ?? null,
			'warningCode' => $warningCode,
			'warning' => $status === 'failed' ? $lastError : null,
			'lastSyncAt' => $this->formatDateTime($state->getLastQuotaSyncAt()),
		];
	}

    private function invalidUidResponse(): JSONResponse {
        return $this->errorResponse(
            'invalid_nc_uid',
            'Nextcloud user id must not be empty.',
            Http::STATUS_BAD_REQUEST
        );
    }

    private function serviceErrorResponse(string $code, string $message, \Throwable $e): JSONResponse {
        $detail = $this->redactString($e->getMessage());
        $this->logger->warning($message . ' ' . $detail, [
            'app' => Application::APP_ID,
        ]);

        return $this->errorResponse($code, $message, Http::STATUS_INTERNAL_SERVER_ERROR, [
            'detail' => $detail,
        ]);
    }

    private function success(array $payload = [], int $status = Http::STATUS_OK): JSONResponse {
        return new JSONResponse(array_merge(['success' => true], $payload), $status);
    }

    private function errorResponse(string $code, string $message, int $status, array $details = []): JSONResponse {
        return new JSONResponse([
            'success' => false,
            'error' => array_filter([
                'code' => $code,
                'message' => $message,
                'details' => $this->redact($details),
            ], static fn(mixed $value): bool => $value !== [] && $value !== null),
        ], $status);
    }

    private function redact(mixed $value): mixed {
        if (is_array($value)) {
            $redacted = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && $this->isSecretKey($key)) {
                    $redacted[$key] = '[redacted]';
                    continue;
                }

                $redacted[$key] = $this->redact($item);
            }

            return $redacted;
        }

        if (is_string($value)) {
            return $this->redactString($value);
        }

        return $value;
    }

    private function redactString(string $value): string {
        return preg_replace('/(api[_-]?key|token|password|secret|authorization)(\s*[=:]\s*)[^\s,;]+/i', '$1$2[redacted]', $value) ?? $value;
    }

    private function formatDateTime(?DateTimeInterface $dateTime): ?string {
        return $dateTime?->format(DateTimeInterface::ATOM);
    }

    private function isSecretKey(string $key): bool {
        if (preg_match('/(?:configured|_set)$/i', $key) === 1) {
            return false;
        }

        return preg_match('/(^|[_-])(api[_-]?key|token|password|secret|authorization)($|[_-])/i', $key) === 1;
    }
}
