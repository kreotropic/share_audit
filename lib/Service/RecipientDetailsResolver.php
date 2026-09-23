<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Says what a Talk conversation or a Deck card is, for the shares made into
 * them. oc_share keeps only an opaque key for those recipients — a conversation's
 * token (`kz6giye3`), a card's number — which tells an auditor nothing about
 * who can read the file. This looks the key up in the other app's own tables,
 * once per page rather than once per row, like DisplayNameResolver does for
 * accounts.
 *
 * It reads another app's tables, which are not an API. So every lookup is
 * fenced: if the app is not installed, or its schema is not what this expects,
 * the rows come back untouched and the listing shows the raw key it always did.
 * Nothing here can make a list fail.
 */
class RecipientDetailsResolver {

    /** Talk's Room::TYPE_* — its classes are not available to this app. */
    private const ROOM_ONE_TO_ONE = 1;
    private const ROOM_GROUP = 2;
    private const ROOM_PUBLIC = 3;
    private const LISTABLE_NONE = 0;

    /** Talk attendee actor types that stand for many people, not one. */
    private const COLLECTIVE_ACTORS = ['groups', 'circles'];

    /** Deck's own per-user rows of a card share (IShare::TYPE_DECK_USER). */
    private const DECK_USER_SHARE_TYPE = 13;

    /** Ids per IN (...) — well under every supported database's parameter limit. */
    private const CHUNK = 500;

