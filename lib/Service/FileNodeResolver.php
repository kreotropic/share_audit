<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Service;

use OCP\Files\IRootFolder;
use OCP\Files\Node;

/**
 * Thin wrapper around IRootFolder::getUserFolder()->getById() — its own
 * class only so SoftDeleteService (and its tests) don't need to depend on
 * IRootFolder directly. IRootFolder extends OC\Hooks\Emitter, a private
 * (non-OCP) interface that isn't available in a plain `composer install`
 * environment (no full Nextcloud server checkout) — mocking it, or even
 * just type-hinting it, blows up PHPUnit there and in CI. Keeping that
 * dependency confined to this one small, untested-by-unit-tests class lets
 * everything that only needs "resolve a node by owner + fileid" stay
 * testable without a running Nextcloud instance.
 */
class FileNodeResolver {

    public function __construct(
        private IRootFolder $rootFolder,
    ) {
    }

    public function resolve(string $uid, int $fileId): ?Node {
        try {
            $userFolder = $this->rootFolder->getUserFolder($uid);
        } catch (\Throwable) {
            return null;
        }
        $nodes = $userFolder->getById($fileId);
        return $nodes[0] ?? null;
    }
}
