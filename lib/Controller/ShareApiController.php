<?php

declare(strict_types=1);

namespace OCA\ShareAuditDashboard\Controller;

use OCA\ShareAuditDashboard\Service\OrphanShareService;
use OCA\ShareAuditDashboard\Service\ReportService;
use OCA\ShareAuditDashboard\Service\SecurityAnalyzerService;
use OCA\ShareAuditDashboard\Service\SettingsService;
use OCA\ShareAuditDashboard\Service\ShareCollectorService;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Admin-only JSON API backing the Share Audit Dashboard frontend.
 *
 * Every action is guarded by requireAdmin(): these endpoints expose share
 * metadata across all users and must never be reachable by a regular account.
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
        IUserSession $userSession,
        IGroupManager $groupManager,
    ) {
        parent::__construct($appName, $request, $userSession, $groupManager);
    }

    /**
     * GET /api/stats — dashboard counters, trends and top owners.
     */
    public function stats(): JSONResponse {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        $stats = $this->collector->getStats();
        $stats['orphanCount'] = $this->orphanService->countOrphanShares();
        return new JSONResponse($stats);
    }

    /**
     * GET /api/shares — paginated, filterable list of all shares.
     */
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
    ): JSONResponse {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }

        $filters = $this->buildFilters($types, $owner, $search, $hasPassword, $hasExpiration, $createdSince);
        $filters['pathSearch'] = $pathSearch !== '' ? $pathSearch : null;
        $filters['ownerSearch'] = $ownerSearch !== '' ? $ownerSearch : null;
        $filters['recipientSearch'] = $recipientSearch !== '' ? $recipientSearch : null;

        return new JSONResponse($this->collector->getShares($filters, $page, $limit, $sort, $sortDir));
    }

    /**
     * GET /api/export — CSV of the filtered share list (same filters, column
     * search and sort as index(), so the export always matches what the
     * admin is looking at on screen).
     *
     * Tokens (bare credentials for public links) are omitted unless
     * $includeTokens is explicitly set — the frontend must warn the admin
     * before turning this on.
     */
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
    ): DataDownloadResponse|JSONResponse {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }

        $filters = $this->buildFilters($types, $owner, $search, $hasPassword, $hasExpiration, $createdSince);
        $filters['pathSearch'] = $pathSearch !== '' ? $pathSearch : null;
        $filters['ownerSearch'] = $ownerSearch !== '' ? $ownerSearch : null;
        $filters['recipientSearch'] = $recipientSearch !== '' ? $recipientSearch : null;

        $rows = $this->collector->getAllForExport($filters, $includeTokens, $sort, $sortDir);
        $csv = $this->report->buildCsv($rows, $includeTokens);
        $filename = 'share-audit-' . date('Y-m-d') . '.csv';

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
     * compute the category breakdown, so both the total and the breakdown cover
     * the full set while only one page of items is returned to the browser.
     *
     * A $limit of 0 (or less) returns every alert on a single page, so the
     * "select all" bulk action can span the whole set.
     */
    public function alerts(int $page = 1, int $limit = 25): JSONResponse {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        $all = $this->security->getAlerts();
        $total = count($all);
        $offset = max(0, ($page - 1) * $limit);
        return new JSONResponse([
            'items' => $limit > 0 ? array_slice($all, $offset, $limit) : $all,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'breakdown' => $this->security->countByIssue($all),
        ]);
    }

    /**
     * GET /api/settings — current configurable alert rules.
     */
    public function getSettings(): JSONResponse {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        return new JSONResponse($this->settings->getSettings());
    }

    /**
     * POST /api/settings — persist the configurable alert rules.
     */
    public function saveSettings(
        string $sensitiveExtensions = '',
        bool $ruleNoPassword = true,
        bool $ruleNoExpiration = true,
        bool $ruleSensitiveFile = true,
    ): JSONResponse {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        $this->settings->saveSettings($sensitiveExtensions, [
            'no_password' => $ruleNoPassword,
            'no_expiration' => $ruleNoExpiration,
            'sensitive_file' => $ruleSensitiveFile,
        ]);
        return new JSONResponse($this->settings->getSettings());
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
