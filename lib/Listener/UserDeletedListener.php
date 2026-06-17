<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\IntegrationImmich\Listener;

use OCA\IntegrationImmich\AppInfo\Application;
use OCA\IntegrationImmich\Service\AdminConfigService;
use OCA\IntegrationImmich\Service\ImmichUserAdminService;
use OCA\IntegrationImmich\Service\SyncStateService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/** @template-implements IEventListener<Event> */
class UserDeletedListener implements IEventListener {
    public function __construct(
        private AdminConfigService $adminConfigService,
        private SyncStateService $syncStateService,
        private ImmichUserAdminService $immichUserAdminService,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        $ncUid = $this->extractUid($event);
        if ($ncUid === null) {
            return;
        }

        $state = null;
        try {
            $state = $this->syncStateService->findByUid($ncUid);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to look up Immich sync state for deleted Nextcloud user "' . $ncUid . '": ' . $e->getMessage(), [
                'app' => Application::APP_ID,
                'ncUid' => $ncUid,
            ]);
        }

        $immichUserId = $state === null ? '' : trim((string)$state->getImmichUserId());
        if ($immichUserId !== '') {
            $this->cleanupImmichUser($immichUserId, $ncUid);
        }

        try {
            $this->syncStateService->deleteByUid($ncUid);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to drop Immich sync state for deleted Nextcloud user "' . $ncUid . '": ' . $e->getMessage(), [
                'app' => Application::APP_ID,
                'ncUid' => $ncUid,
            ]);
        }
    }

    private function cleanupImmichUser(string $immichUserId, string $ncUid): void {
        $destructive = $this->adminConfigService->allowsDestructiveUserDelete();

        try {
            if ($destructive) {
                $this->immichUserAdminService->deleteUser($immichUserId);
                return;
            }

            $this->immichUserAdminService->disableUser($immichUserId);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to clean up Immich user "' . $immichUserId . '" for deleted Nextcloud user "' . $ncUid . '": ' . $e->getMessage(), [
                'app' => Application::APP_ID,
                'ncUid' => $ncUid,
                'immichUserId' => $immichUserId,
                'destructive' => $destructive,
            ]);
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
