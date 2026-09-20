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
 * Second migration for this app: the exceptions table behind "Acknowledge"
 * (ROADMAP.md's G2 — also closes GitHub issue #5, "Mark as already
 * reviewed"). One row per (share, rule) pair an admin has explicitly
 * accepted — SecurityAnalyzerService::getAlerts() excludes (or, when
 * $includeAcknowledged is true, just annotates) any alert issue whose
 * (share_id, rule_code) has a matching row here, so a link that's
 * intentionally passwordless doesn't keep tripping the same alert forever.
 */
class Version0006Date20260920160000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('shareaudit_ack')) {
            $table = $schema->createTable('shareaudit_ack');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            // The oc_share.id the exception applies to. Not a foreign key:
            // this app never joins against oc_share for writes, and a share
            // that's later deleted just leaves this row unreferenced (see
            // AckMapper's docblock for why that's an accepted trade-off).
            $table->addColumn('share_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            // One of SecurityAnalyzerService::ISSUE_CODES (e.g. 'no_password').
            // A single alert row can carry several issues; each is
            // acknowledged (or not) independently, so this is part of the
            // uniqueness key, not the id alone.
            $table->addColumn('rule_code', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('acknowledged_by', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('acknowledged_at', Types::BIGINT, [
                'notnull' => true,
            ]);
            // Optional free-text justification ("intentional newsletter
            // link", ...), shown in the "show acknowledged" filter.
            $table->addColumn('note', Types::STRING, [
                'notnull' => false,
                'length' => 1000,
            ]);

            $table->setPrimaryKey(['id']);
            // Enforces one exception per (share, rule) — AckService upserts
            // against this instead of ever risking a duplicate — and doubles
            // as the lookup index computeAlerts() needs on every request.
            $table->addUniqueIndex(['share_id', 'rule_code'], 'shareaudit_ack_share_rule');
        }

        return $schema;
    }
}
