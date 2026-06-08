<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\IntegrationImmich\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20260607 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('integration_immich_sync')) {
            $table = $schema->createTable('integration_immich_sync');
            $table->addColumn('id', 'integer', [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('nc_uid', 'string', [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('immich_user_id', 'string', [
                'notnull' => false,
                'length' => 255,
            ]);
            $table->addColumn('immich_email', 'string', [
                'notnull' => false,
                'length' => 320,
            ]);
            $table->addColumn('storage_label', 'string', [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('nc_mount_id', 'integer', [
                'notnull' => false,
                'unsigned' => true,
            ]);
            $table->addColumn('scope_status', 'string', [
                'notnull' => true,
                'length' => 32,
                'default' => 'pending',
            ]);
            $table->addColumn('last_sync_status', 'string', [
                'notnull' => true,
                'length' => 32,
                'default' => 'pending',
            ]);
            $table->addColumn('last_error', 'text', [
                'notnull' => false,
            ]);
            $table->addColumn('last_quota_sync_at', 'datetime', [
                'notnull' => false,
            ]);
            $table->addColumn('created_at', 'datetime', [
                'notnull' => true,
            ]);
            $table->addColumn('updated_at', 'datetime', [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['nc_uid'], 'immich_sync_uid_uq');
            $table->addUniqueIndex(['immich_user_id'], 'immich_sync_immich_uq');
            $table->addUniqueIndex(['storage_label'], 'immich_sync_label_uq');
            $table->addUniqueIndex(['nc_mount_id'], 'immich_sync_mount_uq');
        }

        return $schema;
    }
}
