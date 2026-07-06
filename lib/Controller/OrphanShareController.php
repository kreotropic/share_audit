<?php

declare(strict_types=1);

namespace OCA\ShareAuditDashboard\Controller;

use OCA\ShareAuditDashboard\Service\OrphanShareService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Admin-only API for orphan shares (owned by disabled/deleted accounts).
 */
class OrphanShareController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private OrphanShareService $orphanService,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * GET /api/orphans — paginated list of orphan shares.
     */
    public function index(int $page = 1, int $limit = 50): JSONResponse {
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
        $deleted = $this->orphanService->revoke($ids);
        return new JSONResponse(['deleted' => $deleted]);
    }

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
}
