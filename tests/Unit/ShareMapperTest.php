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

    // -------------------------------------------------------------------
    // applyFilters() — a scalar uid filter must not use empty()/!empty():
    // PHP's empty('0') is true, so a real account whose uid is literally
    // "0" would otherwise silently lose its owner/initiator filter and
    // match every row instead of just its own.
    // -------------------------------------------------------------------

    /**
     * Builds an expr()/qb() pair like queryBuilderMockWithExpr(), but
     * without pre-stubbing eq()/in()/andWhere() — a test that needs to spy
     * on their exact arguments must configure those itself: PHPUnit only
     * honors the FIRST stub registered for a given method when there is no
     * with()-constraint to disambiguate, so layering a spy on top of
     * queryBuilderMockWithExpr()'s generic stubs would silently never run.
     *
     * @return array{0: IQueryBuilder&MockObject, 1: IExpressionBuilder&MockObject}
     */
    private function queryBuilderMockForSpying(): array {
        $expr = $this->createMock(IExpressionBuilder::class);
        foreach (['neq', 'isNull', 'isNotNull', 'lt', 'lte', 'gt', 'gte', 'notIn'] as $method) {
            $expr->method($method)->willReturn('expr');
        }
        $composite = $this->createMock(\OCP\DB\QueryBuilder\ICompositeExpression::class);
        $expr->method('orX')->willReturn($composite);
        $expr->method('andX')->willReturn($composite);

        $func = $this->createMock(IFunctionBuilder::class);
        $func->method('count')->willReturn($this->createMock(IQueryFunction::class));

        $qb = $this->createMock(IQueryBuilder::class);
        foreach (['select', 'selectAlias', 'from', 'leftJoin', 'where', 'orderBy', 'addOrderBy', 'groupBy'] as $method) {
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

    public function testApplyFiltersTreatsOwnerZeroAsARealFilterNotAnEmptyOne(): void {
        $result = $this->createMock(IResult::class);
        $result->method('fetchOne')->willReturn(0);
        $result->method('closeCursor')->willReturn(true);

        [$qb, $expr] = $this->queryBuilderMockForSpying();
        $qb->method('executeQuery')->willReturn($result);
        $eqCalls = [];
        $expr->method('eq')->willReturnCallback(function ($col, $val) use (&$eqCalls) {
            $eqCalls[] = [$col, $val];
            return 'expr';
        });
        $qb->method('andWhere')->willReturnSelf();

        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($qb);
        (new ShareMapper($db))->countShares(['owner' => '0']);

        $this->assertContains(['s.uid_owner', '0'], $eqCalls);
    }

    public function testApplyFiltersTreatsOwnerOrInitiatorZeroAsARealFilterNotAnEmptyOne(): void {
        $result = $this->createMock(IResult::class);
        $result->method('fetchOne')->willReturn(0);
        $result->method('closeCursor')->willReturn(true);

        [$qb, $expr] = $this->queryBuilderMockForSpying();
        $qb->method('executeQuery')->willReturn($result);
        $eqCalls = [];
        $expr->method('eq')->willReturnCallback(function ($col, $val) use (&$eqCalls) {
            $eqCalls[] = [$col, $val];
            return 'expr';
        });
        $qb->method('andWhere')->willReturnSelf();

        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($qb);
        (new ShareMapper($db))->countShares(['ownerOrInitiator' => '0']);

        $this->assertContains(['s.uid_owner', '0'], $eqCalls);
        $this->assertContains(['s.uid_initiator', '0'], $eqCalls);
    }

    /**
     * An explicitly empty owners list (e.g. a manager with zero direct
     * reports, or every orphan owner having just been reactivated) must
     * match NO rows, not every row — the pre-fix code skipped the whole
     * uid_owner condition for an empty array, same underlying empty()
     * mistake as the scalar filters above.
     */
    public function testApplyFiltersWithEmptyOwnersArrayMatchesNothing(): void {
        $result = $this->createMock(IResult::class);
        $result->method('fetchOne')->willReturn(0);
        $result->method('closeCursor')->willReturn(true);

        [$qb, $expr] = $this->queryBuilderMockForSpying();
        $qb->method('executeQuery')->willReturn($result);
        $expr->method('eq')->willReturn('expr');
        // Must never reach an IN (...) at all for an empty list.
        $expr->expects($this->never())->method('in');
        $andWhereCalls = [];
        $qb->method('andWhere')->willReturnCallback(function ($cond) use (&$andWhereCalls, $qb) {
            $andWhereCalls[] = $cond;
            return $qb;
        });

        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($qb);
        (new ShareMapper($db))->countShares(['owners' => []]);

        $this->assertContains('1 = 0', $andWhereCalls);
    }

    // -------------------------------------------------------------------
    // findInsecureLinks() / insecureLinkConditions() — the sensitive_file
    // rule must widen the SQL candidate pool, otherwise a link that has
    // both a password and a comfortably-future expiration but points at a
    // sensitive extension never reaches issuesFor() at all.
    // -------------------------------------------------------------------

    public function testFindInsecureLinksAddsAConditionPerSensitiveExtension(): void {
        $result = $this->createMock(IResult::class);
        $result->method('fetchAll')->willReturn([]);
        $result->method('closeCursor')->willReturn(true);

        [$qb, $expr] = $this->queryBuilderMockForSpying();
        $qb->method('executeQuery')->willReturn($result);
        $qb->method('andWhere')->willReturnSelf();
        $expr->method('eq')->willReturn('expr');
        $expr->method('isNull')->willReturn('expr');
        $iLikeCalls = [];
        $expr->method('iLike')->willReturnCallback(function ($col, $val) use (&$iLikeCalls) {
            $iLikeCalls[] = [$col, $val];
            return 'expr';
        });

        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($qb);
        $db->method('escapeLikeParameter')->willReturnArgument(0);
        (new ShareMapper($db))->findInsecureLinks(null, null, ['xlsx', 'PDF']);

        $this->assertContains(['f.path', '%.xlsx'], $iLikeCalls);
        $this->assertContains(['f.path', '%.pdf'], $iLikeCalls, 'the extension is lowercased, matching isSensitiveFile()');
    }

    public function testFindInsecureLinksAddsNoExtensionConditionWhenNoneGiven(): void {
        $result = $this->createMock(IResult::class);
        $result->method('fetchAll')->willReturn([]);
        $result->method('closeCursor')->willReturn(true);

        [$qb, $expr] = $this->queryBuilderMockForSpying();
        $qb->method('executeQuery')->willReturn($result);
        $qb->method('andWhere')->willReturnSelf();
        $expr->method('eq')->willReturn('expr');
        $expr->method('isNull')->willReturn('expr');
        $expr->expects($this->never())->method('iLike');

        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($qb);
        (new ShareMapper($db))->findInsecureLinks(null, null, []);
    }
}
