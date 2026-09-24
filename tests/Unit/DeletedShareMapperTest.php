<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Tests\Unit;

use OCA\ShareAuditDashboard\Db\DeletedShareMapper;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * claim() is what lets two restores of one entry run at once without both
 * winning: it says whether THIS call is the one that removed the row. Whether
 * the database really makes the others wait and then find nothing is what
 * tests/Integration/RestoreConcurrencyTest checks; this pins down the part that
 * is the mapper's own — that the answer comes from how many rows were removed.
 */
class DeletedShareMapperTest extends TestCase {

    private IQueryBuilder&MockObject $qb;
    private DeletedShareMapper $mapper;

    protected function setUp(): void {
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturn('expr');
        $this->qb = $this->createMock(IQueryBuilder::class);
        $this->qb->method('delete')->willReturnSelf();
        $this->qb->method('where')->willReturnSelf();
        $this->qb->method('expr')->willReturn($expr);
        $this->qb->method('createNamedParameter')->willReturnArgument(0);
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($this->qb);
        $this->mapper = new DeletedShareMapper($db);
    }

    public function testTheCallThatRemovedTheRowClaimedIt(): void {
        $this->qb->expects($this->once())->method('delete')->with('shareaudit_deleted')->willReturnSelf();
        $this->qb->method('executeStatement')->willReturn(1);

        $this->assertTrue($this->mapper->claim(7));
    }

    public function testACallThatFoundNothingToRemoveDidNotClaimIt(): void {
        $this->qb->method('executeStatement')->willReturn(0);

        $this->assertFalse($this->mapper->claim(7));
    }
}
