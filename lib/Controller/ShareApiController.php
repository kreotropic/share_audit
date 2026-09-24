<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Controller;

use OCA\ShareAuditDashboard\Service\AccessService;
use OCA\ShareAuditDashboard\Service\ExpiryDefaultsService;
use OCA\ShareAuditDashboard\Service\ExposureMapService;
use OCA\ShareAuditDashboard\Service\OrphanShareService;
use OCA\ShareAuditDashboard\Service\ReportService;
use OCA\ShareAuditDashboard\Service\SecurityAnalyzerService;
use OCA\ShareAuditDashboard\Service\SettingsService;
use OCA\ShareAuditDashboard\Service\ShareAuditLogger;
use OCA\ShareAuditDashboard\Service\ShareCollectorService;
use OCA\ShareAuditDashboard\Service\SoftDeleteService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;

/**
 * JSON API backing the Share Audit Dashboard frontend.
 *
 * stats(), index(), export() and alerts() are read-only and open to admins
 * and auditors (see AdminController::requireViewer()) — export() and
 * alerts() additionally strip public-link tokens for a non-admin, and
 * index()/export() likewise redact a Talk conversation's bare token from
 * `recipient` (see RecipientDetailsResolver::redactRoomTokens()), since those
 * are bare credentials too. Every other action, including settings, stays
 * behind requireAdmin(): these endpoints expose or change share metadata
 * across all users and must never be reachable by a regular account.
 */
class ShareApiController extends AdminController {

    public function __construct(
        string $appName,
        IRequest $request,
        private ShareCollectorService $collector,
        private SecurityAnalyzerService $security,
        private ReportService $report,
        private SettingsService $settings,
        private OrphanShareService $orphanService,
        private SoftDeleteService $softDelete,
        private ExpiryDefaultsService $expiryDefaults,
        private IGroupManager $groupManager,
        private ShareAuditLogger $auditLogger,
        private ExposureMapService $exposure,
        AccessService $access,
    ) {
        parent::__construct($appName, $request, $access);
    }

    /**
     * GET /api/stats — dashboard counters, trends and top owners.
     */
    #[NoAdminRequired]
    public function stats(): JSONResponse {
        if (($scope = $this->requireViewer()) instanceof JSONResponse) {
            return $scope;
        }
        $stats = $this->collector->getStats();
        $stats['orphanCount'] = $this->orphanService->countOrphanShares();
        $stats['deletedCount'] = $this->softDelete->count();
        // The donut is drawn from the exposure categories, not from the raw
        // share types: a public Talk conversation is public exposure whatever
        // its share type says (see ExposureMapService).
        $stats['exposure'] = $this->exposure->getCounts();
        return new JSONResponse($stats);
    }

    /**
     * GET /api/shares — paginated, filterable list of all shares.
     *
     * $limit keeps Nextcloud's default 1..500 rule (no "all" page size here:
     * the collector clamps it to at least 1).
     */
    #[NoAdminRequired]
    public function index(
        int $page = 1,
        int $limit = 50,
        string $types = '',
        string $owner = '',
        string $search = '',
        string $pathSearch = '',
        string $ownerSearch = '',
        string $recipientSearch = '',
        string $hasPassword = '',
        string $hasExpiration = '',
        int $createdSince = 0,
        string $sort = 'created',
        string $sortDir = 'desc',
        string $exposure = '',
    ): JSONResponse {
        if (($scope = $this->requireViewer()) instanceof JSONResponse) {
            return $scope;
        }

        $filters = $this->buildFilters($types, $owner, $search, $hasPassword, $hasExpiration, $createdSince);
        $filters['pathSearch'] = $pathSearch !== '' ? $pathSearch : null;
        $filters['ownerSearch'] = $ownerSearch !== '' ? $ownerSearch : null;
        $filters['recipientSearch'] = $recipientSearch !== '' ? $recipientSearch : null;
        $filters['exposure'] = $exposure !== '' ? $exposure : null;
        if ($exposure !== '' && !$this->exposure->isCategory($exposure)) {
            return new JSONResponse(['message' => 'Unknown exposure category.'], Http::STATUS_BAD_REQUEST);
        }

        return new JSONResponse($this->collector->getShares($filters, $page, $limit, $sort, $sortDir, $scope->canSeeTokens()));
    }

