<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\IntegrationImmich\Listener;

use OCA\IntegrationImmich\BackgroundJob\ReconcileUsersJob;
use OCA\IntegrationImmich\Service\AdminConfigService;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/** @template-implements IEventListener<Event> */
class GroupMembershipListener implements IEventListener {
    public function __construct(
        private AdminConfigService $adminConfigService,
        private IJobList $jobList,
    ) {
    }

    public function handle(Event $event): void {
        $config = $this->adminConfig();
        if (($config[AdminConfigService::KEY_PROVISIONING_ENABLED] ?? false) !== true) {
            return;
        }

        if (($config[AdminConfigService::KEY_USER_SCOPE_MODE] ?? 'all') !== 'groups') {
            return;
        }

        $groupId = $this->extractGroupId($event);
        if ($groupId === null || !in_array($groupId, $this->configuredGroups($config[AdminConfigService::KEY_USER_SCOPE_GROUPS] ?? []), true)) {
            return;
        }

        $ncUid = $this->extractUid($event);
        if ($ncUid === null) {
            return;
        }

        $this->jobList->add(ReconcileUsersJob::class, ['ncUid' => $ncUid]);
    }

    private function adminConfig(): array {
        try {
            return $this->adminConfigService->getAdminConfig();
        } catch (\Throwable) {
            return [];
        }
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

    private function extractGroupId(Event $event): ?string {
        if (!method_exists($event, 'getGroup')) {
            return null;
        }

        try {
            $group = $event->getGroup();
        } catch (\Throwable) {
            return null;
        }

        if (!is_object($group) || !method_exists($group, 'getGID')) {
            return null;
        }

        try {
            $groupId = $group->getGID();
        } catch (\Throwable) {
            return null;
        }

        return is_string($groupId) && trim($groupId) !== '' ? trim($groupId) : null;
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
