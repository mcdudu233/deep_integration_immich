<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\IntegrationImmich\Db;

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
 * @method DateTimeInterface|null getLastQuotaSyncAt()
 * @method void setLastQuotaSyncAt(?DateTimeInterface $lastQuotaSyncAt)
 * @method DateTimeInterface getCreatedAt()
 * @method void setCreatedAt(DateTimeInterface $createdAt)
 * @method DateTimeInterface getUpdatedAt()
 * @method void setUpdatedAt(DateTimeInterface $updatedAt)
 */
class SyncState extends Entity implements JsonSerializable {
    protected string $ncUid = '';
    protected ?string $immichUserId = null;
    protected ?string $immichEmail = null;
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
        $this->addType('storageLabel', 'string');
        $this->addType('ncMountId', 'integer');
        $this->addType('scopeStatus', 'string');
        $this->addType('lastSyncStatus', 'string');
        $this->addType('lastError', 'string');
        $this->addType('lastQuotaSyncAt', 'datetime');
        $this->addType('createdAt', 'datetime');
        $this->addType('updatedAt', 'datetime');

        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
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
}
