<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Tests\Unit;

use OCA\ShareAuditDashboard\Db\ShareMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers the "Path" column filter of the All shares table. It has to find a
 * share by the name it was given as well as by where its file is: a link
 * called "Q3 Budget — external review" says nothing of that in its path.
 */
class ShareMapperPathSearchTest extends TestCase {

    private IDBConnection&MockObject $db;
    private ShareMapper $mapper;

    /** @var string[] the LIKE conditions built, as "column:pattern", in order */
    private array $likes = [];

    /** @var array<int, string[]> the conditions each orX() was given */
    private array $orGroups = [];

    protected function setUp(): void {
        $this->db = $this->createMock(IDBConnection::class);
        $this->db->method('escapeLikeParameter')->willReturnCallback(
            static fn (string $value) => addcslashes($value, '%_\\'),
        );
        $this->mapper = new ShareMapper($this->db);
        $this->likes = [];
        $this->orGroups = [];

        $this->db->method('getQueryBuilder')->willReturn($this->recordingBuilder());
    }

    private function recordingBuilder(): IQueryBuilder {
        $expr = $this->createMock(IExpressionBuilder::class);
        foreach (['eq', 'neq', 'isNull', 'isNotNull', 'lt', 'lte', 'gt', 'gte', 'in', 'notIn'] as $method) {
            $expr->method($method)->willReturn('cond');
        }
        $expr->method('iLike')->willReturnCallback(function (string $column, string $pattern) {
            $this->likes[] = $column . ':' . $pattern;
            return 'like(' . $column . ')';
        });
        $expr->method('orX')->willReturnCallback(function (...$conditions) {
            $this->orGroups[] = $conditions;
            return $this->createMock(ICompositeExpression::class);
        });

        $func = $this->createMock(IFunctionBuilder::class);
        $func->method('count')->willReturn($this->createMock(IQueryFunction::class));

        $result = $this->createMock(IResult::class);
        $result->method('fetchOne')->willReturn(0);

        $qb = $this->createMock(IQueryBuilder::class);
        foreach (['select', 'selectAlias', 'from', 'leftJoin', 'where', 'andWhere'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('createNamedParameter')->willReturnArgument(0);
        $qb->method('expr')->willReturn($expr);
        $qb->method('func')->willReturn($func);
        $qb->method('executeQuery')->willReturn($result);
        return $qb;
    }

    public function testThePathFilterMatchesThePathOrTheShareName(): void {
        $this->mapper->countShares(['pathSearch' => 'budget']);

        $this->assertSame(['f.path:%budget%', 's.label:%budget%'], $this->likes);
        $this->assertSame(
            [['like(f.path)', 'like(s.label)']],
            $this->orGroups,
            'the two must be alternatives — a share matches by either, not both',
        );
    }

    public function testNoPathFilterAddsNoCondition(): void {
        $this->mapper->countShares([]);

        $this->assertSame([], $this->likes);
    }

    public function testAnEmptyPathFilterAddsNoCondition(): void {
        $this->mapper->countShares(['pathSearch' => '']);

        $this->assertSame([], $this->likes);
    }

    public function testWildcardsTypedInTheBoxAreLiteral(): void {
        // "100%_done" must find that text, not everything containing "100".
        $this->mapper->countShares(['pathSearch' => '100%_done']);

        $this->assertSame(
            ['f.path:%100\%\_done%', 's.label:%100\%\_done%'],
            $this->likes,
        );
    }

    public function testThePathFilterIsOnlyTheColumnItIsNamedAfter(): void {
        // The owner and recipient boxes are separate filters and stay so.
        $this->mapper->countShares(['pathSearch' => 'x', 'ownerSearch' => 'y']);

        $this->assertContains('f.path:%x%', $this->likes);
        $this->assertContains('s.label:%x%', $this->likes);
        $this->assertNotContains('s.label:%y%', $this->likes);
    }
}
