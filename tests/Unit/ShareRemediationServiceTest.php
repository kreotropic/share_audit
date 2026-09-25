<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Tests\Unit;

use OCA\ShareAuditDashboard\Service\ExpiryDefaultsService;
use OCA\ShareAuditDashboard\Service\PasswordGeneratorService;
use OCA\ShareAuditDashboard\Service\SecurityAnalyzerService;
use OCA\ShareAuditDashboard\Service\ShareAuditLogger;
use OCA\ShareAuditDashboard\Service\ShareExpiredException;
use OCA\ShareAuditDashboard\Service\ShareRemediationService;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers ShareRemediationService::applyExpiration(): the lifetime is what the
 * caller asked for, else the instance's default, and never longer than the
 * instance enforces — see ExpiryDefaultsService.
 */
class ShareRemediationServiceTest extends TestCase {

    private IManager&MockObject $shareManager;
    private ExpiryDefaultsService&MockObject $expiryDefaults;
    private SecurityAnalyzerService&MockObject $analyzer;
    private ShareAuditLogger&MockObject $auditLogger;
    private ShareRemediationService $service;
    private ?\DateTime $appliedDate = null;

    protected function setUp(): void {
        $this->shareManager = $this->createMock(IManager::class);
        $this->expiryDefaults = $this->createMock(ExpiryDefaultsService::class);
        $this->analyzer = $this->createMock(SecurityAnalyzerService::class);
        $this->auditLogger = $this->createMock(ShareAuditLogger::class);
        $this->service = new ShareRemediationService(
            $this->shareManager,
            $this->createMock(PasswordGeneratorService::class),
            $this->auditLogger,
            $this->analyzer,
            $this->expiryDefaults,
        );
    }

    /**
     * A share the manager hands back, recording the date it is given.
     */
    private function stubShare(int $type = IShare::TYPE_LINK, bool $expired = false): IShare&MockObject {
        $share = $this->createMock(IShare::class);
        $share->method('getShareType')->willReturn($type);
        $share->method('isExpired')->willReturn($expired);
        $share->method('getShareOwner')->willReturn('alice');
        $share->method('getSharedBy')->willReturn('bob');
        $share->method('setExpirationDate')->willReturnCallback(function (?\DateTime $date) use ($share) {
            $this->appliedDate = $date;
            return $share;
        });
        // Never through Nextcloud's validity check: see loadShare().
        $this->shareManager->method('getShareById')->with('ocinternal:5', null, false)->willReturn($share);
        return $share;
    }

    private function inDays(int $days): string {
        return (new \DateTime('today'))->modify('+' . $days . ' days')->format('Y-m-d');
    }

    public function testTheRequestedNumberOfDaysIsUsedAsGiven(): void {
        $this->stubShare();
        $this->expiryDefaults->method('forShareType')->willReturn(['days' => 14, 'maxDays' => null]);

        $result = $this->service->applyExpiration(5, 90);

        $this->assertSame($this->inDays(90), $this->appliedDate?->format('Y-m-d'));
        $this->assertSame($this->inDays(90), $result['expiration']);
    }

    public function testNoDaysMeansTheInstanceDefault(): void {
        $this->stubShare();
        $this->expiryDefaults->method('forShareType')->willReturn(['days' => 14, 'maxDays' => null]);

        $result = $this->service->applyExpiration(5);

        $this->assertSame($this->inDays(14), $result['expiration']);
    }

    public function testDaysBelowOneAlsoMeanTheInstanceDefault(): void {
        $this->stubShare();
        $this->expiryDefaults->method('forShareType')->willReturn(['days' => 14, 'maxDays' => null]);

        $this->assertSame($this->inDays(14), $this->service->applyExpiration(5, -3)['expiration']);
    }

    public function testDaysBeyondAnEnforcedMaximumAreCappedInsteadOfFailing(): void {
        $this->stubShare();
        $this->expiryDefaults->method('forShareType')->willReturn(['days' => 7, 'maxDays' => 7]);
        // IShareManager would throw for anything past the maximum; the
        // update must be attempted with the capped date, not with 90 days.
        $this->shareManager->expects($this->once())->method('updateShare');

        $result = $this->service->applyExpiration(5, 90);

        $this->assertSame($this->inDays(7), $result['expiration']);
    }

