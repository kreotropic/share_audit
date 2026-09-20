<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Tests\Unit;

use OCA\ShareAuditDashboard\Service\ExpiryDefaultsService;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers how the instance's three sharing-expiration policies (public links,
 * internal shares, remote/server shares — Administration settings → Sharing)
 * turn into the default lifetime the "Set expiry" actions offer, and the
 * longest lifetime the instance will accept when it enforces expiration.
 */
class ExpiryDefaultsServiceTest extends TestCase {

    private IManager&MockObject $manager;
    private ExpiryDefaultsService $service;

    protected function setUp(): void {
        $this->manager = $this->createMock(IManager::class);
        $this->service = new ExpiryDefaultsService($this->manager);
    }

    private function linkPolicy(bool $enabled, bool $enforced, int $days): void {
        $this->manager->method('shareApiLinkDefaultExpireDate')->willReturn($enabled);
        $this->manager->method('shareApiLinkDefaultExpireDateEnforced')->willReturn($enforced);
        $this->manager->method('shareApiLinkDefaultExpireDays')->willReturn($days);
    }

    public function testADefaultExpirationSwitchedOnUsesTheConfiguredDays(): void {
        $this->linkPolicy(true, false, 14);
        $this->assertSame(['days' => 14, 'maxDays' => null], $this->service->forLinks());
    }

    public function testWithoutADefaultExpirationItFallsBackToThirtyDays(): void {
        // The configured number is still stored when the default is off (7 is
        // Nextcloud's own preset) — it must not leak through as if it applied.
        $this->linkPolicy(false, false, 7);
        $this->assertSame(
            ['days' => ExpiryDefaultsService::FALLBACK_DAYS, 'maxDays' => null],
            $this->service->forLinks(),
        );
    }

    public function testEnforcedExpirationMakesTheConfiguredDaysTheMaximum(): void {
        $this->linkPolicy(true, true, 7);
        $this->assertSame(['days' => 7, 'maxDays' => 7], $this->service->forLinks());
    }

    public function testTheFallbackIsNeverLongerThanAnEnforcedMaximum(): void {
        // Not reachable through the admin UI, but the arithmetic must hold:
        // 30 days offered under a 7-day maximum would be rejected outright.
        $this->linkPolicy(false, true, 7);
        $this->assertSame(['days' => 7, 'maxDays' => 7], $this->service->forLinks());
    }

    public function testAnAbsurdConfiguredValueIsClampedToOneDay(): void {
        $this->linkPolicy(true, true, 0);
        $this->assertSame(['days' => 1, 'maxDays' => 1], $this->service->forLinks());
    }

    public function testUserAndGroupSharesFollowTheInternalPolicy(): void {
        $this->manager->method('shareApiInternalDefaultExpireDate')->willReturn(true);
        $this->manager->method('shareApiInternalDefaultExpireDateEnforced')->willReturn(true);
        $this->manager->method('shareApiInternalDefaultExpireDays')->willReturn(90);
        // A different link policy: reading it here would be the bug.
        $this->linkPolicy(true, false, 7);

        foreach ([IShare::TYPE_USER, IShare::TYPE_GROUP] as $type) {
            $this->assertSame(['days' => 90, 'maxDays' => 90], $this->service->forShareType($type));
        }
    }

    public function testFederatedSharesFollowTheRemotePolicy(): void {
        $this->manager->method('shareApiRemoteDefaultExpireDate')->willReturn(true);
        $this->manager->method('shareApiRemoteDefaultExpireDateEnforced')->willReturn(false);
        $this->manager->method('shareApiRemoteDefaultExpireDays')->willReturn(21);
        $this->linkPolicy(true, false, 7);

        foreach ([IShare::TYPE_REMOTE, IShare::TYPE_REMOTE_GROUP] as $type) {
            $this->assertSame(['days' => 21, 'maxDays' => null], $this->service->forShareType($type));
        }
    }

    public function testEmailSharesFollowTheLinkPolicy(): void {
        $this->linkPolicy(true, true, 10);
        $this->assertSame(['days' => 10, 'maxDays' => 10], $this->service->forShareType(IShare::TYPE_EMAIL));
    }
}
