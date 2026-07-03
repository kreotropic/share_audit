<?php

declare(strict_types=1);

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
    private const EXCLUDED_TYPES = [2, 11, 13];

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
     * Total number of (top-level) shares.
     */
    public function countTotal(): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from('share')
            ->where($qb->expr()->notIn('share_type',
                $qb->createNamedParameter(self::EXCLUDED_TYPES, IQueryBuilder::PARAM_INT_ARRAY)));
        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();
        return $count;
    }

    /**
     * Number of shares created since the given unix timestamp.
     */
    public function countCreatedSince(int $since): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from('share')
            ->where($qb->expr()->notIn('share_type',
                $qb->createNamedParameter(self::EXCLUDED_TYPES, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->gte('stime',
                $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();
        return $count;
    }

    /**
     * Number of shares created in the half-open interval [from, to).
     */
    public function countCreatedBetween(int $from, int $to): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from('share')
            ->where($qb->expr()->notIn('share_type',
                $qb->createNamedParameter(self::EXCLUDED_TYPES, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->gte('stime', $qb->createNamedParameter($from, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->lt('stime', $qb->createNamedParameter($to, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();
        return $count;
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
    public function findShares(array $filters, int $limit, int $offset): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select(
            's.id', 's.share_type', 's.share_with', 's.uid_owner', 's.uid_initiator',
            's.item_type', 's.file_source', 's.file_target', 's.permissions',
            's.stime', 's.expiration', 's.token', 's.password', 's.share_name',
        )
            ->selectAlias('f.path', 'file_path')
            ->from('share', 's')
            ->leftJoin('s', 'filecache', 'f', $qb->expr()->eq('s.file_source', 'f.fileid'))
            ->orderBy('s.stime', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);
        $this->applyFilters($qb, $filters);

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();
        return $rows;
    }

    /**
     * Public links (type 3) that are missing a password and/or an expiration
     * date — the raw material for the security alerts view.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findInsecureLinks(): array {
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
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('s.password'),
                $qb->expr()->eq('s.password', $qb->createNamedParameter('')),
                $qb->expr()->isNull('s.expiration'),
            ))
            ->orderBy('s.stime', 'DESC');

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();
        return $rows;
    }

    /**
     * Apply the supported filters to a query builder that has aliased the
     * share table as `s`.
     *
     * Supported keys:
     *  - types:        int[] of raw share_type values to include
     *  - owner:        string uid_owner exact match
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

        if (!empty($filters['search'])) {
            $like = '%' . $this->db->escapeLikeParameter((string)$filters['search']) . '%';
            $qb->andWhere($qb->expr()->orX(
                $qb->expr()->iLike('f.path', $qb->createNamedParameter($like)),
                $qb->expr()->iLike('s.share_with', $qb->createNamedParameter($like)),
                $qb->expr()->iLike('s.share_name', $qb->createNamedParameter($like)),
            ));
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