    /**
     * GET /api/export — CSV of the filtered share list (same filters, column
     * search and sort as index(), so the export always matches what the
     * admin is looking at on screen).
     *
     * Tokens (bare credentials for public links) are omitted unless
     * $includeTokens is explicitly set — the frontend must warn the admin
     * before turning this on. An auditor never gets them, regardless of
     * $includeTokens: see AccessScope::canSeeTokens().
     */
    #[NoAdminRequired]
    public function export(
        string $types = '',
        string $owner = '',
        string $search = '',
        string $pathSearch = '',
        string $ownerSearch = '',
        string $recipientSearch = '',
        string $hasPassword = '',
        string $hasExpiration = '',
        int $createdSince = 0,
        string $sort = 'created',
        string $sortDir = 'desc',
        bool $includeTokens = false,
        string $exposure = '',
    ): DataDownloadResponse|JSONResponse {
        if (($scope = $this->requireViewer()) instanceof JSONResponse) {
            return $scope;
        }
        $includeTokens = $includeTokens && $scope->canSeeTokens();

        $filters = $this->buildFilters($types, $owner, $search, $hasPassword, $hasExpiration, $createdSince);
        $filters['pathSearch'] = $pathSearch !== '' ? $pathSearch : null;
        $filters['ownerSearch'] = $ownerSearch !== '' ? $ownerSearch : null;
        $filters['recipientSearch'] = $recipientSearch !== '' ? $recipientSearch : null;
        $filters['exposure'] = $exposure !== '' ? $exposure : null;
        if ($exposure !== '' && !$this->exposure->isCategory($exposure)) {
            return new JSONResponse(['message' => 'Unknown exposure category.'], Http::STATUS_BAD_REQUEST);
        }

        $rows = $this->collector->getAllForExport($filters, $includeTokens, $sort, $sortDir);
        $csv = $this->report->buildCsv($rows, $includeTokens);
        $filename = 'share-audit-' . date('Y-m-d') . '.csv';

        if (!$scope->canManage()) {
            // An admin's own export exposes nothing new; a viewer's does —
            // see ShareAuditLogger::logExport().
            $this->auditLogger->logExport(count($rows), $scope->role);
        }

        return new DataDownloadResponse($csv, $filename, 'text/csv; charset=UTF-8');
    }

    /**
     * Build the normalized filter array shared by index() and export().
     *
     * @return array<string, mixed>
     */
    private function buildFilters(
        string $types,
        string $owner,
        string $search,
        string $hasPassword,
        string $hasExpiration,
        int $createdSince,
    ): array {
        return [
            'types' => $this->parseTypes($types),
            'owner' => $owner !== '' ? $owner : null,
            'search' => $search !== '' ? $search : null,
            'hasPassword' => $this->parseTristate($hasPassword),
            'hasExpiration' => $this->parseTristate($hasExpiration),
            'createdSince' => $createdSince > 0 ? $createdSince : null,
        ];
    }

    /**
     * GET /api/alerts — security alerts, most severe first, paginated.
     *
     * The analyzer must evaluate every insecure link to rank by severity and to
     * compute the category breakdown, so both the breakdown and the $issue
     * filter below are applied over the full set while only one page of items
     * is returned to the browser.
     *
     * $issue (optional) restricts the returned/paginated items to alerts that
     * carry that issue code (e.g. 'no_password') — used by the "Alerts by
     * category" chart's click-to-filter. The breakdown itself always reflects
     * the FULL set, unfiltered, so the chart stays a stable overview the admin
     * can use to jump between categories.
     *
     * A $limit of 0 returns every matching alert on a single page,
     * so the "select all" bulk action can span the whole (filtered) set.
     *
     * $sort defaults to 'severity' (today's behaviour: critical > warning >
     * info, as ranked by SecurityAnalyzerService::getAlerts()). Passing
     * 'created' re-sorts by share creation date instead, direction per
     * $sortDir — lets an admin triage the oldest risky shares first.
     *
     * $includeAcknowledged (the alerts view's "show acknowledged" toggle)
     * mirrors getAlerts()'s own parameter: false (default) hides anything
     * an admin has already accepted (see AckService), so `items`,
     * `breakdown` and `totalAll` all reflect only what's still active —
     * true returns everything, each issue annotated with its acknowledgment
     * details, for reviewing or undoing exceptions.
     *
     * $search keeps only the alerts whose file/folder name, share label or owner
     * contain every word of it (see SecurityAnalyzerService::filterBySearch()).
     * The breakdown counts follow it, so the chart always adds up to the list;
     * `totalAll` — the tab badge — deliberately does not.
     *
     * $sort = 'name' orders by file/folder name, direction per $sortDir.
     *
     * @param int<0, 500> $limit page size, 0 = everything on one page. Declared
     *        so Nextcloud 34+ accepts 0: without an explicit range its dispatcher
     *        rejects any `limit` outside 1..500 with a 400 ("All" would fail).
     */
    #[NoAdminRequired]
    public function alerts(int $page = 1, int $limit = 25, string $issue = '', string $sort = 'severity', string $sortDir = 'desc', bool $includeAcknowledged = false, string $search = ''): JSONResponse {
        if (($scope = $this->requireViewer()) instanceof JSONResponse) {
            return $scope;
        }
        $all = $this->security->getAlerts(null, $includeAcknowledged);
        $searched = $this->security->filterBySearch($all, $search);
        $breakdown = $this->security->countByIssue($searched);
        $filtered = $issue !== ''
            ? array_values(array_filter($searched, static fn ($alert) => in_array($issue, array_column($alert['issues'], 'code'), true)))
            : $searched;
        if ($sort === 'created') {
            $direction = $sortDir === 'asc' ? 1 : -1;
            usort($filtered, static fn ($a, $b) => $direction * (($a['created'] ?? 0) <=> ($b['created'] ?? 0)));
        } elseif ($sort === 'name') {
            $filtered = $this->security->sortByName($filtered, $sortDir === 'asc');
        }
        $total = count($filtered);
        $offset = max(0, ($page - 1) * $limit);
        $items = $limit > 0 ? array_slice($filtered, $offset, $limit) : $filtered;
        if (!$scope->canSeeTokens()) {
            // Public-link tokens are bare credentials — never handed to an
            // auditor, who can only read, not act on the alert anyway.
            $items = array_map(static function (array $alert): array {
                $alert['token'] = null;
                return $alert;
            }, $items);
        }
        return new JSONResponse([
            'items' => $items,
            'total' => $total,
            // Unfiltered count, for the tab badge — must stay stable while
            // browsing a single category so it always reads as "all insecure
            // links", not "links in the current category".
            'totalAll' => count($all),
            'page' => $page,
            'limit' => $limit,
            'breakdown' => $breakdown,
            // What "Set expiry" should offer: the instance's own sharing policy.
            'expiryDefaults' => $this->expiryDefaults->forLinks(),
        ]);
    }

