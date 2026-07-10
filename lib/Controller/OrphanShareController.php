<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Controller;

use OCA\ShareAuditDashboard\Service\OrphanShareService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Admin-only API for orphan shares (owned by disabled/deleted accounts).
 */
class OrphanShareController extends AdminController {

    /**
     * Max ids accepted by revoke() in one request. Since H1, each id is a
     * synchronous IShareManager call (provider lookup + delete), so this
     * mirrors ShareActionController::BULK_MAX_IDS for the same reason: an
     * unbounded "select all" could otherwise tie up a PHP worker for a long
     * time. The frontend splits larger selections into sequential requests.
     */
    private const MAX_IDS = 500;

    public function __construct(
        string $appName,
        IRequest $request,
        private OrphanShareService $orphanService,
        IUserSession $userSession,
        IGroupManager $groupManager,
    ) {
        parent::__construct($appName, $request, $userSession, $groupManager);
    }

    /**
     * GET /api/orphans — paginated list of orphan shares.
     *
     * A $limit of 0 (or less) returns every orphan on a single page.
     */
    public function index(int $page = 1, int $limit = 25): JSONResponse {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        return new JSONResponse($this->orphanService->getOrphanShares($page, $limit));
    }

    /**
     * POST /api/orphans/revoke — revoke selected orphan shares.
     *
     * @param int[] $ids
     */
    public function revoke(array $ids = []): JSONResponse {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        if (count($ids) > self::MAX_IDS) {
            return new JSONResponse(
                ['message' => 'Too many ids in one request (max ' . self::MAX_IDS . ').'],
                Http::STATUS_BAD_REQUEST,
            );
        }
        $result = $this->orphanService->revoke($ids);
        return new JSONResponse($result);
    }
}
