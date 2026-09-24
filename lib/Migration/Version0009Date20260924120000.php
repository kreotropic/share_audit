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
 * The queue behind "move the files of an orphan share" (see
 * OrphanFileMoveService): one row per move of a disabled account's files to
 * another account, with the state the dashboard shows while a background job
 * works through it. The move itself can take long enough (a big folder, a slow
 * disk) that it cannot run inside the request that asked for it, and the
 * admin needs to see whether it finished or why it did not.
 */
class Version0009Date20260924120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('shareaudit_filemove')) {
            $table = $schema->createTable('shareaudit_filemove');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            // The disabled account whose files move, and who receives them.
            $table->addColumn('source_uid', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('target_uid', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            // 'path' (one file or folder) or 'account' (everything).
            $table->addColumn('scope', Types::STRING, [
                'notnull' => true,
                'length' => 16,
            ]);
            // Relative to the source's files folder; null for scope 'account'
            // (not '': Oracle stores an empty string as NULL).
            $table->addColumn('path', Types::STRING, [
                'notnull' => false,
                'length' => 4000,
            ]);
            // queued | running | done | failed
            $table->addColumn('status', Types::STRING, [
                'notnull' => true,
                'length' => 16,
            ]);
            $table->addColumn('error', Types::STRING, [
                'notnull' => false,
                'length' => 1000,
            ]);
            // Shares the admin selected that this move covers (for an
            // account, every share the account owned when it was queued).
            $table->addColumn('share_count', Types::INTEGER, [
                'notnull' => true,
            ]);
            $table->addColumn('requested_by', Types::STRING, [
                'notnull' => false,
                'length' => 64,
            ]);
            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
            ]);
            $table->addColumn('started_at', Types::BIGINT, [
                'notnull' => false,
            ]);
            $table->addColumn('finished_at', Types::BIGINT, [
                'notnull' => false,
            ]);

            $table->setPrimaryKey(['id']);
            // Looked up by "is anything still queued or running for this
            // account" when a move is requested.
            $table->addIndex(['source_uid', 'status'], 'shareaudit_fm_src');
        }

        return $schema;
    }
}
