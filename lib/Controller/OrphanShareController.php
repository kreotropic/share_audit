<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Controller;

use OCA\ShareAuditDashboard\Service\AccessService;
use OCA\ShareAuditDashboard\Service\OrphanFileMoveService;
use OCA\ShareAuditDashboard\Service\OrphanShareService;
use OCA\ShareAuditDashboard\Service\OrphanTransferService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * API for orphan shares (owned by disabled/deleted accounts). Listing is
 * read-only and open to admins and auditors; revoke/transfer stay admin-only.
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
        private OrphanTransferService $transferService,
        private OrphanFileMoveService $fileMoveService,
        AccessService $access,
    ) {
        parent::__construct($appName, $request, $access);
    }

    /**
     * GET /api/orphans — paginated list of orphan shares.
     *
     * A $limit of 0 returns every orphan on a single page.
     *
     * @param int<0, 500> $limit page size, 0 = everything on one page. Declared
     *        so Nextcloud 34+ accepts 0: without an explicit range its dispatcher
     *        rejects any `limit` outside 1..500 with a 400 ("All" would fail).
     */
    #[NoAdminRequired]
    public function index(int $page = 1, int $limit = 25): JSONResponse {
        if (($scope = $this->requireViewer()) instanceof JSONResponse) {
            return $scope;
        }
        return new JSONResponse($this->orphanService->getOrphanShares($page, $limit, $scope->canSeeTokens()));
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

    /**
     * POST /api/orphans/transfer — hand selected orphan shares to another
     * account. Each share that cannot move comes back with the reason, see
     * OrphanTransferService::transfer().
     *
     * @param int[] $ids
     */
    public function transfer(array $ids = [], string $newOwner = ''): JSONResponse {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        if (count($ids) > self::MAX_IDS) {
            return new JSONResponse(
                ['message' => 'Too many ids in one request (max ' . self::MAX_IDS . ').'],
                Http::STATUS_BAD_REQUEST,
            );
        }
        try {
            return new JSONResponse($this->transferService->transfer($ids, trim($newOwner)));
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * POST /api/orphans/move-files — queue moving the files of the selected
     * orphan shares' disabled owners to another account, so the shares can be
     * handed over with them. Runs in the background; each share that a move
     * cannot serve comes back with the reason, see
     * OrphanFileMoveService::enqueue().
     *
     * @param int[] $ids
     * @param string $scope 'path' (the files behind the selected shares) or
     *                      'account' (everything the owner has)
     */
    public function moveFiles(array $ids = [], string $newOwner = '', string $scope = OrphanFileMoveService::SCOPE_PATH): JSONResponse {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        if (count($ids) > self::MAX_IDS) {
            return new JSONResponse(
                ['message' => 'Too many ids in one request (max ' . self::MAX_IDS . ').'],
                Http::STATUS_BAD_REQUEST,
            );
        }
        try {
            return new JSONResponse($this->fileMoveService->enqueue($ids, trim($newOwner), $scope));
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * GET /api/orphans/file-moves — the newest file moves and how each is
     * going. Read-only, so open to auditors too.
     */
    #[NoAdminRequired]
    public function fileMoves(): JSONResponse {
        if (($scope = $this->requireViewer()) instanceof JSONResponse) {
            return $scope;
        }
        return new JSONResponse($this->fileMoveService->overview());
    }

    /**
     * POST /api/orphans/file-moves/{id}/release — free a file move that is
     * still marked as running, for when its worker is gone and nothing else can
     * tell. Refused (409) while the worker is known to be alive, see
     * OrphanFileMoveService::release().
     */
    public function releaseFileMove(int $id): JSONResponse {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        $result = $this->fileMoveService->release($id);
        return match ($result) {
            OrphanFileMoveService::RELEASE_OK => new JSONResponse(['released' => true]),
            OrphanFileMoveService::RELEASE_NOT_FOUND => new JSONResponse(['reason' => $result], Http::STATUS_NOT_FOUND),
            default => new JSONResponse(['reason' => $result], Http::STATUS_CONFLICT),
        };
    }

    /**
     * GET /api/orphans/transfer-targets — enabled accounts that can take
     * shares over, for the new-owner picker.
     */
    public function transferTargets(string $search = ''): JSONResponse {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        return new JSONResponse(['items' => $this->transferService->searchTargets(trim($search))]);
    }
}
