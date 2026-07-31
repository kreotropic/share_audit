<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Dumps every read path of the app over the fixture that seed-fixture.php
 * creates, in a form two instances backed by different databases can be
 * diffed on. Run it on each, redirect to a file, `diff` the two.
 *
 * What it normalises away, and why:
 *  - share ids and file ids become stable ordinals (`#0`, `#1`, …). They come
 *    from auto-increment and sequences, so they differ between instances by
 *    construction and say nothing about the engine.
 *  - link tokens become `<token>`. They are random per share; only whether one
 *    exists is meaningful.
 *  - object keys are sorted, so a difference in map iteration order between
 *    engines is not mistaken for a difference in content.
 *
 * Every section is wrapped: a failure prints into the output, and therefore
 * into the diff, instead of truncating the dump. That matters more than it
 * sounds — a wrong call signature once ended a run early with exit code 0 and
 * an empty stderr, which read exactly like a short-but-successful dump.
 *
 * Ordering is deliberately exercised across every sortable column: sorting is
 * the one place the two engines genuinely disagree (MySQL puts NULL first,
 * PostgreSQL last), so a dump that only covered the default sort would miss
 * the whole class of bug this tooling exists to catch.
 *
 *   docker exec -u www-data <app-container> \
 *       php /var/www/html/custom_apps/share_audit_dashboard/build/dump-readpaths.php > out.txt
 *
 * Usage: dump-readpaths.php [prefix]   (must match the seeder's, default sa_fixture)
 */

require_once '/var/www/html/lib/base.php';
\OC_App::loadApps();

use OCA\ShareAuditDashboard\Service\ExposureMapService;
use OCA\ShareAuditDashboard\Service\OrphanShareService;
use OCA\ShareAuditDashboard\Service\RecipientLookupService;
use OCA\ShareAuditDashboard\Service\SecurityAnalyzerService;
use OCA\ShareAuditDashboard\Service\ShareCollectorService;

$prefix = $argv[1] ?? 'sa_fixture';

$collector = \OCP\Server::get(ShareCollectorService::class);
$analyzer = \OCP\Server::get(SecurityAnalyzerService::class);
$exposure = \OCP\Server::get(ExposureMapService::class);
$orphans = \OCP\Server::get(OrphanShareService::class);
$recipients = \OCP\Server::get(RecipientLookupService::class);

/** Replaces instance-specific identifiers with values that compare equal. */
function normalise(mixed $value): mixed {
    static $ordinals = [];
    if (!is_array($value)) {
        return $value;
    }
    $out = [];
    foreach ($value as $key => $item) {
        $isId = in_array($key, ['id', 'fileId', 'file_id', 'item_source', 'file_source', 'parent'], true)
            && (is_int($item) || (is_string($item) && ctype_digit($item)));
        if ($isId) {
            $slot = $key . ':' . $item;
            $ordinals[$slot] ??= '#' . count($ordinals);
            $out[$key] = $ordinals[$slot];
            continue;
        }
        $out[$key] = $key === 'token'
            ? ($item === null ? null : '<token>')
            : normalise($item);
    }
    ksort($out);
    return $out;
}

function section(string $label, callable $produce): void {
    echo "########## $label\n";
    try {
        $json = json_encode(
            normalise($produce()),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        echo $json, "\n\n";
    } catch (\Throwable $e) {
        echo 'FAILED: ' . $e::class . ': ' . $e->getMessage() . "\n\n";
    }
}

$ana = "{$prefix}_ana";
$bruno = "{$prefix}_bruno";
$carla = "{$prefix}_carla";
$eng = "{$prefix}_eng";

section('stats', fn () => $collector->getStats());

// Every sort key the mapper accepts. `password` has its own CASE-based
// ordering; `expires`, `recipient` and `path` are the nullable ones.
foreach (['created', 'path', 'owner', 'recipient', 'type', 'expires', 'password'] as $sort) {
    section("shares sort=$sort asc", fn () => $collector->getShares([], 1, 50, $sort, 'asc'));
    section("shares sort=$sort desc", fn () => $collector->getShares([], 1, 50, $sort, 'desc'));
}
section('shares page 2 of 3', fn () => $collector->getShares([], 2, 3, 'created', 'asc'));

// Filters, including the case-insensitive (iLike) columns.
section('filter pathSearch', fn () => $collector->getShares(['pathSearch' => 'dados'], 1, 50));
section('filter ownerSearch uppercase', fn () => $collector->getShares(['ownerSearch' => strtoupper($ana)], 1, 50));
section('filter recipientSearch uppercase', fn () => $collector->getShares(['recipientSearch' => 'EXTERNO'], 1, 50));
section('filter hasPassword=false', fn () => $collector->getShares(['hasPassword' => false], 1, 50));
section('filter hasExpiration=true', fn () => $collector->getShares(['hasExpiration' => true], 1, 50));
section('filter types=[3] link', fn () => $collector->getShares(['types' => [3]], 1, 50));

$alerts = $analyzer->getAlerts();
section('alerts count', fn () => $analyzer->countAlerts());
section('alerts', fn () => $alerts);
section('alerts by issue', fn () => $analyzer->countByIssue($alerts));
section('alerts scoped to one owner', fn () => $analyzer->getAlerts($ana));
section('alerts count scoped', fn () => $analyzer->countAlerts($carla));

section('exposure overview', fn () => $exposure->getOverview());
section('exposure top users', fn () => $exposure->getTopExposedUsers(10));

section('orphan owners', fn () => $orphans->getOrphanOwners(true));
section('orphan count', fn () => $orphans->countOrphanShares());
section('orphan shares', fn () => $orphans->getOrphanShares(1, 50));

section('recipient search', fn () => $recipients->search($prefix));
section('recipient search uppercase', fn () => $recipients->search('EXTERNO'));
section('recipient shares user', fn () => $recipients->getShares($bruno, 0));
section('recipient shares group', fn () => $recipients->getShares($eng, 1));
