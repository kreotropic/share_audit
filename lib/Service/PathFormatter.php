<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Service;

use OCA\GroupFolders\Folder\FolderManager;
use OCP\Server;

/**
 * Turns a raw oc_filecache path into a user-facing one. Stateless utility
 * shared by ShareCollectorService and SecurityAnalyzerService (both used to
 * carry their own copy of this logic).
 */
class PathFormatter {

    /**
     * Strip the internal "files/" storage prefix, or resolve a groupfolders
     * "__groupfolders/<id>/..." path to the team folder's display name.
     */
    public function prettyPath(?string $path): ?string {
        if ($path === null) {
            return null;
        }
        if (str_starts_with($path, '__groupfolders/')) {
            return $this->prettyGroupFolderPath($path);
        }
        if (str_starts_with($path, 'files/')) {
            return '/' . substr($path, strlen('files/'));
        }
        if ($path === 'files') {
            return '/';
        }
        return $path;
    }

    private function prettyGroupFolderPath(string $path): string {
        $rest = substr($path, strlen('__groupfolders/'));
        [$id, $sub] = array_pad(explode('/', $rest, 2), 2, '');

        $name = ctype_digit($id) ? $this->groupFolderName((int)$id) : null;
        $label = $name ?? ('Team folder ' . $id);

        return $sub !== '' ? '/' . $label . '/' . $sub : '/' . $label;
    }

    /**
     * Resolve a groupfolders folder id to its configured mount point name.
     * Returns null if the groupfolders app isn't installed/enabled, or the
     * folder no longer exists — callers fall back to a generic label.
     */
    private function groupFolderName(int $folderId): ?string {
        if (!class_exists(FolderManager::class)) {
            return null;
        }
        try {
            return Server::get(FolderManager::class)->getFolder($folderId)?->mountPoint;
        } catch (\Throwable) {
            return null;
        }
    }
}
