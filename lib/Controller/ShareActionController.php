<?php

declare(strict_types=1);

namespace OCA\ShareAuditDashboard\Controller;

use OCA\ShareAuditDashboard\Service\ShareRemediationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Admin-only actions that remediate insecure shares: set a password, set an
 * expiration date, revoke, and the same in bulk. Operates through IShareManager
 * so Nextcloud's own validation and hooks run.
 *
 * Revoke currently deletes the share; once soft-delete (roadmap F4) lands it
 * will route through the retention table instead.
 */
class ShareActionController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private ShareRemediationService $remediation,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * POST /api/shares/{id}/password — set a password (auto-generated if none given).
     */
    public function setPassword(int $id, string $password = ''): JSONResponse {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        try {
            return new JSONResponse($this->remediation->applyPassword($id, $password));
        } catch (\Throwable $e) {
            return $this->fail($id, $e);
        }
    }

    /**
     * POST /api/shares/{id}/expiration — set expiration N days from now.
     */
    public function setExpiration(int $id, int $days = 30): JSONResponse {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        try {
            return new JSONResponse($this->remediation->applyExpiration($id, $days));
        } catch (\Throwable $e) {
            return $this->fail($id, $e);
        }
    }

    /**
     * DELETE /api/shares/{id} — revoke (delete) a share.
     */
    public function revoke(int $id): JSONResponse {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        try {
            return new JSONResponse($this->remediation->revoke($id));
        } catch (\Throwable $e) {
            return $this->fail($id, $e);
        }
    }

    /**
     * POST /api/shares/bulk — apply one action to many shares.
     *
     * @param int[] $ids
     */
    public function bulk(string $action, array $ids = [], int $days = 30): JSONResponse {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }

        $results = [];
        foreach ($ids as $rawId) {
            $id = (int)$rawId;
            try {
                $results[] = match ($action) {
                    'password' => $this->remediation->applyPassword($id, ''),
                    'expiration' => $this->remediation->applyExpiration($id, $days),
                    'revoke' => $this->remediation->revoke($id),
                    default => throw new \InvalidArgumentException('Unknown action: ' . $action),
                };
            } catch (\Throwable $e) {
                $results[] = ['id' => $id, 'success' => false, 'error' => $e->getMessage()];
            }
        }

        $ok = count(array_filter($results, static fn ($r) => $r['success'] ?? false));
        return new JSONResponse([
            'action' => $action,
            'total' => count($results),
            'succeeded' => $ok,
            'failed' => count($results) - $ok,
            'results' => $results,
        ]);
    }

    private function fail(int $id, \Throwable $e): JSONResponse {
        $this->logger->warning('Share action failed', ['id' => $id, 'exception' => $e]);
        return new JSONResponse(
            ['id' => $id, 'success' => false, 'error' => $e->getMessage()],
            Http::STATUS_BAD_REQUEST,
        );
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
