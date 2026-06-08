<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\IntegrationImmich\Listener;

use OCA\IntegrationImmich\BackgroundJob\SyncImmichUserJob;
use OCA\IntegrationImmich\Service\AdminConfigService;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/** @template-implements IEventListener<Event> */
class AccountUpdatedListener implements IEventListener {
    private const IDENTITY_KEYS = [
        'displayname',
        'display-name',
        'displayName',
        'email',
        'eMailAddress',
    ];

    public function __construct(
        private AdminConfigService $adminConfigService,
        private IJobList $jobList,
    ) {
    }

    public function handle(Event $event): void {
        if (!$this->isProvisioningEnabled() || !$this->touchesIdentity($event)) {
            return;
        }

        $ncUid = $this->extractUid($event);
        if ($ncUid === null) {
            return;
        }

        $this->jobList->add(SyncImmichUserJob::class, ['ncUid' => $ncUid]);
    }

    private function touchesIdentity(Event $event): bool {
        if (!method_exists($event, 'getData')) {
            return false;
        }

        try {
            $data = $event->getData();
        } catch (\Throwable) {
            return false;
        }

        if (!is_array($data)) {
            return false;
        }

        foreach (self::IDENTITY_KEYS as $key) {
            if (array_key_exists($key, $data)) {
                return true;
            }
        }

        return false;
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
