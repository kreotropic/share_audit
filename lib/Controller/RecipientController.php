<?php

declare(strict_types=1);

namespace OCA\ShareAuditDashboard\Controller;

use OCA\ShareAuditDashboard\Service\RecipientLookupService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Admin-only API for the reverse recipient drill-down.
 */
class RecipientController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private RecipientLookupService $lookup,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * GET /api/recipients/search — autocomplete recipients.
     */
    public function search(string $q = ''): JSONResponse {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        return new JSONResponse(['items' => $this->lookup->search($q)]);
    }

    /**
     * GET /api/recipients/shares — shares granting access to a recipient.
     */
    public function shares(string $shareWith = '', int $shareType = -1): JSONResponse {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        return new JSONResponse($this->lookup->getShares($shareWith, $shareType));
    }

    /**
     * POST /api/recipients/revoke-all — revoke every share to a recipient.
     */
    public function revokeAll(string $shareWith = '', int $shareType = -1): JSONResponse {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        return new JSONResponse(['deleted' => $this->lookup->revokeAll($shareWith, $shareType)]);
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
