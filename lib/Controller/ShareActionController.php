<?php

declare(strict_types=1);

namespace OCA\ShareAuditDashboard\Controller;

use OCA\ShareAuditDashboard\Service\ShareRemediationService;
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
class ShareActionController extends AdminController {

    /**
     * Client-facing message for any failed action. The real exception is
     * always logged server-side (see fail() / bulk()) — never echoed back,
     * since it can carry internal details (paths, driver errors, ...).
     */
    private const GENERIC_ERROR = 'The action could not be completed.';

    /** Max ids accepted by bulk() in one request; see bulk() docblock. */
    private const BULK_MAX_IDS = 500;

    public function __construct(
        string $appName,
        IRequest $request,
        private ShareRemediationService $remediation,
        IUserSession $userSession,
        IGroupManager $groupManager,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request, $userSession, $groupManager);
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
     * Capped at BULK_MAX_IDS per request: each id is a synchronous
     * IShareManager call inside this one HTTP request, so an unbounded
     * "select all" on a large instance could otherwise tie up a PHP worker
     * for minutes. The frontend splits larger selections into sequential
     * requests instead.
     *
     * @param int[] $ids
     */
    public function bulk(string $action, array $ids = [], int $days = 30): JSONResponse {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        if (count($ids) > self::BULK_MAX_IDS) {
            return new JSONResponse(
                ['message' => 'Too many ids in one request (max ' . self::BULK_MAX_IDS . ').'],
                Http::STATUS_BAD_REQUEST,
            );
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
                $this->logger->warning('Bulk share action failed', ['id' => $id, 'action' => $action, 'exception' => $e]);
                $results[] = ['id' => $id, 'success' => false, 'error' => self::GENERIC_ERROR];
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
            ['id' => $id, 'success' => false, 'error' => self::GENERIC_ERROR],
            Http::STATUS_BAD_REQUEST,
        );
    }
}
