<?php

declare(strict_types=1);

namespace OCA\ShareAuditDashboard\Controller;

use OCA\ShareAuditDashboard\Service\ExposureMapService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Admin-only API for the exposure map.
 */
class ExposureController extends AdminController {

    public function __construct(
        string $appName,
        IRequest $request,
        private ExposureMapService $exposure,
        IUserSession $userSession,
        IGroupManager $groupManager,
    ) {
        parent::__construct($appName, $request, $userSession, $groupManager);
    }

    /**
     * GET /api/exposure — counts per category, score and top exposed users.
     */
    public function overview(): JSONResponse {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        return new JSONResponse($this->exposure->getOverview());
    }
}
