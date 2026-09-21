<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Controller;

use OCA\ShareAuditDashboard\Service\RecipientLookupService;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Admin-only API for the reverse recipient drill-down.
 */
class RecipientController extends AdminController {

    public function __construct(
        string $appName,
        IRequest $request,
        private RecipientLookupService $lookup,
        IUserSession $userSession,
        IGroupManager $groupManager,
    ) {
        parent::__construct($appName, $request, $userSession, $groupManager);
    }

    /**
     * GET /api/recipients/search — autocomplete recipients.
     */
    #[UserRateLimit(limit: 60, period: 60)]
    public function search(string $q = ''): JSONResponse {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        return new JSONResponse(['items' => $this->lookup->search($q)]);
    }

    /**
     * GET /api/recipients/shares — shares granting access to a recipient.
     *
     * A $limit of 0 returns every matching share on a single page.
     *
     * @param int<0, 500> $limit page size, 0 = everything on one page. Declared
     *        so Nextcloud 34+ accepts 0: without an explicit range its dispatcher
     *        rejects any `limit` outside 1..500 with a 400 ("All" would fail).
     */
    #[UserRateLimit(limit: 60, period: 60)]
    public function shares(string $shareWith = '', int $shareType = -1, int $page = 1, int $limit = 25): JSONResponse {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        return new JSONResponse($this->lookup->getShares($shareWith, $shareType, $page, $limit));
    }

    /**
     * POST /api/recipients/revoke-all — revoke every share to a recipient.
     *
     * Lower limit than the read endpoints: each call is a batch of up to 500
     * synchronous deletes (see RecipientLookupService::revokeAll()), so it's
     * both heavier per-request and the one endpoint here that mutates.
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function revokeAll(string $shareWith = '', int $shareType = -1): JSONResponse {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        return new JSONResponse($this->lookup->revokeAll($shareWith, $shareType));
    }
}
