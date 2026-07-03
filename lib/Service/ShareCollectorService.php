<?php

declare(strict_types=1);

namespace OCA\ShareAuditDashboard\Service;

use OCA\ShareAuditDashboard\Db\ShareMapper;
use OCP\Share\IShare;

/**
 * Aggregates raw share rows into normalized, presentation-ready structures
 * for the dashboard: stats, paginated lists with human-readable permissions
 * and types, and trend counters.
 */
class ShareCollectorService {

    /** Category buckets used by the dashboard cards, keyed by raw share_type. */
    private const CATEGORY_BY_TYPE = [
        IShare::TYPE_USER => 'user',
        IShare::TYPE_GROUP => 'group',
        IShare::TYPE_LINK => 'link',
        IShare::TYPE_EMAIL => 'email',
        IShare::TYPE_REMOTE => 'federated',
        IShare::TYPE_REMOTE_GROUP => 'federated',
        IShare::TYPE_ROOM => 'talk',
    ];

    public function __construct(
        private ShareMapper $mapper,
        private SecurityAnalyzerService $security,
    ) {
    }

    /**
     * Dashboard statistics: totals per category, trends and top owners.
     *
     * @return array<string, mixed>
     */
    public function getStats(): array {
        $rawCounts = $this->mapper->countByType();

        $byType = [
            'user' => 0, 'group' => 0, 'link' => 0, 'email' => 0,
            'federated' => 0, 'talk' => 0, 'other' => 0,
        ];
        foreach ($rawCounts as $type => $count) {
            $category = self::CATEGORY_BY_TYPE[$type] ?? 'other';
            $byType[$category] += $count;
        }

        $now = time();
        return [
            'total' => $this->mapper->countTotal(),
            'byType' => $byType,
            'trend' => [
                'last30' => $this->mapper->countCreatedSince($now - 30 * 86400),
                'last90' => $this->mapper->countCreatedSince($now - 90 * 86400),
                'last365' => $this->mapper->countCreatedSince($now - 365 * 86400),
            ],
            'trendSeries' => $this->monthlyTrend(12),
            'topOwners' => $this->mapper->topOwners(5),
            'alertsCount' => $this->security->countAlerts(),
        ];
    }

    /**
     * Shares created per calendar month for the last $months months (oldest
     * first), for the dashboard trend chart.
     *
     * @return array<int, array{label: string, count: int}>
     */
    private function monthlyTrend(int $months): array {
        $series = [];
        $firstOfThisMonth = new \DateTimeImmutable('first day of this month midnight');
        for ($i = $months - 1; $i >= 0; $i--) {
            $start = $firstOfThisMonth->sub(new \DateInterval('P' . $i . 'M'));
            $end = $start->add(new \DateInterval('P1M'));
            $series[] = [
                'label' => $start->format('Y-m'),
                'count' => $this->mapper->countCreatedBetween($start->getTimestamp(), $end->getTimestamp()),
            ];
        }
        return $series;
    }

    /**
     * Paginated, filtered list of shares.
     *
     * @param array $filters normalized filters (see ShareMapper::applyFilters)
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, limit: int}
     */
    public function getShares(array $filters, int $page, int $limit): array {
        $page = max(1, $page);
        $limit = max(1, min(500, $limit));
        $offset = ($page - 1) * $limit;

        $rows = $this->mapper->findShares($filters, $limit, $offset);

        return [
            'items' => array_map([$this, 'normalizeRow'], $rows),
            'total' => $this->mapper->countShares($filters),
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /**
     * All shares matching the filters, normalized, for export. Unpaginated but
     * capped to avoid unbounded memory use on very large instances.
     *
     * @param array $filters normalized filters (see ShareMapper::applyFilters)
     * @return array<int, array<string, mixed>>
     */
    public function getAllForExport(array $filters, int $max = 100000): array {
        $rows = $this->mapper->findShares($filters, $max, 0);
        return array_map([$this, 'normalizeRow'], $rows);
    }

    /**
     * Turn a raw DB row into a presentation-ready share record.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function normalizeRow(array $row): array {
        $type = (int)$row['share_type'];
        $permissions = (int)$row['permissions'];

        return [
            'id' => (int)$row['id'],
            'type' => $type,
            'typeLabel' => $this->typeLabel($type),
            'category' => self::CATEGORY_BY_TYPE[$type] ?? 'other',
            'owner' => (string)$row['uid_owner'],
            'initiator' => (string)($row['uid_initiator'] ?? ''),
            'recipient' => (string)($row['share_with'] ?? ''),
            'itemType' => (string)($row['item_type'] ?? ''),
            'path' => $this->prettyPath($row['file_path'] ?? null),
            'permissions' => $permissions,
            'permissionLabels' => $this->permissionLabels($permissions),
            'created' => isset($row['stime']) ? (int)$row['stime'] : null,
            'expiration' => $this->normalizeExpiration($row['expiration'] ?? null),
            'hasPassword' => !empty($row['password']),
            'hasExpiration' => !empty($row['expiration']),
            'token' => $row['token'] ?? null,
            'name' => $row['share_name'] ?? null,
        ];
    }

    /**
     * Human-readable label for a raw share_type.
     */
    public function typeLabel(int $type): string {
        return match ($type) {
            IShare::TYPE_USER => 'user',
            IShare::TYPE_GROUP => 'group',
            IShare::TYPE_LINK => 'link',
            IShare::TYPE_EMAIL => 'email',
            IShare::TYPE_CIRCLE => 'circle',
            IShare::TYPE_REMOTE => 'federated',
            IShare::TYPE_REMOTE_GROUP => 'federated_group',
            IShare::TYPE_ROOM => 'talk',
            IShare::TYPE_DECK => 'deck',
            default => 'other',
        };
    }

    /**
     * Decode a Nextcloud permission bitmask into readable tokens.
     *
     * @return string[]
     */
    public function permissionLabels(int $permissions): array {
        $labels = [];
        if ($permissions & 1) {
            $labels[] = 'read';
        }
        if ($permissions & 2) {
            $labels[] = 'update';
        }
        if ($permissions & 4) {
            $labels[] = 'create';
        }
        if ($permissions & 8) {
            $labels[] = 'delete';
        }
        if ($permissions & 16) {
            $labels[] = 'share';
        }
        return $labels;
    }

    /**
     * Strip the internal "files/" storage prefix from a filecache path so the
     * result reads like a user-facing path.
     */
    private function prettyPath(?string $path): ?string {
        if ($path === null) {
            return null;
        }
        if (str_starts_with($path, 'files/')) {
            return '/' . substr($path, strlen('files/'));
        }
        if ($path === 'files') {
            return '/';
        }
        return $path;
    }

    /**
     * Normalize the expiration column (stored as a datetime string) to an
     * ISO-8601 date or null.
     */
    private function normalizeExpiration($expiration): ?string {
        if (empty($expiration)) {
            return null;
        }
        try {
            return (new \DateTimeImmutable((string)$expiration))->format('Y-m-d');
        } catch (\Exception) {
            return (string)$expiration;
        }
    }
}
