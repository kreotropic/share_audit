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
 * Two filters ShareMapper applies for callers of different standing:
 *
 *  - `exposure`: the shares of one exposure category — the share types that
 *    belong to it plus the Talk conversations that do — so that a category's
 *    "View" button opens the set its number was made of;
 *  - `hideRoomTokens`: for a caller who may not see a Talk conversation's token,
 *    which is what oc_share.share_with holds for those rows. Not being shown the
 *    token is not enough if the token can still be searched for, or sorted by,
 *    a substring at a time.
 */
class ShareMapperExposureAndTokenTest extends TestCase {

    private IDBConnection&MockObject $db;
    private ShareMapper $mapper;

    /** @var array<int, mixed[]> the conditions each andX() was given */
    private array $andGroups = [];

    /** @var array<int, mixed[]> the conditions each orX() was given */
    private array $orGroups = [];

    /** @var mixed[] every argument andWhere() was given, in order */
    private array $wheres = [];

    /** @var string[] every raw expression createFunction() was given */
    private array $functions = [];

    /** @var array<int, array{0: mixed, 1: string}> every orderBy()/addOrderBy() call */
    private array $orders = [];

    protected function setUp(): void {
        $this->andGroups = [];
        $this->orGroups = [];
        $this->wheres = [];
        $this->functions = [];
        $this->orders = [];
        $this->db = $this->createMock(IDBConnection::class);
        $this->db->method('escapeLikeParameter')->willReturnArgument(0);
        $this->mapper = new ShareMapper($this->db);
        $this->db->method('getQueryBuilder')->willReturn($this->recordingBuilder());
    }

