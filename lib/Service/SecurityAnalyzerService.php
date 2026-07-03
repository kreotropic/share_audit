<?php

declare(strict_types=1);

namespace OCA\ShareAuditDashboard\Service;

use OCA\ShareAuditDashboard\Db\ShareMapper;

/**
 * Detects shares that represent a governance / security risk. For the MVP this
 * focuses on public links (type 3) that lack a password or an expiration date,
 * and flags when such a link exposes a sensitive file type.
 */
class SecurityAnalyzerService {

    public function __construct(
        private ShareMapper $mapper,
        private SettingsService $settings,
    ) {
    }

    /**
     * Number of shares flagged as insecure (used for dashboard badges).
     * Counts actual alerts so it honours the configurable rule toggles.
     */
    public function countAlerts(): int {
        return count($this->getAlerts());
    }

    /**
     * Full list of security alerts, most severe first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAlerts(): array {
        $alerts = [];
        foreach ($this->mapper->findInsecureLinks() as $row) {
            $issues = $this->issuesFor($row);
            if ($issues === []) {
                continue;
            }
            $severity = $this->maxSeverity($issues);
            $alerts[] = [
                'id' => (int)$row['id'],
                'owner' => (string)$row['uid_owner'],
                'path' => $this->prettyPath($row['file_path'] ?? null),
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
        if ($this->settings->isRuleEnabled('no_expiration') && empty($row['expiration'])) {
            $issues[] = ['code' => 'no_expiration', 'severity' => 'warning'];
        }
        if ($this->settings->isRuleEnabled('sensitive_file') && $this->isSensitiveFile($row['file_path'] ?? null)) {
            $issues[] = ['code' => 'sensitive_file', 'severity' => 'warning'];
        }

        return $issues;
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

    private function prettyPath(?string $path): ?string {
        if ($path === null) {
            return null;
        }
        if (str_starts_with($path, 'files/')) {
            return '/' . substr($path, strlen('files/'));
        }
        return $path;
    }
}
