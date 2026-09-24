<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Tests\Unit;

use OCA\ShareAuditDashboard\Db\FileMoveMapper;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * claim() is what stops a queued move running twice: it says whether THIS call
 * is the one that took it. That the database makes the others lose is checked
 * against a real one in tests/Integration/FileMoveQueueTest; this pins down the
 * part that is the mapper's own — that the answer is how many rows changed.
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

        $this->assertTrue($this->mapper->claim(7, 1_800_000_000));
    }

    public function testACallThatFoundTheRowAlreadyTakenDidNotClaimIt(): void {
        $this->qb->method('executeStatement')->willReturn(0);

        $this->assertFalse($this->mapper->claim(7, 1_800_000_000));
    }
}
