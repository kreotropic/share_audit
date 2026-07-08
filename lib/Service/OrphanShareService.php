<?php

declare(strict_types=1);

namespace OCA\ShareAuditDashboard\Service;

use OCA\ShareAuditDashboard\Db\ShareMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * Finds "orphan" shares: those whose owner (uid_owner) is a disabled or deleted
 * account. Such shares keep granting access after the person is gone, so they
 * are a governance risk during offboarding.
 */
class OrphanShareService {

    private const EXCLUDED_TYPES = [2, 11, 13];

    /**
     * getOrphanOwners() is on the hot path of the dashboard/stats endpoint
     * and, per distinct owner, can cost a backend round trip (e.g. an LDAP
     * lookup) to resolve. A short cache absorbs repeated loads; revoke()
     * always bypasses it (see $fresh) so a just-reactivated account can never
     * be treated as orphaned by a stale read.
     */
    private const CACHE_TTL = 90;
    private const CACHE_KEY = 'orphan-owners';

    private ICache $cache;

    public function __construct(
        private IDBConnection $db,
        private IUserManager $userManager,
        private ShareMapper $mapper,
        private ShareCollectorService $collector,
        private ShareDeletionService $deletion,
        ICacheFactory $cacheFactory,
    ) {
        $this->cache = $cacheFactory->createDistributed('share_audit_dashboard-orphans');
    }

    /**
     * Map of orphaned owner uid => status ('disabled' | 'deleted').
     *
     * @param bool $fresh bypass the cache — required wherever the result
     *                    gates a mutation (see revoke()).
     * @return array<string, string>
     */
    public function getOrphanOwners(bool $fresh = false): array {
        if (!$fresh) {
            $cached = $this->cache->get(self::CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $orphans = $this->computeOrphanOwners();
        $this->cache->set(self::CACHE_KEY, $orphans, self::CACHE_TTL);
        return $orphans;
    }

    /**
     * @return array<string, string>
     */
    private function computeOrphanOwners(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('uid_owner')
            ->from('share')
            ->where($qb->expr()->notIn('share_type',
                $qb->createNamedParameter(self::EXCLUDED_TYPES, IQueryBuilder::PARAM_INT_ARRAY)));
        $result = $qb->executeQuery();
        $owners = array_map('strval', $result->fetchAll(\PDO::FETCH_COLUMN));
        $result->closeCursor();

        // One batch query covers every disabled account. This is normally
        // what made this slow: it replaces a per-uid backend round trip
        // (e.g. an LDAP bind) for each disabled owner with a single lookup.
        $disabled = $this->userManager->getDisabledUsers();

        $orphans = [];
        foreach ($owners as $uid) {
            if ($uid === '') {
                continue;
            }
            if (isset($disabled[$uid])) {
                $orphans[$uid] = 'disabled';
                continue;
            }
            // Not in the disabled batch: still need a per-uid existence
            // check to tell "deleted" apart from "a normal active user" —
            // there is no batch API for that, hence the cache above.
            if ($this->userManager->get($uid) === null) {
                $orphans[$uid] = 'deleted';
            }
        }
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
     * Revoke (delete) shares by id — but only those that are *currently*
     * owned by an orphaned (disabled/deleted) account. IDs are re-checked
     * against a freshly computed (not cached) orphan-owner set here, so a
     * stale client-side selection — or a stale cache entry — can never
     * revoke a share belonging to an active user.
     *
     * Deletion goes through IShareManager (see ShareDeletionService) so
     * federated unshare, ShareDeletedEvent and provider cleanup all run,
     * instead of a raw DELETE.
     *
     * @param int[] $ids
     * @return int number of share rows deleted
     */
    public function revoke(array $ids): int {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn ($v) => $v > 0));
        if ($ids === []) {
            return 0;
        }

        $orphanOwners = $this->getOrphanOwners(true);
        $rows = array_filter(
            $this->mapper->findByIds($ids),
            static fn (array $row) => isset($orphanOwners[(string)$row['uid_owner']]),
        );

        return $this->deletion->deleteRows($rows);
    }
}
