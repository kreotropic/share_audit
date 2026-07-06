<?php

declare(strict_types=1);

namespace OCA\ShareAuditDashboard\Controller;

use OCA\ShareAuditDashboard\Service\PasswordGeneratorService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Share\IManager;
use OCP\Share\IShare;
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
        private IManager $shareManager,
        private PasswordGeneratorService $passwordGenerator,
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
            $result = $this->applyPassword($id, $password);
            return new JSONResponse($result);
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
            return new JSONResponse($this->applyExpiration($id, $days));
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
            return new JSONResponse($this->applyRevoke($id));
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
                    'password' => $this->applyPassword($id, ''),
                    'expiration' => $this->applyExpiration($id, $days),
                    'revoke' => $this->applyRevoke($id),
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

    /**
     * @return array<string, mixed>
     */
    private function applyPassword(int $id, string $password): array {
        $share = $this->loadShare($id);
        $plain = $password !== '' ? $password : $this->passwordGenerator->generate();
        $share->setPassword($plain);
        $this->shareManager->updateShare($share);
        return ['id' => $id, 'success' => true, 'action' => 'password', 'password' => $plain];
    }

    /**
     * @return array<string, mixed>
     */
    private function applyExpiration(int $id, int $days): array {
        $days = max(1, $days);
        $date = (new \DateTime('today'))->modify('+' . $days . ' days');
        $share = $this->loadShare($id);
        $share->setExpirationDate($date);
        $this->shareManager->updateShare($share);
        return ['id' => $id, 'success' => true, 'action' => 'expiration', 'expiration' => $date->format('Y-m-d')];
    }

    /**
     * @return array<string, mixed>
     */
    private function applyRevoke(int $id): array {
        $share = $this->loadShare($id);
        $this->shareManager->deleteShare($share);
        return ['id' => $id, 'success' => true, 'action' => 'revoke'];
    }

    /**
     * Load a share by its numeric oc_share id. Alerts only cover public links,
     * which are always served by the default ("ocinternal") provider.
     */
    private function loadShare(int $id): IShare {
        return $this->shareManager->getShareById('ocinternal:' . $id);
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
