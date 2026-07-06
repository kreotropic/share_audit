<?php

declare(strict_types=1);

namespace OCA\ShareAuditDashboard\Controller;

use OCA\ShareAuditDashboard\Service\OrphanShareService;
use OCA\ShareAuditDashboard\Service\ReportService;
use OCA\ShareAuditDashboard\Service\SecurityAnalyzerService;
use OCA\ShareAuditDashboard\Service\SettingsService;
use OCA\ShareAuditDashboard\Service\ShareCollectorService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
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
class ShareApiController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private ShareCollectorService $collector,
        private SecurityAnalyzerService $security,
        private ReportService $report,
        private SettingsService $settings,
        private OrphanShareService $orphanService,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
    ) {
        parent::__construct($appName, $request);
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
     * GET /api/export — CSV of the filtered share list (same filters as index).
     */
    public function export(
        string $types = '',
        string $owner = '',
        string $search = '',
        string $hasPassword = '',
        string $hasExpiration = '',
        int $createdSince = 0,
    ): DataDownloadResponse|JSONResponse {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }

        $filters = $this->buildFilters($types, $owner, $search, $hasPassword, $hasExpiration, $createdSince);
        $rows = $this->collector->getAllForExport($filters);
        $csv = $this->report->buildCsv($rows);
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
     * GET /api/alerts — security alerts, most severe first.
     */
    public function alerts(): JSONResponse {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        $alerts = $this->security->getAlerts();
        return new JSONResponse([
            'items' => $alerts,
            'breakdown' => $this->security->countByIssue($alerts),
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
     * @return JSONResponse|null a 403 response when the caller is not an admin,
     *                           or null when access is granted.
     */
    private function requireAdmin(): ?JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null || !$this->groupManager->isAdmin($user->getUID())) {
            return new JSONResponse(
                ['message' => 'Administrator privileges required'],
                Http::STATUS_FORBIDDEN,
            );
        }
        return null;
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
