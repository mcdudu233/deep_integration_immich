<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


declare(strict_types=1);

namespace OCA\IntegrationImmich\AppInfo;

use OCA\IntegrationImmich\BackgroundJob\SyncQuotaJob;
use OCA\IntegrationImmich\Db\SyncStateMapper;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\IntegrationImmich\Listener\AccountUpdatedListener;
use OCA\IntegrationImmich\Listener\CspListener;
use OCA\IntegrationImmich\Listener\GroupMembershipListener;
use OCA\IntegrationImmich\Listener\LoadAdditionalScriptsListener;
use OCA\IntegrationImmich\Listener\UserChangedListener;
use OCA\IntegrationImmich\Listener\UserCreatedListener;
use OCA\IntegrationImmich\Listener\UserDeletedListener;
use OCA\IntegrationImmich\Service\AdminConfigService;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\BackgroundJob\IJobList;
use OCP\IDBConnection;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;

class Application extends App implements IBootstrap {
    public const APP_ID = 'deep_integration_immich';

    private const DAILY_BACKGROUND_JOBS = [
        'OCA\\IntegrationImmich\\BackgroundJob\\ReconcileUsersJob',
        'OCA\\IntegrationImmich\\BackgroundJob\\VerifyProvisioningJob',
    ];

    private const LIFECYCLE_EVENT_LISTENERS = [
        'OCP\\User\\Events\\UserCreatedEvent' => UserCreatedListener::class,
        'OCP\\User\\Events\\UserChangedEvent' => UserChangedListener::class,
        'OCP\\User\\Events\\UserDeletedEvent' => UserDeletedListener::class,
        'OCP\\Accounts\\UserUpdatedEvent' => AccountUpdatedListener::class,
        'OCP\\Group\\Events\\UserAddedEvent' => GroupMembershipListener::class,
        'OCP\\Group\\Events\\UserRemovedEvent' => GroupMembershipListener::class,
        'OCP\\User\\GetQuotaEvent' => UserChangedListener::class,
        'OCP\\User\\Events\\UserIdAssignedEvent' => UserCreatedListener::class,
        'OCP\\User\\Events\\UserIdUnassignedEvent' => UserDeletedListener::class,
    ];

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        $context->registerEventListener(
            LoadAdditionalScriptsEvent::class,
            LoadAdditionalScriptsListener::class
        );
        $context->registerEventListener(
            AddContentSecurityPolicyEvent::class,
            CspListener::class
        );

        foreach (self::LIFECYCLE_EVENT_LISTENERS as $eventClass => $listenerClass) {
            if (class_exists($eventClass)) {
                $context->registerEventListener($eventClass, $listenerClass);
            }
        }
    }

    public function boot(IBootContext $context): void {
        $context->injectFn(function (IJobList $jobList, AdminConfigService $adminConfigService, IDBConnection $db): void {
            $this->registerTimedJobs($jobList, $adminConfigService, $db);
        });
    }

    public function registerTimedJobs(IJobList $jobList, AdminConfigService $adminConfigService, IDBConnection $db): void {
        $config = $adminConfigService->getAdminConfig();
        if (($config[AdminConfigService::KEY_PROVISIONING_ENABLED] ?? false) !== true) {
            return;
        }

        foreach (self::DAILY_BACKGROUND_JOBS as $jobClass) {
            $this->addJobIfAvailable($jobList, $jobClass, null);
        }

        if (($config[AdminConfigService::KEY_QUOTA_SYNC_MODE] ?? 'disabled') !== 'event_scheduled') {
            return;
        }

        foreach ($this->mappedUserIds($db) as $ncUid) {
            $this->addJobIfAvailable($jobList, SyncQuotaJob::class, ['ncUid' => $ncUid]);
        }
    }

    private function addJobIfAvailable(IJobList $jobList, string $jobClass, mixed $argument): void {
        if (!class_exists($jobClass) || $jobList->has($jobClass, $argument)) {
            return;
        }

        $jobList->add($jobClass, $argument);
    }

    /**
     * @return string[]
     */
    private function mappedUserIds(IDBConnection $db): array {
        try {
            $qb = $db->getQueryBuilder();
            $qb->select('nc_uid', 'immich_user_id')
                ->from(SyncStateMapper::TABLE_NAME);
            $result = $qb->executeQuery();
        } catch (\Throwable) {
            return [];
        }

        $uids = [];
        while (($row = $result->fetch()) !== false) {
            $ncUid = is_string($row['nc_uid'] ?? null) ? trim($row['nc_uid']) : '';
            $immichUserId = is_string($row['immich_user_id'] ?? null) ? trim($row['immich_user_id']) : '';
            if ($ncUid !== '' && $immichUserId !== '') {
                $uids[] = $ncUid;
            }
        }

        return array_values(array_unique($uids));
    }
}
