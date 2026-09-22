<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Tests\Unit;

use OCA\ShareAuditDashboard\Service\AccessScope;
use OCA\ShareAuditDashboard\Service\AccessService;
use OCA\ShareAuditDashboard\Service\SettingsService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers AccessService::getScope()/isAdmin(): the precedence (admin wins
 * over auditor group membership), the "no session / no groups configured /
 * not a member" paths that all deny access, and that isAdmin() ignores
 * auditor-group membership entirely (it is the requireAdmin() guard — see
 * AdminController — which must stay a real admin check regardless of any
 * auditor group the admin might also be in).
 */
class AccessServiceTest extends TestCase {

    private IUserSession&MockObject $userSession;
    private IGroupManager&MockObject $groupManager;
    private SettingsService&MockObject $settings;
    private AccessService $service;

    protected function setUp(): void {
        $this->userSession = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->settings = $this->createMock(SettingsService::class);

        $this->service = new AccessService($this->userSession, $this->groupManager, $this->settings);
    }

    private function stubUser(?string $uid): void {
        if ($uid === null) {
            $this->userSession->method('getUser')->willReturn(null);
            return;
        }
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
    }

    public function testNoLoggedInUserGetsNoScope(): void {
        $this->stubUser(null);

        $this->assertNull($this->service->getScope());
        $this->assertFalse($this->service->isAdmin());
    }

    public function testAdminGetsAdminScopeRegardlessOfAuditorGroups(): void {
        $this->stubUser('alice');
        $this->groupManager->method('isAdmin')->with('alice')->willReturn(true);
        // Never consulted: isAdmin() short-circuits before the auditor check.
        $this->settings->expects($this->never())->method('getAuditorGroups');

        $scope = $this->service->getScope();
        $this->assertNotNull($scope);
        $this->assertSame(AccessScope::ROLE_ADMIN, $scope->role);
        $this->assertTrue($scope->isGlobal());
        $this->assertTrue($scope->canSeeTokens());
        $this->assertTrue($this->service->isAdmin());
    }

    public function testMemberOfAnAuditorGroupGetsAuditorScope(): void {
        $this->stubUser('bob');
        $this->groupManager->method('isAdmin')->with('bob')->willReturn(false);
        $this->settings->method('getAuditorGroups')->willReturn(['auditors', 'security-team']);
        $this->groupManager->method('isInGroup')->willReturnMap([
            ['bob', 'auditors', false],
            ['bob', 'security-team', true],
        ]);

        $scope = $this->service->getScope();
        $this->assertNotNull($scope);
        $this->assertSame(AccessScope::ROLE_AUDITOR, $scope->role);
        $this->assertTrue($scope->isGlobal());
        $this->assertFalse($scope->canSeeTokens());
        $this->assertFalse($this->service->isAdmin());
    }

    public function testRegularUserWithNoAuditorGroupGetsNoScope(): void {
        $this->stubUser('carol');
        $this->groupManager->method('isAdmin')->with('carol')->willReturn(false);
        $this->settings->method('getAuditorGroups')->willReturn(['auditors']);
        $this->groupManager->method('isInGroup')->with('carol', 'auditors')->willReturn(false);

        $this->assertNull($this->service->getScope());
    }

    public function testNoAuditorGroupsConfiguredDeniesEveryNonAdmin(): void {
        $this->stubUser('dave');
        $this->groupManager->method('isAdmin')->with('dave')->willReturn(false);
        $this->settings->method('getAuditorGroups')->willReturn([]);
        // Nothing to check membership against.
        $this->groupManager->expects($this->never())->method('isInGroup');

        $this->assertNull($this->service->getScope());
    }
}
