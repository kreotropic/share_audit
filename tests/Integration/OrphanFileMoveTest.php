<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Tests\Integration;

use OCA\ShareAuditDashboard\BackgroundJob\OrphanFileMoveJob;
use OCA\ShareAuditDashboard\Db\FileMoveMapper;
use OCA\ShareAuditDashboard\Service\OrphanFileMoveService;
use OCA\ShareAuditDashboard\Service\WorkerLock;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Server;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;

/**
 * Moving a disabled account's files to another account, end to end and through
 * the Files app's real ownership transfer: the files change hands, the shares
 * of them come along with the same id and the same link token, and the link
 * serves the file again — which is the whole point, since a link owned by a
 * disabled account is dead.
 *
 * The background job is not needed here: the service is called the way the job
 * calls it.
 */
final class OrphanFileMoveTest extends TestCase {

    private const LEAVER = 'sai_leaver';
    private const TAKER = 'sai_taker';

    private static IUserManager $users;
    private OrphanFileMoveService $service;
    private FileMoveMapper $moves;

    public static function setUpBeforeClass(): void {
        Fixtures::boot();
        self::$users = Server::get(IUserManager::class);
        foreach ([self::LEAVER, self::TAKER] as $uid) {
            if (!self::$users->userExists($uid)) {
                self::$users->createUser($uid, Fixtures::PASSWORD);
            }
        }
    }

    protected function setUp(): void {
        $this->service = Server::get(OrphanFileMoveService::class);
        $this->moves = Server::get(FileMoveMapper::class);
        $this->cleanUp();
        self::$users->get(self::LEAVER)->setEnabled(true);
    }

    protected function tearDown(): void {
        $this->cleanUp();
        self::$users->get(self::LEAVER)->setEnabled(true);
    }

    private function cleanUp(): void {
        $db = Server::get(IDBConnection::class);
        $db->executeStatement('DELETE FROM *PREFIX*share WHERE uid_owner IN (?, ?)', [self::LEAVER, self::TAKER]);
        $db->executeStatement('DELETE FROM *PREFIX*shareaudit_filemove WHERE source_uid = ?', [self::LEAVER]);
        foreach ([self::LEAVER, self::TAKER] as $uid) {
            \OC_Util::setupFS($uid);
            $home = Server::get(IRootFolder::class)->getUserFolder($uid);
            foreach ($home->getDirectoryListing() as $node) {
                $node->delete();
            }
        }
    }

    private function leaverHome(): Folder {
        \OC_Util::setupFS(self::LEAVER);
        return Server::get(IRootFolder::class)->getUserFolder(self::LEAVER);
    }

    /** A file at $path (folders are made as needed) in the leaver's home. */
    private function leaverFile(string $path, string $content): File {
        $folder = $this->leaverHome();
        $parts = explode('/', $path);
        $name = array_pop($parts);
        foreach ($parts as $part) {
            $folder = $folder->nodeExists($part) ? $folder->get($part) : $folder->newFolder($part);
        }
        return $folder->newFile($name, $content);
    }

    private function link(File $file): IShare {
        $manager = Server::get(IManager::class);
        $share = $manager->newShare();
        $share->setNode($file)
            ->setShareType(IShare::TYPE_LINK)
            ->setSharedBy(self::LEAVER)
            ->setShareOwner(self::LEAVER)
            ->setPermissions(1);
        return $manager->createShare($share);
    }

    private function leaves(): void {
        self::$users->get(self::LEAVER)->setEnabled(false);
    }

    /**
     * @return array{id: int, owner: string, initiator: string}
     */
    private function shareState(int $id): array {
        $row = Fixtures::shareRow($id);
        $this->assertNotNull($row, "share $id still exists");
        return ['id' => (int)$row['id'], 'owner' => (string)$row['uid_owner'], 'initiator' => (string)$row['uid_initiator']];
    }

    /**
     * Queue, then run what was queued the way the background job does.
     *
     * @param int[] $shareIds
     * @return array{queued: array<int, array<string, mixed>>, skipped: array<int, array<string, mixed>>}
     */
    private function moveFiles(array $shareIds, string $scope): array {
        $result = $this->service->enqueue($shareIds, self::TAKER, $scope);
        $jobs = Server::get(IJobList::class);
        foreach ($result['queued'] as $queued) {
            $this->service->run($queued['id']);
            $jobs->remove(OrphanFileMoveJob::class, ['id' => $queued['id']]);
        }
        return $result;
    }

