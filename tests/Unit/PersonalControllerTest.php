<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Tests\Unit;

use OCA\ShareAuditDashboard\Controller\PersonalController;
use OCA\ShareAuditDashboard\Db\ShareMapper;
use OCA\ShareAuditDashboard\Service\ExpiryDefaultsService;
use OCA\ShareAuditDashboard\Service\SecurityAnalyzerService;
use OCA\ShareAuditDashboard\Service\SettingsService;
use OCA\ShareAuditDashboard\Service\ShareCollectorService;
use OCA\ShareAuditDashboard\Service\ShareRemediationService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * PersonalController isn't an AdminController subclass — "authorization"
 * here means (a) a real login is required at all, and (b) once logged in,
 * every action is scoped to that account's own uid. This covers the two
 * things ControllerAccessTest structurally cannot, since PersonalController
 * carries no VIEWER_ROUTES-style admin/auditor guard for it to check:
 *  - an anonymous caller getting 401 on every endpoint;
 *  - the personal-view-disabled 403 added to close the API when the
 *    "My shares audit" page itself is turned off (see PersonalSettings) —
 *    every endpoint here used to stay reachable regardless of the toggle.
 */
class PersonalControllerTest extends TestCase {

    private ShareCollectorService&MockObject $collector;
    private SecurityAnalyzerService&MockObject $security;
    private ShareRemediationService&MockObject $remediation;
    private ShareMapper&MockObject $mapper;
    private SettingsService&MockObject $settings;
    private IUserSession&MockObject $userSession;
    private PersonalController $controller;

    protected function setUp(): void {
        $this->collector = $this->createMock(ShareCollectorService::class);
        $this->security = $this->createMock(SecurityAnalyzerService::class);
        $this->remediation = $this->createMock(ShareRemediationService::class);
        $this->mapper = $this->createMock(ShareMapper::class);
        $this->settings = $this->createMock(SettingsService::class);
        $this->userSession = $this->createMock(IUserSession::class);

        $this->controller = new PersonalController(
            'share_audit_dashboard',
            $this->createMock(IRequest::class),
            $this->collector,
            $this->security,
            $this->remediation,
            $this->createMock(ExpiryDefaultsService::class),
            $this->mapper,
            $this->settings,
            $this->userSession,
            $this->createMock(LoggerInterface::class),
        );
    }

    private function stubLoggedIn(string $uid): void {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
    }

    private function stubAnonymous(): void {
        $this->userSession->method('getUser')->willReturn(null);
    }

    // -------------------------------------------------------------------
    // Anonymous caller — every action must 401, not just the ones that
    // happen to be exercised by other tests.
    // -------------------------------------------------------------------

    public function testSummaryRequires401WhenNotLoggedIn(): void {
        $this->stubAnonymous();
        $this->settings->method('isPersonalViewEnabled')->willReturn(true);
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->summary()->getStatus());
    }

    public function testSharesRequires401WhenNotLoggedIn(): void {
        $this->stubAnonymous();
        $this->settings->method('isPersonalViewEnabled')->willReturn(true);
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->shares()->getStatus());
    }

    public function testAlertsRequires401WhenNotLoggedIn(): void {
        $this->stubAnonymous();
        $this->settings->method('isPersonalViewEnabled')->willReturn(true);
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->alerts()->getStatus());
    }

    public function testSetPasswordRequires401WhenNotLoggedIn(): void {
        $this->stubAnonymous();
        $this->settings->method('isPersonalViewEnabled')->willReturn(true);
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->setPassword(1)->getStatus());
    }

    public function testRevokeRequires401WhenNotLoggedIn(): void {
        $this->stubAnonymous();
        $this->settings->method('isPersonalViewEnabled')->willReturn(true);
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->revoke(1)->getStatus());
    }

    // -------------------------------------------------------------------
    // Personal view disabled instance-wide — the API must close too, not
    // just the settings page / nav entry (see PersonalSettings::getSection()).
    // -------------------------------------------------------------------

    public function testSummaryReturns403WhenPersonalViewIsDisabled(): void {
        $this->stubLoggedIn('alice');
        $this->settings->method('isPersonalViewEnabled')->willReturn(false);
        $this->mapper->expects($this->never())->method('countShares');

        $this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->summary()->getStatus());
    }

    public function testSharesReturns403WhenPersonalViewIsDisabled(): void {
        $this->stubLoggedIn('alice');
        $this->settings->method('isPersonalViewEnabled')->willReturn(false);
        $this->collector->expects($this->never())->method('getShares');

        $this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->shares()->getStatus());
    }

    public function testAlertsReturns403WhenPersonalViewIsDisabled(): void {
        $this->stubLoggedIn('alice');
        $this->settings->method('isPersonalViewEnabled')->willReturn(false);
        $this->security->expects($this->never())->method('getAlerts');

        $this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->alerts()->getStatus());
    }

    public function testSetPasswordReturns403WhenPersonalViewIsDisabled(): void {
        $this->stubLoggedIn('alice');
        $this->settings->method('isPersonalViewEnabled')->willReturn(false);
        $this->remediation->expects($this->never())->method('isAccessibleBy');

        $this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->setPassword(1)->getStatus());
    }

    public function testRevokeReturns403WhenPersonalViewIsDisabled(): void {
        $this->stubLoggedIn('alice');
        $this->settings->method('isPersonalViewEnabled')->willReturn(false);
        $this->remediation->expects($this->never())->method('isAccessibleBy');

        $this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->revoke(1)->getStatus());
    }

    // -------------------------------------------------------------------
    // Logged in, feature enabled — must actually work (regression guard
    // against the 403/401 checks above swallowing the happy path too).
    // -------------------------------------------------------------------

    public function testSummarySucceedsWhenLoggedInAndEnabled(): void {
        $this->stubLoggedIn('alice');
        $this->settings->method('isPersonalViewEnabled')->willReturn(true);
        $this->mapper->method('countShares')->willReturn(3);
        $this->security->method('countAlerts')->willReturn(1);

        $response = $this->controller->summary();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(3, $response->getData()['total']);
    }

    public function testRevokeRejectsASharesNotOwnedByTheCaller(): void {
        $this->stubLoggedIn('alice');
        $this->settings->method('isPersonalViewEnabled')->willReturn(true);
        $this->remediation->method('isAccessibleBy')->with(1, 'alice')->willReturn(false);
        $this->remediation->expects($this->never())->method('revoke');

        $this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->revoke(1)->getStatus());
    }
}