    public function testDaysWithinAnEnforcedMaximumAreLeftAlone(): void {
        $this->stubShare();
        $this->expiryDefaults->method('forShareType')->willReturn(['days' => 30, 'maxDays' => 30]);

        $this->assertSame($this->inDays(7), $this->service->applyExpiration(5, 7)['expiration']);
    }

    public function testThePolicyComesFromTheTypeOfTheShareBeingChanged(): void {
        $this->stubShare(IShare::TYPE_USER);
        $this->expiryDefaults->expects($this->once())->method('forShareType')
            ->with(IShare::TYPE_USER)->willReturn(['days' => 5, 'maxDays' => null]);

        $this->service->applyExpiration(5);
    }

    public function testTheAlertsCacheIsInvalidatedForOwnerAndCreator(): void {
        $this->stubShare();
        $this->expiryDefaults->method('forShareType')->willReturn(['days' => 7, 'maxDays' => null]);
        $this->analyzer->expects($this->once())->method('invalidate')->with('alice', 'bob');

        $this->service->applyExpiration(5, 7);
    }

    // -------------------------------------------------------------------
    // An expired share. Asking Nextcloud for one through its validity check
    // deletes it and throws, so a plain "look at it" used to destroy the link
    // and report a failure. That is what made a bulk revoke of expired links
    // "fail" while removing them.
    // -------------------------------------------------------------------

    public function testAShareIsLoadedWithoutTheCheckThatDeletesExpiredOnes(): void {
        $this->stubShare();
        // stubShare() pins the arguments; this makes the intent explicit and fails
        // loudly if a second, checked, load ever creeps in.
        $this->shareManager->expects($this->once())->method('getShareById');

        $this->service->revoke(5);
    }

    public function testAnExpiredShareCanBeRevoked(): void {
        $share = $this->stubShare(IShare::TYPE_LINK, true);
        $this->shareManager->expects($this->once())->method('deleteShare')->with($share);
        $this->auditLogger->expects($this->once())->method('logRevoke');

        $result = $this->service->revoke(5);

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('alreadyGone', $result);
    }

    public function testRevokingAShareThatIsAlreadyGoneCountsAsDone(): void {
        $this->shareManager->method('getShareById')->willThrowException(new ShareNotFound());
        $this->shareManager->expects($this->never())->method('deleteShare');
        $this->auditLogger->expects($this->never())->method('logRevoke');

        $result = $this->service->revoke(5);

        $this->assertTrue($result['success'], 'the share is gone, which is what was asked for');
        $this->assertTrue($result['alreadyGone']);
    }

    public function testAnyOtherFailureWhileRevokingIsStillAFailure(): void {
        $this->shareManager->method('getShareById')->willThrowException(new \RuntimeException('database down'));

        $this->expectException(\RuntimeException::class);
        $this->service->revoke(5);
    }

    public function testAPasswordIsNotSetOnAnExpiredShareBecauseThatWouldDeleteIt(): void {
        $this->stubShare(IShare::TYPE_LINK, true);
        $this->shareManager->expects($this->never())->method('updateShare');

        $this->expectException(ShareExpiredException::class);
        $this->service->applyPassword(5, 'secret');
    }

    public function testAnExpirationIsNotSetOnAnExpiredShareBecauseThatWouldDeleteIt(): void {
        $this->stubShare(IShare::TYPE_LINK, true);
        $this->expiryDefaults->method('forShareType')->willReturn(['days' => 7, 'maxDays' => null]);
        $this->shareManager->expects($this->never())->method('updateShare');

        $this->expectException(ShareExpiredException::class);
        $this->service->applyExpiration(5, 7);
    }

    public function testAShareThatHasNotExpiredIsChangedAsBefore(): void {
        $this->stubShare(IShare::TYPE_LINK, false);
        $this->shareManager->expects($this->once())->method('updateShare');

        $result = $this->service->applyPassword(5, 'secret');

        $this->assertTrue($result['success']);
    }

    public function testTheReasonsAClientCanActOnAreNamedAndTheRestAreNot(): void {
        $this->assertSame('expired', ShareRemediationService::failureReason(new ShareExpiredException()));
        $this->assertSame('not_found', ShareRemediationService::failureReason(new ShareNotFound()));
        $this->assertNull(ShareRemediationService::failureReason(new \RuntimeException('boom')));
    }
}
