<?php

declare(strict_types=1);

namespace OCA\ShareAuditDashboard\Service;

use OCA\ShareAuditDashboard\Db\ShareMapper;
use OCP\IUserManager;
use OCP\Share\IShare;

/**
 * Builds the "exposure map": how far the instance's shared data reaches, split
 * into internal / external / public, with a 0-100 exposure score and a ranking
 * of the users with the most public links.
 */
class ExposureMapService {

    /** Raw share_type => exposure category. */
    private const CATEGORY = [
        IShare::TYPE_USER => 'internal',
        IShare::TYPE_GROUP => 'internal',
        IShare::TYPE_ROOM => 'internal',
        IShare::TYPE_CIRCLE => 'internal',
        IShare::TYPE_EMAIL => 'external',
        IShare::TYPE_REMOTE => 'external',
        IShare::TYPE_REMOTE_GROUP => 'external',
        IShare::TYPE_LINK => 'public',
    ];

    /** Risk weight per category (0 = safe … 2 = most exposed). */
    private const WEIGHT = ['internal' => 0, 'external' => 1, 'public' => 2];

    public function __construct(
        private ShareMapper $mapper,
        private IUserManager $userManager,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getOverview(): array {
        $counts = ['internal' => 0, 'external' => 0, 'public' => 0];
        foreach ($this->mapper->countByType() as $type => $count) {
            $category = self::CATEGORY[$type] ?? 'internal';
            $counts[$category] += $count;
        }
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
     * Users with the most public links.
     *
     * @return array<int, array{owner: string, displayName: string, count: int}>
     */
    public function getTopExposedUsers(int $limit): array {
        return array_map(function (array $o) {
            $user = $this->userManager->get($o['owner']);
            $o['displayName'] = $user?->getDisplayName() ?: $o['owner'];
            return $o;
        }, $this->mapper->topOwnersByType(IShare::TYPE_LINK, $limit));
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
