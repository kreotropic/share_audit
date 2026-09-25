<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Tests\Unit;

use OCA\ShareAuditDashboard\Db\FileMove;
use OCA\ShareAuditDashboard\Db\FileMoveMapper;
use OCA\ShareAuditDashboard\Db\ShareMapper;
use OCA\ShareAuditDashboard\Service\BackgroundJobsStatus;
use OCA\ShareAuditDashboard\Service\DisplayNameResolver;
use OCA\ShareAuditDashboard\Service\FileNodeResolver;
use OCA\ShareAuditDashboard\Service\OrphanFileMoveService;
use OCA\ShareAuditDashboard\Service\OrphanShareService;
use OCA\ShareAuditDashboard\Service\OwnershipTransferGateway;
use OCA\ShareAuditDashboard\Service\SecurityAnalyzerService;
use OCA\ShareAuditDashboard\Service\ShareAuditLogger;
use OCA\ShareAuditDashboard\Service\WorkerLock;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

// The Files app's own exception is not in the OCP package the unit tests run
// against; on a real Nextcloud (the integration suite) the real one is used.
if (!class_exists(\OCA\Files\Exception\TransferOwnershipException::class, false)) {
    class_alias(FakeTransferOwnershipException::class, 'OCA\\Files\\Exception\\TransferOwnershipException');
}

/** Stands in for OCA\Files\Exception\TransferOwnershipException. */
class FakeTransferOwnershipException extends \Exception {
}

/**
 * Covers OrphanFileMoveService: which selected orphan shares a file move can
 * serve and why the others cannot, how selections are folded into as few moves
 * as there are folders to move, and what the background run does — above all
 * that it re-checks the accounts instead of trusting the moment it was queued.
 */
class OrphanFileMoveServiceTest extends TestCase {

    private const NOW = 1_800_000_000;

    private FileMoveMapper&MockObject $moves;
    private ShareMapper&MockObject $mapper;
    private OrphanShareService&MockObject $orphans;
    private IUserManager&MockObject $userManager;
    private IUserSession&MockObject $userSession;
    private FileNodeResolver&MockObject $nodes;
    private OwnershipTransferGateway&MockObject $gateway;
    private DisplayNameResolver&MockObject $displayNames;
    private IJobList&MockObject $jobList;
    private ITimeFactory&MockObject $time;
    private ShareAuditLogger&MockObject $auditLogger;
    private SecurityAnalyzerService&MockObject $analyzer;
    private WorkerLock&MockObject $workerLock;
    private BackgroundJobsStatus&MockObject $jobsStatus;
    private OrphanFileMoveService $service;

    /** @var FileMove[] what the mapper was asked to insert */
    private array $inserted = [];

