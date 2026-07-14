<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Service;

use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Resolves uids/gids to display names, batched per unique id rather than per
 * row. On an LDAP/AD-backed instance a raw uid is often a long, unreadable
 * UUID, and IUserManager::get() can cost a backend round trip per call — so
 * every list-producing service resolves once per unique id in the page it's
 * building, not once per row (a page can have many rows sharing an owner or
 * recipient).
 */
class DisplayNameResolver {

    public function __construct(
        private IUserManager $userManager,
        private IGroupManager $groupManager,
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

    /**
     * Resolve every unique, non-empty gid in $gids to its group display
     * name. Falls back to the gid itself when the group no longer exists —
     * same contract as resolveMany(), see its docblock.
     *
     * @param iterable<string|null> $gids
     * @return array<string, string> gid => displayName
     */
    public function resolveManyGroups(iterable $gids): array {
        $unique = [];
        foreach ($gids as $gid) {
            if ($gid !== null && $gid !== '') {
                $unique[$gid] = true;
            }
        }
        $result = [];
        foreach (array_keys($unique) as $gid) {
            $result[$gid] = $this->groupManager->getDisplayName($gid) ?: $gid;
        }
        return $result;
    }

    /**
     * Gids of groups whose display name contains $term — the group
     * equivalent of searchUids(), for the same reason (see its docblock).
     *
     * @return list<string>
     */
    public function searchGroupIds(string $term, int $limit = 50): array {
        if ($term === '') {
            return [];
        }
        return array_map(
            static fn ($group) => $group->getGID(),
            $this->groupManager->search($term, $limit),
        );
    }

    /**
     * Uids of accounts whose display name contains $term. On LDAP/SAML/AD
     * instances the uid is often an opaque identifier (a UUID or an internal
     * code) while the table shows the human-readable display name — an
     * owner-column search for what's on screen must therefore also match
     * against display names, not just the raw uid column.
     *
     * @return list<string>
     */
    public function searchUids(string $term, int $limit = 50): array {
        if ($term === '') {
            return [];
        }
        return array_map(
            static fn ($user) => $user->getUID(),
            $this->userManager->searchDisplayName($term, $limit),
        );
    }
}
