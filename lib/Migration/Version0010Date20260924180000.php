<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Lets the database decide that only one file move to a given account runs at
 * a time (see FileMoveMapper::claim()).
 *
 * The Files app puts what it moves into a folder named after the second the
 * move started, and moving a file onto a name that already exists deletes what
 * was there. Nextcloud runs background jobs in as many processes as the admin
 * starts, so two moves to one account can start in the same second and erase
 * each other's files of the same name. Waiting a second between moves only
 * helps when they run one after the other.
 *
 * `running_target` holds the receiving account while a move is running and
 * NULL the rest of the time. A UNIQUE index treats NULLs as distinct, so any
 * number of moves may be idle, but a second one cannot start running for an
 * account that already has one — the UPDATE fails, atomically, in every
 * process and on every database.
 */
class Version0010Date20260924180000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('shareaudit_filemove')) {
            $table = $schema->getTable('shareaudit_filemove');
            if (!$table->hasColumn('running_target')) {
                $table->addColumn('running_target', Types::STRING, [
                    'notnull' => false,
                    'length' => 64,
                ]);
            }
            if (!$table->hasIndex('shareaudit_fm_run')) {
                $table->addUniqueIndex(['running_target'], 'shareaudit_fm_run');
            }
        }

        return $schema;
    }
}
