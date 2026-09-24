<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Service;

use OCA\ShareAuditDashboard\Db\ShareMapper;
use OCP\Share\IShare;

/**
 * Aggregates raw share rows into normalized, presentation-ready structures
 * for the dashboard: stats, paginated lists with human-readable permissions
 * and types, and trend counters.
 */
class ShareCollectorService {

    /**
     * Category buckets used by the dashboard cards, keyed by raw share_type.
     * Circles are internal, group-like recipients (a defined set of members
     * within the instance) — bucketed with 'group' rather than 'other', for
     * consistency with ExposureMapService::CATEGORY, which already treats
     * TYPE_CIRCLE as 'internal' alongside TYPE_GROUP.
     */
    private const CATEGORY_BY_TYPE = [
        IShare::TYPE_USER => 'user',
        IShare::TYPE_GROUP => 'group',
        IShare::TYPE_CIRCLE => 'group',
        IShare::TYPE_LINK => 'link',
        IShare::TYPE_EMAIL => 'email',
        IShare::TYPE_REMOTE => 'federated',
        IShare::TYPE_REMOTE_GROUP => 'federated',
        IShare::TYPE_ROOM => 'talk',
        IShare::TYPE_DECK => 'deck',
    ];

    public function __construct(
        private ShareMapper $mapper,
        private SecurityAnalyzerService $security,
        private PathFormatter $pathFormatter,
        private DisplayNameResolver $displayNames,
        private RecipientDetailsResolver $recipientDetails,
        private ExposureMapService $exposure,
    ) {
    }

    /**
     * Dashboard statistics: totals per category, trends and top owners.
     *
     * @return array<string, mixed>
     */
    public function getStats(): array {
        $rawCounts = $this->mapper->countByType();

        $byType = [
            'user' => 0, 'group' => 0, 'link' => 0, 'email' => 0,
            'federated' => 0, 'talk' => 0, 'other' => 0,
        ];
        foreach ($rawCounts as $type => $count) {
            $category = self::CATEGORY_BY_TYPE[$type] ?? 'other';
            // A Deck share is labelled "Deck" on its row, but the dashboard has
            // no bucket, colour or internal/external call for it yet, so its
            // counters keep counting it under "other" (see ROADMAP.md).
            $byType[$category === 'deck' ? 'other' : $category] += $count;
        }

        $now = time();
        return [
            // Same filter as countByType(), so the total is just the sum of
            // its buckets — no need for a separate COUNT(*) round trip.
            'total' => array_sum($rawCounts),
            'byType' => $byType,
            'trend' => $this->mapper->countRecentBuckets($now - 30 * 86400, $now - 90 * 86400, $now - 365 * 86400),
            'trendSeries' => $this->monthlyTrend(12),
            'topOwners' => $this->enrichOwners($this->mapper->topOwners(5)),
            'alertsCount' => $this->security->countAlerts(),
        ];
    }

    /**
     * Add resolved display names to the top-owners list.
     *
     * @param array<int, array{owner: string, count: int}> $owners
     * @return array<int, array{owner: string, displayName: string, count: int}>
     */
    private function enrichOwners(array $owners): array {
        $names = $this->displayNames->resolveMany(array_column($owners, 'owner'));
        foreach ($owners as &$o) {
            $o['displayName'] = $names[$o['owner']] ?? $o['owner'];
        }
        unset($o);
        return $owners;
    }

