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
 * Covers the "Recipient" column filter of the All shares table for Talk and
 * Deck. A conversation is shown by its name, so typing that name has to find its
 * shares — but the ids that match are a token and a card number, which could
 * just as well be somebody's uid. They must therefore only ever match the type
 * of share they belong to.
 */
class ShareMapperRecipientSearchTest extends TestCase {

    private IDBConnection&MockObject $db;
    private ShareMapper $mapper;

    /** @var array<int, string[]> the conditions each andX() was given */
    private array $andGroups = [];

    /** @var array<int, mixed[]> the conditions each orX() was given */
    private array $orGroups = [];

    protected function setUp(): void {
        $this->db = $this->createMock(IDBConnection::class);
        $this->db->method('escapeLikeParameter')->willReturnArgument(0);
        $this->mapper = new ShareMapper($this->db);
        $this->andGroups = [];
        $this->orGroups = [];
        $this->db->method('getQueryBuilder')->willReturn($this->recordingBuilder());
    }

    private function recordingBuilder(): IQueryBuilder {
        $expr = $this->createMock(IExpressionBuilder::class);
        foreach (['neq', 'isNull', 'isNotNull', 'lt', 'lte', 'gt', 'gte', 'notIn'] as $method) {
            $expr->method($method)->willReturn('cond');
        }
        // Conditions render as text with their bound values, so a test can read them.
        $expr->method('eq')->willReturnCallback(static fn ($column, $value) => "eq($column,$value)");
        $expr->method('in')->willReturnCallback(static fn ($column, $value) => "in($column,$value)");
        $expr->method('iLike')->willReturnCallback(static fn ($column, $value) => "like($column,$value)");
        $expr->method('andX')->willReturnCallback(function (...$parts) {
            $this->andGroups[] = $parts;
            return $this->createMock(ICompositeExpression::class);
        });
        $expr->method('orX')->willReturnCallback(function (...$parts) {
            $this->orGroups[] = $parts;
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
        $qb->method('createNamedParameter')->willReturnCallback(static fn ($value) => json_encode($value));
        $qb->method('expr')->willReturn($expr);
        $qb->method('func')->willReturn($func);
        $qb->method('executeQuery')->willReturn($result);
        return $qb;
    }

    public function testAConversationFoundByNameMatchesOnlyRoomShares(): void {
        $this->mapper->countShares(['recipientSearch' => 'market', 'recipientSearchRooms' => ['iitqa25e']]);

        $this->assertSame(
            [['eq(s.share_type,10)', 'in(s.share_with,["iitqa25e"])']],
            $this->andGroups,
            'a token is only a Talk recipient when the share is a Talk share',
        );
    }

    public function testACardFoundByTitleMatchesOnlyDeckShares(): void {
        $this->mapper->countShares(['recipientSearch' => 'alfa', 'recipientSearchCards' => ['6']]);

        $this->assertSame([['eq(s.share_type,12)', 'in(s.share_with,["6"])']], $this->andGroups);
    }

    public function testCardNumbersAreMatchedAsTheStringsTheColumnHolds(): void {
        // share_with is text: an int list would compare 6 with '6' differently per database.
        $this->mapper->countShares(['recipientSearch' => 'alfa', 'recipientSearchCards' => [6, 9]]);

        $this->assertSame([['eq(s.share_type,12)', 'in(s.share_with,["6","9"])']], $this->andGroups);
    }

    public function testTheNameSearchIsAnAlternativeToTheExistingOnes(): void {
        $this->mapper->countShares([
            'recipientSearch' => 'mark',
            'recipientSearchIds' => ['mark.twain'],
            'recipientSearchRooms' => ['iitqa25e'],
            'recipientSearchCards' => ['6'],
        ]);

        // The stored value, an account's name, a conversation and a card: any of them.
        $this->assertCount(1, $this->orGroups);
        $this->assertCount(4, $this->orGroups[0]);
        $this->assertSame('like(s.share_with,"%mark%")', $this->orGroups[0][0]);
    }

    public function testWithNoConversationOrCardFoundNothingIsAdded(): void {
        $this->mapper->countShares([
            'recipientSearch' => 'mark',
            'recipientSearchRooms' => [],
            'recipientSearchCards' => [],
        ]);

        $this->assertSame([], $this->andGroups);
        $this->assertCount(1, $this->orGroups[0]);
    }

    public function testWithoutARecipientSearchTheseKeysDoNothing(): void {
        // They only refine a search that was asked for.
        $this->mapper->countShares(['recipientSearchRooms' => ['iitqa25e'], 'recipientSearchCards' => ['6']]);

        $this->assertSame([], $this->andGroups);
        $this->assertSame([], $this->orGroups);
    }
}
