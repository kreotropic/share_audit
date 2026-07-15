<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Tests\Unit;

use OCA\ShareAuditDashboard\Db\ShareMapper;
use OCA\ShareAuditDashboard\Service\DisplayNameResolver;
use OCA\ShareAuditDashboard\Service\PathFormatter;
use OCA\ShareAuditDashboard\Service\SecurityAnalyzerService;
use OCA\ShareAuditDashboard\Service\SettingsService;
use OCP\ICacheFactory;
use OCP\IGroup;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers SecurityAnalyzerService::issuesFor() (private, exercised through
 * getAlerts()): the four-way branch on expiration state (none / future /
 * expiring soon / already expired) crossed with the configurable rule
 * toggles — the highest-value place for coverage given how easy it'd be to
 * break silently. Also covers the two newest rules (public_upload,
 * group_share_editable).
 */
class SecurityAnalyzerServiceTest extends TestCase {

    private ShareMapper&MockObject $mapper;
    private SettingsService&MockObject $settings;
    private IGroupManager&MockObject $groupManager;
    private DisplayNameResolver&MockObject $displayNames;
    private ArrayCache $cache;

    protected function setUp(): void {
        $this->mapper = $this->createMock(ShareMapper::class);
        $this->settings = $this->createMock(SettingsService::class);
        $this->settings->method('getSensitiveExtensions')->willReturn(['xlsx', 'pdf']);
        $this->settings->method('getGroupShareMinMembers')->willReturn(20);
        // isRuleEnabled() is deliberately NOT stubbed here: PHPUnit only honors
        // the first configured stub for a given method when there's no
        // with()-constraint to disambiguate, so every test calls stubRules()
        // itself exactly once instead of layering a second config on top of
        // a setUp() default.
        $this->groupManager = $this->createMock(IGroupManager::class);
        // Unconfigured: resolveMany() auto-returns [] (its declared array
        // return type), which is a safe default — buildAlert()'s consumer
        // falls back to the raw uid. Tests that care configure it themselves.
        $this->displayNames = $this->createMock(DisplayNameResolver::class);

        $this->cache = new ArrayCache();
    }

    /**
     * Configure isRuleEnabled() for all five rules in one go (default: all
     * on). Call at most once per test — see the note in setUp().
     *
     * @param array<string, bool> $overrides rule code => enabled
     */
    private function stubRules(array $overrides = []): void {
        $rules = array_merge(
            [
                'no_password' => true, 'no_expiration' => true, 'sensitive_file' => true,
                'group_share_editable' => true, 'public_upload' => true,
            ],
            $overrides,
        );
        $this->settings->method('isRuleEnabled')->willReturnMap([
            ['no_password', $rules['no_password']],
            ['no_expiration', $rules['no_expiration']],
            ['sensitive_file', $rules['sensitive_file']],
            ['group_share_editable', $rules['group_share_editable']],
            ['public_upload', $rules['public_upload']],
        ]);
    }

