<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Controller;

use OCA\ShareAuditDashboard\Service\AccessService;
use OCA\ShareAuditDashboard\Service\ExposureMapService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * API for the exposure map. Read-only, open to admins and auditors — see
 * AdminController::requireViewer().
 */
class ExposureController extends AdminController {

    public function __construct(
        string $appName,
        IRequest $request,
        private ExposureMapService $exposure,
        AccessService $access,
    ) {
        parent::__construct($appName, $request, $access);
    }

    /**
     * GET /api/exposure — counts per category, score and top exposed users.
     */
    #[NoAdminRequired]
    public function overview(): JSONResponse {
        if (($scope = $this->requireViewer()) instanceof JSONResponse) {
            return $scope;
        }
        return new JSONResponse($this->exposure->getOverview());
    }
}
