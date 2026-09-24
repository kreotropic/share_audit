<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Service;

use OCP\IUser;
use OCP\Server;

/**
 * The one place that calls the Files app's ownership transfer — the code
 * behind `occ files:transfer-ownership`.
 *
 * Its own class for the reason FileNodeResolver is: OCA\Files\Service\
 * OwnershipTransferService is not part of the OCP package a plain
 * `composer install` provides, so anything that type-hinted it would not load
 * under PHPUnit outside a full Nextcloud. It is fetched here, when needed,
 * instead of injected.
 *
 * It is an internal class, not public API, and its trailing parameters differ
 * across Nextcloud 31 to 35. Only the first three arguments are passed — the
 * same call the Files app's own TransferOwnership background job makes — so
 * the defaults of the running version apply.
 *
 * What the transfer does: the file or folder goes to the new owner under a
 * folder named "Transferred from <name> on <date and time>", and every share
 * of it (link, user, group, remote, Talk, mail, ...) is handed over with it,
 * keeping its id and token.
 */
class OwnershipTransferGateway {

    /**
     * @param string $path relative to the source's files folder; empty moves
     *                     the whole account
     * @throws \Throwable whatever the transfer refuses with, notably the Files
     *                    app's TransferOwnershipException (unknown path, not
     *                    enough space, encrypted files, ...)
     */
    public function move(IUser $from, IUser $to, string $path): void {
        try {
            $service = Server::get(\OCA\Files\Service\OwnershipTransferService::class);
            $service->transfer($from, $to, ltrim($path, '/'));
        } finally {
            $this->waitForNextSecond();
        }
    }

    /**
     * The destination folder is named after the second the transfer started,
     * and moving a file onto a name that already exists *deletes* what was
     * there. Two moves to the same account that start in the same second put
     * their files in the same folder, so `A/report.docx` and `B/report.docx`
     * would erase each other. Waiting until the clock has moved past the end
     * of this transfer (which is after its folder was named) means the next
     * one always gets a folder of its own.
     *
     * Moves in different processes are not covered, but the background job
     * runs them one after another.
     */
    private function waitForNextSecond(): void {
        $now = (int)floor(microtime(true));
        while ((int)floor(microtime(true)) <= $now) {
            usleep(50000);
        }
    }
}