    private function analyzer(): SecurityAnalyzerService {
        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($this->cache);
        return new SecurityAnalyzerService(
            $this->mapper,
            $this->settings,
            new PathFormatter(),
            $this->groupManager,
            $this->displayNames,
            $cacheFactory,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array {
        return array_merge([
            'id' => 1,
            'share_type' => 3,
            'uid_owner' => 'alice',
            'uid_initiator' => 'alice',
            'password' => null,
            'expiration' => null,
            'permissions' => 1,
            'file_path' => 'files/report.txt',
            'file_source' => 42,
            'stime' => 1700000000,
            'token' => 'tok123',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function groupShareRow(array $overrides = []): array {
        return array_merge([
            'id' => 10,
            'share_type' => 1,
            'share_with' => 'finance',
            'uid_owner' => 'alice',
            'uid_initiator' => 'alice',
            'permissions' => 31, // PERMISSION_ALL — read+update+create+delete+share
            'file_path' => 'files/Finance',
            'file_source' => 99,
            'stime' => 1700000000,
        ], $overrides);
    }

    /**
     * @return IGroup&MockObject
     */
    private function groupWithMembers(int $count): IGroup&MockObject {
        $group = $this->createMock(IGroup::class);
        $group->method('count')->willReturn($count);
        return $group;
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
    // Caching + invalidation.
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

    // -------------------------------------------------------------------
    // public_upload.
    // -------------------------------------------------------------------

    public function testFileDropWithoutPasswordIsFlagged(): void {
        $this->stubRules();
        // create without read: "file drop", visitor can add but not browse.
        $this->mapper->method('findInsecureLinks')->willReturn([
            $this->row(['permissions' => 4]),
        ]);
        $alerts = $this->analyzer()->getAlerts();
        $this->assertContains('public_upload', $this->issueCodes($alerts[0]));
    }

    public function testCreateAndUpdateWithoutPasswordIsFlagged(): void {
        $this->stubRules();
        // create + update + read, no password: full write access to a public link.
        $this->mapper->method('findInsecureLinks')->willReturn([
            $this->row(['permissions' => 1 | 2 | 4]),
        ]);
        $alerts = $this->analyzer()->getAlerts();
        $this->assertContains('public_upload', $this->issueCodes($alerts[0]));
    }

    public function testCreateWithReadButNoUpdateIsNotFlagged(): void {
        $this->stubRules();
        // create + read, no update: plain "upload to a public folder", not
        // materially different from a normal public link — not flagged.
        $this->mapper->method('findInsecureLinks')->willReturn([
            $this->row(['permissions' => 1 | 4, 'expiration' => (new \DateTimeImmutable('+30 days'))->format('Y-m-d H:i:s')]),
        ]);
        $alerts = $this->analyzer()->getAlerts();
        // No password already trips no_password separately; isolate public_upload.
        $this->assertCount(0, array_filter($this->issueCodes($alerts[0] ?? ['issues' => []]), fn ($c) => $c === 'public_upload'));
    }

    public function testPasswordSetSuppressesPublicUploadEvenWithCreatePermission(): void {
        $this->stubRules();
        $this->mapper->method('findInsecureLinks')->willReturn([
            $this->row(['permissions' => 4, 'password' => 'x']),
        ]);
        $alerts = $this->analyzer()->getAlerts();
        // Password set + create-only + no expiration set -> only no_expiration remains.
        $this->assertNotContains('public_upload', $this->issueCodes($alerts[0]));
    }

    public function testPublicUploadRuleDisabledSuppressesIssue(): void {
        $this->stubRules(['public_upload' => false]);
        $this->mapper->method('findInsecureLinks')->willReturn([
            $this->row(['permissions' => 4]),
        ]);
        $alerts = $this->analyzer()->getAlerts();
        $this->assertNotContains('public_upload', $this->issueCodes($alerts[0]));
    }

    // -------------------------------------------------------------------
    // group_share_editable.
    // -------------------------------------------------------------------

    public function testLargeEditableGroupIsFlagged(): void {
        $this->stubRules();
        $this->mapper->method('findGroupShares')->willReturn([$this->groupShareRow()]);
        $this->groupManager->method('get')->with('finance')->willReturn($this->groupWithMembers(85));
        $this->groupManager->method('getDisplayName')->willReturn('Finance');

        $alerts = $this->analyzer()->getAlerts();

        $this->assertCount(1, $alerts);
        $this->assertSame(['group_share_editable'], $this->issueCodes($alerts[0]));
        $this->assertSame('warning', $alerts[0]['severity']);
        $this->assertSame('finance', $alerts[0]['recipient']);
        $this->assertSame('Finance', $alerts[0]['recipientLabel']);
        $this->assertSame(85, $alerts[0]['memberCount']);
        // Link-only fields must be safely absent/null, not crash the response.
        $this->assertNull($alerts[0]['token']);
    }

    public function testSmallGroupBelowThresholdIsNotFlagged(): void {
        $this->stubRules();
        $this->mapper->method('findGroupShares')->willReturn([$this->groupShareRow(['share_with' => 'small-team'])]);
        $this->groupManager->method('get')->willReturn($this->groupWithMembers(5));

        $alerts = $this->analyzer()->getAlerts();
        $this->assertCount(0, $alerts);
    }

    public function testReadOnlyGroupShareIsNotFlaggedRegardlessOfSize(): void {
        $this->stubRules();
        // Read-only (no update, no reshare — "edit/reshare" is the rule's
        // whole premise, see EDIT_PERMISSIONS): not flagged even for a huge group.
        $this->mapper->method('findGroupShares')->willReturn([
            $this->groupShareRow(['permissions' => 1]),
        ]);
        $this->groupManager->method('get')->willReturn($this->groupWithMembers(500));

        $alerts = $this->analyzer()->getAlerts();
        $this->assertCount(0, $alerts);
    }

    public function testReshareOnlyGroupShareIsFlagged(): void {
        $this->stubRules();
        // Reshare without update is still "editable" for this rule's purpose:
        // the group can cascade access further, not just modify content.
        $this->mapper->method('findGroupShares')->willReturn([
            $this->groupShareRow(['permissions' => 1 | 16]),
        ]);
        $this->groupManager->method('get')->willReturn($this->groupWithMembers(500));

        $alerts = $this->analyzer()->getAlerts();
        $this->assertCount(1, $alerts);
    }

    public function testGroupShareEditableRuleDisabledSkipsGroupLookupEntirely(): void {
        $this->stubRules(['group_share_editable' => false]);
        $this->mapper->expects($this->never())->method('findGroupShares');

        $alerts = $this->analyzer()->getAlerts();
        $this->assertCount(0, $alerts);
    }

    public function testDeletedGroupCountsAsZeroMembersNotFlagged(): void {
        $this->stubRules();
        $this->mapper->method('findGroupShares')->willReturn([$this->groupShareRow()]);
        $this->groupManager->method('get')->willReturn(null);

        $alerts = $this->analyzer()->getAlerts();
        $this->assertCount(0, $alerts);
    }

    public function testGroupMemberCountIsCachedAcrossRows(): void {
        $this->stubRules();
        // Two shares of the same large group, on different files.
        $this->mapper->method('findGroupShares')->willReturn([
            $this->groupShareRow(['id' => 10, 'file_source' => 99]),
            $this->groupShareRow(['id' => 11, 'file_source' => 100]),
        ]);
        $this->groupManager->expects($this->once())->method('get')
            ->with('finance')->willReturn($this->groupWithMembers(85));

        $alerts = $this->analyzer()->getAlerts();
        $this->assertCount(2, $alerts);
    }

    public function testCountAlertsIncludesGroupShareAlerts(): void {
        $this->stubRules();
        $this->mapper->method('countInsecureLinks')->willReturn(0);
        $this->mapper->method('findGroupShares')->willReturn([$this->groupShareRow()]);
        $this->groupManager->method('get')->willReturn($this->groupWithMembers(85));

        $this->assertSame(1, $this->analyzer()->countAlerts());
    }

    // -------------------------------------------------------------------
    // ownerDisplayName — resolved for the raw AD/LDAP uid a plain "owner"
    // field would otherwise show (see the display-name gap this session
    // fixed across the whole app, not just the dashboard's "top sharers").
    // -------------------------------------------------------------------

    public function testOwnerDisplayNameIsAttachedToLinkAlerts(): void {
        $this->stubRules();
        $this->mapper->method('findInsecureLinks')->willReturn([$this->row()]);
        $this->displayNames->method('resolveMany')->willReturn(['alice' => 'Alice Silva']);

        $alerts = $this->analyzer()->getAlerts();
        $this->assertSame('Alice Silva', $alerts[0]['ownerDisplayName']);
    }

    public function testOwnerDisplayNameFallsBackToUidWhenUnresolved(): void {
        $this->stubRules();
        $this->mapper->method('findInsecureLinks')->willReturn([$this->row()]);
        $this->displayNames->method('resolveMany')->willReturn([]);

        $alerts = $this->analyzer()->getAlerts();
        $this->assertSame('alice', $alerts[0]['ownerDisplayName']);
    }

    public function testOwnerDisplayNameIsAttachedToGroupShareAlerts(): void {
        $this->stubRules();
        $this->mapper->method('findGroupShares')->willReturn([$this->groupShareRow()]);
        $this->groupManager->method('get')->willReturn($this->groupWithMembers(85));
        $this->displayNames->method('resolveMany')->willReturn(['alice' => 'Alice Silva']);

        $alerts = $this->analyzer()->getAlerts();
        $this->assertSame('Alice Silva', $alerts[0]['ownerDisplayName']);
    }
}
