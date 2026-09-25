<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Controller;

use OCA\ShareAuditDashboard\Db\ShareMapper;
use OCA\ShareAuditDashboard\Service\ExpiryDefaultsService;
use OCA\ShareAuditDashboard\Service\SecurityAnalyzerService;
use OCA\ShareAuditDashboard\Service\SettingsService;
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
        private ExpiryDefaultsService $expiryDefaults,
        private ShareMapper $mapper,
        private SettingsService $settings,
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
        if (($guard = $this->requireEnabled()) !== null) {
            return $guard;
        }
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
        if (($guard = $this->requireEnabled()) !== null) {
            return $guard;
        }
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
        if (($guard = $this->requireEnabled()) !== null) {
            return $guard;
        }
        $uid = $this->uid();
        if ($uid === null) {
            return $this->unauthenticated();
        }
        return new JSONResponse([
            'items' => $this->security->getAlerts($uid),
            'expiryDefaults' => $this->expiryDefaults->forLinks(),
        ]);
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
    public function setExpiration(int $id, int $days = 0): JSONResponse {
        return $this->owned($id, fn () => $this->remediation->applyExpiration($id, $days));
    }

    /**
     * DELETE /api/my/shares/{id}
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 20, period: 60)]
    public function revoke(int $id): JSONResponse {
        // A share that is already gone is what a revoke is after, so it is
        // reported as done, not as an error.
        return $this->owned(
            $id,
            fn () => $this->remediation->revoke($id),
            ['id' => $id, 'success' => true, 'action' => 'revoke', 'alreadyGone' => true],
        );
    }

    /**
     * Run $action only if the current user owns share $id.
     *
     * @param array<string, mixed>|null $ifGone what to answer when the share does
     *        not exist (any more); null answers 404, which is right for a change
     *        but not for a revoke
     */
    private function owned(int $id, callable $action, ?array $ifGone = null): JSONResponse {
        if (($guard = $this->requireEnabled()) !== null) {
            return $guard;
        }
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
            $reason = ShareRemediationService::failureReason($e);
            if ($reason === 'not_found' && $ifGone !== null) {
                return new JSONResponse($ifGone);
            }
            if ($reason !== null) {
                // An expected state (expired, gone), not something to log.
                return new JSONResponse(
                    ['id' => $id, 'success' => false, 'reason' => $reason, 'error' => self::GENERIC_ERROR],
                    $reason === 'expired' ? Http::STATUS_CONFLICT : Http::STATUS_NOT_FOUND,
                );
            }
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

    /**
     * The admin can turn the personal view off instance-wide (see
     * PersonalSettings) — that must also close the API, not just hide the
     * settings page and Personal app's own nav entry: without this guard,
     * a direct request to any /api/my/* endpoint still worked regardless of
     * the toggle.
     */
    private function requireEnabled(): ?JSONResponse {
        if (!$this->settings->isPersonalViewEnabled()) {
            return new JSONResponse(
                ['message' => 'The personal shares audit is disabled on this instance.'],
                Http::STATUS_FORBIDDEN,
            );
        }
        return null;
    }
}
