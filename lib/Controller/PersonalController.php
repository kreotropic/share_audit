<?php

declare(strict_types=1);

namespace OCA\ShareAuditDashboard\Controller;

use OCA\ShareAuditDashboard\Db\ShareMapper;
use OCA\ShareAuditDashboard\Service\SecurityAnalyzerService;
use OCA\ShareAuditDashboard\Service\ShareCollectorService;
use OCA\ShareAuditDashboard\Service\ShareRemediationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Per-user API: any logged-in account can audit and fix THEIR OWN shares.
 * Everything is scoped to the current user's uid; remediation actions verify
 * that the target share belongs to the caller.
 */
class PersonalController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private ShareCollectorService $collector,
        private SecurityAnalyzerService $security,
        private ShareRemediationService $remediation,
        private ShareMapper $mapper,
        private IUserSession $userSession,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * GET /api/my/summary — counts of the user's own shares and insecure links.
     */
    #[NoAdminRequired]
    public function summary(): JSONResponse {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->unauthenticated();
        }
        return new JSONResponse([
            'total' => $this->mapper->countShares(['owner' => $uid]),
            'alertsCount' => $this->security->countAlerts($uid),
        ]);
    }

    /**
     * GET /api/my/shares — the user's own shares, paginated.
     */
    #[NoAdminRequired]
    public function shares(int $page = 1, int $limit = 50): JSONResponse {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->unauthenticated();
        }
        return new JSONResponse($this->collector->getShares(['owner' => $uid], $page, $limit));
    }

    /**
     * GET /api/my/alerts — the user's own insecure public links.
     */
    #[NoAdminRequired]
    public function alerts(): JSONResponse {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->unauthenticated();
        }
        return new JSONResponse(['items' => $this->security->getAlerts($uid)]);
    }

    /**
     * POST /api/my/shares/{id}/password
     */
    #[NoAdminRequired]
    public function setPassword(int $id, string $password = ''): JSONResponse {
        return $this->owned($id, fn () => $this->remediation->applyPassword($id, $password));
    }

    /**
     * POST /api/my/shares/{id}/expiration
     */
    #[NoAdminRequired]
    public function setExpiration(int $id, int $days = 30): JSONResponse {
        return $this->owned($id, fn () => $this->remediation->applyExpiration($id, $days));
    }

    /**
     * DELETE /api/my/shares/{id}
     */
    #[NoAdminRequired]
    public function revoke(int $id): JSONResponse {
        return $this->owned($id, fn () => $this->remediation->revoke($id));
    }

    /**
     * Run $action only if the current user owns share $id.
     */
    private function owned(int $id, callable $action): JSONResponse {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->unauthenticated();
        }
        try {
            if ($this->remediation->ownerOf($id) !== $uid) {
                return new JSONResponse(['message' => 'Not your share'], Http::STATUS_FORBIDDEN);
            }
            return new JSONResponse($action());
        } catch (\Throwable $e) {
            return new JSONResponse(
                ['id' => $id, 'success' => false, 'error' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST,
            );
        }
    }

    private function uid(): ?string {
        return $this->userSession->getUser()?->getUID();
    }

    private function unauthenticated(): JSONResponse {
        return new JSONResponse(['message' => 'Login required'], Http::STATUS_UNAUTHORIZED);
    }
}
