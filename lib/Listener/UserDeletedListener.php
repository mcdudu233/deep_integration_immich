<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\IntegrationImmich\Listener;

use OCA\IntegrationImmich\BackgroundJob\ReconcileUsersJob;
use OCA\IntegrationImmich\Service\AdminConfigService;
use OCA\IntegrationImmich\Service\SyncStateService;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/** @template-implements IEventListener<Event> */
class UserDeletedListener implements IEventListener {
    public function __construct(
        private AdminConfigService $adminConfigService,
        private IJobList $jobList,
        private SyncStateService $syncStateService,
    ) {
    }

    public function handle(Event $event): void {
        $ncUid = $this->extractUid($event);
        if ($ncUid === null) {
            return;
        }

        try {
            $this->syncStateService->updateMapping($ncUid, [
                'scopeStatus' => SyncStateService::STATUS_DELETED,
                'lastSyncStatus' => SyncStateService::STATUS_DELETED,
                'lastError' => null,
            ]);
        } catch (\Throwable) {
            // Deleting a user without an existing mapping is safe; periodic reconcile can clean up later.
        }

        if (class_exists(ReconcileUsersJob::class) && !$this->jobList->has(ReconcileUsersJob::class, ['ncUid' => $ncUid])) {
            $this->jobList->add(ReconcileUsersJob::class, ['ncUid' => $ncUid]);
        }
    }

    private function extractUid(Event $event): ?string {
        foreach (['getUid', 'getUserId'] as $method) {
            if (!method_exists($event, $method)) {
                continue;
            }

            try {
                $uid = $event->{$method}();
            } catch (\Throwable) {
                continue;
            }

            if (is_string($uid) && trim($uid) !== '') {
                return trim($uid);
            }
        }

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
