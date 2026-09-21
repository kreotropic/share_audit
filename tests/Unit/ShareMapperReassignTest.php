<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Tests\Unit;

use OCA\ShareAuditDashboard\Db\ShareMapper;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers ShareMapper::reassignOwner(), the one write in the mapper: which rows
 * it touches, and that it touches none of them unless the share still belongs
 * to the owner it was read with.
 */
class ShareMapperReassignTest extends TestCase {

    private IDBConnection&MockObject $db;
    private ShareMapper $mapper;

    /** @var array<int, array{table: string, sets: array<string, mixed>, wheres: array<int, string>}> */
    private array $updates = [];

    /** @var int[] rows each successive UPDATE reports as changed */
    private array $affected = [];

    protected function setUp(): void {
        $this->db = $this->createMock(IDBConnection::class);
        $this->mapper = new ShareMapper($this->db);
        $this->updates = [];
        $this->affected = [];

        $this->db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());
    }

    /**
     * A query builder that records the UPDATE it is given instead of running it,
     * or fails when it is asked to run it if $failWith is given.
     */
    private function recordingBuilder(?\Throwable $failWith = null): IQueryBuilder {
        $index = count($this->updates);
        $this->updates[$index] = ['table' => '', 'sets' => [], 'wheres' => []];

        // Conditions render as "column=value" so a test can read them back.
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturnCallback(static fn ($column, $value) => $column . '=' . $value);

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('expr')->willReturn($expr);
        // The parameter is recorded as its own value, so `parent=7` reads plainly.
        $qb->method('createNamedParameter')->willReturnArgument(0);
        $qb->method('update')->willReturnCallback(function (string $table) use ($index, $qb) {
            $this->updates[$index]['table'] = $table;
            return $qb;
        });
        $qb->method('set')->willReturnCallback(function (string $column, $value) use ($index, $qb) {
            $this->updates[$index]['sets'][$column] = $value;
            return $qb;
        });
        $qb->method('where')->willReturnCallback(function (string $condition) use ($index, $qb) {
            $this->updates[$index]['wheres'][] = $condition;
            return $qb;
        });
        $qb->method('andWhere')->willReturnCallback(function (string $condition) use ($index, $qb) {
            $this->updates[$index]['wheres'][] = $condition;
            return $qb;
        });
        $qb->method('executeStatement')->willReturnCallback(function () use ($failWith) {
            if ($failWith !== null) {
                throw $failWith;
            }
            return array_shift($this->affected) ?? 1;
        });
        return $qb;
    }

    public function testTheOwnerTheCreatorAndTheGroupRowsAllMove(): void {
        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->once())->method('commit');
        $this->db->expects($this->never())->method('rollBack');

        $this->assertTrue($this->mapper->reassignOwner(7, 'gone', 'dana'));

        $this->assertCount(4, $this->updates);
        foreach ($this->updates as $update) {
            $this->assertSame('share', $update['table']);
        }
        // The share itself: owner, then creator — each only where it was 'gone'.
        $this->assertSame(['uid_owner' => 'dana'], $this->updates[0]['sets']);
        $this->assertSame(['id=7', 'uid_owner=gone'], $this->updates[0]['wheres']);
        $this->assertSame(['uid_initiator' => 'dana'], $this->updates[1]['sets']);
        $this->assertSame(['id=7', 'uid_initiator=gone'], $this->updates[1]['wheres']);
        // The per-user rows of a group share, which point back at it.
        $usergroup = 'share_type=' . IShare::TYPE_USERGROUP;
        $this->assertSame(['uid_owner' => 'dana'], $this->updates[2]['sets']);
        $this->assertSame(['parent=7', $usergroup, 'uid_owner=gone'], $this->updates[2]['wheres']);
        $this->assertSame(['uid_initiator' => 'dana'], $this->updates[3]['sets']);
        $this->assertSame(['parent=7', $usergroup, 'uid_initiator=gone'], $this->updates[3]['wheres']);
    }

    public function testAShareThatChangedOwnerMeanwhileIsLeftAlone(): void {
        // The owner UPDATE finds no row still owned by 'gone'.
        $this->affected = [0];
        $this->db->expects($this->once())->method('commit');
        $this->db->expects($this->never())->method('rollBack');

        $this->assertFalse($this->mapper->reassignOwner(7, 'gone', 'dana'));

        $this->assertCount(1, $this->updates, 'nothing else may be touched');
    }

    public function testAFailurePartWayRollsEverythingBack(): void {
        // Owner and creator changed; the group rows blow up.
        $calls = 0;
        $db = $this->createMock(IDBConnection::class);
        $db->expects($this->once())->method('beginTransaction');
        $db->expects($this->once())->method('rollBack');
        $db->expects($this->never())->method('commit');
        // By reference: an arrow function would count on a copy and never reach 3.
        $db->method('getQueryBuilder')->willReturnCallback(
            function () use (&$calls) {
                return $this->recordingBuilder(++$calls === 3 ? new \RuntimeException('deadlock') : null);
            },
        );

        $this->expectException(\RuntimeException::class);
        (new ShareMapper($db))->reassignOwner(7, 'gone', 'dana');
    }
}
