<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Tests\Unit;

use OCA\ShareAuditDashboard\Db\ShareMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers ShareMapper's query-building helpers, notably applyFilters()'s
 * hasExpiration semantics (see below) — the sort/filter surface backing the
 * "All shares" view.
 */
class ShareMapperTest extends TestCase {

    /**
     * @return array{0: IQueryBuilder&MockObject, 1: IExpressionBuilder&MockObject}
     */
    private function queryBuilderMockWithExpr(): array {
        $expr = $this->createMock(IExpressionBuilder::class);
        foreach (['eq', 'neq', 'isNull', 'isNotNull', 'lt', 'lte', 'gt', 'gte', 'in', 'notIn', 'iLike'] as $method) {
            $expr->method($method)->willReturn('expr');
        }
        // orX()/andX() are typed to return ICompositeExpression, not string.
        $composite = $this->createMock(\OCP\DB\QueryBuilder\ICompositeExpression::class);
        $expr->method('orX')->willReturn($composite);
        $expr->method('andX')->willReturn($composite);

        $func = $this->createMock(IFunctionBuilder::class);
        $func->method('count')->willReturn($this->createMock(IQueryFunction::class));

        $qb = $this->createMock(IQueryBuilder::class);
        foreach (['select', 'selectAlias', 'from', 'leftJoin', 'where', 'andWhere', 'orderBy', 'addOrderBy', 'groupBy'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('setFirstResult')->willReturnSelf();
        $qb->method('createNamedParameter')->willReturnArgument(0);
        $qb->method('createFunction')->willReturnArgument(0);
        $qb->method('expr')->willReturn($expr);
        $qb->method('func')->willReturn($func);
        return [$qb, $expr];
    }

    // -------------------------------------------------------------------
    // applyFilters() — hasExpiration must mean "future expiration", not
    // just "column is not null".
    // -------------------------------------------------------------------

    public function testHasExpirationTrueRequiresAFutureDateNotJustNonNull(): void {
        $result = $this->createMock(IResult::class);
        $result->method('fetchOne')->willReturn(1);
        $result->method('closeCursor')->willReturn(true);

        [$qb, $expr] = $this->queryBuilderMockWithExpr();
        $qb->method('executeQuery')->willReturn($result);
        // Must compare against "now", not just check IS NOT NULL.
        $expr->expects($this->atLeastOnce())->method('gt')
            ->with('s.expiration', $this->anything());

        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($qb);
        (new ShareMapper($db))->countShares(['hasExpiration' => true]);
    }

    public function testHasExpirationFalseAlsoMatchesAnAlreadyExpiredDate(): void {
        $result = $this->createMock(IResult::class);
        $result->method('fetchOne')->willReturn(1);
        $result->method('closeCursor')->willReturn(true);

        [$qb, $expr] = $this->queryBuilderMockWithExpr();
        $qb->method('executeQuery')->willReturn($result);
        // "Without expiration" must include "expired but not purged yet",
        // via a <= now comparison, not just IS NULL.
        $expr->expects($this->atLeastOnce())->method('lte')
            ->with('s.expiration', $this->anything());

        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($qb);
        (new ShareMapper($db))->countShares(['hasExpiration' => false]);
    }
}
