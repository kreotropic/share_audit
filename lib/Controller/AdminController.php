<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Controller;

use OCA\ShareAuditDashboard\Service\AccessScope;
use OCA\ShareAuditDashboard\Service\AccessService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Base for the admin-only and read-only-viewer controllers: centralizes the
 * requireAdmin() / requireViewer() guards so every endpoint enforces the
 * same check from a single place — see AccessService.
 */
abstract class AdminController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private AccessService $access,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Guards every endpoint that reads or writes: only a real administrator
     * may pass. Used by every action that changes something (all POST/DELETE
     * endpoints) and by the few GET endpoints an auditor must not reach
     * either (settings, transfer targets, ...).
     *
     * @return JSONResponse|null a 403 response when the caller is not an admin,
     *                           or null when access is granted.
     */
    protected function requireAdmin(): ?JSONResponse {
        if (!$this->access->isAdmin()) {
            return new JSONResponse(
                ['message' => 'Administrator privileges required'],
                Http::STATUS_FORBIDDEN,
            );
        }
        return null;
    }

    /**
     * Guards the read-only endpoints: an admin or an auditor (a member of a
     * group the admin picked in Settings) may pass. The method carrying this
     * check must also carry #[NoAdminRequired], since without it Nextcloud's
     * own SecurityMiddleware already blocks a non-admin before the
     * controller runs.
     *
     * @return AccessScope|JSONResponse the caller's scope, or a 403 response.
     */
    protected function requireViewer(): AccessScope|JSONResponse {
        $scope = $this->access->getScope();
        if ($scope === null) {
            return new JSONResponse(
                ['message' => 'Administrator or auditor privileges required'],
                Http::STATUS_FORBIDDEN,
            );
        }
        return $scope;
    }
}
