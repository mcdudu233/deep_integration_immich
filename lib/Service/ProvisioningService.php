<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\IntegrationImmich\Service;

use DateTimeImmutable;
use OCA\IntegrationImmich\AppInfo\Application;
use OCA\IntegrationImmich\Db\SyncState;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class ProvisioningService {
    private const LOCK_TIMEOUT_SECONDS = 60;

    private QuotaSyncService $quotaSyncService;
    private LockService $lockService;

    /**
     * @param object $lockFactory ILockFactory-compatible dependency. This app falls back to LockService/flock when no stable Nextcloud ILockFactory API is present.
     */
    public function __construct(
        private ImmichUserAdminService $immichUserAdminService,
        private SyncStateService $syncStateService,
        private PathTemplateService $pathTemplateService,
        private AdminConfigService $adminConfigService,
        private IUserManager $userManager,
        private LoggerInterface $logger,
        private ?object $lockFactory = null,
        ?QuotaSyncService $quotaSyncService = null,
        ?LockService $lockService = null,
    ) {
        $this->quotaSyncService = $quotaSyncService ?? new QuotaSyncService($userManager, $adminConfigService, $logger);
        $this->lockService = $lockService ?? new LockService($lockFactory, $logger);
    }

    public function reconcileUser(string $ncUid, bool $dryRun = false): array {
        $lockKey = 'deep_integration_immich_provision_' . $ncUid;

        try {
            return $this->lockService->withLock($lockKey, self::LOCK_TIMEOUT_SECONDS, function () use ($ncUid, $dryRun): array {
                return $this->reconcileUserLocked($ncUid, $dryRun);
            });
        } catch (\Throwable $e) {
            if (!$dryRun) {
                $this->persistFailureIfPossible($ncUid, $e->getMessage());
            }

            $this->logger->warning('Immich provisioning failed for Nextcloud user "' . $ncUid . '": ' . $e->getMessage(), [
                'app' => Application::APP_ID,
                'ncUid' => $ncUid,
            ]);

            return $this->result($ncUid, 'skipped', null, $this->safeStorageLabel($ncUid), null, [$e->getMessage()], $dryRun);
        }
    }

    private function reconcileUserLocked(string $ncUid, bool $dryRun): array {
        $errors = [];
        $storageLabel = $this->storageLabelForUid($ncUid);
        $user = $this->userManager->get($ncUid);
        if ($user === null) {
            return $this->result($ncUid, 'skipped', null, $storageLabel, null, ['Nextcloud user was not found.'], $dryRun);
        }

        $email = $this->emailForUser($user, $ncUid, $storageLabel);
        $name = $this->nameForUser($user, $ncUid);
        $state = $dryRun ? $this->syncStateService->findByUid($ncUid) : $this->syncStateService->getOrCreateForUid($ncUid);
        $knownImmichUserId = $state?->getImmichUserId();
        $this->assertMappingStorageLabelIsRepairable($state, $storageLabel);
        $quotaSet = null;
        $quotaEnabled = $this->isQuotaSyncEnabled();

        if ($dryRun) {
            if ($quotaEnabled) {
                $quotaSet = $this->quotaSyncService->computeQuota($ncUid, 0);
                $errors = $this->quotaErrors($errors);
            }

            $action = $knownImmichUserId === null || $knownImmichUserId === '' ? 'created' : 'updated';
            if ($errors !== []) {
                $action = 'skipped';
            }

            return $this->result($ncUid, $action, $knownImmichUserId, $storageLabel, $quotaSet, $errors, true);
        }

        $immichUser = $this->immichUserAdminService->findUserForNcUid($ncUid, $email, $storageLabel);
        $created = false;

        if ($immichUser === null) {
            $fields = [
                'email' => $email,
                'name' => $name,
                'storageLabel' => $storageLabel,
            ];
            if ($quotaEnabled) {
                $quotaSet = $this->quotaSyncService->computeQuota($ncUid, 0);
                $errors = $this->quotaErrors($errors);
                if ($errors !== []) {
                    $this->persistQuotaFailure($ncUid, implode('; ', $errors));
                    return $this->result($ncUid, 'skipped', $knownImmichUserId, $storageLabel, null, $errors, false);
                }
                $fields['quotaSizeInBytes'] = $quotaSet;
            }

            $immichUser = $this->immichUserAdminService->createUser($fields);
            $created = true;
        }

        $immichUserId = $this->extractImmichUserId($immichUser);
        if ($immichUserId === null) {
            throw new \RuntimeException('Immich user response did not include a user id.');
        }

        if (!$created) {
            $usage = $this->immichUserAdminService->getUserQuotaUsage($immichUserId);
            if ($quotaEnabled) {
                $quotaSet = $this->quotaSyncService->computeQuota($ncUid, $usage ?? 0);
                $errors = $this->quotaErrors($errors);
                if ($errors !== []) {
                    $this->persistQuotaFailure($ncUid, implode('; ', $errors));
                    return $this->result($ncUid, 'skipped', $immichUserId, $storageLabel, null, $errors, false);
                }
            }

            $updateFields = $this->buildUpdateFields($immichUser, $email, $name, $storageLabel, $usage);
            if ($quotaEnabled) {
                $updateFields['quotaSizeInBytes'] = $quotaSet;
            }

            if ($updateFields !== []) {
                $this->immichUserAdminService->updateUser($immichUserId, $updateFields);
            }
        }

        $mappingChanged = $this->didChangeMapping($state, $immichUserId, $email, $storageLabel);
        $this->persistActiveMapping($ncUid, $immichUserId, $email, $storageLabel, $quotaEnabled);

        $action = $created ? 'created' : ($mappingChanged || $quotaEnabled ? 'updated' : 'unchanged');
        return $this->result($ncUid, $action, $immichUserId, $storageLabel, $quotaSet, $errors, false);
    }

    private function buildUpdateFields(array $immichUser, string $email, string $name, string $storageLabel, ?int $immichUsage): array {
        $fields = [];
        if (strtolower((string)($immichUser['email'] ?? '')) !== strtolower($email)) {
            $fields['email'] = $email;
        }
        if ((string)($immichUser['name'] ?? '') !== $name) {
            $fields['name'] = $name;
        }

        $currentStorageLabel = (string)($immichUser['storageLabel'] ?? '');
        if ($currentStorageLabel !== $storageLabel) {
            throw new \RuntimeException('Storage label mismatch requires explicit repair or migration before provisioning can continue.');
        }

        return $fields;
    }

    private function persistActiveMapping(string $ncUid, string $immichUserId, string $email, string $storageLabel, bool $quotaEnabled): void {
        $fields = [
            'immichUserId' => $immichUserId,
            'immichEmail' => $email,
            'storageLabel' => $storageLabel,
            'scopeStatus' => SyncStateService::STATUS_ACTIVE,
            'lastSyncStatus' => SyncStateService::STATUS_ACTIVE,
            'lastError' => null,
        ];

        if ($quotaEnabled) {
            $fields['lastQuotaSyncAt'] = new DateTimeImmutable();
        }

        $this->syncStateService->updateMapping($ncUid, $fields);
    }

    private function persistQuotaFailure(string $ncUid, string $error): void {
        $this->syncStateService->updateMapping($ncUid, [
            'lastSyncStatus' => SyncStateService::STATUS_QUOTA_FAILED,
            'lastError' => $error,
        ]);
    }

    private function persistFailureIfPossible(string $ncUid, string $error): void {
        try {
            $this->syncStateService->updateMapping($ncUid, [
                'lastSyncStatus' => SyncStateService::STATUS_FAILED,
                'lastError' => $error,
            ]);
        } catch (\Throwable) {
            // A mapping may not exist yet; the returned reconcile error remains the source of truth.
        }
    }

    private function didChangeMapping(?SyncState $state, string $immichUserId, string $email, string $storageLabel): bool {
        if ($state === null) {
            return true;
        }

        return $state->getImmichUserId() !== $immichUserId
            || $state->getImmichEmail() !== $email
            || $state->getStorageLabel() !== $storageLabel;
    }

    private function isQuotaSyncEnabled(): bool {
        $config = $this->adminConfigService->getAdminConfig();
        return in_array((string)($config[AdminConfigService::KEY_QUOTA_SYNC_MODE] ?? 'disabled'), ['manual', 'event_scheduled'], true);
    }

    private function quotaErrors(array $errors): array {
        $quotaError = $this->quotaSyncService->getLastError();
        if ($quotaError !== null) {
            $errors[] = $quotaError;
        }

        return $errors;
    }

    private function emailForUser(object $user, string $ncUid, string $storageLabel): string {
        $email = method_exists($user, 'getEMailAddress') ? trim((string)$user->getEMailAddress()) : '';
        if ($email !== '') {
            return $email;
        }

        $config = $this->adminConfigService->getAdminConfig();
        $template = (string)($config[AdminConfigService::KEY_EMAIL_TEMPLATE] ?? '{uid}@immich.local');
        return $this->expandTemplate($template, $ncUid, $storageLabel);
    }

    private function nameForUser(object $user, string $ncUid): string {
        $displayName = method_exists($user, 'getDisplayName') ? trim((string)$user->getDisplayName()) : '';
        return $displayName !== '' ? $displayName : $ncUid;
    }

    private function expandTemplate(string $template, string $ncUid, string $storageLabel): string {
        return strtr($template, [
            '{uid}' => $ncUid,
            '{storageLabel}' => $storageLabel,
        ]);
    }

    private function extractImmichUserId(array $immichUser): ?string {
        $id = $immichUser['id'] ?? $immichUser['userId'] ?? null;
        if (!is_string($id) || $id === '') {
            return null;
        }

        return $id;
    }

    private function assertMappingStorageLabelIsRepairable(?SyncState $state, string $expectedStorageLabel): void {
        if ($state === null) {
            return;
        }

        $mappedStorageLabel = trim((string)$state->getStorageLabel());
        if ($mappedStorageLabel === '' || $mappedStorageLabel === $expectedStorageLabel) {
            return;
        }

        if ($this->pathTemplateService->isUuidLikeStorageLabel($mappedStorageLabel)) {
            throw new \RuntimeException('Stored storage_label looks like an Immich UUID; repair or migration is required before provisioning can continue.');
        }

        throw new \RuntimeException('Stored storage_label differs from the expected Nextcloud UID-derived label; repair or migration is required before provisioning can continue.');
    }

    private function safeStorageLabel(string $ncUid): string {
        try {
            return $this->storageLabelForUid($ncUid);
        } catch (\Throwable) {
            return '';
        }
    }

    private function storageLabelForUid(string $ncUid): string {
        $config = $this->adminConfigService->getAdminConfig();
        $template = (string)($config[AdminConfigService::KEY_STORAGE_LABEL_TEMPLATE] ?? '{uid}');

        return $this->pathTemplateService->expandStorageLabelTemplate($template, $ncUid);
    }

    private function result(string $ncUid, string $action, ?string $immichUserId, string $storageLabel, ?int $quotaSet, array $errors, bool $dryRun): array {
        return [
            'ncUid' => $ncUid,
            'nc_uid' => $ncUid,
            'action' => $action,
            'immichUserId' => $immichUserId,
            'immich_user_id' => $immichUserId,
            'storageLabel' => $storageLabel,
            'storage_label' => $storageLabel,
            'quotaSet' => $quotaSet,
            'errors' => array_values($errors),
            'dryRun' => $dryRun,
        ];
    }
}
