<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Tests\Unit;

use OCA\ShareAuditDashboard\Db\ShareMapper;
use OCA\ShareAuditDashboard\Service\PathFormatter;
use OCA\ShareAuditDashboard\Service\SecurityAnalyzerService;
use OCA\ShareAuditDashboard\Service\SettingsService;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers SecurityAnalyzerService::issuesFor() (private, exercised through
 * getAlerts()): the four-way branch on expiration state (none / future /
 * expiring soon / already expired) crossed with the three configurable rule
 * toggles — see QUALITY_REVIEW_PLAN.md M-Q1, which flagged this as the
 * highest-value place for coverage given how easy it'd be to break silently.
 */
class SecurityAnalyzerServiceTest extends TestCase {

    private ShareMapper&MockObject $mapper;
    private SettingsService&MockObject $settings;
    private ArrayCache $cache;

    protected function setUp(): void {
        $this->mapper = $this->createMock(ShareMapper::class);
        $this->settings = $this->createMock(SettingsService::class);
        $this->settings->method('getSensitiveExtensions')->willReturn(['xlsx', 'pdf']);
        // isRuleEnabled() is deliberately NOT stubbed here: PHPUnit only honors
        // the first configured stub for a given method when there's no
        // with()-constraint to disambiguate, so every test calls stubRules()
        // itself exactly once instead of layering a second config on top of
        // a setUp() default.

        $this->cache = new ArrayCache();
    }

    /**
     * Configure isRuleEnabled() for all three rules in one go (default: all
     * on). Call at most once per test — see the note in setUp().
     *
     * @param array<string, bool> $overrides rule code => enabled
     */
    private function stubRules(array $overrides = []): void {
        $rules = array_merge(
            ['no_password' => true, 'no_expiration' => true, 'sensitive_file' => true],
            $overrides,
        );
        $this->settings->method('isRuleEnabled')->willReturnMap([
            ['no_password', $rules['no_password']],
            ['no_expiration', $rules['no_expiration']],
            ['sensitive_file', $rules['sensitive_file']],
        ]);
    }

