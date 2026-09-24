<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Tests\Integration;

use OCA\ShareAuditDashboard\Db\FileMove;
use OCA\ShareAuditDashboard\Db\FileMoveMapper;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The file-move queue (migration Version0009, FileMoveMapper) on the real
 * database: the table the migration made takes what the entity writes, and the
 * two updates that decide whether a queued move can run twice or block its
 * account for good do what the unit tests assume the database does.
 */
final class FileMoveQueueTest extends TestCase {

    private const SOURCE = 'sai_queue_source';

    private FileMoveMapper $mapper;

    protected function setUp(): void {
        $this->mapper = Server::get(FileMoveMapper::class);
        $this->cleanUp();
    }

    protected function tearDown(): void {
        $this->cleanUp();
    }

    private function cleanUp(): void {
        Server::get(IDBConnection::class)->executeStatement(
            'DELETE FROM *PREFIX*shareaudit_filemove WHERE source_uid = ?',
            [self::SOURCE],
        );
    }

    private function queue(?string $path, string $scope = 'path', string $target = 'sai_queue_target', int $createdAt = 1_800_000_000): FileMove {
        $move = new FileMove();
        $move->setSourceUid(self::SOURCE);
        $move->setTargetUid($target);
        $move->setScope($scope);
        $move->setPath($path);
        $move->setStatus(FileMoveMapper::STATUS_QUEUED);
        $move->setShareCount(3);
        $move->setCreatedAt($createdAt);
        return $this->mapper->insert($move);
    }

    public function testARowRoundTripsIncludingAnAccountMoveWithoutAPath(): void {
        $folder = $this->queue('Projects/2024');
        $account = $this->queue(null, 'account');

        $found = $this->mapper->find((int)$folder->getId());
        $this->assertSame('Projects/2024', $found->getPath());
        $this->assertSame(3, $found->getShareCount());
        $this->assertNull($found->getRequestedBy());
        $this->assertNull($found->getStartedAt());

        $this->assertNull($this->mapper->find((int)$account->getId())->getPath());
    }

    public function testOnlyOneOfSeveralClaimsWins(): void {
        $id = (int)$this->queue('Docs')->getId();

        $this->assertSame(FileMoveMapper::CLAIM_OK, $this->mapper->claim($id, 'sai_queue_target', 1_800_000_100));
        $this->assertSame(
            FileMoveMapper::CLAIM_TAKEN,
            $this->mapper->claim($id, 'sai_queue_target', 1_800_000_101),
            'a move already taken cannot be taken again',
        );

        $claimed = $this->mapper->find($id);
        $this->assertSame(FileMoveMapper::STATUS_RUNNING, $claimed->getStatus());
        $this->assertSame(1_800_000_100, $claimed->getStartedAt(), 'the first claim is the one that counts');
    }

    public function testAFinishedMoveCannotBeClaimedAgain(): void {
        $id = (int)$this->queue('Docs')->getId();
        $this->mapper->claim($id, 'sai_queue_target', 1_800_000_100);
        $this->mapper->finish($id, FileMoveMapper::STATUS_DONE, null, 1_800_000_200);

        $this->assertSame(FileMoveMapper::CLAIM_TAKEN, $this->mapper->claim($id, 'sai_queue_target', 1_800_000_300));
        $done = $this->mapper->find($id);
        $this->assertSame(FileMoveMapper::STATUS_DONE, $done->getStatus());
        $this->assertNull($done->getError());
        $this->assertSame(1_800_000_200, $done->getFinishedAt());
    }

    public function testAFailureKeepsItsMessage(): void {
        $id = (int)$this->queue('Docs')->getId();
        $this->mapper->claim($id, 'sai_queue_target', 1_800_000_100);
        $this->mapper->finish($id, FileMoveMapper::STATUS_FAILED, 'Target user does not have enough free space available.', 1_800_000_200);

        $failed = $this->mapper->find($id);
        $this->assertSame(FileMoveMapper::STATUS_FAILED, $failed->getStatus());
        $this->assertSame('Target user does not have enough free space available.', $failed->getError());
    }

