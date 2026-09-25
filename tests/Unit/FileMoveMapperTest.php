<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Tests\Unit;

use OCA\ShareAuditDashboard\Db\FileMoveMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * claim() is what stops a queued move running twice, or two moves running into
 * one account at once: it says whether THIS call took the move, lost it to
 * another worker, or found the account busy. That the database really makes the
 * others lose is checked against a real one in tests/Integration/FileMoveQueueTest
 * and OrphanFileMoveTest; this pins down the part that is the mapper's own —
 * how each outcome is told apart.
 */
class FileMoveMapperTest extends TestCase {

    private IQueryBuilder&MockObject $qb;
    private FileMoveMapper $mapper;

    protected function setUp(): void {
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturn('expr');
        $this->qb = $this->createMock(IQueryBuilder::class);
        $this->qb->method('update')->willReturnSelf();
        $this->qb->method('set')->willReturnSelf();
        $this->qb->method('where')->willReturnSelf();
        $this->qb->method('andWhere')->willReturnSelf();
        $this->qb->method('expr')->willReturn($expr);
        $this->qb->method('createNamedParameter')->willReturnArgument(0);
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($this->qb);
        $this->mapper = new FileMoveMapper($db);
    }

    public function testTheCallThatChangedTheRowClaimedIt(): void {
        $this->qb->expects($this->once())->method('update')->with('shareaudit_filemove')->willReturnSelf();
        $this->qb->method('executeStatement')->willReturn(1);

        $this->assertSame(FileMoveMapper::CLAIM_OK, $this->mapper->claim(7, 'dana', 1_800_000_000));
    }

    public function testACallThatFoundTheRowAlreadyTakenDidNotClaimIt(): void {
        $this->qb->method('executeStatement')->willReturn(0);

        $this->assertSame(FileMoveMapper::CLAIM_TAKEN, $this->mapper->claim(7, 'dana', 1_800_000_000));
    }

    /**
     * The database refusing a second running move for the same account (the
     * unique index on running_target) is the "busy" answer — not an error, and
     * not the same as somebody else having taken this very move.
     */
    public function testAnAccountAlreadyReceivingAMoveReportsBusyNotAnError(): void {
        $violation = $this->createMock(Exception::class);
        $violation->method('getReason')->willReturn(Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION);
        $this->qb->method('executeStatement')->willThrowException($violation);

        $this->assertSame(FileMoveMapper::CLAIM_BUSY, $this->mapper->claim(7, 'dana', 1_800_000_000));
    }

    public function testAnyOtherDatabaseErrorIsNotMistakenForBusy(): void {
        $other = $this->createMock(Exception::class);
        $other->method('getReason')->willReturn(Exception::REASON_CONNECTION_LOST);
        $this->qb->method('executeStatement')->willThrowException($other);

        $this->expectException(Exception::class);
        $this->mapper->claim(7, 'dana', 1_800_000_000);
    }

    /**
     * abandon() frees ONE move and only if it is still running, so that racing
     * with the worker that finishes it — or with another one giving up on it —
     * frees nothing twice. The answer is whether this call did.
     */
    public function testAMoveThatWasStillRunningIsAbandoned(): void {
        $this->qb->method('andWhere')->willReturnSelf();
        $this->qb->method('executeStatement')->willReturn(1);

        $this->assertTrue($this->mapper->abandon(7, 'interrupted', 1_800_000_000));
    }

    public function testAMoveThatIsNoLongerRunningIsNotAbandonedAgain(): void {
        $this->qb->method('andWhere')->willReturnSelf();
        $this->qb->method('executeStatement')->willReturn(0);

        $this->assertFalse($this->mapper->abandon(7, 'interrupted', 1_800_000_000));
    }
}
