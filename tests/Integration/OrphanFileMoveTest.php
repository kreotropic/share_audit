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
