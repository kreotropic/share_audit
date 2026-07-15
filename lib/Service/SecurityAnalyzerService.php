<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Service;

use OCA\ShareAuditDashboard\Db\ShareMapper;
use OCP\Constants;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IGroupManager;

/**
 * Detects shares that represent a governance / security risk. Originally
 * scoped to public links (type 3) missing a password or expiration date, or
 * exposing a sensitive file type; also covers two share_type-independent
 * risks: a public link open for anonymous
 * upload without a password, and a native group share with edit/reshare
 * permission granted to a very large group.
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

    /**
     * Group member counts don't change nearly as often as share state, and
     * resolving them (IGroupManager, possibly LDAP) is the expensive part of
     * the group_share_editable rule — cached separately and longer-lived
     * than the alerts cache above.
     */
    private const GROUP_MEMBER_CACHE_TTL = 900;

    /** Window (days) within which a still-valid expiration counts as "expiring soon". */
    private const EXPIRING_SOON_DAYS = 7;

    /** Permission bits that make a group share "editable" for the group_share_editable rule. */
    private const EDIT_PERMISSIONS = Constants::PERMISSION_UPDATE | Constants::PERMISSION_SHARE;

    private ICache $cache;
    private ICache $groupMemberCache;

    public function __construct(
        private ShareMapper $mapper,
        private SettingsService $settings,
        private PathFormatter $pathFormatter,
        private IGroupManager $groupManager,
        private DisplayNameResolver $displayNames,
        ICacheFactory $cacheFactory,
    ) {
        $this->cache = $cacheFactory->createDistributed('share_audit_dashboard-alerts');
        $this->groupMemberCache = $cacheFactory->createDistributed('share_audit_dashboard-group-members');
    }

    /**
     * Number of shares flagged as insecure (used for dashboard badges).
     * Link-based rules are computed directly in SQL — see
     * ShareMapper::countInsecureLinks() — so badges don't pay the cost of
     * normalizing every alert just for a count; group_share_editable can't
     * take that shortcut (member counts aren't in oc_share) and reuses the
     * same row-filtering as getAlerts(), just without building full alert
     * records — cheap in practice since group shares are a small slice of
     * the share table.
     */
    public function countAlerts(?string $owner = null): int {
        $count = $this->mapper->countInsecureLinks(
            $this->settings->isRuleEnabled('no_password'),
            $this->settings->isRuleEnabled('no_expiration'),
            $this->settings->isRuleEnabled('sensitive_file'),
            $this->settings->getSensitiveExtensions(),
            $owner,
            $this->expiringSoonCutoff(),
        );
        if ($this->settings->isRuleEnabled('group_share_editable')) {
            $count += count($this->riskyGroupShareRows($owner));
        }
        return $count;
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
            $alerts[] = $this->buildAlert($row, $issues, $row['token'] ?? null);
        }

        if ($this->settings->isRuleEnabled('group_share_editable')) {
            foreach ($this->riskyGroupShareRows($owner) as $row) {
                $issue = ['code' => 'group_share_editable', 'severity' => 'warning'];
                $alerts[] = $this->buildAlert($row, [$issue], null) + [
                    'recipient' => (string)($row['share_with'] ?? ''),
                    'recipientLabel' => $this->groupManager->getDisplayName((string)($row['share_with'] ?? '')) ?? (string)($row['share_with'] ?? ''),
                    'memberCount' => $row['_memberCount'],
                ];
            }
        }

        // Sort critical > warning > info.
        $rank = ['critical' => 0, 'warning' => 1, 'info' => 2];
        usort($alerts, fn ($a, $b) => $rank[$a['severity']] <=> $rank[$b['severity']]);

        // Batched once for the whole page, not per alert — see DisplayNameResolver.
        $names = $this->displayNames->resolveMany(array_column($alerts, 'owner'));
        foreach ($alerts as &$alert) {
            $alert['ownerDisplayName'] = $names[$alert['owner']] ?? $alert['owner'];
        }
        unset($alert);

        return $alerts;
    }

    /**
     * Build the fields shared by every alert shape (link-based or
     * group-share-based). Callers may merge additional fields on top.
     *
     * @param array<string, mixed> $row
     * @param array<int, array{code: string, severity: string}> $issues
     * @return array<string, mixed>
     */
    private function buildAlert(array $row, array $issues, ?string $token): array {
        return [
            'id' => (int)$row['id'],
            'shareType' => (int)($row['share_type'] ?? 0),
            'owner' => (string)$row['uid_owner'],
            'fileId' => isset($row['file_source']) ? (int)$row['file_source'] : null,
            'path' => $this->pathFormatter->prettyPath($row['file_path'] ?? null),
            'token' => $token,
            'created' => isset($row['stime']) ? (int)$row['stime'] : null,
            'issues' => $issues,
            'severity' => $this->maxSeverity($issues),
        ];
    }

    /**
     * Group shares that grant edit/reshare permission to a group with at
     * least SettingsService::getGroupShareMinMembers() members — the
     * candidate pool for the group_share_editable rule. Reused by both
     * getAlerts() (full records) and countAlerts() (just the count).
     *
     * @return array<int, array<string, mixed>> rows annotated with `_memberCount`
     */
    private function riskyGroupShareRows(?string $owner): array {
        $minMembers = $this->settings->getGroupShareMinMembers();
        $rows = [];
        foreach ($this->mapper->findGroupShares($owner) as $row) {
            $permissions = (int)($row['permissions'] ?? 0);
            if (($permissions & self::EDIT_PERMISSIONS) === 0) {
                continue;
            }
            $gid = (string)($row['share_with'] ?? '');
            if ($gid === '') {
                continue;
            }
            $memberCount = $this->groupMemberCount($gid);
            if ($memberCount < $minMembers) {
                continue;
            }
            $row['_memberCount'] = $memberCount;
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Member count for a group, cached — see GROUP_MEMBER_CACHE_TTL. A
     * group that no longer exists (deleted after the share was created)
     * counts as 0, not flagged: a share to a group that isn't there anymore
     * grants no one anything.
     */
    private function groupMemberCount(string $gid): int {
        $cacheKey = 'count_' . $gid;
        $cached = $this->groupMemberCache->get($cacheKey);
        if (is_int($cached)) {
            return $cached;
        }
        $group = $this->groupManager->get($gid);
        $count = $group !== null ? max(0, (int)$group->count()) : 0;
        $this->groupMemberCache->set($cacheKey, $count, self::GROUP_MEMBER_CACHE_TTL);
        return $count;
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

        if ($this->settings->isRuleEnabled('public_upload') && $this->isPublicUpload($row)) {
            $issues[] = ['code' => 'public_upload', 'severity' => 'warning'];
        }

        if (empty($row['expiration'])) {
            if ($this->settings->isRuleEnabled('no_expiration')) {
                $issues[] = ['code' => 'no_expiration', 'severity' => 'warning'];
            }
        } else {
            // Independent of the no_expiration toggle: a link *with* an
            // expiration set is only actually protected while that date is
            // still in the future and not imminent
            // (ShareCollectorService/ShareMapper's own "hasExpiration" flag
            // and filter follow the same rule).
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
     * A passwordless public link open for anonymous upload: either "file
     * drop" (create without read — the visitor can only add files, not see
     * what's already there) or full create+update (they can add AND modify
     * existing files). Both are only a
     * meaningfully distinct risk from plain no_password when a password
     * *would* otherwise gate them, so this always requires no password;
     * with one set, upload is limited to whoever has the password anyway
     * and is covered by that risk assessment instead.
     */
    private function isPublicUpload(array $row): bool {
        if (!empty($row['password'])) {
            return false;
        }
        $permissions = (int)($row['permissions'] ?? 0);
        $canCreate = ($permissions & Constants::PERMISSION_CREATE) !== 0;
        if (!$canCreate) {
            return false;
        }
        $canRead = ($permissions & Constants::PERMISSION_READ) !== 0;
        $canUpdate = ($permissions & Constants::PERMISSION_UPDATE) !== 0;
        return !$canRead || $canUpdate;
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
