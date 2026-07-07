<?php

declare(strict_types=1);

namespace OCA\ShareAuditDashboard\Service;

use OCA\ShareAuditDashboard\Db\ShareMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Share\IShare;

/**
 * Reverse lookup: given a recipient (user, group, email or federated address),
 * find every share that grants them access — the "who can reach this data"
 * question Nextcloud can't answer natively. Useful for offboarding and audits.
 */
class RecipientLookupService {

    /** Share types that carry a recipient in share_with (links have none). */
    private const RECIPIENT_TYPES = [
        IShare::TYPE_USER, IShare::TYPE_GROUP, IShare::TYPE_EMAIL,
        IShare::TYPE_REMOTE, IShare::TYPE_CIRCLE, IShare::TYPE_REMOTE_GROUP,
        IShare::TYPE_ROOM,
    ];

    private const CATEGORY = [
        IShare::TYPE_USER => 'user',
        IShare::TYPE_GROUP => 'group',
        IShare::TYPE_EMAIL => 'email',
        IShare::TYPE_REMOTE => 'federated',
        IShare::TYPE_REMOTE_GROUP => 'federated',
        IShare::TYPE_ROOM => 'talk',
    ];

    public function __construct(
        private IDBConnection $db,
        private IUserManager $userManager,
        private IGroupManager $groupManager,
        private ShareMapper $mapper,
        private ShareCollectorService $collector,
    ) {
    }

    /**
     * Autocomplete: recipients whose id/email matches the query, grouped by
     * (share_with, share_type) with a share count.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query, int $limit = 20): array {
        if (trim($query) === '') {
            return [];
        }
        $like = '%' . $this->db->escapeLikeParameter($query) . '%';

        $qb = $this->db->getQueryBuilder();
        $qb->select('share_with', 'share_type')
            ->selectAlias($qb->func()->count('*'), 'cnt')
            ->from('share')
            ->where($qb->expr()->in('share_type',
                $qb->createNamedParameter(self::RECIPIENT_TYPES, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->iLike('share_with', $qb->createNamedParameter($like)))
            ->groupBy('share_with', 'share_type')
            ->orderBy('cnt', 'DESC')
            ->setMaxResults($limit);

        $result = $qb->executeQuery();
        $rows = [];
        while ($row = $result->fetch()) {
            $shareWith = (string)$row['share_with'];
            $type = (int)$row['share_type'];
            $rows[] = [
                'shareWith' => $shareWith,
                'shareType' => $type,
                'category' => self::CATEGORY[$type] ?? 'other',
                'label' => $this->displayName($shareWith, $type),
                'count' => (int)$row['cnt'],
            ];
        }
        $result->closeCursor();
        return $rows;
    }

    /**
     * All shares granting access to a given recipient, normalized.
     *
     * @return array{recipient: array<string,mixed>, items: array<int, array<string, mixed>>, total: int}
     */
    public function getShares(string $shareWith, int $shareType): array {
        $filters = ['shareWith' => $shareWith, 'shareType' => $shareType];
        $rows = $this->mapper->findShares($filters, 500, 0);

        return [
            'recipient' => [
                'shareWith' => $shareWith,
                'shareType' => $shareType,
                'category' => self::CATEGORY[$shareType] ?? 'other',
                'label' => $this->displayName($shareWith, $shareType),
            ],
            'items' => array_map([$this->collector, 'normalizeRow'], $rows),
            'total' => $this->mapper->countShares($filters),
        ];
    }

    /**
     * Revoke every share to this recipient (and internal child rows).
     *
     * @return int number of top-level shares revoked
     */
    public function revokeAll(string $shareWith, int $shareType): int {
        // Collect the matching top-level ids first (for child cleanup).
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from('share')
            ->where($qb->expr()->eq('share_with', $qb->createNamedParameter($shareWith)))
            ->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter($shareType, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $ids = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
        $result->closeCursor();

        if ($ids === []) {
            return 0;
        }

        $children = $this->db->getQueryBuilder();
        $children->delete('share')
            ->where($children->expr()->in('parent',
                $children->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
        $children->executeStatement();

        $del = $this->db->getQueryBuilder();
        $del->delete('share')
            ->where($del->expr()->in('id', $del->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
        return $del->executeStatement();
    }

    private function displayName(string $shareWith, int $shareType): string {
        if ($shareType === IShare::TYPE_USER) {
            return $this->userManager->get($shareWith)?->getDisplayName() ?: $shareWith;
        }
        if ($shareType === IShare::TYPE_GROUP) {
            return $this->groupManager->get($shareWith)?->getDisplayName() ?: $shareWith;
        }
        return $shareWith;
    }
}
