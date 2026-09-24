<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
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

    /**
     * Where the file $fileId sits in $uid's own home, relative to their files
     * folder ("Projects/Plan.docx") — or null when it is not in their home.
     *
     * That is what tells a file that can move with its owner from one that
     * cannot: a Team Folder, an external storage and a folder somebody else
     * shared with them all show up in the same tree, but belong to another
     * storage, and moving the owner's account would not carry them along.
     */
    public function homeRelativePath(string $uid, int $fileId): ?string {
        try {
            $userFolder = $this->rootFolder->getUserFolder($uid);
            // The same file can be reachable through more than one mount; the
            // home one is the one that counts.
            foreach ($userFolder->getById($fileId) as $node) {
                if (rtrim($node->getMountPoint()->getMountPoint(), '/') !== '/' . $uid) {
                    continue;
                }
                $relative = trim((string)$userFolder->getRelativePath($node->getPath()), '/');
                return $relative === '' ? null : $relative;
            }
        } catch (\Throwable) {
            // Not resolvable for this account: not something that can move.
        }
        return null;
    }
}
