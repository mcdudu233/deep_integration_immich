<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\IntegrationImmich\Db;

use DateTime;
use DateTimeInterface;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method string getNcUid()
 * @method void setNcUid(string $ncUid)
 * @method string|null getImmichUserId()
 * @method void setImmichUserId(?string $immichUserId)
 * @method string|null getImmichEmail()
 * @method void setImmichEmail(?string $immichEmail)
 * @method string|null getImmichUsername()
 * @method void setImmichUsername(?string $immichUsername)
 * @method string|null getImmichPassword()
 * @method void setImmichPassword(?string $immichPassword)
 * @method string getStorageLabel()
 * @method void setStorageLabel(string $storageLabel)
 * @method int|null getNcMountId()
 * @method void setNcMountId(?int $ncMountId)
 * @method string getScopeStatus()
 * @method void setScopeStatus(string $scopeStatus)
 * @method string getLastSyncStatus()
 * @method void setLastSyncStatus(string $lastSyncStatus)
 * @method string|null getLastError()
 * @method void setLastError(?string $lastError)
 */
class SyncState extends Entity implements JsonSerializable {
    protected string $ncUid = '';
    protected ?string $immichUserId = null;
    protected ?string $immichEmail = null;
    protected ?string $immichUsername = null;
    protected ?string $immichPassword = null;
    protected string $storageLabel = '';
    protected ?int $ncMountId = null;
    protected string $scopeStatus = 'pending';
    protected string $lastSyncStatus = 'pending';
    protected ?string $lastError = null;
    protected ?DateTimeInterface $lastQuotaSyncAt = null;
    protected DateTimeInterface $createdAt;
    protected DateTimeInterface $updatedAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('ncUid', 'string');
        $this->addType('immichUserId', 'string');
        $this->addType('immichEmail', 'string');
        $this->addType('immichUsername', 'string');
        $this->addType('immichPassword', 'string');
        $this->addType('storageLabel', 'string');
        $this->addType('ncMountId', 'integer');
        $this->addType('scopeStatus', 'string');
        $this->addType('lastSyncStatus', 'string');
        $this->addType('lastError', 'string');
        $this->addType('lastQuotaSyncAt', 'datetime');
        $this->addType('createdAt', 'datetime');
        $this->addType('updatedAt', 'datetime');

        $now = new DateTime();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getLastQuotaSyncAt(): ?DateTimeInterface {
        return $this->lastQuotaSyncAt;
    }

    public function getImmichUsername(): ?string {
        return $this->immichUsername;
    }

    public function setImmichUsername(mixed $immichUsername): void {
        if ($this->immichUsername === $immichUsername) {
            return;
        }

        $this->markFieldUpdated('immichUsername');
        $this->immichUsername = $immichUsername === null ? null : (string)$immichUsername;
    }

    public function getImmichPassword(): ?string {
        return $this->immichPassword;
    }

    public function setImmichPassword(mixed $immichPassword): void {
        if ($this->immichPassword === $immichPassword) {
            return;
        }

        $this->markFieldUpdated('immichPassword');
        $this->immichPassword = $immichPassword === null ? null : (string)$immichPassword;
    }

    public function setLastQuotaSyncAt(mixed $lastQuotaSyncAt): void {
        if ($this->lastQuotaSyncAt === $lastQuotaSyncAt) {
            return;
        }

        $this->markFieldUpdated('lastQuotaSyncAt');
        $this->lastQuotaSyncAt = $lastQuotaSyncAt === null ? null : $this->asMutableDateTime($lastQuotaSyncAt);
    }

    public function getCreatedAt(): DateTimeInterface {
        return $this->createdAt;
    }

    public function setCreatedAt(mixed $createdAt): void {
        if ($this->createdAt === $createdAt) {
            return;
        }

        $this->markFieldUpdated('createdAt');
        $this->createdAt = $this->asMutableDateTime($createdAt);
    }

    public function getUpdatedAt(): DateTimeInterface {
        return $this->updatedAt;
    }

    public function setUpdatedAt(mixed $updatedAt): void {
        if ($this->updatedAt === $updatedAt) {
            return;
        }

        $this->markFieldUpdated('updatedAt');
        $this->updatedAt = $this->asMutableDateTime($updatedAt);
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'ncUid' => $this->getNcUid(),
            'immichUserId' => $this->getImmichUserId(),
            'immichEmail' => $this->getImmichEmail(),
            'storageLabel' => $this->getStorageLabel(),
            'ncMountId' => $this->getNcMountId(),
            'scopeStatus' => $this->getScopeStatus(),
            'lastSyncStatus' => $this->getLastSyncStatus(),
            'lastError' => $this->getLastError(),
            'lastQuotaSyncAt' => $this->formatDateTime($this->getLastQuotaSyncAt()),
            'createdAt' => $this->formatDateTime($this->getCreatedAt()),
            'updatedAt' => $this->formatDateTime($this->getUpdatedAt()),
        ];
    }

    private function formatDateTime(?DateTimeInterface $dateTime): ?string {
        return $dateTime?->format(DateTimeInterface::ATOM);
    }

    private function asMutableDateTime(mixed $dateTime): DateTime {
        if ($dateTime instanceof DateTime) {
            return $dateTime;
        }

        if (!$dateTime instanceof DateTimeInterface && !is_string($dateTime)) {
            throw new \InvalidArgumentException('Sync state timestamp values must be DateTimeInterface instances or datetime strings.');
        }

        return new DateTime($dateTime instanceof DateTimeInterface ? $dateTime->format(DateTimeInterface::ATOM) : $dateTime);
    }
}
