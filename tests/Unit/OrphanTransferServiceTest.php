<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Tests\Unit;

use OCA\ShareAuditDashboard\Db\ShareMapper;
use OCA\ShareAuditDashboard\Service\FileNodeResolver;
use OCA\ShareAuditDashboard\Service\OrphanShareService;
use OCA\ShareAuditDashboard\Service\OrphanTransferService;
use OCA\ShareAuditDashboard\Service\SecurityAnalyzerService;
use OCA\ShareAuditDashboard\Service\ShareAuditLogger;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Node;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\Events\ShareTransferredEvent;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers OrphanTransferService: which shares may be handed to a new owner and
 * why the others may not. The rule is the one Nextcloud applies when a share is
 * created — the new owner has to reach the file, be allowed to share it, and
 * not be handing out more than they hold — because an owner who cannot resolve
 * the file leaves a share that no longer works.
 */
class OrphanTransferServiceTest extends TestCase {

    private const PERM_READ = 1;
    private const PERM_ALL = 31;
    private const PERM_ALL_BUT_SHARE = 15;

    private ShareMapper&MockObject $mapper;
    private OrphanShareService&MockObject $orphans;
    private IUserManager&MockObject $userManager;
    private FileNodeResolver&MockObject $nodes;
    private ShareAuditLogger&MockObject $auditLogger;
    private SecurityAnalyzerService&MockObject $analyzer;
    private IManager&MockObject $shareManager;
    private IEventDispatcher&MockObject $dispatcher;
    private OrphanTransferService $service;