    public function __construct(
        private IDBConnection $db,
        private DisplayNameResolver $displayNames,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Adds `recipientInfo` (what the recipient is) and `recipientDisplayName`
     * (a label for it) to the Talk and Deck rows of a normalized list. Every
     * other row, and any row whose conversation or card cannot be found, is
     * returned as it came.
     *
     * @param array<int, array<string, mixed>> $items normalizeRow() output
     * @return array<int, array<string, mixed>>
     */
    public function decorate(array $items): array {
        $tokens = [];
        $cardIds = [];
        $deckShareIds = [];
        foreach ($items as $item) {
            $recipient = (string)($item['recipient'] ?? '');
            $type = $item['type'] ?? null;
            if ($type === IShare::TYPE_ROOM && $recipient !== '') {
                $tokens[$recipient] = true;
            } elseif ($type === IShare::TYPE_DECK && ctype_digit($recipient)) {
                $cardIds[(int)$recipient] = true;
                $deckShareIds[] = (int)$item['id'];
            }
        }
        if ($tokens === [] && $cardIds === []) {
            return $items;
        }

        $rooms = $tokens === [] ? [] : $this->describeRooms(array_map('strval', array_keys($tokens)));
        $cards = $cardIds === [] ? [] : $this->describeCards(array_keys($cardIds), $deckShareIds);

        foreach ($items as &$item) {
            $recipient = (string)($item['recipient'] ?? '');
            if (($item['type'] ?? null) === IShare::TYPE_ROOM && isset($rooms[$recipient])) {
                $item['recipientInfo'] = $rooms[$recipient];
                $item['recipientDisplayName'] = $rooms[$recipient]['label'];
            } elseif (($item['type'] ?? null) === IShare::TYPE_DECK && ctype_digit($recipient) && isset($cards['cards'][(int)$recipient])) {
                $card = $cards['cards'][(int)$recipient];
                $card['people'] = $cards['people'][(int)$item['id']] ?? null;
                $item['recipientInfo'] = $card;
                $item['recipientDisplayName'] = $card['label'];
            }
        }
        unset($item);
        return $items;
    }

    /**
     * Tokens of the group and public conversations whose name contains $term,
     * so the Recipient filter can find a share by the name that is on screen.
     * A one-to-one conversation is left out: its "name" is a list of account
     * ids, not something anybody would type.
     *
     * @return list<string>
     */
    public function searchRoomTokens(string $term, int $limit = 50): array {
        if ($term === '') {
            return [];
        }
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('token')
                ->from('talk_rooms')
                ->where($qb->expr()->iLike('name',
                    $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($term) . '%')))
                ->andWhere($qb->expr()->in('type',
                    $qb->createNamedParameter([self::ROOM_GROUP, self::ROOM_PUBLIC], IQueryBuilder::PARAM_INT_ARRAY)))
                ->orderBy('token', 'ASC')
                ->setMaxResults($limit);
            $result = $qb->executeQuery();
            $tokens = [];
            while (($token = $result->fetchOne()) !== false) {
                $tokens[] = (string)$token;
            }
            $result->closeCursor();
            return $tokens;
        } catch (\Throwable $e) {
            $this->logUnavailable('Talk', $e);
            return [];
        }
    }

    /**
     * Ids (as strings, as oc_share.share_with holds them) of the Deck cards
     * whose title, or whose board's title, contains $term.
     *
     * @return list<string>
     */
    public function searchCardIds(string $term, int $limit = 50): array {
        if ($term === '') {
            return [];
        }
        try {
            $like = '%' . $this->db->escapeLikeParameter($term) . '%';
            $qb = $this->db->getQueryBuilder();
            $qb->select('c.id')
                ->from('deck_cards', 'c')
                ->innerJoin('c', 'deck_stacks', 's', $qb->expr()->eq('s.id', 'c.stack_id'))
                ->innerJoin('s', 'deck_boards', 'b', $qb->expr()->eq('b.id', 's.board_id'))
                ->where($qb->expr()->orX(
                    $qb->expr()->iLike('c.title', $qb->createNamedParameter($like)),
                    $qb->expr()->iLike('b.title', $qb->createNamedParameter($like)),
                ))
                ->orderBy('c.id', 'ASC')
                ->setMaxResults($limit);
            $result = $qb->executeQuery();
            $ids = [];
            while (($id = $result->fetchOne()) !== false) {
                $ids[] = (string)$id;
            }
            $result->closeCursor();
            return $ids;
        } catch (\Throwable $e) {
            $this->logUnavailable('Deck', $e);
            return [];
        }
    }

    /**
     * Exposure category for each of $tokens' conversation: 'public' when
     * anyone holding the link can join (Room::TYPE_PUBLIC — same reach as a
     * public file link), 'internal' for every other kind (one-to-one, a
     * defined group of participants, or a room that could not be resolved
     * at all). Used by ExposureMapService, which otherwise has no way to
     * tell a public conversation's shares apart from a private one's: both
     * are just a TYPE_ROOM row whose share_with is an opaque token.
     *
     * A room this app can't resolve (Talk uninstalled, or its schema
     * doesn't match) keeps the pre-existing "assume internal" behaviour
     * rather than being counted as unknown/other: unlike a share_type this
     * app has simply never seen before, a TYPE_ROOM share can only exist at
     * all because Talk created it, so an unresolvable one is stale data
     * (the room itself is gone), not evidence of anything actually public.
     *
     * @param string[] $tokens
     * @return array<string, string> token => 'public' | 'internal'
     */
    public function describeRoomOpenness(array $tokens): array {
        try {
            $rooms = $this->fetchRooms($tokens);
        } catch (\Throwable $e) {
            $this->logUnavailable('Talk', $e);
            return [];
        }
        $result = [];
        foreach ($rooms as $token => $room) {
            $result[(string)$token] = (int)$room['type'] === self::ROOM_PUBLIC ? 'public' : 'internal';
        }
        return $result;
    }

    /**
     * @param string[] $tokens
     * @return array<string, array<string, mixed>> token => recipientInfo
     */
    private function describeRooms(array $tokens): array {
        try {
            $rooms = $this->fetchRooms($tokens);
            $withCounts = [];
            $oneToOne = [];
            foreach ($rooms as $room) {
                if ((int)$room['type'] === self::ROOM_ONE_TO_ONE) {
                    $oneToOne[(int)$room['id']] = $this->participantIds((string)$room['name']);
                } else {
                    $withCounts[] = (int)$room['id'];
                }
            }
            $counts = $withCounts === [] ? [] : $this->fetchAttendeeCounts($withCounts);
            $names = $this->displayNames->resolveMany(array_merge([], ...array_values($oneToOne)));
        } catch (\Throwable $e) {
            $this->logUnavailable('Talk', $e);
            return [];
        }

        $info = [];
        foreach ($rooms as $token => $room) {
            $id = (int)$room['id'];
            $info[(string)$token] = $this->roomInfo(
                $room,
                $counts[$id] ?? [],
                array_map(static fn (string $uid) => $names[$uid] ?? $uid, $oneToOne[$id] ?? []),
            );
        }
        return $info;
    }

    /**
     * What a conversation is, from its row, the count of its attendees by kind,
     * and — for a one-to-one — the display names of its two people.
     *
     * @param array<string, mixed> $room a talk_rooms row
     * @param array<string, int> $attendees attendee count by actor type
     * @param string[] $people display names, one-to-one only
     * @return array<string, mixed>
     */
    private function roomInfo(array $room, array $attendees, array $people): array {
        $token = (string)$room['token'];
        $type = (int)$room['type'];
        $name = trim((string)$room['name']);

        if ($type === self::ROOM_ONE_TO_ONE) {
            return [
                'kind' => 'talk',
                'roomType' => 'one_to_one',
                'label' => $people !== [] ? implode(' ↔ ', $people) : $token,
                'people' => $people,
                'participants' => null,
                'groups' => null,
                'openTo' => null,
            ];
        }

        $groups = 0;
        $participants = 0;
        foreach ($attendees as $actorType => $count) {
            if (in_array($actorType, self::COLLECTIVE_ACTORS, true)) {
                $groups += $count;
            } else {
                $participants += $count;
            }
        }

        $openTo = null;
        if ($type === self::ROOM_PUBLIC) {
            $openTo = 'link';
        } elseif ((int)($room['listable'] ?? self::LISTABLE_NONE) !== self::LISTABLE_NONE) {
            $openTo = 'users';
        }

        return [
            'kind' => 'talk',
            'roomType' => match ($type) {
                self::ROOM_GROUP => 'group',
                self::ROOM_PUBLIC => 'public',
                default => 'other',
            },
            'label' => $name !== '' ? $name : $token,
            'people' => [],
            'participants' => $participants,
            'groups' => $groups,
            'openTo' => $openTo,
        ];
    }

    /**
     * A one-to-one conversation is named, in Talk's table, with the JSON list
     * of its two account ids.
     *
     * @return string[]
     */
    private function participantIds(string $name): array {
        $ids = json_decode($name, true);
        if (!is_array($ids)) {
            return [];
        }
        return array_values(array_filter($ids, static fn ($id) => is_string($id) && $id !== ''));
    }

    /**
     * @param int[] $cardIds
     * @param int[] $shareIds the card shares, whose per-user rows say how many
     *                        people the board reaches
     * @return array{cards: array<int, array<string, mixed>>, people: array<int, int>}
     */
    private function describeCards(array $cardIds, array $shareIds): array {
        try {
            $rows = $this->fetchCards($cardIds);
        } catch (\Throwable $e) {
            $this->logUnavailable('Deck', $e);
            return ['cards' => [], 'people' => []];
        }

        $cards = [];
        foreach ($rows as $id => $row) {
            $title = trim($row['title']);
            $cards[$id] = [
                'kind' => 'deck',
                'label' => $title !== '' ? $title : (string)$id,
                'card' => $title,
                'board' => $row['board'],
                'people' => null,
            ];
        }

        try {
            $people = $this->fetchDeckPeople($shareIds);
        } catch (\Throwable $e) {
            $this->logUnavailable('Deck', $e);
            $people = [];
        }
        return ['cards' => $cards, 'people' => $people];
    }

    /**
     * @param string[] $tokens
     * @return array<string, array<string, mixed>> token => talk_rooms row
     */
    protected function fetchRooms(array $tokens): array {
        $rooms = [];
        foreach (array_chunk($tokens, self::CHUNK) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'token', 'name', 'type', 'listable')
                ->from('talk_rooms')
                ->where($qb->expr()->in('token', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)));
            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $rooms[(string)$row['token']] = $row;
            }
            $result->closeCursor();
        }
        return $rooms;
    }

    /**
     * @param int[] $roomIds
     * @return array<int, array<string, int>> room id => (actor type => attendees)
     */
    protected function fetchAttendeeCounts(array $roomIds): array {
        $counts = [];
        foreach (array_chunk($roomIds, self::CHUNK) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('room_id', 'actor_type')
                ->selectAlias($qb->func()->count('*'), 'cnt')
                ->from('talk_attendees')
                ->where($qb->expr()->in('room_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
                ->groupBy('room_id', 'actor_type');
            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $counts[(int)$row['room_id']][(string)$row['actor_type']] = (int)$row['cnt'];
            }
            $result->closeCursor();
        }
        return $counts;
    }

    /**
     * @param int[] $cardIds
     * @return array<int, array{title: string, board: string}> card id => title and board title
     */
    protected function fetchCards(array $cardIds): array {
        $cards = [];
        foreach (array_chunk($cardIds, self::CHUNK) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('c.id', 'c.title')
                ->selectAlias('b.title', 'board_title')
                ->from('deck_cards', 'c')
                ->innerJoin('c', 'deck_stacks', 's', $qb->expr()->eq('s.id', 'c.stack_id'))
                ->innerJoin('s', 'deck_boards', 'b', $qb->expr()->eq('b.id', 's.board_id'))
                ->where($qb->expr()->in('c.id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $cards[(int)$row['id']] = ['title' => (string)$row['title'], 'board' => (string)$row['board_title']];
            }
            $result->closeCursor();
        }
        return $cards;
    }

    /**
     * How many people each Deck card share reaches: Deck writes one row per
     * account that can see the board (its owner excepted), pointing back at the
     * share, so no Deck table is needed.
     *
     * @param int[] $shareIds
     * @return array<int, int> share id => people
     */
    protected function fetchDeckPeople(array $shareIds): array {
        $people = [];
        foreach (array_chunk($shareIds, self::CHUNK) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('parent')
                ->selectAlias($qb->func()->count('*'), 'cnt')
                ->from('share')
                ->where($qb->expr()->eq('share_type', $qb->createNamedParameter(self::DECK_USER_SHARE_TYPE, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->in('parent', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
                ->groupBy('parent');
            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $people[(int)$row['parent']] = (int)$row['cnt'];
            }
            $result->closeCursor();
        }
        return $people;
    }

    /**
     * At debug level on purpose: an instance whose Talk or Deck is gone but whose
     * shares are not would otherwise log this on every page load.
     */
    private function logUnavailable(string $app, \Throwable $e): void {
        $this->logger->debug(
            '{app} details are unavailable, showing the raw recipient: {exception}',
            ['app' => $app, 'exception' => $e],
        );
    }
}
