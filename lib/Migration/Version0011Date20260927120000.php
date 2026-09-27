<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Three things the recycle bin and the file-move queue were missing:
 *
 * - One bin entry per deleted share. Two requests that loaded the same share
 *   before either deleted it each fire BeforeShareDeletedEvent, and each used
 *   to leave an entry: restoring both put two live links on one token. The
 *   UNIQUE index on (original_share_id, share_type) makes the second capture
 *   fail instead (SoftDeleteService ignores it); the duplicates already there
 *   are dropped first, keeping the oldest — they are copies of one share, not
 *   separate shares, so nothing restorable is lost.
 * - shareaudit_deleted.hide_download / attributes: a restore used to bring a
 *   link back without "hide download" and without the download-permission
 *   attribute, silently giving more access than it had. NULL on entries that
 *   predate this: SoftDeleteService treats that as "unknown" and restores
 *   links conservatively.
 * - shareaudit_filemove.file_id: the id of the file or folder that was
 *   selected, so that the move refuses to take whatever is at that path by the
 *   time it runs if it is something else.
 */
class Version0011Date20260927120000 extends SimpleMigrationStep {

    public function __construct(
        private IDBConnection $db,
    ) {
    }

    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if (!$schema->hasTable('shareaudit_deleted')
            || $schema->getTable('shareaudit_deleted')->hasIndex('shareaudit_del_orig')) {
            return;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('original_share_id', 'share_type')
            ->selectAlias($qb->func()->min('id'), 'keep')
            ->from('shareaudit_deleted')
            ->groupBy('original_share_id', 'share_type')
            ->having($qb->expr()->gt($qb->func()->count('*'), $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $groups = $result->fetchAll();
        $result->closeCursor();

        $dropped = 0;
        foreach ($groups as $group) {
            $delete = $this->db->getQueryBuilder();
            $delete->delete('shareaudit_deleted')
                ->where($delete->expr()->eq('original_share_id', $delete->createNamedParameter((int)$group['original_share_id'], IQueryBuilder::PARAM_INT)))
                ->andWhere($delete->expr()->eq('share_type', $delete->createNamedParameter((int)$group['share_type'], IQueryBuilder::PARAM_INT)))
                ->andWhere($delete->expr()->neq('id', $delete->createNamedParameter((int)$group['keep'], IQueryBuilder::PARAM_INT)));
            $dropped += $delete->executeStatement();
        }
        if ($dropped > 0) {
            $output->info(sprintf('Dropped %d duplicate recycle-bin entries of already-captured shares.', $dropped));
        }
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('shareaudit_deleted')) {
            $table = $schema->getTable('shareaudit_deleted');
            if (!$table->hasColumn('hide_download')) {
                $table->addColumn('hide_download', Types::BOOLEAN, [
                    'notnull' => false,
                ]);
            }
            if (!$table->hasColumn('attributes')) {
                $table->addColumn('attributes', Types::TEXT, [
                    'notnull' => false,
                ]);
            }
            if (!$table->hasIndex('shareaudit_del_orig')) {
                $table->addUniqueIndex(['original_share_id', 'share_type'], 'shareaudit_del_orig');
            }
        }

        if ($schema->hasTable('shareaudit_filemove')) {
            $table = $schema->getTable('shareaudit_filemove');
            if (!$table->hasColumn('file_id')) {
                $table->addColumn('file_id', Types::BIGINT, [
                    'notnull' => false,
                ]);
            }
        }

        return $schema;
    }
}
