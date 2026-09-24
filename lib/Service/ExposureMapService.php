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
 * Builds the "exposure map": how far the instance's shared data reaches, split
 * into internal / external / public, with a 0-100 exposure score and a ranking
 * of the users with the most public links.
 */
class ExposureMapService {

    /**
     * Raw share_type => exposure category. TYPE_ROOM is deliberately absent:
     * a Talk conversation's reach depends on the room itself (private vs.
     * anyone-with-the-link public), not on the share_type alone, so it is
     * classified per-room in classifyRoomShares() instead of by this table.
     *
     * This table and classifyRoomShares() are the one place that decides what a
     * share's exposure is: the counts, the score and the "View" filter
     * (filterFor()) all read it, so a category's number and the list behind it
     * cannot disagree.
     */
    private const CATEGORY = [
        IShare::TYPE_USER => 'internal',
        IShare::TYPE_GROUP => 'internal',
        IShare::TYPE_CIRCLE => 'internal',
        IShare::TYPE_EMAIL => 'external',
        IShare::TYPE_REMOTE => 'external',
        IShare::TYPE_REMOTE_GROUP => 'external',
        IShare::TYPE_LINK => 'public',
    ];

    /**
     * Risk weight per category (0 = safe … 2 = most exposed). 'other' covers
     * what could not be classified: a share_type not in CATEGORY above (one
     * added in a future Nextcloud version, e.g. ScienceMesh federation) and a
     * Talk conversation this app could not look up. It must NOT default to
     * 'internal'/weight 0: something that is not known to be safe is weighted
     * the same as 'external' rather than assumed internal.
     */
    private const WEIGHT = ['internal' => 0, 'external' => 1, 'public' => 2, 'other' => 1];

    public function __construct(
        private ShareMapper $mapper,
        private DisplayNameResolver $displayNames,
        private RecipientDetailsResolver $recipientDetails,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getOverview(): array {
        $counts = $this->getCounts();
        $total = array_sum($counts);
        $score = $this->score($counts, $total);

        return [
            'counts' => $counts,
            'total' => $total,
            'score' => $score,
            'level' => $this->level($score),
            'topUsers' => $this->getTopExposedUsers(5),
        ];
    }

    /**
     * Shares per exposure category. The exposure map and the dashboard's
     * "internal vs external" donut both read this, so the two cannot count a
     * Talk conversation differently.
     *
     * @return array{internal: int, external: int, public: int, other: int}
     */
    public function getCounts(): array {
        $counts = ['internal' => 0, 'external' => 0, 'public' => 0, 'other' => 0];
        foreach ($this->mapper->countByType() as $type => $count) {
            if ($type === IShare::TYPE_ROOM) {
                continue;
            }
            $category = self::CATEGORY[$type] ?? 'other';
            $counts[$category] += $count;
        }
        foreach ($this->classifyRoomShares() as $category => $rooms) {
            $counts[$category] += array_sum($rooms);
        }
        return $counts;
    }

    /**
     * What "the shares of this exposure category" means for ShareMapper: the
     * raw share types that belong to it, plus the Talk conversations that do —
     * a public conversation is public exposure, and a filter on share types
     * alone (a public link's type) leaves it out.
     *
     * 'other' is what none of the others take, so it cannot be listed, only
     * described by what it is not: `notTypes` are the share types that have a
     * category of their own (a type outside them is one this app does not know,
     * whatever a future Nextcloud adds), and its conversations are the ones that
     * could not be resolved. Null for a name that is no category at all.
     *
     * @return array{types: int[], roomTokens: string[], notTypes?: int[]}|null
     */
    public function filterFor(string $category): ?array {
        if (!isset(self::WEIGHT[$category])) {
            return null;
        }
        $rooms = array_map('strval', array_keys($this->classifyRoomShares()[$category] ?? []));
        if ($category === 'other') {
            return [
                'types' => [],
                'roomTokens' => $rooms,
                'notTypes' => [...array_keys(self::CATEGORY), IShare::TYPE_ROOM],
            ];
        }
        return [
            'types' => array_keys(array_filter(self::CATEGORY, static fn (string $c) => $c === $category)),
            'roomTokens' => $rooms,
        ];
    }

    /**
     * Whether $category is one of the exposure categories a list can be
     * filtered by.
     */
    public function isCategory(string $category): bool {
        return isset(self::WEIGHT[$category]);
    }

    /**
     * Users with the most public links.
     *
     * @return array<int, array{owner: string, displayName: string, count: int}>
     */
    public function getTopExposedUsers(int $limit): array {
        $owners = $this->mapper->topOwnersByType(IShare::TYPE_LINK, $limit);
        $names = $this->displayNames->resolveMany(array_column($owners, 'owner'));
        foreach ($owners as &$o) {
            $o['displayName'] = $names[$o['owner']] ?? $o['owner'];
        }
        unset($o);
        return $owners;
    }

    /**
     * TYPE_ROOM shares, bucketed by whether their conversation is actually
     * public (Room::TYPE_PUBLIC — the same reach as a public file link) or
     * not, instead of the flat 'internal' every Talk share used to get
     * regardless of the room's own openness.
     *
     * A conversation that cannot be resolved lands in 'other', not in
     * 'internal': when Talk is gone, or its tables are not what this expects,
     * every room is unresolvable at once, and "internal" would report an
     * instance full of public conversations as having no exposure at all.
     *
     * @return array<string, array<string, int>> category => (token => shares into that room)
     */
    private function classifyRoomShares(): array {
        $byToken = $this->mapper->countRoomSharesByToken();
        if ($byToken === []) {
            return [];
        }
        $openness = $this->recipientDetails->describeRoomOpenness(array_map('strval', array_keys($byToken)));

        $classified = [];
        foreach ($byToken as $token => $count) {
            $classified[$openness[$token] ?? 'other'][$token] = $count;
        }
        return $classified;
    }

    /**
     * 0 (all internal) … 100 (all public) weighted exposure score.
     *
     * @param array<string, int> $counts
     */
    private function score(array $counts, int $total): int {
        if ($total === 0) {
            return 0;
        }
        $weighted = 0;
        foreach ($counts as $category => $count) {
            $weighted += self::WEIGHT[$category] * $count;
        }
        return (int)round(100 * $weighted / (2 * $total));
    }

    private function level(int $score): string {
        if ($score < 25) {
            return 'low';
        }
        if ($score < 60) {
            return 'medium';
        }
        return 'high';
    }
}