    /**
     * Attach ownerDisplayName (and initiatorDisplayName, recipientDisplayName
     * when present) to a list of already-normalized rows, resolving each
     * unique uid/gid once regardless of how many rows share it — see
     * DisplayNameResolver.
     *
     * Recipient is only resolved for TYPE_USER (uid) and TYPE_GROUP (gid)
     * shares — every other recipient shape (email address, federated
     * user@server, link/room token) is already human-readable as stored, and
     * resolving it against IUserManager/IGroupManager would just be a wasted
     * round trip that always misses.
     *
     * @param array<int, array<string, mixed>> $items normalizeRow() output
     * @return array<int, array<string, mixed>>
     */
    private function withDisplayNames(array $items): array {
        $uids = [];
        $gids = [];
        foreach ($items as $item) {
            $uids[] = $item['owner'] ?? null;
            $uids[] = $item['initiator'] ?? null;
            if (($item['type'] ?? null) === IShare::TYPE_USER) {
                $uids[] = $item['recipient'] ?? null;
            } elseif (($item['type'] ?? null) === IShare::TYPE_GROUP) {
                $gids[] = $item['recipient'] ?? null;
            }
        }
        $names = $this->displayNames->resolveMany($uids);
        $groupNames = $this->displayNames->resolveManyGroups($gids);
        foreach ($items as &$item) {
            $item['ownerDisplayName'] = $names[$item['owner']] ?? $item['owner'];
            if (($item['initiator'] ?? '') !== '') {
                $item['initiatorDisplayName'] = $names[$item['initiator']] ?? $item['initiator'];
            }
            $recipient = $item['recipient'] ?? '';
            if ($recipient !== '' && $item['type'] === IShare::TYPE_USER) {
                $item['recipientDisplayName'] = $names[$recipient] ?? $recipient;
            } elseif ($recipient !== '' && $item['type'] === IShare::TYPE_GROUP) {
                $item['recipientDisplayName'] = $groupNames[$recipient] ?? $recipient;
            }
        }
        unset($item);
        return $items;
    }

    /**
     * Shares created per calendar month for the last $months months (oldest
     * first), for the dashboard trend chart. Buckets in PHP from a single
     * query instead of issuing one COUNT per month.
     *
     * @return array<int, array{label: string, count: int}>
     */
    private function monthlyTrend(int $months): array {
        $firstOfThisMonth = new \DateTimeImmutable('first day of this month midnight');
        $start = $firstOfThisMonth->sub(new \DateInterval('P' . ($months - 1) . 'M'));

        $counts = [];
        for ($i = 0; $i < $months; $i++) {
            $counts[$start->add(new \DateInterval('P' . $i . 'M'))->format('Y-m')] = 0;
        }

        foreach ($this->mapper->findCreatedTimestampsSince($start->getTimestamp()) as $stime) {
            $label = date('Y-m', $stime);
            if (isset($counts[$label])) {
                $counts[$label]++;
            }
        }

        $series = [];
        foreach ($counts as $label => $count) {
            $series[] = ['label' => $label, 'count' => $count];
        }
        return $series;
    }

