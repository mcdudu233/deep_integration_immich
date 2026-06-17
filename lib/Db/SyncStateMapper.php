<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\IntegrationImmich\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

class SyncStateMapper extends QBMapper {
    public const TABLE_NAME = 'integration_immich_sync';

    public function __construct(IDBConnection $db) {
        parent::__construct($db, self::TABLE_NAME, SyncState::class);
    }

    public function findByUid(string $uid): SyncState {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('nc_uid', $qb->createNamedParameter($uid)));

        /** @var SyncState $syncState */
        $syncState = $this->findEntity($qb);
        return $syncState;
    }

    public function findByImmichUserId(string $id): SyncState {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('immich_user_id', $qb->createNamedParameter($id)));

        /** @var SyncState $syncState */
        $syncState = $this->findEntity($qb);
        return $syncState;
    }

    public function findByStorageLabel(string $label): SyncState {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('storage_label', $qb->createNamedParameter($label)));

        /** @var SyncState $syncState */
        $syncState = $this->findEntity($qb);
        return $syncState;
    }

    public function findByMountId(int $mountId): SyncState {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('nc_mount_id', $qb->createNamedParameter($mountId)));

        /** @var SyncState $syncState */
        $syncState = $this->findEntity($qb);
        return $syncState;
    }

    /**
     * @return SyncState[]
     */
    public function findAllPaginated(int $limit, int $offset): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('nc_uid', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        /** @var SyncState[] $syncStates */
        $syncStates = $this->findEntities($qb);
        return $syncStates;
    }

    /**
     * @return SyncState[]
     */
    public function findMappedPaginated(int $limit, int $offset): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->neq('immich_user_id', $qb->createNamedParameter('')))
            ->orderBy('nc_uid', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        /** @var SyncState[] $syncStates */
        $syncStates = $this->findEntities($qb);
        return $syncStates;
    }

    public function insertState(SyncState $syncState): SyncState {
        /** @var SyncState $inserted */
        $inserted = $this->insert($syncState);
        return $inserted;
    }

    public function updateState(SyncState $syncState): SyncState {
        /** @var SyncState $updated */
        $updated = $this->update($syncState);
        return $updated;
    }

    public function deleteState(SyncState $syncState): SyncState {
        /** @var SyncState $deleted */
        $deleted = $this->delete($syncState);
        return $deleted;
    }
}
