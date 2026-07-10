<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
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
 * Covers ShareMapper::countInsecureLinks()'s early-return path: when no rule
 * is enabled and no expiring-soon cutoff is given, insecureLinkConditions()
 * (the base "candidate" filter shared with findInsecureLinks() — see its
 * docblock, "single source of truth ... so they can never drift apart")
 * still runs to build the base query, but the per-rule $conditions array
 * stays empty and the method must return 0 WITHOUT executing the query.
 *
 * If that early return were ever dropped (e.g. during a refactor), the count
 * would silently become "every type-3 share with no password OR no
 * expiration OR an expiration at/before the cutoff" regardless of which
 * rules are actually enabled — an over-count on the dashboard's security
 * badge. See QUALITY_REVIEW_PLAN.md M-Q1.
 */
class ShareMapperTest extends TestCase {

    /**
     * Build an IQueryBuilder mock whose fluent methods return itself, with a
     * permissive expr()/func() so any condition-building call succeeds
     * without asserting on the SQL shape itself — this test cares about
     * *whether* executeQuery() runs, not about the exact WHERE clause.
     */
    private function queryBuilderMock(): IQueryBuilder&MockObject {
        $expr = $this->createMock(IExpressionBuilder::class);
        foreach (['eq', 'neq', 'isNull', 'isNotNull', 'lte', 'gte', 'in', 'notIn', 'iLike'] as $method) {
            $expr->method($method)->willReturn('expr');
        }
        // orX()/andX() are typed to return ICompositeExpression, not string.
        $composite = $this->createMock(\OCP\DB\QueryBuilder\ICompositeExpression::class);
        $expr->method('orX')->willReturn($composite);
        $expr->method('andX')->willReturn($composite);

        $func = $this->createMock(IFunctionBuilder::class);
        $func->method('count')->willReturn($this->createMock(IQueryFunction::class));

        $qb = $this->createMock(IQueryBuilder::class);
        foreach (['select', 'from', 'leftJoin', 'where', 'andWhere', 'orderBy', 'addOrderBy', 'groupBy'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('setFirstResult')->willReturnSelf();
        $qb->method('createNamedParameter')->willReturnArgument(0);
        $qb->method('createFunction')->willReturnArgument(0);
        $qb->method('expr')->willReturn($expr);
        $qb->method('func')->willReturn($func);
        return $qb;
    }

    private function mapperWith(IQueryBuilder&MockObject $qb): ShareMapper {
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($qb);
        return new ShareMapper($db);
    }

    public function testReturnsZeroWithoutExecutingWhenNoRuleEnabledAndNoCutoff(): void {
        $qb = $this->queryBuilderMock();
        $qb->expects($this->never())->method('executeQuery');

        $count = $this->mapperWith($qb)->countInsecureLinks(
            noPassword: false,
            noExpiration: false,
            sensitiveFile: false,
            sensitiveExtensions: [],
            expiringSoonCutoff: null,
        );

        $this->assertSame(0, $count);
    }

    /**
     * sensitiveFile=true with an empty extension list must behave the same
     * as sensitiveFile=false (see the `$sensitiveFile && $sensitiveExtensions
     * !== []` guard) — it must NOT be treated as "match everything".
     */
    public function testSensitiveFileRuleWithNoExtensionsConfiguredAddsNoCondition(): void {
        $qb = $this->queryBuilderMock();
        $qb->expects($this->never())->method('executeQuery');

        $count = $this->mapperWith($qb)->countInsecureLinks(
            noPassword: false,
            noExpiration: false,
            sensitiveFile: true,
            sensitiveExtensions: [],
            expiringSoonCutoff: null,
        );

        $this->assertSame(0, $count);
    }

    public function testExecutesAndReturnsFetchedCountWhenAtLeastOneRuleEnabled(): void {
        $result = $this->createMock(IResult::class);
        $result->method('fetchOne')->willReturn(5);
        $result->method('closeCursor')->willReturn(true);

        $qb = $this->queryBuilderMock();
        $qb->expects($this->once())->method('executeQuery')->willReturn($result);

        $count = $this->mapperWith($qb)->countInsecureLinks(
            noPassword: true,
            noExpiration: false,
            sensitiveFile: false,
            sensitiveExtensions: [],
            expiringSoonCutoff: null,
        );

        $this->assertSame(5, $count);
    }

    /**
     * A cutoff alone (no_password/no_expiration/sensitive_file all off) is
     * still a real candidate condition ("already expired or expiring soon"
     * — see SecurityAnalyzerService's Q4 issues, which aren't gated by a
     * settings toggle) and must execute, not short-circuit to 0.
     */
    public function testExpiringSoonCutoffAloneStillExecutes(): void {
        $result = $this->createMock(IResult::class);
        $result->method('fetchOne')->willReturn(2);
        $result->method('closeCursor')->willReturn(true);

        $qb = $this->queryBuilderMock();
        $qb->expects($this->once())->method('executeQuery')->willReturn($result);

        $count = $this->mapperWith($qb)->countInsecureLinks(
            noPassword: false,
            noExpiration: false,
            sensitiveFile: false,
            sensitiveExtensions: [],
            expiringSoonCutoff: new \DateTimeImmutable('+7 days'),
        );

        $this->assertSame(2, $count);
    }
}
