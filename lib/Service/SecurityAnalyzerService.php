<?php

declare(strict_types=1);

namespace OCA\ShareAuditDashboard\Service;

use OCA\ShareAuditDashboard\Db\ShareMapper;
use OCP\ICache;
use OCP\ICacheFactory;

/**
 * Detects shares that represent a governance / security risk. For the MVP this
 * focuses on public links (type 3) that lack a password or an expiration date,
 * and flags when such a link exposes a sensitive file type.
 */
class SecurityAnalyzerService {

    /**
     * getAlerts() re-evaluates every insecure link on each call — needed for
     * ranking + breakdown, but wasteful when the alerts page is just paged
     * through (Previous/Next) or reloaded within a few seconds. A short
     * cache absorbs that; a fix applied through remediation may take up to
     * this long to disappear from the list, which is an acceptable trade-off
     * for a non-mutating read (contrast with OrphanShareService, where a
     * mutation gate always bypasses its cache).
     */
    private const CACHE_TTL = 60;

    /** Window (days) within which a still-valid expiration counts as "expiring soon". */
    private const EXPIRING_SOON_DAYS = 7;

    private ICache $cache;

    public function __construct(
        private ShareMapper $mapper,
        private SettingsService $settings,
        private PathFormatter $pathFormatter,
        ICacheFactory $cacheFactory,
    ) {
        $this->cache = $cacheFactory->createDistributed('share_audit_dashboard-alerts');
    }

    /**
     * Number of shares flagged as insecure (used for dashboard badges).
     * Computed directly in SQL — see ShareMapper::countInsecureLinks() — so
     * badges don't pay the cost of normalizing every alert just for a count.
     */
    public function countAlerts(?string $owner = null): int {
        return $this->mapper->countInsecureLinks(
            $this->settings->isRuleEnabled('no_password'),
            $this->settings->isRuleEnabled('no_expiration'),
            $this->settings->isRuleEnabled('sensitive_file'),
            $this->settings->getSensitiveExtensions(),
            $owner,
            $this->expiringSoonCutoff(),
        );
    }

    /**
     * Full list of security alerts, most severe first. When $owner is given
     * (personal view), scoped to links this user owns OR initiated — see
     * ShareMapper::findInsecureLinks().
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAlerts(?string $owner = null): array {
        $cacheKey = $owner ?? '__admin__';
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
        $alerts = $this->computeAlerts($owner);
        $this->cache->set($cacheKey, $alerts, self::CACHE_TTL);
        return $alerts;
    }

    /**
     * Drop cached alerts made stale by a mutation (password/expiration set,
     * revoke, bulk delete). Always clears the admin view; pass every uid
     * whose personal view (owner or initiator) could include the affected
     * share(s) so it doesn't keep showing an already-fixed item for up to
     * CACHE_TTL seconds after the user acted on it.
     */
    public function invalidate(?string ...$uids): void {
        $this->cache->remove('__admin__');
        foreach ($uids as $uid) {
            if ($uid !== null && $uid !== '') {
                $this->cache->remove($uid);
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function computeAlerts(?string $owner): array {
        $alerts = [];
        foreach ($this->mapper->findInsecureLinks($owner, $this->expiringSoonCutoff()) as $row) {
            $issues = $this->issuesFor($row);
            if ($issues === []) {
                continue;
            }
            $severity = $this->maxSeverity($issues);
            $alerts[] = [
                'id' => (int)$row['id'],
                'owner' => (string)$row['uid_owner'],
                'fileId' => isset($row['file_source']) ? (int)$row['file_source'] : null,
                'path' => $this->pathFormatter->prettyPath($row['file_path'] ?? null),
                'token' => $row['token'] ?? null,
                'created' => isset($row['stime']) ? (int)$row['stime'] : null,
                'issues' => $issues,
                'severity' => $severity,
            ];
        }

        // Sort critical > warning > info.
        $rank = ['critical' => 0, 'warning' => 1, 'info' => 2];
        usort($alerts, fn ($a, $b) => $rank[$a['severity']] <=> $rank[$b['severity']]);

        return $alerts;
    }

    /**
     * Count how many alerts carry each issue code, for the alert breakdown
     * chart. Keys follow SettingsService::RULES order.
     *
     * @param array<int, array<string, mixed>> $alerts result of getAlerts()
     * @return array<string, int>
     */
    public function countByIssue(array $alerts): array {
        $counts = array_fill_keys(SettingsService::RULES, 0);
        foreach ($alerts as $alert) {
            foreach ($alert['issues'] as $issue) {
                $counts[$issue['code']] = ($counts[$issue['code']] ?? 0) + 1;
            }
        }
        return $counts;
    }

    /**
     * Determine the list of issues for a public-link row.
     *
     * @param array<string, mixed> $row
     * @return array<int, array{code: string, severity: string}>
     */
    private function issuesFor(array $row): array {
        $issues = [];

        if ($this->settings->isRuleEnabled('no_password') && empty($row['password'])) {
            $issues[] = ['code' => 'no_password', 'severity' => 'critical'];
        }

        if (empty($row['expiration'])) {
            if ($this->settings->isRuleEnabled('no_expiration')) {
                $issues[] = ['code' => 'no_expiration', 'severity' => 'warning'];
            }
        } else {
            // Independent of the no_expiration toggle: a link *with* an
            // expiration set is only actually protected while that date is
            // still in the future and not imminent — see FEATURE_GAPS_PLAN.md
            // Q4 (and G5.2, not implemented here, for hasExpiration itself).
            $expiresAt = $this->parseExpiration($row['expiration']);
            if ($expiresAt !== null) {
                $now = new \DateTimeImmutable();
                if ($expiresAt < $now) {
                    $issues[] = ['code' => 'already_expired', 'severity' => 'warning'];
                } elseif ($expiresAt <= $this->expiringSoonCutoff()) {
                    $issues[] = ['code' => 'expiring_soon', 'severity' => 'info'];
                }
            }
        }

        if ($this->settings->isRuleEnabled('sensitive_file') && $this->isSensitiveFile($row['file_path'] ?? null)) {
            $issues[] = ['code' => 'sensitive_file', 'severity' => 'warning'];
        }

        return $issues;
    }

    private function parseExpiration(string $expiration): ?\DateTimeImmutable {
        try {
            return new \DateTimeImmutable($expiration);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Shared by issuesFor() (PHP-precise) and the mapper's SQL candidate
     * filter/count (which must stay a superset of what this actually flags).
     */
    private function expiringSoonCutoff(): \DateTimeImmutable {
        return (new \DateTimeImmutable())->modify('+' . self::EXPIRING_SOON_DAYS . ' days');
    }

    private function isSensitiveFile(?string $path): bool {
        if (empty($path)) {
            return false;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return $ext !== '' && in_array($ext, $this->settings->getSensitiveExtensions(), true);
    }

    /**
     * @param array<int, array{code: string, severity: string}> $issues
     */
    private function maxSeverity(array $issues): string {
        $rank = ['critical' => 0, 'warning' => 1, 'info' => 2];
        $best = 'info';
        foreach ($issues as $issue) {
            if ($rank[$issue['severity']] < $rank[$best]) {
                $best = $issue['severity'];
            }
        }
        return $best;
    }
}
