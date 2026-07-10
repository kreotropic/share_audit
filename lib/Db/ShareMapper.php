<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Direct, read-only access to the oc_share table (joined with oc_filecache
 * for file paths). Used for the heavy reporting queries where iterating the
 * IShareManager per user would be too slow.
 *
 * All queries filter out the internal per-recipient rows that Nextcloud
 * generates for group / room / deck shares, so counts are not inflated.
 */
class ShareMapper {

    /**
     * Internal share types that are child rows of another share and must be
     * excluded from listings and counts:
     *  - 2  = USERGROUP  (per-user row of a group share)
     *  - 11 = USERROOM   (per-user row of a Talk room share)
     *  - 13 = DECK_USER  (per-user row of a Deck share)
     */
    public const EXCLUDED_TYPES = [2, 11, 13];

    /** Whitelist of sortable columns: frontend key => DB column. */
    private const SORT_COLUMNS = [
        'type' => 's.share_type',
        'path' => 'f.path',
        'owner' => 's.uid_owner',
        'recipient' => 's.share_with',
        'created' => 's.stime',
        'expires' => 's.expiration',
        'password' => 's.password',
    ];

    public function __construct(
        private IDBConnection $db,
    ) {
    }

    /**
     * Count of shares grouped by raw share_type.
     *
     * @return array<int, int> map of share_type => count
     */
    public function countByType(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('share_type')
            ->selectAlias($qb->func()->count('*'), 'cnt')
            ->from('share')
            ->where($qb->expr()->notIn('share_type',
                $qb->createNamedParameter(self::EXCLUDED_TYPES, IQueryBuilder::PARAM_INT_ARRAY)))
            ->groupBy('share_type');

        $result = $qb->executeQuery();
        $counts = [];
        while ($row = $result->fetch()) {
            $counts[(int)$row['share_type']] = (int)$row['cnt'];
        }
        $result->closeCursor();
        return $counts;
    }

    /**
     * Counts of shares created since three points in time, in a single
     * conditional-aggregation query instead of one COUNT round trip each
     * (the dashboard needs all three on every load).
     *
     * @return array{last30: int, last90: int, last365: int}
     */
    public function countRecentBuckets(int $since30, int $since90, int $since365): array {
        $qb = $this->db->getQueryBuilder();
        $p30 = $qb->createNamedParameter($since30, IQueryBuilder::PARAM_INT);
        $p90 = $qb->createNamedParameter($since90, IQueryBuilder::PARAM_INT);
        $p365 = $qb->createNamedParameter($since365, IQueryBuilder::PARAM_INT);

        $qb->selectAlias($qb->createFunction("SUM(CASE WHEN stime >= $p30 THEN 1 ELSE 0 END)"), 'last30')
            ->selectAlias($qb->createFunction("SUM(CASE WHEN stime >= $p90 THEN 1 ELSE 0 END)"), 'last90')
            ->selectAlias($qb->createFunction("SUM(CASE WHEN stime >= $p365 THEN 1 ELSE 0 END)"), 'last365')
            ->from('share')
            ->where($qb->expr()->notIn('share_type',
                $qb->createNamedParameter(self::EXCLUDED_TYPES, IQueryBuilder::PARAM_INT_ARRAY)))
            // Narrows the scan to the widest of the three windows.
            ->andWhere($qb->expr()->gte('stime', $p365));

        $result = $qb->executeQuery();
        $row = $result->fetch() ?: [];
        $result->closeCursor();

        return [
            'last30' => (int)($row['last30'] ?? 0),
            'last90' => (int)($row['last90'] ?? 0),
            'last365' => (int)($row['last365'] ?? 0),
        ];
    }

