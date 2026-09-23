<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Tests\Unit;

use OCA\ShareAuditDashboard\Controller\AdminController;
use OCA\ShareAuditDashboard\Service\AccessScope;
use OCA\ShareAuditDashboard\Service\AccessService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Every concrete controller (ShareApiController, OrphanShareController, ...)
 * relies on requireAdmin()/requireViewer() to turn "who is calling" into
 * either a 403 JSONResponse or permission to proceed. ControllerAccessTest
 * only proves each route's method calls one of these two methods (a
 * source-text check, easy to satisfy while the guard itself is broken); this
 * covers the guards' actual runtime behaviour instead — the real "does an
 * auditor actually get 403 on an admin-only route, and actually get let
 * through on a viewer route" question neither ControllerAccessTest nor
 * AccessServiceTest (role resolution only, not the HTTP-facing conversion)
 * answers on its own.
 */
class AdminControllerTest extends TestCase {

    private AccessService&MockObject $access;
    private TestableAdminController $controller;

    protected function setUp(): void {
        $this->access = $this->createMock(AccessService::class);
        $this->controller = new TestableAdminController(
            'share_audit_dashboard',
            $this->createMock(IRequest::class),
            $this->access,
        );
    }

    // -------------------------------------------------------------------
    // requireAdmin() — every mutating / admin-only endpoint.
    // -------------------------------------------------------------------

    public function testRequireAdminLetsARealAdminThrough(): void {
        $this->access->method('isAdmin')->willReturn(true);
        $this->assertNull($this->controller->callRequireAdmin());
    }

    public function testRequireAdminReturns403ForAnAuditor(): void {
        // An auditor is not an admin — isAdmin() must say so regardless of
        // group membership (see AccessServiceTest); this just confirms the
        // controller-facing guard actually converts that into a 403 and
        // not, say, silently letting the request through.
        $this->access->method('isAdmin')->willReturn(false);

        $result = $this->controller->callRequireAdmin();

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());
    }

    public function testRequireAdminReturns403ForAnAnonymousOrRegularAccount(): void {
        $this->access->method('isAdmin')->willReturn(false);

        $result = $this->controller->callRequireAdmin();

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());
    }

    // -------------------------------------------------------------------
    // requireViewer() — the read-only endpoints admins AND auditors reach.
    // -------------------------------------------------------------------

    public function testRequireViewerLetsAnAdminThroughWithAdminScope(): void {
        $this->access->method('getScope')->willReturn(AccessScope::admin());

        $result = $this->controller->callRequireViewer();

        $this->assertInstanceOf(AccessScope::class, $result);
        $this->assertSame(AccessScope::ROLE_ADMIN, $result->role);
    }

    public function testRequireViewerLetsAnAuditorThroughWithAuditorScope(): void {
        $this->access->method('getScope')->willReturn(AccessScope::auditor());

        $result = $this->controller->callRequireViewer();

        $this->assertInstanceOf(AccessScope::class, $result);
        $this->assertSame(AccessScope::ROLE_AUDITOR, $result->role);
        $this->assertFalse($result->canSeeTokens(), 'an auditor must never be handed bare tokens');
        $this->assertFalse($result->canManage(), 'an auditor must never be able to mutate a share');
    }

    public function testRequireViewerReturns403WhenTheCallerHasNoScopeAtAll(): void {
        // A regular logged-in account, an anonymous request (SecurityMiddleware
        // would normally already have blocked it, but #[NoAdminRequired] is
        // exactly what removes that net — see AdminController's docblock),
        // or an auditor whose group was removed after the page loaded.
        $this->access->method('getScope')->willReturn(null);

        $result = $this->controller->callRequireViewer();

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());
    }
}

/**
 * AdminController is abstract and its guards are protected — every real
 * subclass calls them from within its own action methods. This thin,
 * test-only subclass exposes them directly so their return value (not just
 * "some 403 gets returned somewhere downstream") can be asserted on.
 */
class TestableAdminController extends AdminController {
    public function callRequireAdmin(): ?JSONResponse {
        return $this->requireAdmin();
    }

    public function callRequireViewer(): AccessScope|JSONResponse {
        return $this->requireViewer();
    }
}
