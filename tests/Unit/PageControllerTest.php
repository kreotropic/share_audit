<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Tests\Unit;

use OCA\ShareAuditDashboard\Controller\PageController;
use OCA\ShareAuditDashboard\Service\AccessScope;
use OCA\ShareAuditDashboard\Service\AccessService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * PageController doesn't extend AdminController (it renders a page, not a
 * JSON action) and so falls outside both ControllerAccessTest and
 * AdminControllerTest — its own three-way branch on AccessService::getScope()
 * needs its own coverage: an admin is redirected to their own dashboard
 * (never given "less" than they already have), a scoped viewer gets the
 * page, and anyone else gets a plain 403.
 */
class PageControllerTest extends TestCase {

    private AccessService&MockObject $access;
    private IURLGenerator&MockObject $urlGenerator;
    private PageController $controller;

    protected function setUp(): void {
        $this->access = $this->createMock(AccessService::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->controller = new PageController(
            $this->createMock(IRequest::class),
            $this->access,
            $this->urlGenerator,
        );
    }

    public function testAdminIsRedirectedToTheAdminSettingsPage(): void {
        $this->access->method('getScope')->willReturn(AccessScope::admin());
        $this->urlGenerator->expects($this->once())->method('linkToRoute')
            ->with('settings.AdminSettings.index', $this->anything())
            ->willReturn('https://cloud.example/settings/admin/share_audit_dashboard');

        $this->assertInstanceOf(RedirectResponse::class, $this->controller->index());
    }

    public function testAnAccountWithNoScopeGetsA403Page(): void {
        $this->access->method('getScope')->willReturn(null);

        $response = $this->controller->index();

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testAnAuditorGetsTheViewerPage(): void {
        $this->access->method('getScope')->willReturn(AccessScope::auditor());

        $response = $this->controller->index();

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertNotSame(Http::STATUS_FORBIDDEN, $response->getStatus());
        $this->assertSame(AccessScope::ROLE_AUDITOR, $response->getParams()['role']);
    }
}
