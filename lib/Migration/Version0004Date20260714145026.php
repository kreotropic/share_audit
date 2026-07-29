<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * First migration for this app: the retention table behind the Soft Delete
 * feature (see ROADMAP.md #1). A revoked share's row is copied here — by
 * SoftDeleteListener (BeforeShareDeletedEvent, covers every revocation path
 * including native Nextcloud UI unshares) or directly by
 * ShareDeletionService::deleteDirect() (the one path that bypasses that
 * event) — before the original oc_share row is actually deleted, so it can
 * be restored later instead of being gone for good.
 */
class Version0004Date20260714145026 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('shareaudit_deleted')) {
            $table = $schema->createTable('shareaudit_deleted');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            // The oc_share.id the row was copied from — informational only
            // (restore() creates a brand new share with a new id; a share
            // revoked again after being restored can end up here twice).
            $table->addColumn('original_share_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            $table->addColumn('share_type', Types::SMALLINT, [
                'notnull' => true,
            ]);
            $table->addColumn('share_with', Types::STRING, [
                'notnull' => false,
                'length' => 255,
            ]);
            $table->addColumn('uid_owner', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('uid_initiator', Types::STRING, [
                'notnull' => false,
                'length' => 64,
            ]);
            $table->addColumn('item_type', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('file_source', Types::BIGINT, [
                'notnull' => false,
            ]);
            $table->addColumn('file_target', Types::STRING, [
                'notnull' => false,
                'length' => 4000,
            ]);
            $table->addColumn('permissions', Types::SMALLINT, [
                'notnull' => true,
            ]);
            // Link-share token and (hashed) password, preserved verbatim so
            // restore() can put them back via a raw UPDATE — see
            // SoftDeleteService::restore() docblock for why a plain
            // IShare::setPassword()/setToken() can't be used for this.
            $table->addColumn('token', Types::STRING, [
                'notnull' => false,
                'length' => 128,
            ]);
            $table->addColumn('password', Types::STRING, [
                'notnull' => false,
                'length' => 255,
            ]);
            $table->addColumn('share_name', Types::STRING, [
                'notnull' => false,
                'length' => 64,
            ]);
            $table->addColumn('expiration', Types::STRING, [
                'notnull' => false,
                'length' => 32,
            ]);
            $table->addColumn('stime', Types::BIGINT, [
                'notnull' => false,
            ]);
            $table->addColumn('deleted_at', Types::BIGINT, [
                'notnull' => true,
            ]);
            $table->addColumn('deleted_by', Types::STRING, [
                'notnull' => false,
                'length' => 64,
            ]);
            $table->addColumn('purge_after', Types::BIGINT, [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            // Listing is always "everything, oldest purge_after first" or
            // "mine" filtered by owner — both benefit from an index.
            $table->addIndex(['purge_after'], 'shareaudit_del_purge');
            $table->addIndex(['uid_owner'], 'shareaudit_del_owner');
        }

        return $schema;
    }
}