    /**
     * Paginated, filtered list of shares.
     *
     * $canSeeTokens defaults to true (every existing caller before this
     * parameter was added showed the real recipient): the personal view is
     * always the caller's own shares, so there is nothing to redact from
     * them. Pass false for a multi-user viewer (see AccessScope::
     * canSeeTokens()) so a Talk conversation's bare token — functionally a
     * credential, unlike a uid/gid — is replaced by its resolved name (see
     * RecipientDetailsResolver::redactRoomTokens()) and cannot be searched
     * for or sorted by either (ShareMapper's `hideRoomTokens`).
     *
     * @param array $filters normalized filters (see ShareMapper::applyFilters)
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, limit: int}
     */
    public function getShares(array $filters, int $page, int $limit, string $sort = 'created', string $dir = 'desc', bool $canSeeTokens = true): array {
        $page = max(1, $page);
        $limit = max(1, min(500, $limit));
        $offset = ($page - 1) * $limit;
        $filters = $this->withOwnerSearchUids($filters);
        $filters = $this->withRecipientSearchIds($filters);
        $filters = $this->withExposure($filters);
        if (!$canSeeTokens) {
            $filters['hideRoomTokens'] = true;
        }

        $rows = $this->mapper->findShares($filters, $limit, $offset, $sort, $dir);
        $items = $this->recipientDetails->decorate(
            $this->withDisplayNames(array_map([$this, 'normalizeRow'], $rows)),
        );

        return [
            'items' => $canSeeTokens ? $items : $this->recipientDetails->redactRoomTokens($items),
            'total' => $this->mapper->countShares($filters),
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /**
     * All shares matching the filters, normalized, for export — same filters
     * and sort as getShares() so "Export CSV" always matches what's on
     * screen. Unpaginated but capped to avoid unbounded memory use on very
     * large instances.
     *
     * Deliberately skips withDisplayNames(): ReportService only ever writes
     * the raw uid to the CSV (the unambiguous, exact account id an auditor
     * wants), and resolving up to $max unique owners would add real cost on
     * an LDAP-backed instance for a field nothing reads.
     *
     * @param array $filters normalized filters (see ShareMapper::applyFilters)
     */
    public function getAllForExport(
        array $filters,
        bool $includeTokens = false,
        string $sort = 'created',
        string $dir = 'desc',
        int $max = 100000,
    ): array {
        $filters = $this->withOwnerSearchUids($filters);
        $filters = $this->withRecipientSearchIds($filters);
        $filters = $this->withExposure($filters);
        if (!$includeTokens) {
            // The file will not carry tokens, so the filter must not be a way
            // to find them either — see getShares().
            $filters['hideRoomTokens'] = true;
        }
        $rows = $this->mapper->findShares($filters, $max, 0, $sort, $dir);
        $items = $this->recipientDetails->decorate(
            array_map(fn (array $row) => $this->normalizeRow($row, $includeTokens), $rows),
        );
        return $includeTokens ? $items : $this->recipientDetails->redactRoomTokens($items);
    }

    /**
     * Expands an 'ownerSearch' filter term into the uids of accounts whose
     * display name matches it, so ShareMapper can match the owner column
     * against either the raw uid or what's actually shown in the table (see
     * DisplayNameResolver::searchUids docblock).
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function withOwnerSearchUids(array $filters): array {
        if (!empty($filters['ownerSearch'])) {
            $filters['ownerSearchUids'] = $this->displayNames->searchUids((string)$filters['ownerSearch']);
        }
        return $filters;
    }

    /**
     * Expands a 'recipientSearch' filter term into the uids/gids of
     * accounts/groups whose display name matches it — a recipient can be
     * either, so both DisplayNameResolver searches are combined — and into the
     * Talk conversations and Deck cards whose name does. Same reasoning as
     * withOwnerSearchUids() above.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function withRecipientSearchIds(array $filters): array {
        if (!empty($filters['recipientSearch'])) {
            $term = (string)$filters['recipientSearch'];
            $filters['recipientSearchIds'] = array_merge(
                $this->displayNames->searchUids($term),
                $this->displayNames->searchGroupIds($term),
            );
            // Talk conversations and Deck cards are shown by name too.
            $filters['recipientSearchRooms'] = $this->recipientDetails->searchRoomTokens($term);
            $filters['recipientSearchCards'] = $this->recipientDetails->searchCardIds($term);
        }
        return $filters;
    }

    /**
     * Expands an 'exposure' filter ('internal', 'external' or 'public') into
     * the share types and Talk conversations that belong to that category, as
     * ExposureMapService counts them — so the list a category's "View" button
     * opens is the very set its number was made of. An unknown or unfilterable
     * category is dropped rather than failing the request.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function withExposure(array $filters): array {
        if (!isset($filters['exposure'])) {
            return $filters;
        }
        $expanded = is_string($filters['exposure']) ? $this->exposure->filterFor($filters['exposure']) : null;
        if ($expanded === null) {
            unset($filters['exposure']);
        } else {
            $filters['exposure'] = $expanded;
        }
        return $filters;
    }

    /**
     * Turn a raw DB row into a presentation-ready share record.
     *
     * The token (a bare credential for public links) is only included when
     * explicitly requested by a caller that actually needs it — never in the
     * general-purpose listings (share list, orphans, recipient lookup).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function normalizeRow(array $row, bool $includeToken = false): array {
        $type = (int)$row['share_type'];
        $permissions = (int)$row['permissions'];

        $share = [
            'id' => (int)$row['id'],
            'type' => $type,
            'typeLabel' => $this->typeLabel($type),
            'category' => self::CATEGORY_BY_TYPE[$type] ?? 'other',
            'owner' => (string)$row['uid_owner'],
            'initiator' => (string)($row['uid_initiator'] ?? ''),
            'recipient' => (string)($row['share_with'] ?? ''),
            'itemType' => (string)($row['item_type'] ?? ''),
            'fileId' => isset($row['file_source']) ? (int)$row['file_source'] : null,
            'path' => $this->pathFormatter->prettyPath($row['file_path'] ?? null),
            'permissions' => $permissions,
            'permissionLabels' => $this->permissionLabels($permissions),
            'created' => isset($row['stime']) ? (int)$row['stime'] : null,
            'expiration' => $this->normalizeExpiration($row['expiration'] ?? null),
            'hasPassword' => !empty($row['password']),
            // Whether file_source still resolves against oc_filecache at all
            // (see ShareMapper::findShares()) — independent of whatever
            // `path` ends up being, since that can be blank for reasons that
            // have nothing to do with the file's existence.
            'sourceExists' => isset($row['source_exists']) ? (bool)(int)$row['source_exists'] : true,
            // Matches the ShareMapper::applyFilters() "hasExpiration" filter:
            // an expiration in the past is, for this purpose, the same as no
            // expiration at all.
            'hasExpiration' => $this->hasFutureExpiration($row['expiration'] ?? null),
            // The share's own name (a link's custom label). oc_share keeps it in
            // `label`; `share_name` is a legacy column that is always NULL.
            'name' => ($row['label'] ?? '') !== '' ? (string)$row['label'] : null,
        ];
        if ($includeToken) {
            $share['token'] = $row['token'] ?? null;
        }
        return $share;
    }

    /**
     * Human-readable label for a raw share_type.
     */
    public function typeLabel(int $type): string {
        return match ($type) {
            IShare::TYPE_USER => 'user',
            IShare::TYPE_GROUP => 'group',
            IShare::TYPE_LINK => 'link',
            IShare::TYPE_EMAIL => 'email',
            IShare::TYPE_CIRCLE => 'circle',
            IShare::TYPE_REMOTE => 'federated',
            IShare::TYPE_REMOTE_GROUP => 'federated_group',
            IShare::TYPE_ROOM => 'talk',
            IShare::TYPE_DECK => 'deck',
            default => 'other',
        };
    }

    /**
     * Decode a Nextcloud permission bitmask into readable tokens.
     *
     * @return string[]
     */
    public function permissionLabels(int $permissions): array {
        $labels = [];
        if ($permissions & 1) {
            $labels[] = 'read';
        }
        if ($permissions & 2) {
            $labels[] = 'update';
        }
        if ($permissions & 4) {
            $labels[] = 'create';
        }
        if ($permissions & 8) {
            $labels[] = 'delete';
        }
        if ($permissions & 16) {
            $labels[] = 'share';
        }
        return $labels;
    }

    /**
     * Normalize the expiration column (stored as a datetime string) to an
     * ISO-8601 date or null.
     */
    private function normalizeExpiration($expiration): ?string {
        if (empty($expiration)) {
            return null;
        }
        try {
            return (new \DateTimeImmutable((string)$expiration))->format('Y-m-d');
        } catch (\Exception) {
            return (string)$expiration;
        }
    }

    /**
     * Whether the expiration column holds a date that is still in the
     * future. An unparsable value is treated as "no (usable) expiration",
     * matching SecurityAnalyzerService::parseExpiration()'s fail-open
     * behaviour rather than throwing.
     */
    private function hasFutureExpiration($expiration): bool {
        if (empty($expiration)) {
            return false;
        }
        try {
            return new \DateTimeImmutable((string)$expiration) > new \DateTimeImmutable();
        } catch (\Exception) {
            return false;
        }
    }
}