    private function analyzer(): SecurityAnalyzerService {
        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($this->cache);
        return new SecurityAnalyzerService($this->mapper, $this->settings, new PathFormatter(), $cacheFactory);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array {
        return array_merge([
            'id' => 1,
            'uid_owner' => 'alice',
            'uid_initiator' => 'alice',
            'password' => null,
            'expiration' => null,
            'file_path' => 'files/report.txt',
            'file_source' => 42,
            'stime' => 1700000000,
            'token' => 'tok123',
        ], $overrides);
    }

    private function issueCodes(array $alert): array {
        return array_column($alert['issues'], 'code');
    }

    // -------------------------------------------------------------------
    // no_password / no_expiration — base cases
    // -------------------------------------------------------------------

    public function testNoPasswordNoExpirationBothFlagged(): void {
        $this->stubRules();
        $this->mapper->method('findInsecureLinks')->willReturn([$this->row()]);
        $alerts = $this->analyzer()->getAlerts();

        $this->assertCount(1, $alerts);
        $this->assertSame(['no_password', 'no_expiration'], $this->issueCodes($alerts[0]));
        // no_password is critical, the row's max severity must reflect that.
        $this->assertSame('critical', $alerts[0]['severity']);
    }

    public function testEmptyStringPasswordCountsAsNoPassword(): void {
        $this->stubRules();
        $this->mapper->method('findInsecureLinks')->willReturn([$this->row(['password' => ''])]);
        $alerts = $this->analyzer()->getAlerts();
        $this->assertContains('no_password', $this->issueCodes($alerts[0]));
    }

    public function testPasswordSetSuppressesNoPasswordIssue(): void {
        $this->stubRules();
        $this->mapper->method('findInsecureLinks')->willReturn([$this->row(['password' => 'hash...'])]);
        $alerts = $this->analyzer()->getAlerts();
        $this->assertNotContains('no_password', $this->issueCodes($alerts[0]));
    }

    public function testNoPasswordRuleDisabledSuppressesIssueEvenWithoutPassword(): void {
        $this->stubRules(['no_password' => false]);
        $this->mapper->method('findInsecureLinks')->willReturn([$this->row()]);
        $alerts = $this->analyzer()->getAlerts();
        $this->assertNotContains('no_password', $this->issueCodes($alerts[0]));
    }

    public function testNoExpirationRuleDisabledSuppressesIssueEvenWithoutExpiration(): void {
        $this->stubRules(['no_expiration' => false]);
        // Password set so the only remaining candidate signal is expiration.
        $this->mapper->method('findInsecureLinks')->willReturn([$this->row(['password' => 'x'])]);
        $alerts = $this->analyzer()->getAlerts();
        // No expiration-related issue, and password is set — zero issues means
        // the row is dropped entirely from the alert list (see getAlerts()).
        $this->assertCount(0, $alerts);
    }

    // -------------------------------------------------------------------
    // Expiration date branches — already_expired / expiring_soon / safe.
    // These are NOT gated by the no_expiration toggle (see issuesFor()).
    // -------------------------------------------------------------------

    public function testExpirationInPastFlagsAlreadyExpired(): void {
        $this->stubRules();
        $past = (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s');
        $this->mapper->method('findInsecureLinks')->willReturn([
            $this->row(['password' => 'x', 'expiration' => $past]),
        ]);
        $alerts = $this->analyzer()->getAlerts();
        $this->assertSame(['already_expired'], $this->issueCodes($alerts[0]));
        $this->assertSame('warning', $alerts[0]['severity']);
    }

    public function testExpirationWithinSevenDaysFlagsExpiringSoon(): void {
        $this->stubRules();
        $soon = (new \DateTimeImmutable('+3 days'))->format('Y-m-d H:i:s');
        $this->mapper->method('findInsecureLinks')->willReturn([
            $this->row(['password' => 'x', 'expiration' => $soon]),
        ]);
        $alerts = $this->analyzer()->getAlerts();
        $this->assertSame(['expiring_soon'], $this->issueCodes($alerts[0]));
        $this->assertSame('info', $alerts[0]['severity']);
    }

    public function testExpirationComfortablyInFutureHasNoExpirationIssue(): void {
        $this->stubRules();
        $future = (new \DateTimeImmutable('+30 days'))->format('Y-m-d H:i:s');
        $this->mapper->method('findInsecureLinks')->willReturn([
            $this->row(['password' => 'x', 'expiration' => $future]),
        ]);
        $alerts = $this->analyzer()->getAlerts();
        // Password set + safe future expiration + no sensitive extension = no issues at all.
        $this->assertCount(0, $alerts);
    }

    public function testUnparsableExpirationIsTreatedAsNoIssueRatherThanCrashing(): void {
        $this->stubRules();
        $this->mapper->method('findInsecureLinks')->willReturn([
            $this->row(['password' => 'x', 'expiration' => 'not-a-date']),
        ]);
        $alerts = $this->analyzer()->getAlerts();
        $this->assertCount(0, $alerts);
    }

    // -------------------------------------------------------------------
    // sensitive_file
    // -------------------------------------------------------------------

    public function testSensitiveExtensionFlagged(): void {
        $this->stubRules();
        $future = (new \DateTimeImmutable('+30 days'))->format('Y-m-d H:i:s');
        $this->mapper->method('findInsecureLinks')->willReturn([
            $this->row(['password' => 'x', 'expiration' => $future, 'file_path' => 'files/payroll.xlsx']),
        ]);
        $alerts = $this->analyzer()->getAlerts();
        $this->assertSame(['sensitive_file'], $this->issueCodes($alerts[0]));
    }

    public function testSensitiveRuleDisabledSuppressesIssue(): void {
        $this->stubRules(['sensitive_file' => false]);
        $future = (new \DateTimeImmutable('+30 days'))->format('Y-m-d H:i:s');
        $this->mapper->method('findInsecureLinks')->willReturn([
            $this->row(['password' => 'x', 'expiration' => $future, 'file_path' => 'files/payroll.xlsx']),
        ]);
        $alerts = $this->analyzer()->getAlerts();
        $this->assertCount(0, $alerts);
    }

    // -------------------------------------------------------------------
    // Severity ranking across multiple alerts.
    // -------------------------------------------------------------------

    public function testAlertsAreSortedCriticalFirst(): void {
        $this->stubRules();
        $soon = (new \DateTimeImmutable('+3 days'))->format('Y-m-d H:i:s');
        $this->mapper->method('findInsecureLinks')->willReturn([
            // info-only row, listed first in the raw result...
            $this->row(['id' => 1, 'password' => 'x', 'expiration' => $soon]),
            // ...but critical (no password) must sort ahead of it.
            $this->row(['id' => 2, 'password' => null, 'expiration' => $soon]),
        ]);
        $alerts = $this->analyzer()->getAlerts();

        $this->assertCount(2, $alerts);
        $this->assertSame(2, $alerts[0]['id']);
        $this->assertSame('critical', $alerts[0]['severity']);
        $this->assertSame(1, $alerts[1]['id']);
        $this->assertSame('info', $alerts[1]['severity']);
    }

    // -------------------------------------------------------------------
    // Caching + invalidation (see R2 in PRE_RELEASE_PLAN.md).
    // -------------------------------------------------------------------

    public function testSecondCallWithinTtlDoesNotHitTheMapperAgain(): void {
        $this->stubRules();
        $this->mapper->expects($this->once())->method('findInsecureLinks')->willReturn([$this->row()]);

        $analyzer = $this->analyzer();
        $analyzer->getAlerts();
        $analyzer->getAlerts();
    }

    public function testInvalidateForcesARecompute(): void {
        $this->stubRules();
        $this->mapper->expects($this->exactly(2))->method('findInsecureLinks')->willReturn([$this->row()]);

        $analyzer = $this->analyzer();
        $analyzer->getAlerts();
        $analyzer->invalidate('alice');
        $analyzer->getAlerts();
    }

    public function testPersonalViewAndAdminViewCacheIndependently(): void {
        $this->stubRules();
        $this->mapper->expects($this->exactly(2))->method('findInsecureLinks')->willReturn([$this->row()]);

        $analyzer = $this->analyzer();
        $analyzer->getAlerts(); // admin view, cache key '__admin__'
        $analyzer->getAlerts('alice'); // personal view, cache key 'alice'
    }
}