    protected function setUp(): void {
        $this->moves = $this->createMock(FileMoveMapper::class);
        $this->mapper = $this->createMock(ShareMapper::class);
        $this->orphans = $this->createMock(OrphanShareService::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->nodes = $this->createMock(FileNodeResolver::class);
        $this->gateway = $this->createMock(OwnershipTransferGateway::class);
        $this->displayNames = $this->createMock(DisplayNameResolver::class);
        $this->jobList = $this->createMock(IJobList::class);
        $this->auditLogger = $this->createMock(ShareAuditLogger::class);
        $this->analyzer = $this->createMock(SecurityAnalyzerService::class);
        $this->workerLock = $this->createMock(WorkerLock::class);
        $this->workerLock->method('acquire')->willReturn(true);
        $this->jobsStatus = $this->createMock(BackgroundJobsStatus::class);

        $this->time = $this->createMock(ITimeFactory::class);
        $this->time->method('getTime')->willReturn(self::NOW);

        $admin = $this->user('root');
        $this->userSession->method('getUser')->willReturn($admin);

        $this->moves->method('insert')->willReturnCallback(function (FileMove $move): FileMove {
            $move->setId(100 + count($this->inserted));
            $this->inserted[] = $move;
            return $move;
        });

        $this->service = new OrphanFileMoveService(
            $this->moves,
            $this->mapper,
            $this->orphans,
            $this->userManager,
            $this->userSession,
            $this->nodes,
            $this->gateway,
            $this->displayNames,
            $this->jobList,
            $this->time,
            $this->auditLogger,
            $this->analyzer,
            $this->workerLock,
            $this->jobsStatus,
            $this->createMock(LoggerInterface::class),
        );

        // "leaver" and "leaver2" are disabled, "gone" is deleted, "carol" is active.
        $this->orphans->method('getOrphanOwners')->willReturn([
            'leaver' => 'disabled',
            'leaver2' => 'disabled',
            'gone' => 'deleted',
        ]);
    }

    private function user(string $uid, bool $enabled = true): IUser&MockObject {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $user->method('isEnabled')->willReturn($enabled);
        return $user;
    }

    /** Make "dana" an enabled account that can take files over. */
    private function danaCanTakeOver(): void {
        $this->userManager->method('get')->willReturnCallback(
            fn (string $uid) => $uid === 'dana' ? $this->user('dana') : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id, array $override = []): array {
        return $override + [
            'id' => $id,
            'share_type' => 3,
            'uid_owner' => 'leaver',
            'uid_initiator' => 'leaver',
            'share_with' => null,
            'permissions' => 1,
            'file_source' => 100 + $id,
            'source_exists' => 1,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function candidates(array $rows): void {
        $this->mapper->method('findTransferCandidates')->willReturn($rows);
    }

    /**
     * Where each file id sits in the owner's home; a file not listed is
     * outside it.
     *
     * @param array<int, string> $paths file_source => path
     */
    private function homePaths(array $paths): void {
        $this->nodes->method('homeRelativePath')->willReturnCallback(
            static fn (string $uid, int $fileId) => $paths[$fileId] ?? null,
        );
    }

    private function move(int $id, string $owner, ?string $path, string $scope, string $status = 'queued'): FileMove {
        $move = new FileMove();
        $move->setId($id);
        $move->setSourceUid($owner);
        $move->setTargetUid('dana');
        $move->setScope($scope);
        $move->setPath($path);
        $move->setStatus($status);
        $move->setShareCount(1);
        $move->setRequestedBy('root');
        $move->setCreatedAt(self::NOW - 10);
        return $move;
    }

    // -------------------------------------------------------------------
    // Who may take the files, and what may be asked
    // -------------------------------------------------------------------

    public function testAnUnknownScopeIsRefused(): void {
        $this->danaCanTakeOver();
        $this->moves->expects($this->never())->method('insert');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->enqueue([1], 'dana', 'everything');
    }

    public function testAnUnknownNewOwnerIsRefused(): void {
        $this->userManager->method('get')->willReturn(null);
        $this->moves->expects($this->never())->method('insert');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->enqueue([1], 'nobody', OrphanFileMoveService::SCOPE_PATH);
    }

    public function testADisabledNewOwnerIsRefused(): void {
        $this->userManager->method('get')->willReturn($this->user('dana', false));
        $this->moves->expects($this->never())->method('insert');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->enqueue([1], 'dana', OrphanFileMoveService::SCOPE_PATH);
    }

    public function testNothingSelectedQueuesNothing(): void {
        $this->danaCanTakeOver();
        $this->moves->expects($this->never())->method('insert');

        $this->assertSame(['queued' => [], 'skipped' => []], $this->service->enqueue([], 'dana', OrphanFileMoveService::SCOPE_PATH));
    }

    // -------------------------------------------------------------------
    // Shares a move cannot serve
    // -------------------------------------------------------------------

    public function testAShareThatIsNotOrphanOrDoesNotExistIsSkipped(): void {
        $this->danaCanTakeOver();
        // 1 belongs to an active user, 2 is not in the table at all.
        $this->candidates([$this->row(1, ['uid_owner' => 'carol'])]);
        $this->moves->expects($this->never())->method('insert');

        $result = $this->service->enqueue([1, 2], 'dana', OrphanFileMoveService::SCOPE_PATH);

        $this->assertSame([], $result['queued']);
        $this->assertSame([
            ['id' => 1, 'reason' => OrphanFileMoveService::SKIP_NOT_ORPHAN],
            ['id' => 2, 'reason' => OrphanFileMoveService::SKIP_NOT_ORPHAN],
        ], $result['skipped']);
    }

    public function testADeletedOwnersFilesWentWithTheAccount(): void {
        $this->danaCanTakeOver();
        $this->candidates([$this->row(1, ['uid_owner' => 'gone'])]);
        $this->moves->expects($this->never())->method('insert');

        foreach ([OrphanFileMoveService::SCOPE_PATH, OrphanFileMoveService::SCOPE_ACCOUNT] as $scope) {
            $result = $this->service->enqueue([1], 'dana', $scope);
            $this->assertSame([['id' => 1, 'reason' => OrphanFileMoveService::SKIP_OWNER_DELETED]], $result['skipped'], $scope);
        }
    }

    public function testAShareWhoseFileIsGoneIsSkipped(): void {
        $this->danaCanTakeOver();
        $this->candidates([$this->row(1, ['source_exists' => 0])]);
        $this->homePaths([101 => 'Docs/a.txt']);
        $this->moves->expects($this->never())->method('insert');

        $result = $this->service->enqueue([1], 'dana', OrphanFileMoveService::SCOPE_PATH);

        $this->assertSame([['id' => 1, 'reason' => OrphanFileMoveService::SKIP_SOURCE_MISSING]], $result['skipped']);
    }

    public function testAFileOutsideTheOwnersHomeIsLeftToTheNormalTransfer(): void {
        $this->danaCanTakeOver();
        $this->candidates([$this->row(1)]);
        $this->homePaths([]);
        $this->moves->expects($this->never())->method('insert');

        $result = $this->service->enqueue([1], 'dana', OrphanFileMoveService::SCOPE_PATH);

        $this->assertSame([['id' => 1, 'reason' => OrphanFileMoveService::SKIP_NOT_IN_HOME]], $result['skipped']);
    }

    // -------------------------------------------------------------------
    // Queuing the files behind the selected shares
    // -------------------------------------------------------------------

    public function testEachFileIsQueuedOnceWithItsJobAndItIsRecorded(): void {
        $this->danaCanTakeOver();
        $this->candidates([$this->row(1), $this->row(2)]);
        $this->homePaths([101 => 'Docs/a.txt', 102 => 'Other/b.txt']);

        $this->jobList->expects($this->exactly(2))->method('add');
        $this->auditLogger->expects($this->exactly(2))->method('logFileMoveQueued');

        $result = $this->service->enqueue([1, 2], 'dana', OrphanFileMoveService::SCOPE_PATH);

        $this->assertSame([], $result['skipped']);
        $this->assertCount(2, $result['queued']);
        $this->assertSame(['Docs/a.txt', 'Other/b.txt'], array_column($result['queued'], 'path'));

        $move = $this->inserted[0];
        $this->assertSame('leaver', $move->getSourceUid());
        $this->assertSame('dana', $move->getTargetUid());
        $this->assertSame('path', $move->getScope());
        $this->assertSame('queued', $move->getStatus());
        $this->assertSame('root', $move->getRequestedBy());
        $this->assertSame(self::NOW, $move->getCreatedAt());
    }

    /**
     * The plain transfer only handles user, group and link shares and refuses the
     * rest as an unsupported type, but moving the file carries every share of it
     * along — Talk, mail, federated, circle, Deck — so the file move must not
     * turn a share down for its type.
     */
    public function testSharesOfEveryTypeAreMovedWithTheirFile(): void {
        $this->danaCanTakeOver();
        // room, email, remote, circle, deck
        $types = [10, 4, 6, 7, 12];
        $rows = [];
        $paths = [];
        foreach ($types as $i => $type) {
            $id = $i + 1;
            $rows[] = $this->row($id, ['share_type' => $type]);
            $paths[100 + $id] = "Docs/file$id.txt";
        }
        $this->candidates($rows);
        $this->homePaths($paths);

        $result = $this->service->enqueue([1, 2, 3, 4, 5], 'dana', OrphanFileMoveService::SCOPE_PATH);

        $this->assertSame([], $result['skipped'], 'no share is refused for its type');
        $this->assertCount(5, $result['queued']);
    }

    public function testSeveralSharesOfOneFileAreOneMove(): void {
        $this->danaCanTakeOver();
        $this->candidates([$this->row(1, ['file_source' => 500]), $this->row(2, ['file_source' => 500])]);
        $this->homePaths([500 => 'Docs/a.txt']);

        $result = $this->service->enqueue([1, 2], 'dana', OrphanFileMoveService::SCOPE_PATH);

        $this->assertCount(1, $result['queued']);
        $this->assertSame(2, $result['queued'][0]['shares']);
    }

    public function testAFileInsideAnotherSelectedFolderIsCoveredByTheFolder(): void {
        $this->danaCanTakeOver();
        $this->candidates([$this->row(1), $this->row(2), $this->row(3)]);
        // Listed inner-first on purpose: order must not matter.
        $this->homePaths([101 => 'Projects/Sub/a.docx', 102 => 'Projects', 103 => 'Elsewhere/c.txt']);

        $result = $this->service->enqueue([1, 2, 3], 'dana', OrphanFileMoveService::SCOPE_PATH);

        $this->assertSame([], $result['skipped']);
        $byPath = array_column($result['queued'], 'shares', 'path');
        $this->assertSame(['Projects' => 2, 'Elsewhere/c.txt' => 1], $byPath);
    }

    public function testAPathThatIsOnlyANamePrefixIsNotInsideTheFolder(): void {
        $this->danaCanTakeOver();
        $this->candidates([$this->row(1), $this->row(2)]);
        $this->homePaths([101 => 'Proj', 102 => 'Project/a.txt']);

        $result = $this->service->enqueue([1, 2], 'dana', OrphanFileMoveService::SCOPE_PATH);

        $this->assertCount(2, $result['queued'], '"Project/a.txt" is not inside "Proj"');
    }

    public function testANumericFolderNameDoesNotBreakFolding(): void {
        $this->danaCanTakeOver();
        // PHP turns the array key "2024" into an integer.
        $this->candidates([$this->row(1), $this->row(2)]);
        $this->homePaths([101 => '2024', 102 => '2024/report.docx']);

        $result = $this->service->enqueue([1, 2], 'dana', OrphanFileMoveService::SCOPE_PATH);

        $this->assertCount(1, $result['queued']);
        $this->assertSame('2024', $result['queued'][0]['path']);
        $this->assertSame(2, $result['queued'][0]['shares']);
    }

    public function testTwoOwnersAreQueuedSeparately(): void {
        $this->danaCanTakeOver();
        $this->candidates([$this->row(1), $this->row(2, ['uid_owner' => 'leaver2'])]);
        $this->homePaths([101 => 'Docs/a.txt', 102 => 'Docs/a.txt']);

        $result = $this->service->enqueue([1, 2], 'dana', OrphanFileMoveService::SCOPE_PATH);

        $this->assertSame(['leaver', 'leaver2'], array_column($result['queued'], 'owner'));
    }

    // -------------------------------------------------------------------
    // Queuing a whole account
    // -------------------------------------------------------------------

    public function testAWholeAccountIsOneMoveWhateverTheSelection(): void {
        $this->danaCanTakeOver();
        $this->candidates([$this->row(1), $this->row(2), $this->row(3, ['source_exists' => 0])]);
        $this->mapper->method('countShares')->with(['owners' => ['leaver']])->willReturn(9);
        $this->nodes->expects($this->never())->method('homeRelativePath');

        $result = $this->service->enqueue([1, 2, 3], 'dana', OrphanFileMoveService::SCOPE_ACCOUNT);

        $this->assertSame([], $result['skipped']);
        $this->assertCount(1, $result['queued']);
        $this->assertNull($result['queued'][0]['path']);
        $this->assertSame(9, $result['queued'][0]['shares'], 'every share of the account goes along, not just the selected ones');
        $this->assertNull($this->inserted[0]->getPath());
        $this->assertSame('account', $this->inserted[0]->getScope());
    }

    // -------------------------------------------------------------------
    // Moves that are already on their way
    // -------------------------------------------------------------------

    public function testAnAccountMoveAlreadyQueuedCoversEverything(): void {
        $this->danaCanTakeOver();
        $this->candidates([$this->row(1)]);
        $this->homePaths([101 => 'Docs/a.txt']);
        $this->moves->method('findActiveForSources')->willReturn([$this->move(7, 'leaver', null, 'account', 'running')]);
        $this->moves->expects($this->never())->method('insert');

        foreach ([OrphanFileMoveService::SCOPE_PATH, OrphanFileMoveService::SCOPE_ACCOUNT] as $scope) {
            $result = $this->service->enqueue([1], 'dana', $scope);
            $this->assertSame([['id' => 1, 'reason' => OrphanFileMoveService::SKIP_ALREADY_QUEUED]], $result['skipped'], $scope);
        }
    }

    public function testAFolderMoveAlreadyQueuedCoversWhatIsInsideIt(): void {
        $this->danaCanTakeOver();
        $this->candidates([$this->row(1), $this->row(2)]);
        $this->homePaths([101 => 'Projects/a.docx', 102 => 'Other/b.docx']);
        $this->moves->method('findActiveForSources')->willReturn([$this->move(7, 'leaver', 'Projects', 'path')]);

        $result = $this->service->enqueue([1, 2], 'dana', OrphanFileMoveService::SCOPE_PATH);

        $this->assertSame([['id' => 1, 'reason' => OrphanFileMoveService::SKIP_ALREADY_QUEUED]], $result['skipped']);
        $this->assertSame(['Other/b.docx'], array_column($result['queued'], 'path'));
    }

    public function testAFolderMoveInFlightBlocksAnAccountMoveOfTheSameOwner(): void {
        $this->danaCanTakeOver();
        $this->candidates([$this->row(1)]);
        $this->moves->method('findActiveForSources')->willReturn([$this->move(7, 'leaver', 'Projects', 'path')]);
        $this->moves->expects($this->never())->method('insert');

        $result = $this->service->enqueue([1], 'dana', OrphanFileMoveService::SCOPE_ACCOUNT);

        $this->assertSame([['id' => 1, 'reason' => OrphanFileMoveService::SKIP_ALREADY_QUEUED]], $result['skipped']);
    }

    // -------------------------------------------------------------------
    // Running a queued move
    // -------------------------------------------------------------------

    /**
     * @param array<string, bool|null> $accounts uid => enabled, or null for an account that no longer exists
     */
    private function accounts(array $accounts): void {
        $this->userManager->method('get')->willReturnCallback(
            fn (string $uid) => isset($accounts[$uid]) ? $this->user($uid, $accounts[$uid]) : null,
        );
    }

    private function queued(?string $path = 'Docs'): FileMove {
        $move = $this->move(7, 'leaver', $path, $path === null ? 'account' : 'path');
        $this->moves->method('find')->with(7)->willReturn($move);
        return $move;
    }

    public function testAMoveThatDoesNotExistAnyMoreIsIgnored(): void {
        $this->moves->method('find')->willThrowException(new DoesNotExistException(''));
        $this->moves->expects($this->never())->method('claim');
        $this->gateway->expects($this->never())->method('move');

        $this->service->run(7);
    }

    public function testAMoveAnotherWorkerTookIsNotRunTwice(): void {
        $this->queued();
        $this->accounts(['leaver' => false, 'dana' => true]);
        $this->moves->method('claim')->willReturn(FileMoveMapper::CLAIM_TAKEN);
        $this->gateway->expects($this->never())->method('move');
        $this->moves->expects($this->never())->method('finish');

        $this->service->run(7);
    }

    private function runningMove(int $id, int $startedAt = self::NOW - 60): FileMove {
        $holder = $this->move($id, 'gone-leaver', 'Other', 'path', 'running');
        $holder->setStartedAt($startedAt);
        return $holder;
    }

    /**
     * Another worker is already moving files into the same account and is alive:
     * this one must not touch the files, must leave the move queued, and must put
     * itself back in the job list for later instead of being lost.
     */
    public function testAMoveWhoseAccountIsBusyBacksOffAndTriesLater(): void {
        $this->queued();
        $this->accounts(['leaver' => false, 'dana' => true]);
        $this->moves->method('claim')->with(7, 'dana', self::NOW)->willReturn(FileMoveMapper::CLAIM_BUSY);
        $this->moves->method('findRunningFor')->willReturn($this->runningMove(3));
        $this->workerLock->method('state')->with(3)->willReturn(WorkerLock::ALIVE);

        $this->gateway->expects($this->never())->method('move');
        $this->moves->expects($this->never())->method('abandon');
        $this->moves->expects($this->never())->method('finish');
        $this->jobList->expects($this->once())->method('scheduleAfter')
            ->with(\OCA\ShareAuditDashboard\BackgroundJob\OrphanFileMoveJob::class, self::NOW + 60, ['id' => 7]);

        $this->service->run(7);
    }

    /**
     * The point of the whole change: however long a move has been running, it is
     * never given up on for that reason. A live worker moving a huge folder is
     * indistinguishable from a dead one by age.
     */
    public function testAMoveThatHasBeenRunningForDaysIsStillLeftAloneWhileItsWorkerIsAlive(): void {
        $this->queued();
        $this->accounts(['leaver' => false, 'dana' => true]);
        $this->moves->method('claim')->willReturn(FileMoveMapper::CLAIM_BUSY);
        $this->moves->method('findRunningFor')->willReturn($this->runningMove(3, self::NOW - 5 * 86400));
        $this->workerLock->method('state')->willReturn(WorkerLock::ALIVE);

        $this->moves->expects($this->never())->method('abandon');
        $this->gateway->expects($this->never())->method('move');
        $this->jobList->expects($this->once())->method('scheduleAfter');

        $this->service->run(7);
    }

    /** Nothing can be said about the worker (another machine, an unwritable directory): also left alone. */
    public function testAMoveWhoseWorkerCannotBeToldIsLeftAloneToo(): void {
        $this->queued();
        $this->accounts(['leaver' => false, 'dana' => true]);
        $this->moves->method('claim')->willReturn(FileMoveMapper::CLAIM_BUSY);
        $this->moves->method('findRunningFor')->willReturn($this->runningMove(3, self::NOW - 5 * 86400));
        $this->workerLock->method('state')->willReturn(WorkerLock::UNKNOWN);

        $this->moves->expects($this->never())->method('abandon');
        $this->jobList->expects($this->once())->method('scheduleAfter');

        $this->service->run(7);
    }

    /**
     * The move in the way belongs to a worker that is provably gone: it is given up
     * on (and that is recorded), and the account then goes to this one.
     */
    public function testABusyAccountWhoseWorkerIsDeadIsTakenOver(): void {
        $this->queued();
        $this->accounts(['leaver' => false, 'dana' => true]);
        $this->moves->method('claim')->willReturnOnConsecutiveCalls(FileMoveMapper::CLAIM_BUSY, FileMoveMapper::CLAIM_OK);
        $this->moves->method('findRunningFor')->willReturn($this->runningMove(3));
        $this->workerLock->method('state')->with(3)->willReturn(WorkerLock::DEAD);

        $this->moves->expects($this->once())->method('abandon')
            ->with(3, OrphanFileMoveService::ERROR_INTERRUPTED, self::NOW)
            ->willReturn(true);
        $this->workerLock->expects($this->once())->method('forget')->with(3);
        $this->auditLogger->expects($this->exactly(2))->method('logFileMoveFinished');
        $this->gateway->expects($this->once())->method('move');
        $this->jobList->expects($this->never())->method('scheduleAfter');
        $this->moves->expects($this->once())->method('finish')->with(7, 'done', null, self::NOW);

        $this->service->run(7);
    }

    public function testTheAccountIsTakenIfTheMoveInTheWayFinishedMeanwhile(): void {
        $this->queued();
        $this->accounts(['leaver' => false, 'dana' => true]);
        $this->moves->method('claim')->willReturnOnConsecutiveCalls(FileMoveMapper::CLAIM_BUSY, FileMoveMapper::CLAIM_OK);
        $this->moves->method('findRunningFor')->willReturn(null);

        $this->moves->expects($this->never())->method('abandon');
        $this->gateway->expects($this->once())->method('move');

        $this->service->run(7);
    }

    // -------------------------------------------------------------------
    // The worker's own lock
    // -------------------------------------------------------------------

    public function testAMoveThatIsBeingWorkedOnElsewhereIsNotRunHere(): void {
        $workerLock = $this->createMock(WorkerLock::class);
        $workerLock->method('acquire')->willReturn(false);
        $service = $this->serviceWithLock($workerLock);
        $this->queued();

        $this->moves->expects($this->never())->method('claim');
        $this->gateway->expects($this->never())->method('move');

        $service->run(7);
    }

    public function testTheWorkersLockIsTakenBeforeTheMoveIsClaimedAndKeptUntilItIsRecorded(): void {
        $this->queued();
        $this->accounts(['leaver' => false, 'dana' => true]);
        $calls = [];
        $workerLock = $this->createMock(WorkerLock::class);
        $workerLock->method('acquire')->willReturnCallback(function () use (&$calls): bool {
            $calls[] = 'acquire';
            return true;
        });
        $workerLock->method('release')->willReturnCallback(function () use (&$calls): void {
            $calls[] = 'release';
        });
        $this->moves->method('claim')->willReturnCallback(function () use (&$calls): string {
            $calls[] = 'claim';
            return FileMoveMapper::CLAIM_OK;
        });
        $this->moves->method('finish')->willReturnCallback(function () use (&$calls): void {
            $calls[] = 'finish';
        });

        $this->serviceWithLock($workerLock)->run(7);

        $this->assertSame(['acquire', 'claim', 'finish', 'release'], $calls);
    }

    public function testTheLockIsReleasedEvenWhenTheMoveThrows(): void {
        $this->queued();
        $this->accounts(['leaver' => false, 'dana' => true]);
        $this->moves->method('claim')->willReturn(FileMoveMapper::CLAIM_OK);
        $this->moves->method('finish')->willThrowException(new \RuntimeException('database gone'));
        $workerLock = $this->createMock(WorkerLock::class);
        $workerLock->method('acquire')->willReturn(true);
        $workerLock->expects($this->once())->method('release')->with(7);

        $this->expectException(\RuntimeException::class);
        $this->serviceWithLock($workerLock)->run(7);
    }

    private function serviceWithLock(WorkerLock $workerLock): OrphanFileMoveService {
        return new OrphanFileMoveService(
            $this->moves,
            $this->mapper,
            $this->orphans,
            $this->userManager,
            $this->userSession,
            $this->nodes,
            $this->gateway,
            $this->displayNames,
            $this->jobList,
            $this->time,
            $this->auditLogger,
            $this->analyzer,
            $workerLock,
            $this->jobsStatus,
            $this->createMock(LoggerInterface::class),
        );
    }

    // -------------------------------------------------------------------
    // Freeing a move by hand
    // -------------------------------------------------------------------

    public function testReleasingAMoveThatDoesNotExistSaysSo(): void {
        $this->moves->method('find')->willThrowException(new DoesNotExistException(''));

        $this->assertSame(OrphanFileMoveService::RELEASE_NOT_FOUND, $this->service->release(7));
    }

    public function testOnlyARunningMoveCanBeReleased(): void {
        foreach (['queued', 'done', 'failed'] as $status) {
            $moves = $this->createMock(FileMoveMapper::class);
            $moves->method('find')->willReturn($this->move(7, 'leaver', 'Docs', 'path', $status));
            $moves->expects($this->never())->method('abandon');
            $service = $this->serviceWith($moves);

            $this->assertSame(OrphanFileMoveService::RELEASE_NOT_RUNNING, $service->release(7), $status);
        }
    }

    public function testAMoveWhoseWorkerIsAliveCannotBeReleased(): void {
        $this->moves->method('find')->willReturn($this->runningMove(7, self::NOW - 9 * 86400));
        $this->workerLock->method('state')->with(7)->willReturn(WorkerLock::ALIVE);

        $this->moves->expects($this->never())->method('abandon');
        $this->auditLogger->expects($this->never())->method('logFileMoveReleased');

        $this->assertSame(OrphanFileMoveService::RELEASE_ALIVE, $this->service->release(7));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function workersThatAreNotKnownToBeAlive(): array {
        return ['dead' => [WorkerLock::DEAD], 'cannot be told' => [WorkerLock::UNKNOWN]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('workersThatAreNotKnownToBeAlive')]
    public function testAMoveWhoseWorkerIsNotKnownToBeAliveCanBeReleasedAndItIsRecorded(string $state): void {
        $this->moves->method('find')->willReturn($this->runningMove(7));
        $this->workerLock->method('state')->willReturn($state);

        $this->moves->expects($this->once())->method('abandon')
            ->with(7, OrphanFileMoveService::ERROR_INTERRUPTED, self::NOW)
            ->willReturn(true);
        $this->workerLock->expects($this->once())->method('forget')->with(7);
        $this->auditLogger->expects($this->once())->method('logFileMoveReleased')->with('gone-leaver', 'dana', 'Other');

        $this->assertSame(OrphanFileMoveService::RELEASE_OK, $this->service->release(7));
    }

    private function serviceWith(FileMoveMapper $moves): OrphanFileMoveService {
        return new OrphanFileMoveService(
            $moves,
            $this->mapper,
            $this->orphans,
            $this->userManager,
            $this->userSession,
            $this->nodes,
            $this->gateway,
            $this->displayNames,
            $this->jobList,
            $this->time,
            $this->auditLogger,
            $this->analyzer,
            $this->workerLock,
            $this->jobsStatus,
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testTheFilesMoveAndTheMoveIsRecordedAsDone(): void {
        $this->queued('Docs');
        $this->accounts(['leaver' => false, 'dana' => true]);
        $this->moves->method('claim')->willReturn(FileMoveMapper::CLAIM_OK);

        $this->gateway->expects($this->once())->method('move')
            ->with($this->callback(fn (IUser $u) => $u->getUID() === 'leaver'), $this->callback(fn (IUser $u) => $u->getUID() === 'dana'), 'Docs');
        $this->moves->expects($this->once())->method('finish')->with(7, 'done', null, self::NOW);
        $this->auditLogger->expects($this->once())->method('logFileMoveFinished')->with('root', 'leaver', 'dana', 'Docs', null);
        $this->orphans->expects($this->once())->method('flushOwnerCache');

        $this->service->run(7);
    }

    public function testAnAccountMoveHandsTheGatewayAnEmptyPath(): void {
        $this->queued(null);
        $this->accounts(['leaver' => false, 'dana' => true]);
        $this->moves->method('claim')->willReturn(FileMoveMapper::CLAIM_OK);

        $this->gateway->expects($this->once())->method('move')->with($this->anything(), $this->anything(), '');

        $this->service->run(7);
    }

    public function testNothingMovesForAnOwnerWhoIsActiveAgain(): void {
        $this->queued();
        $this->accounts(['leaver' => true, 'dana' => true]);
        $this->moves->method('claim')->willReturn(FileMoveMapper::CLAIM_OK);

        $this->gateway->expects($this->never())->method('move');
        $this->moves->expects($this->once())->method('finish')->with(7, 'failed', OrphanFileMoveService::ERROR_OWNER_ACTIVE, self::NOW);
        $this->orphans->expects($this->never())->method('flushOwnerCache');

        $this->service->run(7);
    }

    public function testNothingMovesForAnOwnerWhoWasDeletedMeanwhile(): void {
        $this->queued();
        $this->accounts(['dana' => true]);
        $this->moves->method('claim')->willReturn(FileMoveMapper::CLAIM_OK);

        $this->gateway->expects($this->never())->method('move');
        $this->moves->expects($this->once())->method('finish')->with(7, 'failed', OrphanFileMoveService::ERROR_OWNER_MISSING, self::NOW);

        $this->service->run(7);
    }

    public function testNothingMovesToANewOwnerWhoIsNoLongerEnabled(): void {
        $this->queued();
        $this->accounts(['leaver' => false, 'dana' => false]);
        $this->moves->method('claim')->willReturn(FileMoveMapper::CLAIM_OK);

        $this->gateway->expects($this->never())->method('move');
        $this->moves->expects($this->once())->method('finish')->with(7, 'failed', OrphanFileMoveService::ERROR_TARGET_UNAVAILABLE, self::NOW);

        $this->service->run(7);
    }

    public function testTheFilesAppsOwnRefusalIsKeptSoTheAdminCanActOnIt(): void {
        $this->queued();
        $this->accounts(['leaver' => false, 'dana' => true]);
        $this->moves->method('claim')->willReturn(FileMoveMapper::CLAIM_OK);
        $this->gateway->method('move')->willThrowException(
            new \OCA\Files\Exception\TransferOwnershipException('Target user does not have enough free space available.'),
        );

        $this->moves->expects($this->once())->method('finish')
            ->with(7, 'failed', 'Target user does not have enough free space available.', self::NOW);
        $this->orphans->expects($this->never())->method('flushOwnerCache');

        $this->service->run(7);
    }

    public function testAnyOtherFailureIsNotShownButIsRecordedAsFailed(): void {
        $this->queued();
        $this->accounts(['leaver' => false, 'dana' => true]);
        $this->moves->method('claim')->willReturn(FileMoveMapper::CLAIM_OK);
        $this->gateway->method('move')->willThrowException(new \RuntimeException('SQLSTATE[HY000]: secret internals'));

        $this->moves->expects($this->once())->method('finish')
            ->with(7, 'failed', OrphanFileMoveService::ERROR_UNEXPECTED, self::NOW);
        $this->auditLogger->expects($this->once())->method('logFileMoveFinished')
            ->with('root', 'leaver', 'dana', 'Docs', OrphanFileMoveService::ERROR_UNEXPECTED);

        $this->service->run(7);
    }

    // -------------------------------------------------------------------
    // The list the dashboard shows
    // -------------------------------------------------------------------

    public function testTheListCarriesNamesAndTheWorkersStateOfRunningMoves(): void {
        $running = $this->move(2, 'leaver', 'Docs', 'path', 'running');
        $running->setStartedAt(self::NOW - 60);
        // Running for days: still just "running" — age is not a verdict.
        $old = $this->move(1, 'leaver2', null, 'account', 'running');
        $old->setStartedAt(self::NOW - 25 * 3600);
        $done = $this->move(3, 'leaver', 'x', 'path', 'done');
        $this->moves->method('findRecent')->willReturn([$running, $old, $done]);
        $this->displayNames->method('resolveMany')->willReturn(['leaver' => 'The Leaver', 'dana' => 'Dana']);
        $this->workerLock->method('state')->willReturnMap([[2, WorkerLock::ALIVE], [1, WorkerLock::DEAD]]);

        $items = $this->service->list();

        $this->assertSame('running', $items[0]['status']);
        $this->assertSame('The Leaver', $items[0]['sourceDisplayName']);
        $this->assertSame('Dana', $items[0]['targetDisplayName']);
        $this->assertSame('alive', $items[0]['workerState']);
        $this->assertSame('leaver2', $items[1]['sourceDisplayName'], 'a name it could not resolve falls back to the uid');
        $this->assertSame('running', $items[1]['status'], 'an old run is not declared failed by age');
        $this->assertSame('dead', $items[1]['workerState']);
        $this->assertNull($items[1]['path']);
        $this->assertNull($items[2]['workerState'], 'only a running move has a worker to ask about');
    }

    public function testTheOverviewSaysWhetherBackgroundJobsAreRunningForWaitingMoves(): void {
        $queued = $this->move(2, 'leaver', 'Docs', 'path', 'queued');
        $done = $this->move(1, 'leaver', 'x', 'path', 'done');
        $this->moves->method('findRecent')->willReturn([$queued, $done]);
        $this->displayNames->method('resolveMany')->willReturn([]);
        $this->jobsStatus->expects($this->once())->method('describe')->with(true)
            ->willReturn(['mode' => 'cron', 'lastRun' => null, 'stalled' => true]);

        $overview = $this->service->overview();

        $this->assertCount(2, $overview['items']);
        $this->assertTrue($overview['backgroundJobs']['stalled']);
    }

    public function testNothingWaitingIsNothingToWarnAbout(): void {
        $this->moves->method('findRecent')->willReturn([$this->move(1, 'leaver', 'x', 'path', 'done')]);
        $this->displayNames->method('resolveMany')->willReturn([]);
        $this->jobsStatus->expects($this->once())->method('describe')->with(false)
            ->willReturn(['mode' => 'cron', 'lastRun' => 1, 'stalled' => false]);

        $this->service->overview();
    }
}