    public function testOnlyMovesThatAreStillOpenCountAsActive(): void {
        $queued = $this->queue('A');
        $running = $this->queue('B', 'path', 'sai_queue_target_b');
        $done = $this->queue('C', 'path', 'sai_queue_target_c');
        $this->mapper->claim((int)$running->getId(), 'sai_queue_target_b', 1_800_000_100);
        $this->mapper->claim((int)$done->getId(), 'sai_queue_target_c', 1_800_000_100);
        $this->mapper->finish((int)$done->getId(), FileMoveMapper::STATUS_DONE, null, 1_800_000_200);

        $active = array_map(
            static fn (FileMove $m) => $m->getPath(),
            $this->mapper->findActiveForSources([self::SOURCE, 'somebody_else']),
        );
        sort($active);

        $this->assertSame(['A', 'B'], $active);
        $this->assertSame([], $this->mapper->findActiveForSources([]));
        $this->assertNotNull($queued->getId());
    }

    public function testARunOverdueIsGivenUpOnAndAFreshOneIsNot(): void {
        $overdue = (int)$this->queue('Old', 'path', 'sai_queue_target_a')->getId();
        $fresh = (int)$this->queue('New', 'path', 'sai_queue_target_b')->getId();
        $queued = (int)$this->queue('Waiting')->getId();
        $this->mapper->claim($overdue, 'sai_queue_target_a', 1_800_000_000);
        $this->mapper->claim($fresh, 'sai_queue_target_b', 1_800_050_000);

        $failed = $this->mapper->failStale(1_800_020_000, 'interrupted', 1_800_060_000);

        $this->assertGreaterThanOrEqual(1, $failed);
        $this->assertSame(FileMoveMapper::STATUS_FAILED, $this->mapper->find($overdue)->getStatus());
        $this->assertSame('interrupted', $this->mapper->find($overdue)->getError());
        $this->assertSame(FileMoveMapper::STATUS_RUNNING, $this->mapper->find($fresh)->getStatus());
        $this->assertSame(FileMoveMapper::STATUS_QUEUED, $this->mapper->find($queued)->getStatus(), 'one not started has no worker to lose');
    }

    /**
     * The Files app names the folder it moves into after the second, and a move
     * onto a name that exists deletes what was there, so two moves into one
     * account must never run together. The database is what refuses: a second
     * running row for the same account violates a unique index, whichever worker
     * or process asks.
     */
    public function testOnlyOneMoveToAnAccountCanRunAtATime(): void {
        $first = (int)$this->queue('A')->getId();
        $second = (int)$this->queue('B')->getId();
        $elsewhere = (int)$this->queue('C', 'path', 'sai_queue_target_other')->getId();

        $this->assertSame(FileMoveMapper::CLAIM_OK, $this->mapper->claim($first, 'sai_queue_target', 1_800_000_100));
        $this->assertSame(
            FileMoveMapper::CLAIM_BUSY,
            $this->mapper->claim($second, 'sai_queue_target', 1_800_000_101),
            'the account is already receiving a move',
        );
        $this->assertSame(FileMoveMapper::STATUS_QUEUED, $this->mapper->find($second)->getStatus(), 'the refused move is still waiting');
        $this->assertSame(
            FileMoveMapper::CLAIM_OK,
            $this->mapper->claim($elsewhere, 'sai_queue_target_other', 1_800_000_102),
            'a move into another account is not held up',
        );

        $this->mapper->finish($first, FileMoveMapper::STATUS_DONE, null, 1_800_000_200);

        $this->assertSame(FileMoveMapper::CLAIM_OK, $this->mapper->claim($second, 'sai_queue_target', 1_800_000_201), 'free once the first is over');
    }

    public function testANeverEndingMoveIsGivenUpOnAndFreesItsAccount(): void {
        $dead = (int)$this->queue('Dead')->getId();
        $waiting = (int)$this->queue('Waiting')->getId();
        $this->mapper->claim($dead, 'sai_queue_target', 1_800_000_000);
        $this->assertSame(FileMoveMapper::CLAIM_BUSY, $this->mapper->claim($waiting, 'sai_queue_target', 1_800_090_000));

        $this->mapper->failStale(1_800_050_000, 'interrupted', 1_800_090_000);

        $this->assertSame(FileMoveMapper::CLAIM_OK, $this->mapper->claim($waiting, 'sai_queue_target', 1_800_090_001));
    }

    public function testTheNewestComeFirst(): void {
        $first = (int)$this->queue('First')->getId();
        $second = (int)$this->queue('Second')->getId();

        $ids = array_map(static fn (FileMove $m) => (int)$m->getId(), $this->mapper->findRecent(2));

        $this->assertSame([$second, $first], $ids);
    }
}
