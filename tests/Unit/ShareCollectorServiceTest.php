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
use OCA\ShareAuditDashboard\Service\ShareCollectorService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * normalizeRow() only has one branch of conditional logic worth a test on
 * its own (the rest is straight field mapping) — the "hasExpiration" flag,
 * fixed in QUALITY_REVIEW_PLAN.md G5.2 to mean "still has a future
 * expiration" rather than just "the column isn't null". getShares() also
 * gets a test for its ownerDisplayName attachment (see DisplayNameResolver).
 */
class ShareCollectorServiceTest extends TestCase {

    private ShareMapper&MockObject $mapper;
    private DisplayNameResolver&MockObject $displayNames;

    protected function setUp(): void {
        $this->mapper = $this->createMock(ShareMapper::class);
        $this->displayNames = $this->createMock(DisplayNameResolver::class);
    }

    private function collector(): ShareCollectorService {
        return new ShareCollectorService(
            $this->mapper,
            $this->createMock(SecurityAnalyzerService::class),
            new PathFormatter(),
            $this->displayNames,
        );
    }

    private function row(?string $expiration): array {
        return [
            'id' => 1,
            'share_type' => 3,
            'uid_owner' => 'alice',
            'permissions' => 1,
            'expiration' => $expiration,
        ];
    }

    public function testNoExpirationIsFalse(): void {
        $share = $this->collector()->normalizeRow($this->row(null));
        $this->assertFalse($share['hasExpiration']);
    }

    public function testFutureExpirationIsTrue(): void {
        $future = (new \DateTimeImmutable('+30 days'))->format('Y-m-d H:i:s');
        $share = $this->collector()->normalizeRow($this->row($future));
        $this->assertTrue($share['hasExpiration']);
    }

    public function testPastExpirationIsFalse(): void {
        $past = (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s');
        $share = $this->collector()->normalizeRow($this->row($past));
        $this->assertFalse($share['hasExpiration']);
        // The raw date is still surfaced separately so the admin can see it expired.
        $this->assertNotNull($share['expiration']);
    }

    public function testUnparsableExpirationIsFalseRatherThanCrashing(): void {
        $share = $this->collector()->normalizeRow($this->row('not-a-date'));
        $this->assertFalse($share['hasExpiration']);
    }

    // -------------------------------------------------------------------
    // getShares() — ownerDisplayName/initiatorDisplayName attachment.
    // -------------------------------------------------------------------

    public function testGetSharesAttachesOwnerAndInitiatorDisplayNames(): void {
        $this->mapper->method('findShares')->willReturn([array_merge(
            $this->row(null),
            ['uid_owner' => 'alice', 'uid_initiator' => 'bob'],
        )]);
        $this->mapper->method('countShares')->willReturn(1);
        $this->displayNames->method('resolveMany')->willReturn([
            'alice' => 'Alice Silva',
            'bob' => 'Bob Santos',
        ]);

        $result = $this->collector()->getShares([], 1, 25);

        $this->assertSame('Alice Silva', $result['items'][0]['ownerDisplayName']);
        $this->assertSame('Bob Santos', $result['items'][0]['initiatorDisplayName']);
    }

    public function testGetSharesFallsBackToUidWhenNameIsUnresolved(): void {
        $this->mapper->method('findShares')->willReturn([$this->row(null)]);
        $this->mapper->method('countShares')->willReturn(1);
        $this->displayNames->method('resolveMany')->willReturn([]);

        $result = $this->collector()->getShares([], 1, 25);

        $this->assertSame('alice', $result['items'][0]['ownerDisplayName']);
    }
}
