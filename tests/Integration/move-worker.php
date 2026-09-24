<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * One background worker, for OrphanFileMoveTest: a separate PHP process with its
 * own database connection that runs one queued file move, the way the background
 * job does — but only once the wall clock reaches a given instant, so that
 * several of them can be made to start at the same moment.
 *
 *   php move-worker.php <move id> <unix time (float) to start at>
 *
 * Prints how the run ended, as one line of JSON.
 */

define('OC_CONSOLE', 1);
require (getenv('NEXTCLOUD_ROOT') ?: '/var/www/html') . '/lib/base.php';
\OC_App::loadApps();

$id = (int)($argv[1] ?? 0);
$startAt = (float)($argv[2] ?? 0);

while (microtime(true) < $startAt) {
    usleep(1000);
}

try {
    \OCP\Server::get(\OCA\ShareAuditDashboard\Service\OrphanFileMoveService::class)->run($id);
    echo json_encode(['id' => $id, 'ok' => true]), "\n";
} catch (\Throwable $e) {
    echo json_encode(['id' => $id, 'ok' => false, 'error' => $e->getMessage()]), "\n";
    exit(1);
}
