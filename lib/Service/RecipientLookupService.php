<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Service;

use OCA\ShareAuditDashboard\Db\ShareMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Security\ICrypto;
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

    /**
     * Bytes of the keyed hash kept as the opaque handle a Talk conversation is
     * known by to a caller who may not see its token (128 bits — plenty to
     * never collide; shown as 32 hex characters).
     */
    private const ROOM_HANDLE_BYTES = 16;

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
        private DisplayNameResolver $displayNames,
        private RecipientDetailsResolver $recipientDetails,
        private ICrypto $crypto,
    ) {
    }

    /**
     * Autocomplete: recipients whose id/email OR resolved display name
     * matches the query, grouped by (share_with, share_type) with a share
     * count. On an LDAP/SAML instance share_with is often an opaque uid
     * (e.g. a UUID) while the result label shown to the admin is the
     * display name — searching must match either, same reasoning as
     * ShareCollectorService::withOwnerSearchUids()/withRecipientSearchIds().
     *
     * A Talk conversation is found by its name, like in the share list. Its
     * `share_with` is its token, a bare credential, so only a caller with
     * $canSeeTokens may be handed it back, or have a query matched against it,
     * or be given results in an order or a cut-off that depends on it: any
     * other gets an opaque handle in its place (see roomHandle()) and, for
     * that item, `opaque: true`. An ORDER BY on the token would be as good as
     * the token to someone who can make rooms of their own — every comparison
     * against a token they know is a bit of one they do not — so for that
     * caller the conversations are kept out of the SQL that sorts and limits,
     * and are ordered here by what is public about them (how many shares, what
     * they are called), with the handle, a keyed hash that says nothing about
     * how tokens compare, as the last resort.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query, int $limit = 20, bool $canSeeTokens = true): array {
        // Mirrors the frontend's minimum, but enforced server-side too: a
        // direct API call (bypassing the UI) with a 1-char query would
        // otherwise trigger a full LIKE '%x%' scan of the share table.
        if (mb_strlen(trim($query)) < 2) {
            return [];
        }
        $like = '%' . $this->db->escapeLikeParameter($query) . '%';
        $matchingIds = array_merge(
            $this->displayNames->searchUids($query, $limit),
            $this->displayNames->searchGroupIds($query, $limit),
        );
        $roomTokens = $this->recipientDetails->searchRoomTokens($query);

        $found = $this->findRecipients($like, $matchingIds, $canSeeTokens ? $roomTokens : [], $limit, $canSeeTokens);
        if (!$canSeeTokens) {
            foreach ($this->countSharesIntoRooms($roomTokens) as $token => $count) {
                $found[] = ['share_with' => (string)$token, 'share_type' => IShare::TYPE_ROOM, 'cnt' => $count];
            }
        }

        $names = $this->recipientDetails->roomLabels(array_column(array_filter(
            $found,
            static fn (array $row) => (int)$row['share_type'] === IShare::TYPE_ROOM,
        ), 'share_with'));

        $rows = [];
        foreach ($found as $row) {
            $shareWith = (string)$row['share_with'];
            $type = (int)$row['share_type'];
            $item = [
                'shareWith' => $shareWith,
                'shareType' => $type,
                'category' => self::CATEGORY[$type] ?? 'other',
                'label' => $this->displayName($shareWith, $type),
                'count' => (int)$row['cnt'],
            ];
            if ($type === IShare::TYPE_ROOM) {
                $item['label'] = $names[$shareWith] ?? ($canSeeTokens ? $shareWith : '');
                if (!$canSeeTokens) {
                    $item['shareWith'] = $this->roomHandle($shareWith);
                    $item['opaque'] = true;
                }
            }
            $rows[] = $item;
        }

        if (!$canSeeTokens) {
            // The SQL ordered and cut the people, groups and addresses (none of
            // which is a credential); the conversations joined afterwards, so the
            // whole is put in one order and cut once, here.
            usort($rows, static fn (array $a, array $b) => [-$a['count'], mb_strtolower($a['label']), $a['shareType'], $a['shareWith']]
                <=> [-$b['count'], mb_strtolower($b['label']), $b['shareType'], $b['shareWith']]);
            $rows = array_slice($rows, 0, $limit);
        }
        return $rows;
    }

    /**
     * The recipients (share_with, share_type, share count) that match, most shares
     * first. Without $withRooms no Talk row is a candidate at all; with it, the
     * rows into $roomTokens (found by name) are, along with any Talk row whose
     * token contains the query.
     *
     * @param string[] $matchingIds uids and gids whose display name matched
     * @param string[] $roomTokens tokens of the conversations whose name matched
     * @return array<int, array<string, mixed>>
     */
    private function findRecipients(string $like, array $matchingIds, array $roomTokens, int $limit, bool $withRooms): array {
        $types = $withRooms ? self::RECIPIENT_TYPES : array_values(array_diff(self::RECIPIENT_TYPES, [IShare::TYPE_ROOM]));

        $qb = $this->db->getQueryBuilder();
        $conditions = [$qb->expr()->iLike('share_with', $qb->createNamedParameter($like))];
        if ($matchingIds !== []) {
            $conditions[] = $qb->expr()->in('share_with',
                $qb->createNamedParameter($matchingIds, IQueryBuilder::PARAM_STR_ARRAY));
        }
        if ($roomTokens !== []) {
            $conditions[] = $qb->expr()->andX(
                $qb->expr()->eq('share_type', $qb->createNamedParameter(IShare::TYPE_ROOM, IQueryBuilder::PARAM_INT)),
                $qb->expr()->in('share_with', $qb->createNamedParameter($roomTokens, IQueryBuilder::PARAM_STR_ARRAY)),
            );
        }

        $qb->select('share_with', 'share_type')
            ->selectAlias($qb->func()->count('*'), 'cnt')
            ->from('share')
            ->where($qb->expr()->in('share_type',
                $qb->createNamedParameter($types, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->orX(...$conditions))
            ->groupBy('share_with', 'share_type')
            ->orderBy('cnt', 'DESC')
            // Same reason as ShareMapper::topOwners(): with a LIMIT, an
            // untied-broken sort decides which recipients are offered as
            // autocomplete results at all, and the databases disagree.
            ->addOrderBy('share_with', 'ASC')
            ->addOrderBy('share_type', 'ASC')
            ->setMaxResults($limit);

        $result = $qb->executeQuery();
        $found = $result->fetchAll();
        $result->closeCursor();
        return $found;
    }

    /**
     * How many shares go into each of these conversations. Deliberately with
     * no ORDER BY and no LIMIT: this is for a caller who may not see the
     * tokens, and the database's idea of "first" would be theirs to read (see
     * search()). $tokens is already capped by searchRoomTokens().
     *
     * @param string[] $tokens
     * @return array<string, int> token => shares
     */
    private function countSharesIntoRooms(array $tokens): array {
        if ($tokens === []) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('share_with')
            ->selectAlias($qb->func()->count('*'), 'cnt')
            ->from('share')
            ->where($qb->expr()->eq('share_type', $qb->createNamedParameter(IShare::TYPE_ROOM, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->in('share_with', $qb->createNamedParameter($tokens, IQueryBuilder::PARAM_STR_ARRAY)))
            ->groupBy('share_with');
        $result = $qb->executeQuery();
        $counts = [];
        while ($row = $result->fetch()) {
            $counts[(string)$row['share_with']] = (int)$row['cnt'];
        }
        $result->closeCursor();
        return $counts;
    }

    /**
     * All shares granting access to a given recipient, normalized and paginated.
     *
     * A $limit of 0 (or less) returns every matching share on a single page,
     * same convention as OrphanShareService::getOrphanShares().
     *
     * Without $canSeeTokens a Talk conversation is looked up by the opaque
     * handle search() gave out for it, never by its token — a token passed in
     * finds nothing — and no row carries the token back. An empty $shareWith,
     * or a share type that has no recipient, finds nothing either, instead of
     * quietly meaning "every share of that type".
     *
     * @return array{recipient: array<string,mixed>, items: array<int, array<string, mixed>>, total: int, page: int, limit: int}
     */
    public function getShares(string $shareWith, int $shareType, int $page = 1, int $limit = 25, bool $canSeeTokens = true): array {
        $page = max(1, $page);
        $all = $limit <= 0;
        $limit = $all ? 0 : max(1, min(500, $limit));

        // A conversation asked for by an auditor is answered with what this
        // side found, never with an echo of what was asked: a value that is
        // not a handle (a token, say) gets the same empty answer as any other.
        $hidden = $shareType === IShare::TYPE_ROOM && !$canSeeTokens;
        $recipient = [
            'shareWith' => $hidden ? '' : $shareWith,
            'shareType' => $shareType,
            'category' => self::CATEGORY[$shareType] ?? 'other',
            'label' => $hidden ? '' : $this->displayName($shareWith, $shareType),
        ];
        $empty = ['recipient' => $recipient, 'items' => [], 'total' => 0, 'page' => $page, 'limit' => $limit];

        if ($shareWith === '' || !in_array($shareType, self::RECIPIENT_TYPES, true)) {
            return $empty;
        }

        if ($shareType === IShare::TYPE_ROOM) {
            $token = $canSeeTokens ? $shareWith : $this->tokenForRoomHandle($shareWith);
            if ($token === null) {
                return $empty;
            }
            $label = $this->recipientDetails->roomLabels([$token])[$token] ?? null;
            $recipient['label'] = $label ?? ($canSeeTokens ? $token : '');
            if (!$canSeeTokens) {
                $recipient['shareWith'] = $shareWith;
                $recipient['opaque'] = true;
            }
            $shareWith = $token;
        }

        $filters = ['shareWith' => $shareWith, 'shareType' => $shareType];
        $total = $this->mapper->countShares($filters);
        $rows = $this->mapper->findShares(
            $filters,
            $all ? max(1, $total) : $limit,
            $all ? 0 : ($page - 1) * $limit,
        );
        $items = array_map([$this->collector, 'normalizeRow'], $rows);
        if (!$canSeeTokens) {
            $items = $this->recipientDetails->redactRoomTokens($this->recipientDetails->decorate($items));
        }

        $names = $this->displayNames->resolveMany(array_column($items, 'owner'));
        foreach ($items as &$item) {
            $item['ownerDisplayName'] = $names[$item['owner']] ?? $item['owner'];
        }
        unset($item);

        return [
            'recipient' => $recipient,
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /**
     * What a Talk conversation is called to a caller who may not see its token:
     * a keyed hash of it (HMAC with the instance's secret), so it identifies the
     * conversation from one request to the next and reveals nothing about the
     * token, which nobody without the secret can compute back from it or
     * forward to it.
     *
     * ICrypto::calculateHMAC() returns the raw bytes of the hash, not hex, so
     * they are turned into text here — a handle is a URL parameter and a JSON
     * string.
     */
    private function roomHandle(string $token): string {
        return bin2hex(substr(
            $this->crypto->calculateHMAC('share_audit_dashboard:room:' . $token),
            0,
            self::ROOM_HANDLE_BYTES,
        ));
    }

    /**
     * The token behind a handle, among the conversations that have shares — the
     * only ones this app ever hands a handle out for. Null for anything else,
     * including a token given in place of a handle.
     */
    private function tokenForRoomHandle(string $handle): ?string {
        if ($handle === '') {
            return null;
        }
        foreach (array_keys($this->mapper->countRoomSharesByToken()) as $token) {
            if (hash_equals($this->roomHandle((string)$token), $handle)) {
                return (string)$token;
            }
        }
        return null;
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
