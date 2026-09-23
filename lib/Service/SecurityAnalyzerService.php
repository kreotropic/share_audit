<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Service;

use OCA\ShareAuditDashboard\Db\Ack;
use OCA\ShareAuditDashboard\Db\AckMapper;
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

    /**
     * Every issue code an alert can carry: SettingsService::RULES (the five
     * toggleable rules) plus expiring_soon/already_expired, which aren't
     * configurable (see issuesFor()). Single source of truth for validating
     * a caller-supplied rule code — see AckService::acknowledge().
     */
    public const ISSUE_CODES = [
        'no_password', 'no_expiration', 'sensitive_file',
        'group_share_editable', 'public_upload',
        'expiring_soon', 'already_expired',
    ];

    private ICache $cache;
    private ICache $groupMemberCache;

    public function __construct(
        private ShareMapper $mapper,
        private SettingsService $settings,
        private PathFormatter $pathFormatter,
        private IGroupManager $groupManager,
        private DisplayNameResolver $displayNames,
        private AckMapper $ackMapper,
        ICacheFactory $cacheFactory,
    ) {
        $this->cache = $cacheFactory->createDistributed('share_audit_dashboard-alerts');
        $this->groupMemberCache = $cacheFactory->createDistributed('share_audit_dashboard-group-members');
    }

    /**
     * Number of shares still actively needing attention (used for dashboard
     * badges) — i.e. excluding anything an admin has already acknowledged
     * (see ROADMAP.md's G2). Delegates to getAlerts(), which already carries
     * this exact filtering and a short cache (CACHE_TTL): a dedicated
     * SQL-only count (the pre-G2 approach — see ShareMapper's git history)
     * can't know which rows are acknowledged without re-deriving the same
     * per-issue logic getAlerts() already does, so there's nothing cheaper
     * left to shortcut to once acknowledgment has to be honored.
     */
    public function countAlerts(?string $owner = null): int {
        return count($this->getAlerts($owner));
    }

    /**
     * Full list of security alerts, most severe first. When $owner is given
     * (personal view), scoped to links this user owns OR initiated — see
     * ShareMapper::findInsecureLinks().
     *
     * By default ($includeAcknowledged = false — every existing caller),
     * an issue an admin has acknowledged (see AckService) is dropped from
     * its alert's `issues`, and the whole alert disappears once none are
     * left — this is what keeps an intentionally-accepted link from
     * permanently inflating the count. Pass true (the alerts view's "show
     * acknowledged" filter) to get every alert back unfiltered, each issue
     * annotated with `acknowledged` (+ `acknowledgedBy`/`acknowledgedAt`/
     * `note` when true) so the UI can review and undo exceptions.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAlerts(?string $owner = null, bool $includeAcknowledged = false): array {
        $cacheKey = self::cacheKeyFor($owner);
        $cached = $this->cache->get($cacheKey);
        if (!is_array($cached)) {
            $cached = $this->computeAlerts($owner);
            $this->cache->set($cacheKey, $cached, self::CACHE_TTL);
        }
        return $includeAcknowledged ? $cached : $this->stripAcknowledged($cached);
    }

    /**
     * The alerts whose file/folder name, share label, owner (uid or display
     * name) or group recipient contain every word of $search — case-
     * insensitively, in any order, each word free to match a different field
     * ("contrato ana" finds Ana's contract). An empty search keeps them all.
     *
     * Deliberately not the path: a folder name in it would flood a search for
     * a file. Done here rather than in SQL because the list is computed and
     * cached whole (see getAlerts()) and paged in PHP.
     *
     * @param array<int, array<string, mixed>> $alerts
     * @return array<int, array<string, mixed>>
     */
    public function filterBySearch(array $alerts, string $search): array {
        $words = preg_split('/\s+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return $alerts;
        }
        return array_values(array_filter($alerts, static function (array $alert) use ($words): bool {
            $fields = [
                self::nameOf($alert), $alert['label'] ?? '', $alert['owner'] ?? '',
                $alert['ownerDisplayName'] ?? '', $alert['recipient'] ?? '', $alert['recipientLabel'] ?? '',
            ];
            foreach ($words as $word) {
                $found = false;
                foreach ($fields as $field) {
                    if ($field !== '' && mb_stripos((string)$field, $word) !== false) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    return false;
                }
            }
            return true;
        }));
    }

    /**
     * $alerts ordered alphabetically by file/folder name — case-insensitive,
     * and "natural" so file2 comes before file10. An alert with no known name
     * (its file has left the cache) goes last whichever way the sort runs, so
     * reversing it reorders the named ones instead of dragging those to the top.
     *
     * @param array<int, array<string, mixed>> $alerts
     * @return array<int, array<string, mixed>>
     */
    public function sortByName(array $alerts, bool $ascending): array {
        $direction = $ascending ? 1 : -1;
        usort($alerts, static function (array $a, array $b) use ($direction): int {
            $nameA = self::nameOf($a);
            $nameB = self::nameOf($b);
            if ($nameA === '' || $nameB === '') {
                return ($nameA === '') <=> ($nameB === '');
            }
            return $direction * strnatcmp(mb_strtolower($nameA), mb_strtolower($nameB));
        });
        return $alerts;
    }

    /**
     * The file/folder name of an alert: the last segment of its path, or ''.
     *
     * @param array<string, mixed> $alert
     */
    private static function nameOf(array $alert): string {
        $parts = array_values(array_filter(explode('/', (string)($alert['path'] ?? '')), 'strlen'));
        return $parts === [] ? '' : $parts[count($parts) - 1];
    }

    /**
     * Drop cached alerts made stale by a mutation (password/expiration set,
     * revoke, bulk delete). Always clears the admin view; pass every uid
     * whose personal view (owner or initiator) could include the affected
     * share(s) so it doesn't keep showing an already-fixed item for up to
     * CACHE_TTL seconds after the user acted on it.
     */
    public function invalidate(?string ...$uids): void {
        $this->cache->remove(self::cacheKeyFor(null));
        foreach ($uids as $uid) {
            if ($uid !== null && $uid !== '') {
                $this->cache->remove(self::cacheKeyFor($uid));
            }
        }
    }

    /**
     * The admin/global cache entry (owner === null) and a per-user entry
     * share one ICache instance, so each needs its own namespace — a prefix
     * no uid can ever produce, on both branches, so no real account (e.g.
     * one literally named "admin") can collide with the global entry.
     */
    private static function cacheKeyFor(?string $owner): string {
        return $owner !== null ? 'user:' . $owner : 'admin:__global__';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function computeAlerts(?string $owner): array {
        $acked = $this->loadAcknowledgedPairs();

        // Only widen the SQL candidate pool with the sensitive-extension
        // check when the rule is actually on — see
        // ShareMapper::insecureLinkConditions().
        $sensitiveExtensions = $this->settings->isRuleEnabled('sensitive_file')
            ? $this->settings->getSensitiveExtensions()
            : [];

        $alerts = [];
        foreach ($this->mapper->findInsecureLinks($owner, $this->expiringSoonCutoff(), $sensitiveExtensions) as $row) {
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
            $alert = $this->annotateAcknowledgements($alert, $acked);
        }
        unset($alert);

        return $alerts;
    }

    /**
     * Every recorded exception, indexed by "shareId:ruleCode" for O(1)
     * lookup while annotating each alert's issues — see AckMapper::findAll().
     *
     * @return array<string, Ack>
     */
    private function loadAcknowledgedPairs(): array {
        $pairs = [];
        foreach ($this->ackMapper->findAll() as $ack) {
            $pairs[$ack->getShareId() . ':' . $ack->getRuleCode()] = $ack;
        }
        return $pairs;
    }

    /**
     * Attach `acknowledged` (+ details when true) to every issue on $alert,
     * and an overall `acknowledged` flag on the alert itself — true only
     * when *every* issue is covered, matching stripAcknowledged()'s "drop
     * the whole alert only once nothing active is left" behaviour.
     *
     * @param array<string, mixed> $alert
     * @param array<string, Ack> $acked
     * @return array<string, mixed>
     */
    private function annotateAcknowledgements(array $alert, array $acked): array {
        $allAcked = true;
        foreach ($alert['issues'] as &$issue) {
            $ack = $acked[$alert['id'] . ':' . $issue['code']] ?? null;
            $issue['acknowledged'] = $ack !== null;
            if ($ack !== null) {
                $issue['acknowledgedBy'] = $ack->getAcknowledgedBy();
                $issue['acknowledgedAt'] = $ack->getAcknowledgedAt();
                $issue['note'] = $ack->getNote();
            } else {
                $allAcked = false;
            }
        }
        unset($issue);
        $alert['acknowledged'] = $allAcked;
        return $alert;
    }

    /**
     * Default view of getAlerts(): drop acknowledged issues from each
     * alert's `issues`, and the alert itself once none are left. Severity is
     * recomputed from whatever remains, so an alert that was critical only
     * because of its (now acknowledged) no_password issue correctly drops
     * to whatever its remaining issues warrant.
     *
     * @param array<int, array<string, mixed>> $alerts
     * @return array<int, array<string, mixed>>
     */
    private function stripAcknowledged(array $alerts): array {
        $result = [];
        foreach ($alerts as $alert) {
            if ($alert['acknowledged']) {
                continue;
            }
            $activeIssues = array_values(array_filter(
                $alert['issues'],
                static fn (array $issue) => !$issue['acknowledged'],
            ));
            if ($activeIssues === []) {
                continue;
            }
            $alert['issues'] = $activeIssues;
            $alert['severity'] = $this->maxSeverity($activeIssues);
            $result[] = $alert;
        }
        return $result;
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
            // The name given to the share itself (a link's custom label), if any.
            // oc_share keeps it in `label`; `share_name` is a legacy column
            // that is always NULL.
            'label' => ($row['label'] ?? '') !== '' ? (string)$row['label'] : null,
            'token' => $token,
            'created' => isset($row['stime']) ? (int)$row['stime'] : null,
            'issues' => $issues,
            'severity' => $this->maxSeverity($issues),
        ];
    }

    /**
     * Group shares that grant edit/reshare permission to a group with at
     * least SettingsService::getGroupShareMinMembers() members — the
     * candidate pool for the group_share_editable rule, used by
     * computeAlerts() (and, through it, both getAlerts() and countAlerts()).
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