    protected function setUp(): void {
        $this->mapper = $this->createMock(ShareMapper::class);
        $this->orphans = $this->createMock(OrphanShareService::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->nodes = $this->createMock(FileNodeResolver::class);
        $this->auditLogger = $this->createMock(ShareAuditLogger::class);
        $this->analyzer = $this->createMock(SecurityAnalyzerService::class);
        $this->shareManager = $this->createMock(IManager::class);
        $this->dispatcher = $this->createMock(IEventDispatcher::class);
        $this->service = new OrphanTransferService(
            $this->mapper,
            $this->orphans,
            $this->userManager,
            $this->nodes,
            $this->auditLogger,
            $this->analyzer,
            $this->shareManager,
            $this->dispatcher,
            $this->createMock(LoggerInterface::class),
        );

        // "gone" is a deleted account, "bob" is disabled; "carol" is a normal one.
        $this->orphans->method('getOrphanOwners')->willReturn(['gone' => 'deleted', 'bob' => 'disabled']);
    }

    private function user(string $uid, bool $enabled = true, ?string $displayName = null): IUser&MockObject {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $user->method('isEnabled')->willReturn($enabled);
        $user->method('getDisplayName')->willReturn($displayName ?? $uid);
        return $user;
    }

    /** Make "dana" an enabled account that can be picked as the new owner. */
    private function newOwnerIsDana(): void {
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
            'share_type' => IShare::TYPE_LINK,
            'uid_owner' => 'gone',
            'uid_initiator' => 'gone',
            'share_with' => null,
            'permissions' => self::PERM_READ,
            'file_source' => 100 + $id,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function candidates(array $rows): void {
        $this->mapper->method('findTransferCandidates')->willReturn($rows);
    }

    /** Dana reaches every file, with $permissions on it. */
    private function danaSees(int $permissions, bool $shareable = true): void {
        $node = $this->createMock(Node::class);
        $node->method('getPermissions')->willReturn($permissions);
        $node->method('isShareable')->willReturn($shareable);
        $this->nodes->method('resolve')->with('dana')->willReturn($node);
    }

    // -------------------------------------------------------------------
    // Who can take shares over
    // -------------------------------------------------------------------

    public function testAnUnknownNewOwnerIsRefused(): void {
        $this->userManager->method('get')->willReturn(null);
        $this->mapper->expects($this->never())->method('reassignOwner');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->transfer([1], 'nobody');
    }

    public function testADisabledNewOwnerIsRefused(): void {
        $this->userManager->method('get')->willReturn($this->user('dana', false));
        $this->mapper->expects($this->never())->method('reassignOwner');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->transfer([1], 'dana');
    }

    public function testNothingToTransferDoesNotTouchTheDatabase(): void {
        $this->newOwnerIsDana();
        $this->mapper->expects($this->never())->method('findTransferCandidates');

        $this->assertSame(
            ['transferred' => 0, 'skipped' => [], 'failed' => []],
            $this->service->transfer([0, -3], 'dana'),
        );
    }

    // -------------------------------------------------------------------
    // The happy path
    // -------------------------------------------------------------------

    public function testAnOrphanShareTheNewOwnerReachesIsHandedOver(): void {
        $this->newOwnerIsDana();
        $this->candidates([$this->row(7)]);
        $this->danaSees(self::PERM_ALL);
        $this->mapper->expects($this->once())->method('reassignOwner')->with(7, 'gone', 'dana')->willReturn(true);

        $result = $this->service->transfer([7], 'dana');

        $this->assertSame(['transferred' => 1, 'skipped' => [], 'failed' => []], $result);
    }

    public function testATransferIsAuditedAndInvalidatesTheAlertCaches(): void {
        $this->newOwnerIsDana();
        $this->candidates([$this->row(7), $this->row(8, ['uid_owner' => 'bob', 'uid_initiator' => 'erin'])]);
        $this->danaSees(self::PERM_ALL);
        $this->mapper->method('reassignOwner')->willReturn(true);

        $this->auditLogger->expects($this->once())->method('logTransfer')->with(
            $this->callback(static fn (array $rows) => array_column($rows, 'id') === [7, 8]),
            'dana',
        );
        // The new owner, both previous owners and the reshare's author, once each.
        $this->analyzer->expects($this->once())->method('invalidate')->with(
            'dana', 'gone', 'bob', 'erin',
        );

        $this->service->transfer([7, 8], 'dana');
    }

    public function testNothingIsAuditedWhenNothingMoved(): void {
        $this->newOwnerIsDana();
        $this->candidates([$this->row(7, ['share_type' => IShare::TYPE_EMAIL])]);
        $this->auditLogger->expects($this->never())->method('logTransfer');
        $this->analyzer->expects($this->never())->method('invalidate');

        $this->service->transfer([7], 'dana');
    }

    public function testCoreIsToldTheShareChangedHands(): void {
        $this->newOwnerIsDana();
        $this->candidates([$this->row(7)]);
        $this->danaSees(self::PERM_ALL);
        $this->mapper->method('reassignOwner')->willReturn(true);
        $share = $this->createMock(IShare::class);
        $this->shareManager->expects($this->once())->method('getShareById')
            ->with('ocinternal:7', null, false)->willReturn($share);

        $this->dispatcher->expects($this->once())->method('dispatchTyped')
            ->with($this->callback(static fn ($event) => $event instanceof ShareTransferredEvent
                && $event->getShare() === $share));

        $this->service->transfer([7], 'dana');
    }

    public function testATransferStillCountsWhenAnnouncingItFails(): void {
        $this->newOwnerIsDana();
        $this->candidates([$this->row(7)]);
        $this->danaSees(self::PERM_ALL);
        $this->mapper->method('reassignOwner')->willReturn(true);
        $this->shareManager->method('getShareById')->willThrowException(new \RuntimeException('no node'));

        $result = $this->service->transfer([7], 'dana');

        $this->assertSame(1, $result['transferred']);
        $this->assertSame([], $result['failed']);
    }

    // -------------------------------------------------------------------
    // Shares that must stay where they are
    // -------------------------------------------------------------------

    public function testAShareOfAnActiveOwnerIsNeverMoved(): void {
        $this->newOwnerIsDana();
        $this->candidates([$this->row(7, ['uid_owner' => 'carol'])]);
        $this->mapper->expects($this->never())->method('reassignOwner');

        $result = $this->service->transfer([7], 'dana');

        $this->assertSame([['id' => 7, 'reason' => OrphanTransferService::SKIP_NOT_ORPHAN]], $result['skipped']);
    }

    public function testAnIdThatNoLongerExistsIsReportedNotSilentlyDropped(): void {
        $this->newOwnerIsDana();
        $this->candidates([]);

        $result = $this->service->transfer([7], 'dana');

        $this->assertSame([['id' => 7, 'reason' => OrphanTransferService::SKIP_NOT_ORPHAN]], $result['skipped']);
    }

    /**
     * The UI is expected to already hide "Transfer" for a row whose
     * sourceExists is false (see OrphanShareService), but the endpoint must
     * refuse it too — a stale client or a direct API call must never end up
     * transferring a share whose file is simply gone.
     */
    public function testAShareWhoseSourceIsGoneCannotBeTransferred(): void {
        $this->newOwnerIsDana();
        $this->candidates([$this->row(7, ['source_exists' => 0])]);
        $this->nodes->expects($this->never())->method('resolve');
        $this->mapper->expects($this->never())->method('reassignOwner');

        $result = $this->service->transfer([7], 'dana');

        $this->assertSame(OrphanTransferService::SKIP_SOURCE_MISSING, $result['skipped'][0]['reason']);
    }

    /**
     * A missing source is checked first: it would otherwise fall through to
     * SKIP_UNSUPPORTED_TYPE or SKIP_NO_ACCESS, both misleading for a file
     * that doesn't exist for anyone, not just the new owner.
     */
    public function testSourceMissingIsReportedBeforeUnsupportedType(): void {
        $this->newOwnerIsDana();
        $this->candidates([$this->row(7, ['source_exists' => 0, 'share_type' => IShare::TYPE_REMOTE])]);

        $result = $this->service->transfer([7], 'dana');

        $this->assertSame(OrphanTransferService::SKIP_SOURCE_MISSING, $result['skipped'][0]['reason']);
    }

    public function testATypeWhoseStateLivesElsewhereIsNotMoved(): void {
        $this->newOwnerIsDana();
        $this->candidates([
            $this->row(1, ['share_type' => IShare::TYPE_REMOTE]),
            $this->row(2, ['share_type' => IShare::TYPE_ROOM]),
            $this->row(3, ['share_type' => IShare::TYPE_EMAIL]),
        ]);
        $this->nodes->expects($this->never())->method('resolve');
        $this->mapper->expects($this->never())->method('reassignOwner');

        $result = $this->service->transfer([1, 2, 3], 'dana');

        $this->assertSame(0, $result['transferred']);
        $this->assertSame(
            array_fill(0, 3, OrphanTransferService::SKIP_UNSUPPORTED_TYPE),
            array_column($result['skipped'], 'reason'),
        );
    }

    public function testAShareForTheNewOwnerCannotBecomeTheirOwn(): void {
        $this->newOwnerIsDana();
        $this->candidates([$this->row(7, ['share_type' => IShare::TYPE_USER, 'share_with' => 'dana'])]);
        $this->mapper->expects($this->never())->method('reassignOwner');

        $result = $this->service->transfer([7], 'dana');

        $this->assertSame(OrphanTransferService::SKIP_RECIPIENT, $result['skipped'][0]['reason']);
    }

    public function testAGroupShareTheNewOwnerBelongsToIsFine(): void {
        $this->newOwnerIsDana();
        $this->candidates([$this->row(7, ['share_type' => IShare::TYPE_GROUP, 'share_with' => 'dana'])]);
        $this->danaSees(self::PERM_ALL);
        $this->mapper->method('reassignOwner')->willReturn(true);

        $this->assertSame(1, $this->service->transfer([7], 'dana')['transferred']);
    }

    public function testAFileTheNewOwnerCannotReachStaysWithTheDepartedOwner(): void {
        $this->newOwnerIsDana();
        $this->candidates([$this->row(7)]);
        $this->nodes->method('resolve')->willReturn(null);
        $this->mapper->expects($this->never())->method('reassignOwner');

        $result = $this->service->transfer([7], 'dana');

        $this->assertSame([['id' => 7, 'reason' => OrphanTransferService::SKIP_NO_ACCESS]], $result['skipped']);
    }

    public function testAShareWithoutAFileIsTreatedAsUnreachable(): void {
        $this->newOwnerIsDana();
        $this->candidates([$this->row(7, ['file_source' => null])]);
        $this->nodes->expects($this->never())->method('resolve');

        $result = $this->service->transfer([7], 'dana');

        $this->assertSame(OrphanTransferService::SKIP_NO_ACCESS, $result['skipped'][0]['reason']);
    }

    public function testAFileTheNewOwnerMayNotShareIsNotMoved(): void {
        $this->newOwnerIsDana();
        $this->candidates([$this->row(7)]);
        $this->danaSees(self::PERM_ALL_BUT_SHARE, false);
        $this->mapper->expects($this->never())->method('reassignOwner');

        $result = $this->service->transfer([7], 'dana');

        $this->assertSame(OrphanTransferService::SKIP_NOT_SHAREABLE, $result['skipped'][0]['reason']);
    }

    public function testAShareCannotGrantMoreThanTheNewOwnerHolds(): void {
        $this->newOwnerIsDana();
        $this->candidates([$this->row(7, ['permissions' => self::PERM_ALL])]);
        $this->danaSees(self::PERM_READ | 16);
        $this->mapper->expects($this->never())->method('reassignOwner');

        $result = $this->service->transfer([7], 'dana');

        $this->assertSame(OrphanTransferService::SKIP_PERMISSIONS, $result['skipped'][0]['reason']);
    }

    public function testAShareWithFewerPermissionsThanTheNewOwnerHasIsFine(): void {
        $this->newOwnerIsDana();
        $this->candidates([$this->row(7, ['permissions' => self::PERM_READ])]);
        $this->danaSees(self::PERM_ALL);
        $this->mapper->method('reassignOwner')->willReturn(true);

        $this->assertSame(1, $this->service->transfer([7], 'dana')['transferred']);
    }

    // -------------------------------------------------------------------
    // Batches and races
    // -------------------------------------------------------------------

    public function testAShareWhoseOwnerChangedMeanwhileIsReportedNotCounted(): void {
        $this->newOwnerIsDana();
        $this->candidates([$this->row(7)]);
        $this->danaSees(self::PERM_ALL);
        $this->mapper->method('reassignOwner')->willReturn(false);
        $this->auditLogger->expects($this->never())->method('logTransfer');

        $result = $this->service->transfer([7], 'dana');

        $this->assertSame(0, $result['transferred']);
        $this->assertSame(OrphanTransferService::SKIP_NOT_ORPHAN, $result['skipped'][0]['reason']);
    }

    public function testOneFailureDoesNotStopTheRest(): void {
        $this->newOwnerIsDana();
        $this->candidates([$this->row(1), $this->row(2), $this->row(3)]);
        $this->danaSees(self::PERM_ALL);
        $this->mapper->method('reassignOwner')->willReturnCallback(
            static function (int $id): bool {
                if ($id === 2) {
                    throw new \RuntimeException('deadlock');
                }
                return true;
            },
        );

        $result = $this->service->transfer([1, 2, 3], 'dana');

        $this->assertSame(2, $result['transferred']);
        $this->assertSame([2], $result['failed']);
        $this->assertSame([], $result['skipped']);
    }

    public function testEveryShareIsAccountedForExactlyOnce(): void {
        $this->newOwnerIsDana();
        $this->candidates([
            $this->row(1),
            $this->row(2, ['uid_owner' => 'carol']),
            $this->row(3, ['share_type' => IShare::TYPE_REMOTE]),
        ]);
        $this->danaSees(self::PERM_ALL);
        $this->mapper->method('reassignOwner')->willReturn(true);

        $result = $this->service->transfer([1, 2, 3, 4, 1], 'dana');

        $this->assertSame(1, $result['transferred']);
        $this->assertSame([2, 3, 4], array_column($result['skipped'], 'id'));
    }

    // -------------------------------------------------------------------
    // The new-owner picker
    // -------------------------------------------------------------------

    public function testThePickerMergesNameAndIdMatchesOnceEach(): void {
        $this->userManager->method('searchDisplayName')->willReturn([
            $this->user('zed', true, 'Zed Zulu'),
            $this->user('dana', true, 'Dana Dias'),
        ]);
        $this->userManager->method('search')->willReturn([
            $this->user('dana', true, 'Dana Dias'),
            $this->user('amy', true, 'Amy Alves'),
        ]);

        $this->assertSame(
            [
                ['uid' => 'amy', 'displayName' => 'Amy Alves'],
                ['uid' => 'dana', 'displayName' => 'Dana Dias'],
                ['uid' => 'zed', 'displayName' => 'Zed Zulu'],
            ],
            $this->service->searchTargets('a'),
        );
    }

    public function testThePickerNeverOffersADisabledAccount(): void {
        $this->userManager->method('searchDisplayName')->willReturn([
            $this->user('bob', false, 'Bob'),
            $this->user('dana', true, 'Dana'),
        ]);
        $this->userManager->method('search')->willReturn([]);

        $this->assertSame([['uid' => 'dana', 'displayName' => 'Dana']], $this->service->searchTargets(''));
    }

    public function testThePickerStopsAtTheLimit(): void {
        $many = array_map(fn (int $i) => $this->user('u' . $i, true, 'User ' . $i), range(10, 40));
        $this->userManager->method('searchDisplayName')->willReturn($many);
        $this->userManager->method('search')->willReturn([]);

        $this->assertCount(5, $this->service->searchTargets('u', 5));
    }
}