    /**
     * Raw creation timestamps for shares created since $since. Used to bucket
     * counts by calendar month in PHP (see ShareCollectorService::monthlyTrend()) —
     * one query instead of one COUNT per month, since calendar-month grouping
     * isn't portable SQL across the DB engines Nextcloud supports.
     *
     * @return int[]
     */
    public function findCreatedTimestampsSince(int $since): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('stime')
            ->from('share')
            ->where($qb->expr()->notIn('share_type',
                $qb->createNamedParameter(self::EXCLUDED_TYPES, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->gte('stime',
                $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $rows = [];
        while (($stime = $result->fetchOne()) !== false) {
            $rows[] = (int)$stime;
        }
        $result->closeCursor();
        return $rows;
    }

    /**
     * Top share owners by number of active shares.
     *
     * @return array<int, array{owner: string, count: int}>
     */
    public function topOwners(int $limit = 5): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('uid_owner')
            ->selectAlias($qb->func()->count('*'), 'cnt')
            ->from('share')
            ->where($qb->expr()->notIn('share_type',
                $qb->createNamedParameter(self::EXCLUDED_TYPES, IQueryBuilder::PARAM_INT_ARRAY)))
            ->groupBy('uid_owner')
            ->orderBy('cnt', 'DESC')
            ->setMaxResults($limit);

        $result = $qb->executeQuery();
        $rows = [];
        while ($row = $result->fetch()) {
            $rows[] = [
                'owner' => (string)$row['uid_owner'],
                'count' => (int)$row['cnt'],
            ];
        }
        $result->closeCursor();
        return $rows;
    }

    /**
     * Top owners for a given raw share_type (e.g. public links), by count.
     *
     * @return array<int, array{owner: string, count: int}>
     */
    public function topOwnersByType(int $shareType, int $limit = 5): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('uid_owner')
            ->selectAlias($qb->func()->count('*'), 'cnt')
            ->from('share')
            ->where($qb->expr()->eq('share_type', $qb->createNamedParameter($shareType, IQueryBuilder::PARAM_INT)))
            ->groupBy('uid_owner')
            ->orderBy('cnt', 'DESC')
            ->setMaxResults($limit);

        $result = $qb->executeQuery();
        $rows = [];
        while ($row = $result->fetch()) {
            $rows[] = ['owner' => (string)$row['uid_owner'], 'count' => (int)$row['cnt']];
        }
        $result->closeCursor();
        return $rows;
    }

    /**
     * Count shares matching the given filters (for pagination totals).
     *
     * @param array $filters see applyFilters()
     */
    public function countShares(array $filters): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('s.id', 'cnt'))
            ->from('share', 's')
            ->leftJoin('s', 'filecache', 'f', $qb->expr()->eq('s.file_source', 'f.fileid'));
        $this->applyFilters($qb, $filters);
        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();
        return $count;
    }

    /**
     * Paginated, filtered list of shares joined with their file path.
     *
     * @param array $filters see applyFilters()
     * @return array<int, array<string, mixed>> raw share rows
     */
    public function findShares(array $filters, int $limit, int $offset, string $sort = 'created', string $dir = 'desc'): array {
        $direction = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';

        $qb = $this->db->getQueryBuilder();
        $qb->select(
            's.id', 's.share_type', 's.share_with', 's.uid_owner', 's.uid_initiator',
            's.item_type', 's.file_source', 's.file_target', 's.permissions',
            's.stime', 's.expiration', 's.token', 's.password', 's.share_name',
        )
            ->selectAlias('f.path', 'file_path')
            ->from('share', 's')
            ->leftJoin('s', 'filecache', 'f', $qb->expr()->eq('s.file_source', 'f.fileid'));

        // Sorting by the raw password column would order by the hash, which
        // is semantically random — sort by "has a password" instead.
        if ($sort === 'password') {
            $qb->orderBy($qb->createFunction('CASE WHEN s.password IS NULL THEN 0 ELSE 1 END'), $direction);
        } else {
            $qb->orderBy(self::SORT_COLUMNS[$sort] ?? 's.stime', $direction);
        }
        $qb->addOrderBy('s.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);
        $this->applyFilters($qb, $filters);

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();
        return $rows;
    }

    /**
     * Load id, share_type and uid_owner for a specific set of share ids, with
     * no other filtering. Used by revoke flows that must resolve the correct
     * IShareManager provider (and, for orphan revokes, re-check ownership)
     * before deleting.
     *
     * @param int[] $ids
     * @return array<int, array{id: int, share_type: int, uid_owner: string}>
     */
    public function findByIds(array $ids): array {
        if ($ids === []) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'share_type', 'uid_owner')
            ->from('share')
            ->where($qb->expr()->in('id',
                $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();
        return $rows;
    }

    /**
     * Public links (type 3) that are missing a password, missing an
     * expiration date, or whose expiration is already past / within
     * $expiringSoonCutoff — the raw material for the security alerts view.
     * A link with both a password and a comfortably-future expiration is
     * never a candidate and is excluded here, before issuesFor() runs.
     *
     * $ownerOrInitiator scopes to a single user's personal view: it matches
     * either uid_owner or uid_initiator, since a user who creates a link on
     * a folder someone else owns is still the one who needs to fix it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findInsecureLinks(?string $ownerOrInitiator = null, ?\DateTimeImmutable $expiringSoonCutoff = null): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select(
            's.id', 's.share_type', 's.uid_owner', 's.uid_initiator',
            's.item_type', 's.file_source', 's.permissions', 's.stime',
            's.expiration', 's.token', 's.password',
        )
            ->selectAlias('f.path', 'file_path')
            ->from('share', 's')
            ->leftJoin('s', 'filecache', 'f', $qb->expr()->eq('s.file_source', 'f.fileid'))
            ->where($qb->expr()->eq('s.share_type', $qb->createNamedParameter(3, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->orX(...$this->insecureLinkConditions($qb, $expiringSoonCutoff)))
            ->orderBy('s.stime', 'DESC');

        if ($ownerOrInitiator !== null) {
            $uid = $qb->createNamedParameter($ownerOrInitiator);
            $qb->andWhere($qb->expr()->orX(
                $qb->expr()->eq('s.uid_owner', $uid),
                $qb->expr()->eq('s.uid_initiator', $uid),
            ));
        }

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();
        return $rows;
    }

    /**
     * OR-able conditions matching the candidate pool issuesFor() actually
     * evaluates: missing password, missing expiration, or (if a cutoff is
     * given) an expiration at/before it — covers both "already expired" and
     * "expiring soon" in one comparison, since the past is always <= cutoff.
     *
     * Single source of truth for the base filter, shared by
     * findInsecureLinks() and countInsecureLinks() so they can never drift
     * apart on *which rows are even candidates* (as opposed to which of
     * those trip an enabled rule, which is each method's own concern).
     *
     * @return array<int, mixed>
     */
    private function insecureLinkConditions(IQueryBuilder $qb, ?\DateTimeImmutable $expiringSoonCutoff): array {
        $conditions = [
            $qb->expr()->isNull('s.password'),
            $qb->expr()->eq('s.password', $qb->createNamedParameter('')),
            $qb->expr()->isNull('s.expiration'),
        ];
        if ($expiringSoonCutoff !== null) {
            $conditions[] = $qb->expr()->lte('s.expiration',
                $qb->createNamedParameter($expiringSoonCutoff->format('Y-m-d H:i:s')));
        }
        return $conditions;
    }

    /**
     * Count of public links matching at least one *enabled* alert rule,
     * computed entirely in SQL — used for dashboard badges (see
     * SecurityAnalyzerService::countAlerts()) where only the number is
     * needed, so evaluating and normalizing every row in PHP is wasted work.
     *
     * The extension check is a superset of the real "sensitive file" rule
     * (a LIKE match on the name, vs. an exact extension match after
     * pathinfo()), so this can very slightly over-count — acceptable for a
     * badge; the alerts list itself still uses the precise PHP evaluation.
     * expiring_soon/already_expired aren't configurable rules (see
     * SecurityAnalyzerService::issuesFor()), so they're always counted when
     * $expiringSoonCutoff is given.
     *
     * @param string[] $sensitiveExtensions lowercase, without the dot
     */
    public function countInsecureLinks(
        bool $noPassword,
        bool $noExpiration,
        bool $sensitiveFile,
        array $sensitiveExtensions,
        ?string $ownerOrInitiator = null,
        ?\DateTimeImmutable $expiringSoonCutoff = null,
    ): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('s.id', 'cnt'))
            ->from('share', 's')
            ->leftJoin('s', 'filecache', 'f', $qb->expr()->eq('s.file_source', 'f.fileid'))
            ->where($qb->expr()->eq('s.share_type', $qb->createNamedParameter(3, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->orX(...$this->insecureLinkConditions($qb, $expiringSoonCutoff)));

        $conditions = [];
        if ($noPassword) {
            $conditions[] = $qb->expr()->orX(
                $qb->expr()->isNull('s.password'),
                $qb->expr()->eq('s.password', $qb->createNamedParameter('')),
            );
        }
        if ($noExpiration) {
            $conditions[] = $qb->expr()->isNull('s.expiration');
        }
        if ($expiringSoonCutoff !== null) {
            $conditions[] = $qb->expr()->andX(
                $qb->expr()->isNotNull('s.expiration'),
                $qb->expr()->lte('s.expiration',
                    $qb->createNamedParameter($expiringSoonCutoff->format('Y-m-d H:i:s'))),
            );
        }
        if ($sensitiveFile && $sensitiveExtensions !== []) {
            $extConditions = array_map(
                fn (string $ext) => $qb->expr()->iLike('f.name', $qb->createNamedParameter('%.' . $ext)),
                $sensitiveExtensions,
            );
            $conditions[] = $qb->expr()->orX(...$extConditions);
        }

        if ($conditions === []) {
            return 0;
        }
        $qb->andWhere($qb->expr()->orX(...$conditions));

        if ($ownerOrInitiator !== null) {
            $uid = $qb->createNamedParameter($ownerOrInitiator);
            $qb->andWhere($qb->expr()->orX(
                $qb->expr()->eq('s.uid_owner', $uid),
                $qb->expr()->eq('s.uid_initiator', $uid),
            ));
        }

        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();
        return $count;
    }

    /**
     * Apply the supported filters to a query builder that has aliased the
     * share table as `s`.
     *
     * Supported keys:
     *  - types:        int[] of raw share_type values to include
     *  - owner:        string uid_owner exact match
     *  - ownerOrInitiator: string uid, matches uid_owner OR uid_initiator —
     *                  a user who created a share on a folder owned by
     *                  someone else is still responsible for it
     *  - search:       string LIKE match on file path or recipient
     *  - hasPassword:  bool
     *  - hasExpiration:bool
     *  - createdSince: int unix timestamp
     */
    private function applyFilters(IQueryBuilder $qb, array $filters): void {
        // Always exclude the internal per-recipient child rows.
        $qb->andWhere($qb->expr()->notIn('s.share_type',
            $qb->createNamedParameter(self::EXCLUDED_TYPES, IQueryBuilder::PARAM_INT_ARRAY)));

        if (!empty($filters['types'])) {
            $qb->andWhere($qb->expr()->in('s.share_type',
                $qb->createNamedParameter($filters['types'], IQueryBuilder::PARAM_INT_ARRAY)));
        }

        if (!empty($filters['owner'])) {
            $qb->andWhere($qb->expr()->eq('s.uid_owner',
                $qb->createNamedParameter($filters['owner'])));
        }

        if (!empty($filters['ownerOrInitiator'])) {
            $uid = $qb->createNamedParameter($filters['ownerOrInitiator']);
            $qb->andWhere($qb->expr()->orX(
                $qb->expr()->eq('s.uid_owner', $uid),
                $qb->expr()->eq('s.uid_initiator', $uid),
            ));
        }

        if (!empty($filters['owners'])) {
            $qb->andWhere($qb->expr()->in('s.uid_owner',
                $qb->createNamedParameter($filters['owners'], IQueryBuilder::PARAM_STR_ARRAY)));
        }

        if (!empty($filters['shareWith'])) {
            $qb->andWhere($qb->expr()->eq('s.share_with',
                $qb->createNamedParameter($filters['shareWith'])));
        }

        if (isset($filters['shareType'])) {
            $qb->andWhere($qb->expr()->eq('s.share_type',
                $qb->createNamedParameter((int)$filters['shareType'], IQueryBuilder::PARAM_INT)));
        }

        if (!empty($filters['search'])) {
            $like = '%' . $this->db->escapeLikeParameter((string)$filters['search']) . '%';
            $qb->andWhere($qb->expr()->orX(
                $qb->expr()->iLike('f.path', $qb->createNamedParameter($like)),
                $qb->expr()->iLike('s.share_with', $qb->createNamedParameter($like)),
                $qb->expr()->iLike('s.share_name', $qb->createNamedParameter($like)),
            ));
        }

        // Per-column search (from the table-header filters).
        foreach (['pathSearch' => 'f.path', 'ownerSearch' => 's.uid_owner', 'recipientSearch' => 's.share_with'] as $key => $column) {
            if (!empty($filters[$key])) {
                $like = '%' . $this->db->escapeLikeParameter((string)$filters[$key]) . '%';
                $qb->andWhere($qb->expr()->iLike($column, $qb->createNamedParameter($like)));
            }
        }

        if (array_key_exists('hasPassword', $filters) && $filters['hasPassword'] !== null) {
            if ($filters['hasPassword']) {
                $qb->andWhere($qb->expr()->isNotNull('s.password'))
                    ->andWhere($qb->expr()->neq('s.password', $qb->createNamedParameter('')));
            } else {
                $qb->andWhere($qb->expr()->orX(
                    $qb->expr()->isNull('s.password'),
                    $qb->expr()->eq('s.password', $qb->createNamedParameter('')),
                ));
            }
        }

        if (array_key_exists('hasExpiration', $filters) && $filters['hasExpiration'] !== null) {
            if ($filters['hasExpiration']) {
                $qb->andWhere($qb->expr()->isNotNull('s.expiration'));
            } else {
                $qb->andWhere($qb->expr()->isNull('s.expiration'));
            }
        }

        if (!empty($filters['createdSince'])) {
            $qb->andWhere($qb->expr()->gte('s.stime',
                $qb->createNamedParameter((int)$filters['createdSince'], IQueryBuilder::PARAM_INT)));
        }
    }
}
