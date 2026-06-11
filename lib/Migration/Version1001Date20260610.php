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

class Version1001Date20260610 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('integration_immich_sync')) {
            return $schema;
        }

        $table = $schema->getTable('integration_immich_sync');

        if (!$table->hasColumn('immich_username')) {
            $table->addColumn('immich_username', 'string', [
                'notnull' => false,
                'length' => 255,
            ]);
        }

        if (!$table->hasColumn('immich_password')) {
            $table->addColumn('immich_password', 'string', [
                'notnull' => false,
                'length' => 255,
            ]);
        }

        return $schema;
    }
}
