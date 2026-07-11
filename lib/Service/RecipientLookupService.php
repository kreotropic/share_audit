<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

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

    /**
     * revokeAll() resolves ids server-side (unlike the bulk-by-id endpoints,
     * which are capped client-side) — a recipient with thousands of shares
     * would otherwise run thousands of synchronous IShareManager deletes in
     * one HTTP request and likely time out, partially applied with no
     * report. Cap one request to a single batch; the caller repeats the
     * request while the response's `remaining` is > 0.
     */
    private const BATCH_SIZE = 500;

    /** Share types that carry a recipient in share_with (links have none). */
    private const RECIPIENT_TYPES = [
        IShare::TYPE_USER, IShare::TYPE_GROUP, IShare::TYPE_EMAIL,
        IShare::TYPE_REMOTE, IShare::TYPE_CIRCLE, IShare::TYPE_REMOTE_GROUP,
        IShare::TYPE_ROOM,
    ];

    /** Same 'circle' → 'group' bucketing as ShareCollectorService::CATEGORY_BY_TYPE. */
    private const CATEGORY = [
        IShare::TYPE_USER => 'user',
        IShare::TYPE_GROUP => 'group',
        IShare::TYPE_CIRCLE => 'group',
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
        private ShareDeletionService $deletion,
    ) {
    }

    /**
     * Autocomplete: recipients whose id/email matches the query, grouped by
     * (share_with, share_type) with a share count.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query, int $limit = 20): array {
        // Mirrors the frontend's minimum, but enforced server-side too: a
        // direct API call (bypassing the UI) with a 1-char query would
        // otherwise trigger a full LIKE '%x%' scan of the share table.
        if (mb_strlen(trim($query)) < 2) {
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
     * Paginated shares granting access to a given recipient, normalized. A
     * $limit of 0 (or less) returns every share on a single page — same
     * convention as OrphanShareService::getOrphanShares().
     *
     * @return array{recipient: array<string,mixed>, items: array<int, array<string, mixed>>, total: int, page: int, limit: int}
     */
    public function getShares(string $shareWith, int $shareType, int $page = 1, int $limit = 500): array {
        $page = max(1, $page);
        $all = $limit <= 0;
        $limit = $all ? 0 : max(1, min(500, $limit));

        $filters = ['shareWith' => $shareWith, 'shareType' => $shareType];
        $total = $this->mapper->countShares($filters);
        $rows = $this->mapper->findShares(
            $filters,
            $all ? max(1, $total) : $limit,
            $all ? 0 : ($page - 1) * $limit,
        );
        $items = $this->collector->withDisplayNames(
            array_map([$this->collector, 'normalizeRow'], $rows),
        );

        return [
            'recipient' => [
                'shareWith' => $shareWith,
                'shareType' => $shareType,
                'category' => self::CATEGORY[$shareType] ?? 'other',
                'label' => $this->displayName($shareWith, $shareType),
            ],
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /**
     * Revoke up to one batch of shares to this recipient. Goes through
     * IShareManager (see ShareDeletionService) so federated unshare,
     * ShareDeletedEvent and provider cleanup all run, instead of a raw
     * DELETE. Resolves and deletes at most BATCH_SIZE rows per call; the
     * caller repeats the request while `remaining` > 0 (a share left behind
     * as `failed` — see ShareDeletionService — also counts toward
     * `remaining`, since its row is still there).
     *
     * @return array{deleted: int, failed: int[], remaining: int}
     */
    public function revokeAll(string $shareWith, int $shareType): array {
        $rows = $this->findRecipientRows($shareWith, $shareType, self::BATCH_SIZE);

        if ($rows === []) {
            return ['deleted' => 0, 'failed' => [], 'remaining' => 0];
        }

        $result = $this->deletion->deleteRows($rows);
        $remaining = $this->countRecipientRows($shareWith, $shareType);

        return $result + ['remaining' => $remaining];
    }

    /**
     * @return array<int, array{id: int, share_type: int, uid_owner: string}>
     */
    private function findRecipientRows(string $shareWith, int $shareType, int $limit): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'share_type', 'uid_owner')->from('share')
            ->where($qb->expr()->eq('share_with', $qb->createNamedParameter($shareWith)))
            ->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter($shareType, IQueryBuilder::PARAM_INT)))
            ->setMaxResults($limit);
        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();
        return $rows;
    }

    private function countRecipientRows(string $shareWith, int $shareType): int {
        $qb = $this->db->getQueryBuilder();
        $qb->selectAlias($qb->func()->count('*'), 'cnt')->from('share')
            ->where($qb->expr()->eq('share_with', $qb->createNamedParameter($shareWith)))
            ->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter($shareType, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();
        return $count;
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
