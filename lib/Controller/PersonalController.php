<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Controller;

use OCA\ShareAuditDashboard\Db\ShareMapper;
use OCA\ShareAuditDashboard\Service\SecurityAnalyzerService;
use OCA\ShareAuditDashboard\Service\ShareCollectorService;
use OCA\ShareAuditDashboard\Service\ShareRemediationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Per-user API: any logged-in account can audit and fix THEIR OWN shares.
 * Everything is scoped to the current user's uid; remediation actions verify
 * that the target share belongs to the caller.
 */
class PersonalController extends Controller {

    /** @see ShareActionController::GENERIC_ERROR */
    private const GENERIC_ERROR = 'The action could not be completed.';

    public function __construct(
        string $appName,
        IRequest $request,
        private ShareCollectorService $collector,
        private SecurityAnalyzerService $security,
        private ShareRemediationService $remediation,
        private ShareMapper $mapper,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * GET /api/my/summary — counts of the user's own shares and insecure links.
     *
     * "Own" means owner OR initiator: a user who shares a link on a folder
     * someone else owns is still the one who created that exposure and must
     * be able to see and fix it.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 30, period: 60)]
    public function summary(): JSONResponse {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->unauthenticated();
        }
        return new JSONResponse([
            'total' => $this->mapper->countShares(['ownerOrInitiator' => $uid]),
            'alertsCount' => $this->security->countAlerts($uid),
        ]);
    }

    /**
     * GET /api/my/shares — the user's own shares (owner or initiator), paginated.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 30, period: 60)]
    public function shares(int $page = 1, int $limit = 50): JSONResponse {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->unauthenticated();
        }
        return new JSONResponse($this->collector->getShares(['ownerOrInitiator' => $uid], $page, $limit));
    }

    /**
     * GET /api/my/alerts — the user's own insecure public links.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 30, period: 60)]
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
    #[UserRateLimit(limit: 20, period: 60)]
    public function setPassword(int $id, string $password = ''): JSONResponse {
        return $this->owned($id, fn () => $this->remediation->applyPassword($id, $password));
    }

    /**
     * POST /api/my/shares/{id}/expiration
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 20, period: 60)]
    public function setExpiration(int $id, int $days = 30): JSONResponse {
        return $this->owned($id, fn () => $this->remediation->applyExpiration($id, $days));
    }

    /**
     * DELETE /api/my/shares/{id}
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 20, period: 60)]
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
            if (!$this->remediation->isAccessibleBy($id, $uid)) {
                return new JSONResponse(['message' => 'Not your share'], Http::STATUS_FORBIDDEN);
            }
            return new JSONResponse($action());
        } catch (\Throwable $e) {
            $this->logger->warning('Personal share action failed', ['id' => $id, 'exception' => $e]);
            return new JSONResponse(
                ['id' => $id, 'success' => false, 'error' => self::GENERIC_ERROR],
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
