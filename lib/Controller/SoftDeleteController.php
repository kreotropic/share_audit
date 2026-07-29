<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Controller;

use OCA\ShareAuditDashboard\Service\SoftDeleteService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Admin-only API for the recycle bin of revoked shares — see
 * SoftDeleteService / ROADMAP.md #1.
 */
class SoftDeleteController extends AdminController {

    /** Mirrors OrphanShareController::MAX_IDS — same reasoning (synchronous per-id work). */
    private const MAX_IDS = 500;

    public function __construct(
        string $appName,
        IRequest $request,
        private SoftDeleteService $softDelete,
        IUserSession $userSession,
        IGroupManager $groupManager,
    ) {
        parent::__construct($appName, $request, $userSession, $groupManager);
    }

    /**
     * GET /api/deleted — paginated list of recycled shares.
     *
     * A $limit of 0 (or less) returns every entry on a single page.
     */
    public function index(int $page = 1, int $limit = 25): JSONResponse {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        return new JSONResponse($this->softDelete->list($page, $limit));
    }

    /**
     * POST /api/deleted/{id}/restore — recreate a recycled share.
     */
    public function restore(int $id): JSONResponse {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        $result = $this->softDelete->restore($id);
        if (!$result['success']) {
            return new JSONResponse($result, Http::STATUS_UNPROCESSABLE_ENTITY);
        }
        return new JSONResponse($result);
    }

    /**
     * DELETE /api/deleted/{id} — permanently remove one recycled entry.
     */
    public function purge(int $id): JSONResponse {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        $removed = $this->softDelete->purge($id);
        return new JSONResponse(['success' => $removed]);
    }

    /**
     * POST /api/deleted/purge — permanently remove several recycled entries at once.
     *
     * @param int[] $ids
     */
    public function purgeMany(array $ids = []): JSONResponse {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        if (count($ids) > self::MAX_IDS) {
            return new JSONResponse(
                ['message' => 'Too many ids in one request (max ' . self::MAX_IDS . ').'],
                Http::STATUS_BAD_REQUEST,
            );
        }
        return new JSONResponse(['purged' => $this->softDelete->purgeMany($ids)]);
    }
}