    private function recordingBuilder(): IQueryBuilder {
        $expr = $this->createMock(IExpressionBuilder::class);
        foreach (['isNull', 'isNotNull', 'lt', 'lte', 'gt', 'gte', 'notIn'] as $method) {
            $expr->method($method)->willReturn('cond');
        }
        $expr->method('neq')->willReturnCallback(static fn ($column, $value) => "neq($column,$value)");
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
        $result->method('fetchAll')->willReturn([]);

        $qb = $this->createMock(IQueryBuilder::class);
        foreach (['select', 'selectAlias', 'from', 'leftJoin', 'setMaxResults', 'setFirstResult'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('andWhere')->willReturnCallback(function ($condition) use ($qb) {
            $this->wheres[] = $condition;
            return $qb;
        });
        foreach (['orderBy', 'addOrderBy'] as $method) {
            $qb->method($method)->willReturnCallback(function ($sort, $direction = 'ASC') use ($qb) {
                $this->orders[] = [$sort, $direction];
                return $qb;
            });
        }
        $qb->method('createFunction')->willReturnCallback(function (string $sql) {
            $this->functions[] = $sql;
            return $this->createMock(IQueryFunction::class);
        });
        $qb->method('createNamedParameter')->willReturnCallback(static fn ($value) => json_encode($value));
        $qb->method('expr')->willReturn($expr);
        $qb->method('func')->willReturn($func);
        $qb->method('executeQuery')->willReturn($result);
        return $qb;
    }

    // -------------------------------------------------------------------
    // hideRoomTokens: search
    // -------------------------------------------------------------------

    public function testTheGeneralSearchMatchesATokenByDefault(): void {
        $this->mapper->countShares(['search' => 'abc']);

        $this->assertSame([], $this->andGroups);
        $this->assertContains('like(s.share_with,"%abc%")', $this->orGroups[0]);
    }

    public function testTheGeneralSearchCannotMatchARoomsTokenWhenTokensAreHidden(): void {
        $this->mapper->countShares(['search' => 'abc', 'hideRoomTokens' => true]);

        $this->assertSame(
            [['neq(s.share_type,10)', 'like(s.share_with,"%abc%")']],
            $this->andGroups,
            'the recipient text match is only for rows that are not Talk shares',
        );
        $this->assertNotContains('like(s.share_with,"%abc%")', $this->orGroups[0], 'never on its own');
    }

    public function testTheRecipientSearchCannotMatchARoomsTokenWhenTokensAreHidden(): void {
        $this->mapper->countShares(['recipientSearch' => 'abc', 'recipientSearchIds' => ['abc-user'], 'hideRoomTokens' => true]);

        $this->assertSame([
            ['neq(s.share_type,10)', 'like(s.share_with,"%abc%")'],
            ['neq(s.share_type,10)', 'in(s.share_with,["abc-user"])'],
        ], $this->andGroups);
    }

    /**
     * A room is still found by what it is CALLED — that is the one way a
     * caller who may not see its token can look for it.
     */
    public function testARoomIsStillFoundByNameWhenTokensAreHidden(): void {
        $this->mapper->countShares([
            'recipientSearch' => 'market',
            'recipientSearchRooms' => ['iitqa25e'],
            'hideRoomTokens' => true,
        ]);

        $this->assertContains(['eq(s.share_type,10)', 'in(s.share_with,["iitqa25e"])'], $this->andGroups);
    }

    public function testTheRecipientSearchIsUnchangedWhenTokensAreNotHidden(): void {
        $this->mapper->countShares(['recipientSearch' => 'abc', 'recipientSearchIds' => ['abc-user']]);

        $this->assertSame([], $this->andGroups);
        $this->assertSame(['like(s.share_with,"%abc%")', 'in(s.share_with,["abc-user"])'], $this->orGroups[0]);
    }

    // -------------------------------------------------------------------
    // hideRoomTokens: sort
    // -------------------------------------------------------------------

    public function testSortingByRecipientOrdersByTheColumnByDefault(): void {
        $this->mapper->findShares([], 10, 0, 'recipient', 'asc');

        $this->assertContains(['s.share_with', 'ASC'], $this->orders);
    }

    public function testSortingByRecipientDoesNotOrderRoomsByTheirTokenWhenHidden(): void {
        $this->mapper->findShares(['hideRoomTokens' => true], 10, 0, 'recipient', 'asc');

        $this->assertNotContains(['s.share_with', 'ASC'], $this->orders, 'the raw column is not an order key');
        $this->assertContains(
            'CASE WHEN s.share_type = 10 THEN NULL ELSE s.share_with END',
            $this->functions,
            'a room counts as having no recipient value, like a link',
        );
    }

    public function testHidingTokensDoesNotChangeAnyOtherSort(): void {
        $this->mapper->findShares(['hideRoomTokens' => true], 10, 0, 'owner', 'asc');

        $this->assertContains(['s.uid_owner', 'ASC'], $this->orders);
    }

    // -------------------------------------------------------------------
    // exposure
    // -------------------------------------------------------------------

    public function testAnExposureCategoryMatchesItsShareTypesOrItsConversations(): void {
        $this->mapper->countShares(['exposure' => ['types' => [3], 'roomTokens' => ['pub12345']]]);

        $this->assertSame('in(s.share_type,[3])', $this->orGroups[0][0]);
        // The conversation half only ever applies to Talk shares: a token could equal somebody's uid.
        $this->assertContains(['eq(s.share_type,10)', 'in(s.share_with,["pub12345"])'], $this->andGroups);
        $this->assertCount(2, $this->orGroups[0]);
    }

    public function testAnExposureCategoryWithNoConversationsIsJustItsTypes(): void {
        $this->mapper->countShares(['exposure' => ['types' => [4, 6, 9], 'roomTokens' => []]]);

        $this->assertSame(['in(s.share_type,[4,6,9])'], $this->orGroups[0]);
        $this->assertSame([], $this->andGroups);
    }

    public function testAnExposureCategoryOfOnlyConversationsIsJustThose(): void {
        $this->mapper->countShares(['exposure' => ['types' => [], 'roomTokens' => ['pub12345']]]);

        $this->assertCount(1, $this->orGroups[0]);
        $this->assertSame([['eq(s.share_type,10)', 'in(s.share_with,["pub12345"])']], $this->andGroups);
    }

    /**
     * The category defined by what it is not: a type outside the ones that have a
     * category of their own, or a conversation that could not be resolved.
     */
    public function testTheOtherCategoryIsATypeOutsideTheKnownOnesOrAnUnresolvedConversation(): void {
        $this->mapper->countShares(['exposure' => ['types' => [], 'roomTokens' => ['stale123'], 'notTypes' => [0, 1, 3, 10]]]);

        $this->assertCount(2, $this->orGroups[0]);
        $this->assertSame('cond', $this->orGroups[0][0], 'share_type NOT IN the known types');
        $this->assertSame([['eq(s.share_type,10)', 'in(s.share_with,["stale123"])']], $this->andGroups);
    }

    public function testAnExposureCategoryThatContainsNothingMatchesNothing(): void {
        $this->mapper->countShares(['exposure' => ['types' => [], 'roomTokens' => []]]);

        $this->assertContains('1 = 0', $this->wheres, 'an empty category is an empty list, not "everything"');
    }

    public function testNumericTokensAreBoundAsStrings(): void {
        $this->mapper->countShares(['exposure' => ['types' => [], 'roomTokens' => [23456789]]]);

        $this->assertSame([['eq(s.share_type,10)', 'in(s.share_with,["23456789"])']], $this->andGroups);
    }

    public function testNoExposureFilterAddsNoCondition(): void {
        $this->mapper->countShares(['exposure' => null]);
        $this->mapper->countShares([]);

        $this->assertSame([], $this->orGroups);
        $this->assertNotContains('1 = 0', $this->wheres);
    }
}