    /** Every file called $name anywhere in the taker's home, with its content. */
    private function takerCopiesOf(string $name): array {
        \OC_Util::setupFS(self::TAKER);
        $found = [];
        $walk = function (Folder $folder) use (&$walk, &$found, $name): void {
            foreach ($folder->getDirectoryListing() as $node) {
                if ($node instanceof Folder) {
                    $walk($node);
                } elseif ($node->getName() === $name) {
                    $found[] = $node->getContent();
                }
            }
        };
        $walk(Server::get(IRootFolder::class)->getUserFolder(self::TAKER));
        sort($found);
        return $found;
    }

    /**
     * A link whose owner is disabled does not serve; once the files and the
     * share are the new owner's, the same URL does.
     */
    private function assertLinkServes(string $token, string $content): void {
        $share = Server::get(IManager::class)->getShareByToken($token);
        $this->assertSame(self::TAKER, $share->getShareOwner());
        $this->assertSame($content, $share->getNode()->getContent(), 'the link serves the file that moved');
    }

    public function testALinkOfADisabledOwnersFileWorksAgainAfterTheFileMoves(): void {
        $file = $this->leaverFile('Projects/Deep/report.txt', 'the report');
        $share = $this->link($file);
        $id = (int)$share->getId();
        $token = $share->getToken();
        $this->leaves();
        try {
            Server::get(IManager::class)->getShareByToken($token);
            $this->fail('a link of a disabled owner should not serve');
        } catch (ShareNotFound) {
            // The orphan: exactly what this feature is for.
        }

        $result = $this->moveFiles([$id], OrphanFileMoveService::SCOPE_PATH);

        $this->assertSame([], $result['skipped']);
        $this->assertSame(['Projects/Deep/report.txt'], array_column($result['queued'], 'path'));
        $move = $this->moves->findRecent(1)[0];
        $this->assertSame(FileMoveMapper::STATUS_DONE, $move->getStatus(), (string)$move->getError());

        $state = $this->shareState($id);
        $this->assertSame(self::TAKER, $state['owner']);
        $this->assertSame(self::TAKER, $state['initiator']);
        $this->assertSame($token, Fixtures::shareRow($id)['token'], 'the link keeps its URL');
        $this->assertLinkServes($token, 'the report');
        $this->assertFalse($this->leaverHome()->nodeExists('Projects/Deep/report.txt'), 'the file left the leaver');
        $this->assertSame(['the report'], $this->takerCopiesOf('report.txt'));
    }

    /**
     * Each move puts its files in a folder named after the second it started,
     * and moving onto a name that exists deletes what was there. Two moves in a
     * row for the same new owner must not share a folder.
     */
    public function testTwoFilesWithTheSameNameDoNotEraseEachOther(): void {
        $a = $this->link($this->leaverFile('A/dup.txt', 'from A'));
        $b = $this->link($this->leaverFile('B/dup.txt', 'from B'));
        $this->leaves();

        $result = $this->moveFiles([(int)$a->getId(), (int)$b->getId()], OrphanFileMoveService::SCOPE_PATH);

        $this->assertCount(2, $result['queued'], 'two folders, two moves');
        $this->assertSame(['from A', 'from B'], $this->takerCopiesOf('dup.txt'), 'both files survived');
        $this->assertLinkServes($a->getToken(), 'from A');
        $this->assertLinkServes($b->getToken(), 'from B');
    }

