<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * A worker that takes a queued file move and then does nothing but stay alive,
 * for OrphanFileMoveTest: what a worker in the middle of moving a very large
 * folder looks like from outside. The test kills it (SIGKILL — no shutdown
 * function runs, nothing is cleaned up) to see what is left behind and whether
 * the next move can tell.
 *
 *   php move-holder.php <move id> <receiving account>
 *
 * Does exactly what OrphanFileMoveService::run() does before it moves anything:
 * takes the worker's lock, then claims the move. Prints "held" once it has both.
 */

define('OC_CONSOLE', 1);
require (getenv('NEXTCLOUD_ROOT') ?: '/var/www/html') . '/lib/base.php';
\OC_App::loadApps();

$id = (int)($argv[1] ?? 0);
$target = (string)($argv[2] ?? '');

$lock = \OCP\Server::get(\OCA\ShareAuditDashboard\Service\WorkerLock::class);
$moves = \OCP\Server::get(\OCA\ShareAuditDashboard\Db\FileMoveMapper::class);

if (!$lock->acquire($id)) {
    echo "lock refused\n";
    exit(1);
}
if ($moves->claim($id, $target, time()) !== \OCA\ShareAuditDashboard\Db\FileMoveMapper::CLAIM_OK) {
    echo "claim refused\n";
    exit(1);
}
echo "held\n";
flush();

sleep(300);
