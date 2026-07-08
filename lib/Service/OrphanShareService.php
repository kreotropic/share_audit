<?php

declare(strict_types=1);

namespace OCA\ShareAuditDashboard\Service;

use OCA\ShareAuditDashboard\Db\ShareMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * Finds "orphan" shares: those whose owner (uid_owner) is a disabled or deleted
 * account. Such shares keep granting access after the person is gone, so they
 * are a governance risk during offboarding.
 */
class OrphanShareService {

    private const EXCLUDED_TYPES = [2, 11, 13];

    public function __construct(
        private IDBConnection $db,
        private IUserManager $userManager,
        private ShareMapper $mapper,
        private ShareCollectorService $collector,
    ) {
    }

    /**
     * Map of orphaned owner uid => status ('disabled' | 'deleted').
     *
     * @return array<string, string>
     */
    public function getOrphanOwners(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('uid_owner')
            ->from('share')
            ->where($qb->expr()->notIn('share_type',
                $qb->createNamedParameter(self::EXCLUDED_TYPES, IQueryBuilder::PARAM_INT_ARRAY)));
        $result = $qb->executeQuery();

        $orphans = [];
        while ($row = $result->fetch()) {
            $uid = (string)$row['uid_owner'];
            if ($uid === '') {
                continue;
            }
            $user = $this->userManager->get($uid);
            if ($user === null) {
                $orphans[$uid] = 'deleted';
            } elseif (!$user->isEnabled()) {
                $orphans[$uid] = 'disabled';
            }
        }
        $result->closeCursor();
        return $orphans;
    }

    /**
     * Number of shares owned by orphaned accounts (dashboard badge).
     */
    public function countOrphanShares(): int {
        $owners = array_keys($this->getOrphanOwners());
        if ($owners === []) {
            return 0;
        }
        return $this->mapper->countShares(['owners' => $owners]);
    }

    /**
     * Paginated list of orphan shares, normalized and annotated with the
     * owner's status.
     *
     * A $limit of 0 (or less) returns every orphan on a single page, so the
     * "select all" bulk revoke can span the whole set rather than one page.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, limit: int}
     */
    public function getOrphanShares(int $page, int $limit): array {
        $page = max(1, $page);
        $all = $limit <= 0;
        $limit = $all ? 0 : max(1, min(500, $limit));
        $statuses = $this->getOrphanOwners();
        $owners = array_keys($statuses);

        if ($owners === []) {
            return ['items' => [], 'total' => 0, 'page' => $page, 'limit' => $limit];
        }

        $filters = ['owners' => $owners];
        $total = $this->mapper->countShares($filters);

        $rows = $this->mapper->findShares(
            $filters,
            $all ? max(1, $total) : $limit,
            $all ? 0 : ($page - 1) * $limit,
        );
        $items = array_map(function (array $row) use ($statuses) {
            $share = $this->collector->normalizeRow($row);
            $share['ownerStatus'] = $statuses[$share['owner']] ?? 'unknown';
            return $share;
        }, $rows);

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /**
     * Revoke (delete) shares by id, including any group/room child rows that
     * reference them as parent. Done at the DB level so every share type is
     * handled uniformly.
     *
     * @param int[] $ids
     * @return int number of share rows deleted
     */
    public function revoke(array $ids): int {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn ($v) => $v > 0));
        if ($ids === []) {
            return 0;
        }

        // Remove internal child rows (e.g. per-recipient rows of a group share)
        // first, then the selected shares — returning the top-level count.
        $children = $this->db->getQueryBuilder();
        $children->delete('share')
            ->where($children->expr()->in('parent',
                $children->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
        $children->executeStatement();

        $qb = $this->db->getQueryBuilder();
        $qb->delete('share')
            ->where($qb->expr()->in('id',
                $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
        return $qb->executeStatement();
    }
}