    /**
     * GET /api/settings — current configurable alert rules. Admin-only, like
     * every other Settings endpoint: this is where the auditor groups
     * themselves are picked, so an auditor must not reach it.
     */
    public function getSettings(): JSONResponse {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        return new JSONResponse($this->withAuditorGroupNames($this->settings->getSettings()));
    }

    /**
     * POST /api/settings — persist the configurable alert rules.
     *
     * $auditorGroups is nullable so an older cached frontend bundle that
     * never sends it leaves the current list untouched — see
     * SettingsService::saveSettings().
     *
     * @param string[]|null $auditorGroups
     */
    public function saveSettings(
        string $sensitiveExtensions = '',
        bool $ruleNoPassword = true,
        bool $ruleNoExpiration = true,
        bool $ruleSensitiveFile = true,
        bool $ruleGroupShareEditable = true,
        bool $rulePublicUpload = true,
        bool $personalViewEnabled = true,
        int $groupShareMinMembers = 20,
        int $retentionDays = 30,
        ?array $auditorGroups = null,
    ): JSONResponse {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        $this->settings->saveSettings($sensitiveExtensions, [
            'no_password' => $ruleNoPassword,
            'no_expiration' => $ruleNoExpiration,
            'sensitive_file' => $ruleSensitiveFile,
            'group_share_editable' => $ruleGroupShareEditable,
            'public_upload' => $rulePublicUpload,
        ], $personalViewEnabled, $groupShareMinMembers, $retentionDays, $auditorGroups);
        return new JSONResponse($this->withAuditorGroupNames($this->settings->getSettings()));
    }

    /**
     * GET /api/settings/groups — search instance groups, for the "Auditor
     * groups" picker in Settings. Admin-only.
     */
    public function groups(string $search = ''): JSONResponse {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        $items = array_map(
            static fn ($group) => ['id' => $group->getGID(), 'displayName' => $group->getDisplayName()],
            $this->groupManager->search(trim($search)),
        );
        return new JSONResponse(['items' => array_values($items)]);
    }

    /**
     * Replaces the bare group ids in $settings['auditorGroups'] with
     * {id, displayName} pairs, so the Settings tab can show the picker's
     * current selection without a second round trip. A group that no longer
     * exists (deleted after being picked) is skipped — SettingsService keeps
     * the id on the next save regardless, in case it is a transient LDAP hiccup.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function withAuditorGroupNames(array $settings): array {
        $settings['auditorGroups'] = array_values(array_filter(array_map(
            function (string $gid): ?array {
                $group = $this->groupManager->get($gid);
                return $group !== null ? ['id' => $gid, 'displayName' => $group->getDisplayName()] : null;
            },
            $settings['auditorGroups'] ?? [],
        )));
        return $settings;
    }

    /**
     * Parse a comma-separated list of raw share_type integers.
     *
     * @return int[]
     */
    private function parseTypes(string $types): array {
        if ($types === '') {
            return [];
        }
        return array_values(array_filter(
            array_map('intval', explode(',', $types)),
            static fn ($v) => $v >= 0,
        ));
    }

    /**
     * Parse a tri-state boolean query param: 'true'/'false' or '' (unset).
     */
    private function parseTristate(string $value): ?bool {
        return match (strtolower($value)) {
            'true', '1' => true,
            'false', '0' => false,
            default => null,
        };
    }
}