    /**
     * Nextcloud runs background jobs in as many processes as the admin starts, so
     * "one after the other" cannot be assumed. Here two real, separate workers
     * are made to start the moves at the same instant: the second must find the
     * account busy and back off — not run alongside, which would put both files in
     * one destination folder and let the second erase the first — and still get
     * its turn later.
     */
    public function testTwoWorkersMovingIntoTheSameAccountAtTheSameTimeDoNotEraseEachOther(): void {
        $a = $this->link($this->leaverFile('A/report.txt', 'from A'));
        $b = $this->link($this->leaverFile('B/report.txt', 'from B'));
        $this->leaves();
        $moves = array_column(
            $this->service->enqueue([(int)$a->getId(), (int)$b->getId()], self::TAKER, OrphanFileMoveService::SCOPE_PATH)['queued'],
            'id',
        );
        $this->assertCount(2, $moves);

        // Long enough for both processes to boot; the same instant for both.
        $startAt = ceil(microtime(true)) + 5.4;
        $workers = [];
        foreach ($moves as $id) {
            $process = proc_open(
                [PHP_BINARY, __DIR__ . '/move-worker.php', (string)$id, sprintf('%.3f', $startAt)],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            $this->assertIsResource($process);
            $workers[] = [$process, $pipes];
        }
        $output = [];
        foreach ($workers as [$process, $pipes]) {
            $output[] = trim((string)stream_get_contents($pipes[1])) . ' ' . trim((string)stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }

        $statuses = array_map(fn (int $id) => $this->moves->find($id)->getStatus(), $moves);
        sort($statuses);
        $this->assertSame(
            [FileMoveMapper::STATUS_DONE, FileMoveMapper::STATUS_QUEUED],
            $statuses,
            'one worker moved, the other found the account busy and stayed queued. Workers said: ' . implode(' | ', $output),
        );

        // The one that backed off asked to be run again, a little later.
        $jobs = Server::get(IJobList::class);
        $waiting = array_values(array_filter($moves, fn (int $id) => $this->moves->find($id)->getStatus() === FileMoveMapper::STATUS_QUEUED))[0];
        $this->assertTrue($jobs->has(OrphanFileMoveJob::class, ['id' => $waiting]), 'the busy move put itself back in the job list');
        $jobs->remove(OrphanFileMoveJob::class, ['id' => $waiting]);

        // That later run: the account is free again.
        $this->service->run($waiting);

        foreach ($moves as $id) {
            $move = $this->moves->find($id);
            $this->assertSame(FileMoveMapper::STATUS_DONE, $move->getStatus(), (string)$move->getError());
        }
        $this->assertSame(['from A', 'from B'], $this->takerCopiesOf('report.txt'), 'both files survived');
        $this->assertLinkServes($a->getToken(), 'from A');
        $this->assertLinkServes($b->getToken(), 'from B');
    }

    // -------------------------------------------------------------------
    // A worker that dies, and one that is only slow
    //
    // A move marked "running" holds its receiving account. If its worker is
    // gone, something has to free the account; but no length of time tells a
    // dead worker from one moving a very large folder, and a second move into
    // the account while the first still writes to it is the data loss the lock
    // exists to prevent. So a move is only given up on when its worker is known
    // to be dead — and these use real processes, killed with SIGKILL.
    // -------------------------------------------------------------------

    /**
     * Two queued moves into the taker: A/x.txt first, B/y.txt second.
     *
     * @return array{0: int, 1: int, 2: IShare, 3: IShare} the two move ids and the two shares
     */
    private function twoQueuedMoves(): array {
        $a = $this->link($this->leaverFile('A/x.txt', 'from A'));
        $b = $this->link($this->leaverFile('B/y.txt', 'from B'));
        $this->leaves();
        $queued = $this->service->enqueue([(int)$a->getId(), (int)$b->getId()], self::TAKER, OrphanFileMoveService::SCOPE_PATH)['queued'];
        $this->assertCount(2, $queued);
        return [$queued[0]['id'], $queued[1]['id'], $a, $b];
    }

    /**
     * A worker in the middle of a move: it holds the worker's lock and has claimed
     * the move, and otherwise does nothing.
     *
     * @return array{0: resource, 1: array<int, resource>}
     */
    private function startHolder(int $moveId): array {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/move-holder.php', (string)$moveId, self::TAKER],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);
        stream_set_timeout($pipes[1], 60);
        $line = trim((string)fgets($pipes[1]));
        if ($line !== 'held') {
            proc_terminate($process, 9);
            $this->fail('the holder did not take the move: ' . $line);
        }
        return [$process, $pipes];
    }

    /** @param array{0: resource, 1: array<int, resource>} $holder */
    private function kill(array $holder): void {
        [$process, $pipes] = $holder;
        proc_terminate($process, 9);   // SIGKILL: nothing runs, nothing is cleaned up
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
    }

    private function dropJob(int $moveId): void {
        Server::get(IJobList::class)->remove(OrphanFileMoveJob::class, ['id' => $moveId]);
    }

    public function testAWorkerThatWasKilledIsRecoveredAndTheNextMoveGetsTheAccount(): void {
        [$first, $second, , ] = $this->twoQueuedMoves();
        $holder = $this->startHolder($first);
        $lock = Server::get(WorkerLock::class);
        $this->assertSame(FileMoveMapper::STATUS_RUNNING, $this->moves->find($first)->getStatus());
        $this->assertSame(WorkerLock::ALIVE, $lock->state($first), 'while it runs, its lock is held');

        $this->kill($holder);

        $this->assertSame(WorkerLock::DEAD, $lock->state($first), 'killed: the operating system dropped the lock, the file remains');
        $this->service->run($second);

        $recovered = $this->moves->find($first);
        $this->assertSame(FileMoveMapper::STATUS_FAILED, $recovered->getStatus());
        $this->assertSame(OrphanFileMoveService::ERROR_INTERRUPTED, $recovered->getError());
        $next = $this->moves->find($second);
        $this->assertSame(FileMoveMapper::STATUS_DONE, $next->getStatus(), (string)$next->getError());
        $this->assertSame(['from B'], $this->takerCopiesOf('y.txt'));
        $this->assertSame(WorkerLock::UNKNOWN, $lock->state($first), 'the dead worker\'s file was cleaned up');
        $this->dropJob($first);
        $this->dropJob($second);
    }

    /**
     * The regression this exists for: the move had been running for three days,
     * which an age limit would have called dead — but its worker is alive.
     */
    public function testAWorkerThatIsStillAliveIsNeverGivenUpOnHoweverLongItHasBeenRunning(): void {
        [$first, $second] = $this->twoQueuedMoves();
        $holder = $this->startHolder($first);
        Server::get(IDBConnection::class)->executeStatement(
            'UPDATE *PREFIX*shareaudit_filemove SET started_at = ? WHERE id = ?',
            [time() - 3 * 86400, $first],
        );

        try {
            $this->service->run($second);

            $this->assertSame(FileMoveMapper::STATUS_RUNNING, $this->moves->find($first)->getStatus(), 'the running move is untouched');
            $this->assertSame(FileMoveMapper::STATUS_QUEUED, $this->moves->find($second)->getStatus(), 'the next one waits its turn');
            $this->assertTrue(Server::get(IJobList::class)->has(OrphanFileMoveJob::class, ['id' => $second]), 'and asks to be run again');
            $this->assertSame(OrphanFileMoveService::RELEASE_ALIVE, $this->service->release($first), 'not even an administrator can free it while it is alive');
            $this->assertSame(FileMoveMapper::STATUS_RUNNING, $this->moves->find($first)->getStatus());
        } finally {
            $this->kill($holder);
        }

        // Now it is gone: freed on request, and the waiting move can go.
        $this->assertSame(OrphanFileMoveService::RELEASE_OK, $this->service->release($first));
        $this->assertSame(FileMoveMapper::STATUS_FAILED, $this->moves->find($first)->getStatus());
        $this->service->run($second);
        $this->assertSame(FileMoveMapper::STATUS_DONE, $this->moves->find($second)->getStatus());
        $this->dropJob($first);
        $this->dropJob($second);
    }

    /**
     * A worker nothing can be said about — no lock file: it runs on another machine
     * that does not share the data directory, or the directory was not writable.
     * It is never freed by itself, only when an administrator says so.
     */
    public function testAWorkerNothingCanBeSaidAboutIsFreedOnlyByAnExplicitRelease(): void {
        [$first, $second] = $this->twoQueuedMoves();
        $this->assertSame(FileMoveMapper::CLAIM_OK, $this->moves->claim($first, self::TAKER, time() - 5 * 86400));
        $this->assertSame(WorkerLock::UNKNOWN, Server::get(WorkerLock::class)->state($first));

        $this->service->run($second);

        $this->assertSame(FileMoveMapper::STATUS_RUNNING, $this->moves->find($first)->getStatus(), 'left alone, five days or not');
        $this->assertSame(FileMoveMapper::STATUS_QUEUED, $this->moves->find($second)->getStatus());
        $this->dropJob($second);

        $this->assertSame(OrphanFileMoveService::RELEASE_OK, $this->service->release($first));
        $this->assertSame(OrphanFileMoveService::RELEASE_NOT_RUNNING, $this->service->release($first), 'and only once');
        $this->service->run($second);
        $this->assertSame(FileMoveMapper::STATUS_DONE, $this->moves->find($second)->getStatus());
        $this->dropJob($first);
        $this->dropJob($second);
    }

    public function testAWholeAccountMovesWithAllItsSharesEvenTheUnselectedOnes(): void {
        $one = $this->link($this->leaverFile('one.txt', 'one'));
        $two = $this->link($this->leaverFile('Folder/two.txt', 'two'));
        $this->leaves();

        $result = $this->moveFiles([(int)$one->getId()], OrphanFileMoveService::SCOPE_ACCOUNT);

        $this->assertSame([], $result['skipped']);
        $this->assertNull($result['queued'][0]['path']);
        $this->assertSame(2, $result['queued'][0]['shares']);
        $this->assertSame(FileMoveMapper::STATUS_DONE, $this->moves->findRecent(1)[0]->getStatus());
        $this->assertSame(self::TAKER, $this->shareState((int)$one->getId())['owner']);
        $this->assertSame(self::TAKER, $this->shareState((int)$two->getId())['owner'], 'the share nobody selected went with the account');
        $this->assertLinkServes($one->getToken(), 'one');
        $this->assertLinkServes($two->getToken(), 'two');
        $this->assertSame([], $this->leaverHome()->getDirectoryListing(), 'nothing is left in the leaver home');
    }

    public function testAnOwnerWhoIsActiveAgainKeepsTheirFiles(): void {
        $share = $this->link($this->leaverFile('mine.txt', 'mine'));
        $this->leaves();

        $queued = $this->service->enqueue([(int)$share->getId()], self::TAKER, OrphanFileMoveService::SCOPE_PATH)['queued'];
        $this->assertCount(1, $queued);
        // Between queuing and the background run, the account comes back.
        self::$users->get(self::LEAVER)->setEnabled(true);
        $this->service->run($queued[0]['id']);
        Server::get(IJobList::class)->remove(OrphanFileMoveJob::class, ['id' => $queued[0]['id']]);

        $move = $this->moves->findRecent(1)[0];
        $this->assertSame(FileMoveMapper::STATUS_FAILED, $move->getStatus());
        $this->assertSame(OrphanFileMoveService::ERROR_OWNER_ACTIVE, $move->getError());
        $this->assertTrue($this->leaverHome()->nodeExists('mine.txt'), 'the file did not move');
        $this->assertSame(self::LEAVER, $this->shareState((int)$share->getId())['owner']);
    }

    public function testAFileTheOwnerDoesNotHoldInTheirHomeIsNotMoved(): void {
        // A share of a file in the *taker's* home, wrongly recorded as the
        // leaver's: nothing of the leaver's to move.
        $takerFile = (function (): File {
            \OC_Util::setupFS(self::TAKER);
            return Server::get(IRootFolder::class)->getUserFolder(self::TAKER)->newFile('theirs.txt', 'theirs');
        })();
        $share = $this->link($this->leaverFile('placeholder.txt', 'x'));
        $this->leaves();
        Server::get(IDBConnection::class)->executeStatement(
            'UPDATE *PREFIX*share SET file_source = ? WHERE id = ?',
            [$takerFile->getId(), (int)$share->getId()],
        );

        $result = $this->service->enqueue([(int)$share->getId()], self::TAKER, OrphanFileMoveService::SCOPE_PATH);

        $this->assertSame([], $result['queued']);
        $this->assertSame(OrphanFileMoveService::SKIP_NOT_IN_HOME, $result['skipped'][0]['reason']);
    }

    public function testADeletedOwnerHasNoFilesToMove(): void {
        $share = $this->link($this->leaverFile('gone.txt', 'gone'));
        $this->leaves();
        // The account is deleted but its shares are still in the table — which is
        // how an orphan of a deleted owner looks.
        $id = (int)$share->getId();
        Server::get(IDBConnection::class)->executeStatement(
            'UPDATE *PREFIX*share SET uid_owner = ? WHERE id = ?',
            ['sai_deleted_owner', $id],
        );

        $result = $this->service->enqueue([$id], self::TAKER, OrphanFileMoveService::SCOPE_ACCOUNT);

        $this->assertSame([], $result['queued']);
        $this->assertSame(OrphanFileMoveService::SKIP_OWNER_DELETED, $result['skipped'][0]['reason']);
    }
}
