<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\IntegrationImmich\BackgroundJob;

use OCA\IntegrationImmich\AppInfo\Application;
use OCA\IntegrationImmich\Db\SyncState;
use OCA\IntegrationImmich\Service\AdminConfigService;
use OCA\IntegrationImmich\Service\SyncStateService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

class ScheduleQuotaSyncJob extends TimedJob {
    private const PAGE_SIZE = 500;

    protected ?array $lastResult = null;

    public function __construct(
        ITimeFactory $timeFactory,
        private AdminConfigService $adminConfigService,
        private SyncStateService $syncStateService,
        private IJobList $jobList,
        private LoggerInterface $logger,
    ) {
        parent::__construct($timeFactory);
        $this->setInterval($this->adminConfigService->getQuotaSyncIntervalSeconds());
    }

    protected function run($argument): void {
        $this->setInterval($this->adminConfigService->getQuotaSyncIntervalSeconds());
        $this->lastResult = $this->scheduleMappedUsers();
    }

    public function scheduleMappedUsers(): array {
        $config = $this->adminConfigService->getAdminConfig();
        $result = [
            'job' => 'schedule_quota_sync',
            'status' => 'skipped',
            'queued' => [],
            'skipped' => [],
            'errors' => [],
        ];

        if (($config[AdminConfigService::KEY_PROVISIONING_ENABLED] ?? false) !== true
            || ($config[AdminConfigService::KEY_QUOTA_SYNC_MODE] ?? 'disabled') !== 'event_scheduled') {
            return $result;
        }

        $offset = 0;
        do {
            try {
                $states = $this->syncStateService->listMappedStates(self::PAGE_SIZE, $offset);
            } catch (\Throwable $e) {
                $error = $this->redactError($e->getMessage());
                $this->logger->warning('Immich quota scheduler failed to list mapped users: ' . $error, [
                    'app' => Application::APP_ID,
                ]);
                $result['status'] = 'failed';
                $result['errors'][] = $error;
                return $result;
            }

            foreach ($states as $state) {
                $ncUid = $this->mappedNcUid($state);
                if ($ncUid === null) {
                    continue;
                }

                $argument = ['ncUid' => $ncUid];
                if ($this->jobList->has(SyncQuotaJob::class, $argument)) {
                    $result['skipped'][] = $ncUid;
                    continue;
                }

                $this->jobList->add(SyncQuotaJob::class, $argument);
                $result['queued'][] = $ncUid;
            }

            $offset += self::PAGE_SIZE;
        } while (count($states) === self::PAGE_SIZE);

        $result['status'] = $result['queued'] === [] ? 'unchanged' : 'queued';
        return $result;
    }

    private function mappedNcUid(SyncState $state): ?string {
        $ncUid = trim($state->getNcUid());
        $immichUserId = trim((string)$state->getImmichUserId());

        return $ncUid !== '' && $immichUserId !== '' ? $ncUid : null;
    }

    private function redactError(string $message): string {
        return preg_replace('/(api[_-]?key|token|password|secret|authorization)(\s*[=:]\s*)([^\s,&]+)/i', '$1$2[redacted]', $message) ?? $message;
    }
}
