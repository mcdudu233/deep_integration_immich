<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\IntegrationImmich\Service;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OCA\IntegrationImmich\Db\SyncState;
use OCA\IntegrationImmich\Db\SyncStateMapper;
use OCP\AppFramework\Db\DoesNotExistException;

class SyncStateService {
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_OUT_OF_SCOPE = 'out_of_scope';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_FAILED = 'failed';
    public const STATUS_MOUNT_PENDING = 'mount_pending';
    public const STATUS_QUOTA_FAILED = 'quota_failed';
    public const STATUS_DELETED = 'deleted';

    private const VALID_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACTIVE,
        self::STATUS_OUT_OF_SCOPE,
        self::STATUS_DISABLED,
        self::STATUS_FAILED,
        self::STATUS_MOUNT_PENDING,
        self::STATUS_QUOTA_FAILED,
        self::STATUS_DELETED,
    ];

    private const UID_REUSE_BLOCKING_STATUSES = [
        self::STATUS_DISABLED,
        self::STATUS_DELETED,
    ];

    private const UPDATEABLE_FIELDS = [
        'immichUserId',
        'immichEmail',
        'immichUsername',
        'immichPassword',
        'immichApiKey',
        'storageLabel',
        'ncMountId',
        'scopeStatus',
        'lastSyncStatus',
        'lastError',
        'lastQuotaSyncAt',
    ];

    public function __construct(
        private SyncStateMapper $mapper,
    ) {
    }

    public function getOrCreateForUid(string $uid): SyncState {
        $this->assertValidUid($uid);

        $existing = $this->findByUid($uid);
        if ($existing !== null) {
            $this->assertUidMayBeProvisioned($existing);
            return $existing;
        }

        $now = $this->now();
        $syncState = new SyncState();
        $syncState->setNcUid($uid);
        $syncState->setStorageLabel($this->sanitizeStorageLabel($uid));
        $syncState->setScopeStatus(self::STATUS_PENDING);
        $syncState->setLastSyncStatus(self::STATUS_PENDING);
        $syncState->setCreatedAt($now);
        $syncState->setUpdatedAt($now);

        try {
            return $this->mapper->insertState($syncState);
        } catch (UniqueConstraintViolationException $e) {
            $existing = $this->findByUid($uid);
            if ($existing === null) {
                throw new \RuntimeException('A sync state mapping already exists for the generated storage label or external identifier.', 0, $e);
            }

            $this->assertUidMayBeProvisioned($existing);
            return $existing;
        }
    }

    public function findByUid(string $uid): ?SyncState {
        try {
            return $this->mapper->findByUid($uid);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    public function findByImmichUserId(string $id): ?SyncState {
        if ($id === '') {
            return null;
        }

        try {
            return $this->mapper->findByImmichUserId($id);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    public function findByStorageLabel(string $label): ?SyncState {
        if ($label === '') {
            return null;
        }

        try {
            return $this->mapper->findByStorageLabel($label);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    public function findByMountId(int $mountId): ?SyncState {
        if ($mountId < 1) {
            return null;
        }

        try {
            return $this->mapper->findByMountId($mountId);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    public function deleteByUid(string $uid): bool {
        $this->assertValidUid($uid);

        $state = $this->findByUid($uid);
        if ($state === null) {
            return false;
        }

        $this->mapper->deleteState($state);
        return true;
    }

    /**
     * @return SyncState[]
     */
    public function listStates(int $limit = 50, int $offset = 0): array {
        $this->assertValidPagination($limit, $offset);

        return $this->mapper->findAllPaginated($limit, $offset);
    }

    /**
     * @return SyncState[]
     */
    public function listMappedStates(int $limit = 500, int $offset = 0): array {
        $this->assertValidPagination($limit, $offset);

        return $this->mapper->findMappedPaginated($limit, $offset);
    }

    public function updateStatus(string $uid, string $scopeStatus, string $lastSyncStatus, ?string $error = null): void {
        $this->assertValidStatus($scopeStatus);
        $this->assertValidStatus($lastSyncStatus);

        $syncState = $this->requireByUid($uid);
        $this->assertNotImplicitReactivation($syncState, $scopeStatus);

        $syncState->setScopeStatus($scopeStatus);
        $syncState->setLastSyncStatus($lastSyncStatus);
        $syncState->setLastError($error);
        $syncState->setUpdatedAt($this->now());

        try {
            $this->mapper->updateState($syncState);
        } catch (UniqueConstraintViolationException $e) {
            throw new \RuntimeException('Sync state status update conflicted with an existing mapping.', 0, $e);
        }
    }

    public function updateMapping(string $uid, array $fields): void {
        $syncState = $this->requireByUid($uid);

        foreach ($fields as $field => $value) {
            if (!in_array($field, self::UPDATEABLE_FIELDS, true)) {
                throw new \InvalidArgumentException('Field "' . $field . '" is not updateable on sync state mappings.');
            }

            $this->applyField($syncState, $field, $value);
        }

        $syncState->setUpdatedAt($this->now());

        try {
            $this->mapper->updateState($syncState);
        } catch (UniqueConstraintViolationException $e) {
            throw new \RuntimeException('Sync state mapping update conflicted with an existing mapping.', 0, $e);
        }
    }

    private function requireByUid(string $uid): SyncState {
        $this->assertValidUid($uid);
        $syncState = $this->findByUid($uid);
        if ($syncState === null) {
            throw new \RuntimeException('No sync state mapping exists for Nextcloud user "' . $uid . '".');
        }

        return $syncState;
    }

    private function applyField(SyncState $syncState, string $field, mixed $value): void {
        switch ($field) {
            case 'immichUserId':
                $syncState->setImmichUserId($this->nullableString($value, $field));
                break;
            case 'immichEmail':
                $syncState->setImmichEmail($this->nullableString($value, $field));
                break;
            case 'immichUsername':
                $syncState->setImmichUsername($this->nullableString($value, $field));
                break;
            case 'immichPassword':
                $syncState->setImmichPassword($this->nullableString($value, $field));
                break;
            case 'immichApiKey':
                $syncState->setImmichApiKey($this->nullableString($value, $field));
                break;
            case 'storageLabel':
                $label = $this->stringValue($value, $field);
                $this->assertValidStorageLabel($label);
                $syncState->setStorageLabel($label);
                break;
            case 'ncMountId':
                $syncState->setNcMountId($this->nullableInt($value, $field));
                break;
            case 'scopeStatus':
                $status = $this->stringValue($value, $field);
                $this->assertValidStatus($status);
                $this->assertNotImplicitReactivation($syncState, $status);
                $syncState->setScopeStatus($status);
                break;
            case 'lastSyncStatus':
                $status = $this->stringValue($value, $field);
                $this->assertValidStatus($status);
                $syncState->setLastSyncStatus($status);
                break;
            case 'lastError':
                $syncState->setLastError($this->nullableString($value, $field));
                break;
            case 'lastQuotaSyncAt':
                if ($value !== null && !$value instanceof DateTimeInterface) {
                    throw new \InvalidArgumentException('Field "lastQuotaSyncAt" must be a DateTimeInterface or null.');
                }
                $syncState->setLastQuotaSyncAt($value);
                break;
        }
    }

    private function sanitizeStorageLabel(string $uid): string {
        $label = preg_replace('/[^A-Za-z0-9._-]/', '-', $uid);
        if ($label === null) {
            throw new \RuntimeException('Failed to sanitize storage label for Nextcloud user "' . $uid . '".');
        }

        $label = trim($label, ". \t\n\r\0\x0B");
        $this->assertValidStorageLabel($label);

        return $label;
    }

    private function assertValidStorageLabel(string $label): void {
        if ($label === '' || $label === '.' || $label === '..') {
            throw new \InvalidArgumentException('Storage label must not be empty, ".", or "..".');
        }
        if (str_contains($label, '/') || str_contains($label, '\\')) {
            throw new \InvalidArgumentException('Storage label must not contain path separators.');
        }
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $label)) {
            throw new \InvalidArgumentException('Storage label may only contain letters, numbers, dots, underscores, and hyphens.');
        }
        if (str_contains($label, '..')) {
            throw new \InvalidArgumentException('Storage label must not contain traversal-like dot-dot sequences.');
        }
    }

    private function assertValidUid(string $uid): void {
        if ($uid === '') {
            throw new \InvalidArgumentException('Nextcloud user id must not be empty.');
        }
    }

    private function assertValidPagination(int $limit, int $offset): void {
        if ($limit < 1) {
            throw new \InvalidArgumentException('Pagination limit must be greater than 0.');
        }

        if ($offset < 0) {
            throw new \InvalidArgumentException('Pagination offset must be greater than or equal to 0.');
        }
    }

    private function assertValidStatus(string $status): void {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException('Unsupported sync state status "' . $status . '".');
        }
    }

    private function assertUidMayBeProvisioned(SyncState $syncState): void {
        if (in_array($syncState->getScopeStatus(), self::UID_REUSE_BLOCKING_STATUSES, true)) {
            throw new \RuntimeException('Existing sync state for Nextcloud user "' . $syncState->getNcUid() . '" is terminal and requires explicit admin reconcile before reuse.');
        }
    }

    private function assertNotImplicitReactivation(SyncState $syncState, string $nextScopeStatus): void {
        if ($nextScopeStatus !== self::STATUS_ACTIVE) {
            return;
        }
        if (in_array($syncState->getScopeStatus(), self::UID_REUSE_BLOCKING_STATUSES, true)) {
            throw new \RuntimeException('Existing sync state for Nextcloud user "' . $syncState->getNcUid() . '" is terminal and requires explicit admin reconcile before reactivation.');
        }
    }

    private function nullableString(mixed $value, string $field): ?string {
        if ($value === null) {
            return null;
        }

        return $this->stringValue($value, $field);
    }

    private function stringValue(mixed $value, string $field): string {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Field "' . $field . '" must be a string.');
        }

        return $value;
    }

    private function nullableInt(mixed $value, string $field): ?int {
        if ($value === null) {
            return null;
        }
        if (!is_int($value)) {
            throw new \InvalidArgumentException('Field "' . $field . '" must be an integer or null.');
        }

        return $value;
    }

    private function now(): DateTimeImmutable {
        return new DateTimeImmutable();
    }
}
