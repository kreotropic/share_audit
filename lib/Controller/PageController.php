<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Controller;

use OCA\ShareAuditDashboard\AppInfo\Application;
use OCA\ShareAuditDashboard\Service\AccessScope;
use OCA\ShareAuditDashboard\Service\AccessService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * Renders the standalone page for a read-only viewer (auditor, and later a
 * manager — see AccessService): the admin's own dashboard lives in
 * Settings → Administration, which they cannot reach.
 *
 * An admin who follows this link is sent to that Settings page instead —
 * this page never offers less than what they already have. Anyone without
 * a scope at all (a regular account, or an auditor whose group was since
 * removed) gets a plain 403.
 */
class PageController extends Controller {

    public function __construct(
        IRequest $request,
        private AccessService $access,
        private IURLGenerator $urlGenerator,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse|RedirectResponse {
        $scope = $this->access->getScope();

        if ($scope !== null && $scope->role === AccessScope::ROLE_ADMIN) {
            return new RedirectResponse(
                $this->urlGenerator->linkToRoute('settings.AdminSettings.index', ['section' => Application::APP_ID]),
            );
        }

        if ($scope === null) {
            $response = new TemplateResponse(Application::APP_ID, 'forbidden', [], TemplateResponse::RENDER_AS_USER);
            $response->setStatus(Http::STATUS_FORBIDDEN);
            return $response;
        }

        return new TemplateResponse(Application::APP_ID, 'viewer', ['role' => $scope->role]);
    }
}
