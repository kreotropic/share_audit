<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Tests\Unit;

use OCA\ShareAuditDashboard\Service\DisplayNameResolver;
use OCA\ShareAuditDashboard\Service\RecipientDetailsResolver;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The two searches that turn a typed name into tokens and card ids. They run
 * their own queries (the filter needs the ids before the list is fetched), so
 * this drives a recording query builder rather than stubbed reads.
 */
class RecipientDetailsResolverSearchTest extends TestCase {

    /** @var array<int, mixed> the values bound as query parameters, in order */
    private array $bound = [];

    /** @var string[] the conditions built, as text */
    private array $conditions = [];

    /** @var string[] every column the query was ordered by */
    private array $orders = [];

    private IDBConnection&MockObject $db;
    private LoggerInterface&MockObject $logger;
    private RecipientDetailsResolver $resolver;

    protected function setUp(): void {
        $this->bound = [];
        $this->conditions = [];
        $this->orders = [];
        $this->db = $this->createMock(IDBConnection::class);
        $this->db->method('escapeLikeParameter')->willReturnCallback(
            static fn (string $value) => addcslashes($value, '%_\\'),
        );
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->resolver = new RecipientDetailsResolver($this->db, $this->createMock(DisplayNameResolver::class), $this->logger);
    }

    /**
     * @param string[] $found what the query returns, one value per row
     */
    private function queryReturns(array $found): void {
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('iLike')->willReturnCallback(function (string $column, string $param) {
            $this->conditions[] = "like($column)";
            return "like($column)";
        });
        $expr->method('in')->willReturnCallback(function (string $column) {
            $this->conditions[] = "in($column)";
            return "in($column)";
        });
        $expr->method('eq')->willReturn('eq');
        $expr->method('orX')->willReturnCallback(function (...$parts) {
            $this->conditions[] = 'or';
            return $this->createMock(ICompositeExpression::class);
        });

        $rows = $found;
        $result = $this->createMock(IResult::class);
        $result->method('fetchOne')->willReturnCallback(static function () use (&$rows) {
            return $rows === [] ? false : array_shift($rows);
        });

        $qb = $this->createMock(IQueryBuilder::class);
        foreach (['select', 'from', 'innerJoin', 'where', 'andWhere', 'setMaxResults'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('orderBy')->willReturnCallback(function (string $column) use ($qb) {
            $this->orders[] = $column;
            return $qb;
        });
        $qb->method('createNamedParameter')->willReturnCallback(function ($value) {
            $this->bound[] = $value;
            return ':p' . count($this->bound);
        });
        $qb->method('expr')->willReturn($expr);
        $qb->method('executeQuery')->willReturn($result);
        $this->db->method('getQueryBuilder')->willReturn($qb);
    }

    public function testAConversationIsFoundByItsName(): void {
        $this->queryReturns(['iitqa25e', 'abcd1234']);

        $this->assertSame(['iitqa25e', 'abcd1234'], $this->resolver->searchRoomTokens('market'));
    }

    public function testOnlyGroupAndPublicConversationsAreSearched(): void {
        $this->queryReturns([]);

        $this->resolver->searchRoomTokens('market');

        // A one-to-one's "name" is a list of account ids: nobody types that.
        $this->assertContains([2, 3], $this->bound);
    }

    /**
     * The search is capped, so what it orders by decides which conversations
     * are found at all when many match — and a caller who may not see tokens
     * gets the result. Ordered by token, the cut-off is a comparison against a
     * token; ordered by the (public) id it says nothing about one.
     */
    public function testTheCutOffOfTheConversationSearchDoesNotFollowTheToken(): void {
        $this->queryReturns([]);

        $this->resolver->searchRoomTokens('market');

        $this->assertSame(['id'], $this->orders);
    }

    public function testTheTypedNameIsMatchedLiterally(): void {
        $this->queryReturns([]);

        $this->resolver->searchRoomTokens('100%_done');

        $this->assertSame('%100\%\_done%', $this->bound[0]);
    }

    public function testACardIsFoundByItsOwnTitleOrByItsBoards(): void {
        $this->queryReturns([6, 9]);

        $this->assertSame(['6', '9'], $this->resolver->searchCardIds('alfa'));
        $this->assertSame(['like(c.title)', 'like(b.title)', 'or'], $this->conditions);
    }

    public function testNothingIsSearchedForAnEmptyTerm(): void {
        $this->db->expects($this->never())->method('getQueryBuilder');

        $this->assertSame([], $this->resolver->searchRoomTokens(''));
        $this->assertSame([], $this->resolver->searchCardIds(''));
    }

    public function testATalkThatCannotBeSearchedFindsNothingInsteadOfFailing(): void {
        $this->db->method('getQueryBuilder')->willThrowException(new \RuntimeException('no such table'));
        $this->logger->expects($this->exactly(2))->method('debug');

        $this->assertSame([], $this->resolver->searchRoomTokens('x'));
        $this->assertSame([], $this->resolver->searchCardIds('x'));
    }
}
