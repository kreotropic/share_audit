<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * DeletedShareMapper::findPage() (the "Deleted shares" recycle-bin listing)
 * always orders by deleted_at DESC, id DESC, but Version0004 only indexed
 * purge_after (the daily purge job's own access pattern) and uid_owner (no
 * current caller actually filters by it). Without a matching index, that
 * ordering is a full table scan + sort on every page, growing linearly with
 * how much has ever been revoked rather than with the page size.
 */
class Version0007Date20260923100000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('shareaudit_deleted')) {
            $table = $schema->getTable('shareaudit_deleted');
            if (!$table->hasIndex('shareaudit_del_recent')) {
                $table->addIndex(['deleted_at', 'id'], 'shareaudit_del_recent');
            }
        }

        return $schema;
    }
}
