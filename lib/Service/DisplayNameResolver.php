<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Service;

use OCP\IUserManager;

/**
 * Resolves uids to display names, batched per unique uid rather than per
 * row. On an LDAP/AD-backed instance a raw uid is often a long, unreadable
 * UUID, and IUserManager::get() can cost a backend round trip per call — so
 * every list-producing service resolves once per unique uid in the page it's
 * building, not once per row (a page can have many rows sharing an owner).
 */
class DisplayNameResolver {

    public function __construct(
        private IUserManager $userManager,
    ) {
    }

    /**
     * Resolve every unique, non-empty uid in $uids to its display name.
     * Falls back to the uid itself when the account no longer exists (or
     * never did) — callers never need a null-check, just `$names[$uid] ??
     * $uid` as a defensive fallback for uids this call was never given.
     *
     * @param iterable<string|null> $uids
     * @return array<string, string> uid => displayName
     */
    public function resolveMany(iterable $uids): array {
        $unique = [];
        foreach ($uids as $uid) {
            if ($uid !== null && $uid !== '') {
                $unique[$uid] = true;
            }
        }
        $result = [];
        foreach (array_keys($unique) as $uid) {
            $result[$uid] = $this->userManager->get($uid)?->getDisplayName() ?: $uid;
        }
        return $result;
    }
}
