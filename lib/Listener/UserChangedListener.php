<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\IntegrationImmich\Listener;

use OCA\IntegrationImmich\BackgroundJob\ReconcileUsersJob;
use OCA\IntegrationImmich\BackgroundJob\SyncImmichUserJob;
use OCA\IntegrationImmich\BackgroundJob\SyncQuotaJob;
use OCA\IntegrationImmich\Service\AdminConfigService;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/** @template-implements IEventListener<Event> */
class UserChangedListener implements IEventListener {
    private const PROFILE_FEATURES = [
        'displayName',
        'displayname',
        'eMailAddress',
        'email',
    ];

    private const SCOPE_FEATURES = [
        'enabled',
        'disabled',
    ];

    public function __construct(
        private AdminConfigService $adminConfigService,
        private IJobList $jobList,
    ) {
    }

    public function handle(Event $event): void {
        $jobClass = $this->jobClassForEvent($event);
        if ($jobClass === null) {
            return;
        }

        if ($jobClass !== ReconcileUsersJob::class && !$this->isProvisioningEnabled()) {
            return;
        }

        $ncUid = $this->extractUid($event);
        if ($ncUid === null) {
            return;
        }

        $this->jobList->add($jobClass, ['ncUid' => $ncUid]);
    }

    private function jobClassForEvent(Event $event): ?string {
        if (method_exists($event, 'getQuota') && method_exists($event, 'setQuota')) {
            return SyncQuotaJob::class;
        }

        if (!method_exists($event, 'getFeature')) {
            return null;
        }

        try {
            $feature = $event->getFeature();
        } catch (\Throwable) {
            return null;
        }

        if (!is_string($feature)) {
            return null;
        }

        if ($feature === 'quota') {
            return SyncQuotaJob::class;
        }

        if (in_array($feature, self::SCOPE_FEATURES, true)) {
            return ReconcileUsersJob::class;
        }

        return in_array($feature, self::PROFILE_FEATURES, true) ? SyncImmichUserJob::class : null;
    }

    private function isProvisioningEnabled(): bool {
        try {
            $config = $this->adminConfigService->getAdminConfig();
        } catch (\Throwable) {
            return false;
        }

        return ($config[AdminConfigService::KEY_PROVISIONING_ENABLED] ?? false) === true;
    }

    private function extractUid(Event $event): ?string {
        if (!method_exists($event, 'getUser')) {
            return null;
        }

        try {
            $user = $event->getUser();
        } catch (\Throwable) {
            return null;
        }

        if (!is_object($user) || !method_exists($user, 'getUID')) {
            return null;
        }

        try {
            $uid = $user->getUID();
        } catch (\Throwable) {
            return null;
        }

        return is_string($uid) && trim($uid) !== '' ? trim($uid) : null;
    }
}
