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
 * Adds shareaudit_deleted.source_exists_at_deletion — see issue #21: the
 * owner of a share can be gone (which is what makes it "orphan" in the first
 * place) while the shared file or folder is, separately, still perfectly
 * fine, or it can ALSO already be gone by the time the share was revoked.
 * Restoring the DB row never brings a deleted file back, so SoftDeleteService
 * captures which case it was at the moment of capture (see captureShare()/
 * captureRow()) instead of leaving "Restore" offered on an entry that can
 * only ever fail.
 *
 * Nullable, and left null (not backfilled) for every row that predates this
 * column: SoftDeleteService::normalize() and the frontend both treat null the
 * same as true (unknown is not evidence the file is gone) — restore() itself
 * still re-checks for real at restore time regardless.
 */
class Version0008Date20260923110000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('shareaudit_deleted')) {
            $table = $schema->getTable('shareaudit_deleted');
            if (!$table->hasColumn('source_exists_at_deletion')) {
                $table->addColumn('source_exists_at_deletion', Types::BOOLEAN, [
                    'notnull' => false,
                ]);
            }
        }

        return $schema;
    }
}
