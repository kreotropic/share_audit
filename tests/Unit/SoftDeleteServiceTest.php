<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Tests\Unit;

use OCA\ShareAuditDashboard\Db\DeletedShare;
use OCA\ShareAuditDashboard\Db\DeletedShareMapper;
use OCA\ShareAuditDashboard\Service\DisplayNameResolver;
use OCA\ShareAuditDashboard\Service\FileNodeResolver;
use OCA\ShareAuditDashboard\Service\PasswordGeneratorService;
use OCA\ShareAuditDashboard\Service\RecipientDetailsResolver;
use OCA\ShareAuditDashboard\Service\SecurityAnalyzerService;
use OCA\ShareAuditDashboard\Service\SettingsService;
use OCA\ShareAuditDashboard\Service\ShareAuditLogger;
use OCA\ShareAuditDashboard\Service\SoftDeleteService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Node;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the two capture paths (from a live IShare vs. a raw oc_share row —
 * see SoftDeleteListener and ShareDeletionService::deleteDirect()), and
 * restore()'s error handling: it must never delete the retention row unless
 * a brand new share was actually created, and must degrade gracefully (not
 * throw) when the original file is gone or token/password can't be restored
 * verbatim onto the new share.
 */
class SoftDeleteServiceTest extends TestCase {

    private DeletedShareMapper&MockObject $mapper;
    private IManager&MockObject $shareManager;
    private FileNodeResolver&MockObject $nodeResolver;
    private IDBConnection&MockObject $db;
    private ITimeFactory&MockObject $time;
    private SettingsService&MockObject $settings;
    private IUserSession&MockObject $userSession;
    private DisplayNameResolver&MockObject $displayNames;
    private SecurityAnalyzerService&MockObject $analyzer;
    private RecipientDetailsResolver&MockObject $recipientDetails;
    private ShareAuditLogger&MockObject $auditLogger;
    private PasswordGeneratorService&MockObject $passwords;
    private LoggerInterface&MockObject $logger;
    private SoftDeleteService $service;

    protected function setUp(): void {
        $this->mapper = $this->createMock(DeletedShareMapper::class);
        $this->shareManager = $this->createMock(IManager::class);
        $this->nodeResolver = $this->createMock(FileNodeResolver::class);
        $this->db = $this->createMock(IDBConnection::class);
        $this->time = $this->createMock(ITimeFactory::class);
        $this->settings = $this->createMock(SettingsService::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->displayNames = $this->createMock(DisplayNameResolver::class);
        $this->analyzer = $this->createMock(SecurityAnalyzerService::class);
        $this->recipientDetails = $this->createMock(RecipientDetailsResolver::class);
        $this->auditLogger = $this->createMock(ShareAuditLogger::class);
        $this->passwords = $this->createMock(PasswordGeneratorService::class);
        $this->passwords->method('generate')->willReturn('Temp-Passw0rd!');
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new SoftDeleteService(
            $this->mapper,
            $this->shareManager,
            $this->nodeResolver,
            $this->db,
            $this->time,
            $this->settings,
            $this->userSession,
            $this->displayNames,
            $this->analyzer,
            $this->recipientDetails,
            $this->auditLogger,
            $this->passwords,
            $this->logger,
        );
    }

    // -------------------------------------------------------------------
    // captureShare() / captureRow() — purge_after must be deleted_at +
    // retentionDays, and deleted_by must be the current user, regardless of
    // which capture path (event listener vs. deleteDirect fallback) is used.
    // -------------------------------------------------------------------

    public function testCaptureShareSetsPurgeAfterFromRetentionDays(): void {
        $this->time->method('getTime')->willReturn(1000);
        $this->settings->method('getRetentionDays')->willReturn(30);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        $share = $this->createMock(IShare::class);
        // String, not int: Nextcloud 34 declares IShare::getId(): string, and
        // PHPUnit enforces a mock's declared return type. NC 33 left it
        // untyped, which is why an int used to pass.
        $share->method('getId')->willReturn('42');
        $share->method('getShareType')->willReturn(IShare::TYPE_LINK);
        $share->method('getSharedWith')->willReturn('');
        $share->method('getShareOwner')->willReturn('bob');
        $share->method('getSharedBy')->willReturn('bob');
        $share->method('getNodeType')->willReturn('file');
        $share->method('getNodeId')->willReturn(99);
        $share->method('getTarget')->willReturn('/photo.png');
        $share->method('getPermissions')->willReturn(1);
        $share->method('getToken')->willReturn('tok123');
        $share->method('getPassword')->willReturn(null);
        $share->method('getLabel')->willReturn(null);
        $share->method('getExpirationDate')->willReturn(null);
        $share->method('getShareTime')->willReturn(null);

        $captured = null;
        $this->mapper->expects($this->once())->method('insert')
            ->with($this->callback(function (DeletedShare $e) use (&$captured) {
                $captured = $e;
                return true;
            }));

        $this->service->captureShare($share);

        $this->assertSame(42, $captured->getOriginalShareId());
        $this->assertSame('bob', $captured->getUidOwner());
        $this->assertSame('alice', $captured->getDeletedBy());
        $this->assertSame(1000, $captured->getDeletedAt());
        $this->assertSame(1000 + 30 * 86400, $captured->getPurgeAfter());
        $this->assertTrue($captured->getSourceExistsAtDeletion());
    }

    /**
     * The owner being gone (why the app captured this at all elsewhere) is
     * a different problem from the file itself being gone — see issue #21.
     * Only a definite NotFoundException from getNode() counts as "gone".
     */
    public function testCaptureShareRecordsWhenTheFileNoLongerExists(): void {
        $this->time->method('getTime')->willReturn(1000);
        $this->settings->method('getRetentionDays')->willReturn(30);
        $this->userSession->method('getUser')->willReturn(null);

        $share = $this->createMock(IShare::class);
        $share->method('getId')->willReturn('42');
        $share->method('getShareType')->willReturn(IShare::TYPE_LINK);
        $share->method('getSharedWith')->willReturn('');
        $share->method('getShareOwner')->willReturn('bob');
        $share->method('getSharedBy')->willReturn('bob');
        $share->method('getNodeType')->willReturn('file');
        $share->method('getNodeId')->willReturn(99);
        $share->method('getTarget')->willReturn('/photo.png');
        $share->method('getPermissions')->willReturn(1);
        $share->method('getToken')->willReturn(null);
        $share->method('getPassword')->willReturn(null);
        $share->method('getLabel')->willReturn(null);
        $share->method('getExpirationDate')->willReturn(null);
        $share->method('getShareTime')->willReturn(null);
        $share->method('getNode')->willThrowException(new \OCP\Files\NotFoundException());

        $captured = null;
        $this->mapper->method('insert')->with($this->callback(function (DeletedShare $e) use (&$captured) {
            $captured = $e;
            return true;
        }));

        $this->service->captureShare($share);

        $this->assertFalse($captured->getSourceExistsAtDeletion());
    }

    public function testCaptureRowBuildsEntityFromRawArray(): void {
        $this->time->method('getTime')->willReturn(500);
        $this->settings->method('getRetentionDays')->willReturn(7);
        $this->userSession->method('getUser')->willReturn(null);
        $this->stubFileExistsQuery(true);

        $captured = null;
        $this->mapper->expects($this->once())->method('insert')
            ->with($this->callback(function (DeletedShare $e) use (&$captured) {
                $captured = $e;
                return true;
            }));

        $this->service->captureRow([
            'id' => 7,
            'share_type' => IShare::TYPE_USER,
            'share_with' => 'carol',
            'uid_owner' => 'dave',
            'uid_initiator' => 'dave',
            'item_type' => 'file',
            'file_source' => 55,
            'file_target' => '/doc.pdf',
            'permissions' => 31,
            'token' => null,
            'password' => null,
            'label' => 'Contract draft',
            'expiration' => null,
            'stime' => 100,
        ]);

        $this->assertSame(7, $captured->getOriginalShareId());
        $this->assertSame('carol', $captured->getShareWith());
        $this->assertSame('dave', $captured->getUidOwner());

        $this->assertSame('Contract draft', $captured->getShareName());
        $this->assertNull($captured->getDeletedBy());
        $this->assertSame(500 + 7 * 86400, $captured->getPurgeAfter());
        $this->assertTrue($captured->getSourceExistsAtDeletion());
    }

    public function testCaptureRowTreatsEmptyLabelAsNoName(): void {
        $this->time->method('getTime')->willReturn(500);
        $this->settings->method('getRetentionDays')->willReturn(7);
        $this->userSession->method('getUser')->willReturn(null);
        $this->stubFileExistsQuery(true);

        $captured = null;
        $this->mapper->expects($this->once())->method('insert')
            ->with($this->callback(function (DeletedShare $e) use (&$captured) {
                $captured = $e;
                return true;
            }));

        // oc_share stores "no label" as '' (or NULL), never as a name.
        $this->service->captureRow([
            'id' => 8,
            'share_type' => IShare::TYPE_LINK,
            'uid_owner' => 'dave',
            'item_type' => 'file',
            'file_source' => 55,
            'permissions' => 1,
            'label' => '',
        ]);

        $this->assertNull($captured->getShareName());
    }

    public function testCaptureRowRecordsWhenTheFileNoLongerExistsInFilecache(): void {
        $this->time->method('getTime')->willReturn(500);
        $this->settings->method('getRetentionDays')->willReturn(7);
        $this->userSession->method('getUser')->willReturn(null);
        $this->stubFileExistsQuery(false);

        $captured = null;
        $this->mapper->method('insert')->with($this->callback(function (DeletedShare $e) use (&$captured) {
            $captured = $e;
            return true;
        }));

        $this->service->captureRow([
            'id' => 9,
            'share_type' => IShare::TYPE_LINK,
            'uid_owner' => 'dave',
            'item_type' => 'file',
            'file_source' => 55,
            'permissions' => 1,
        ]);

        $this->assertFalse($captured->getSourceExistsAtDeletion());
    }

    // -------------------------------------------------------------------
    // restore() — must never delete the retention entry unless a new share
    // was actually created.
    // -------------------------------------------------------------------

    private function retainedLinkEntity(): DeletedShare {
        $e = new DeletedShare();
        $e->setId(1);
        $e->setOriginalShareId(42);
        $e->setShareType(IShare::TYPE_LINK);
        $e->setShareWith(null);
        $e->setUidOwner('bob');
        $e->setUidInitiator('bob');
        $e->setItemType('file');
        $e->setFileSource(99);
        $e->setFileTarget('/photo.png');
        $e->setPermissions(1);
        $e->setToken('tok123');
        $e->setPassword(null);
        $e->setShareName(null);
        $e->setExpiration(null);
        $e->setStime(100);
        $e->setDeletedAt(1000);
        $e->setDeletedBy('alice');
        $e->setPurgeAfter(1000 + 30 * 86400);
        return $e;
    }

    public function testRestoreFailsWithoutCreatingShareWhenOriginalFileIsGone(): void {
        $entity = $this->retainedLinkEntity();
        $this->mapper->method('find')->with(1)->willReturn($entity);

        $this->nodeResolver->method('resolve')->with('bob', 99)->willReturn(null);

        $this->shareManager->expects($this->never())->method('createShare');
        $this->mapper->expects($this->never())->method('delete');
        $this->analyzer->expects($this->never())->method('invalidate');

        $result = $this->service->restore(1);

        $this->assertFalse($result['success']);
    }

    public function testRestoreDoesNotDeleteRetentionEntryWhenCreateShareThrows(): void {
        $entity = $this->retainedLinkEntity();
        $this->mapper->method('find')->with(1)->willReturn($entity);

        $node = $this->createMock(Node::class);
        $this->nodeResolver->method('resolve')->with('bob', 99)->willReturn($node);

        $this->shareManager->method('newShare')->willReturn($this->createMock(IShare::class));
        $this->shareManager->method('createShare')->willThrowException(new \RuntimeException('nope'));

        $this->mapper->expects($this->never())->method('delete');
        $this->analyzer->expects($this->never())->method('invalidate');

        $result = $this->service->restore(1);

        $this->assertFalse($result['success']);
    }

    public function testRestoreReturnsNotFoundMessageWhenRetentionEntryIsGone(): void {
        $this->mapper->method('find')->with(1)->willThrowException(new DoesNotExistException('gone'));

        $this->shareManager->expects($this->never())->method('newShare');
        $this->analyzer->expects($this->never())->method('invalidate');

        $result = $this->service->restore(1);

        $this->assertFalse($result['success']);
    }

    public function testRestoreDeletesRetentionEntryAndReturnsNewIdOnSuccess(): void {
        $entity = $this->retainedLinkEntity();
        $this->mapper->method('find')->with(1)->willReturn($entity);

        $node = $this->createMock(Node::class);
        $this->nodeResolver->method('resolve')->with('bob', 99)->willReturn($node);

        $this->shareManager->method('newShare')->willReturn($this->createMock(IShare::class));
        $created = $this->createMock(IShare::class);
        $created->method('getId')->willReturn('777');
        $this->shareManager->method('createShare')->willReturn($created);

        $this->stubTokenPasswordUpdateQuery();

        $this->mapper->expects($this->once())->method('delete')->with($entity);
        // The restored share is exactly as risky as before it was revoked —
        // without invalidating the alerts cache, the Security alerts list
        // and its tab badge stay stale for up to CACHE_TTL seconds.
        $this->analyzer->expects($this->once())->method('invalidate')->with('bob', 'bob');

        $this->auditLogger->expects($this->once())->method('logRestore')
            ->with(777, 42, IShare::TYPE_LINK, 'bob');

        $result = $this->service->restore(1);

        $this->assertTrue($result['success']);
        $this->assertSame(777, $result['id']);
        $this->assertFalse($result['tokenChanged']);
    }

    /**
     * A stored expiration already in the past (the retention window can
     * easily outlast a short original expiration — see the docblock on
     * restore()) must not fail the whole restore: IShareManager rejects a
     * past expiration outright, so restore() must skip setting one instead.
     */
    public function testRestoreDropsAnExpirationAlreadyInThePastRatherThanFailing(): void {
        $entity = $this->retainedLinkEntity();
        $entity->setExpiration('2000-01-01 00:00:00');
        $this->mapper->method('find')->with(1)->willReturn($entity);

        $node = $this->createMock(Node::class);
        $this->nodeResolver->method('resolve')->with('bob', 99)->willReturn($node);

        $newShare = $this->createMock(IShare::class);
        $newShare->expects($this->never())->method('setExpirationDate');
        $this->shareManager->method('newShare')->willReturn($newShare);
        $created = $this->createMock(IShare::class);
        $created->method('getId')->willReturn('777');
        $this->shareManager->method('createShare')->willReturn($created);

        $this->stubTokenPasswordUpdateQuery();

        $result = $this->service->restore(1);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['expirationCleared']);
    }

    public function testRestoreKeepsAnExpirationStillInTheFuture(): void {
        $entity = $this->retainedLinkEntity();
        $entity->setExpiration((new \DateTimeImmutable('+30 days'))->format('Y-m-d H:i:s'));
        $this->mapper->method('find')->with(1)->willReturn($entity);

        $node = $this->createMock(Node::class);
        $this->nodeResolver->method('resolve')->with('bob', 99)->willReturn($node);

        $newShare = $this->createMock(IShare::class);
        $newShare->expects($this->once())->method('setExpirationDate')->with($this->isInstanceOf(\DateTime::class));
        $this->shareManager->method('newShare')->willReturn($newShare);
        $created = $this->createMock(IShare::class);
        $created->method('getId')->willReturn('777');
        $this->shareManager->method('createShare')->willReturn($created);

        $this->stubTokenPasswordUpdateQuery();

        $result = $this->service->restore(1);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['expirationCleared']);
    }

    /**
     * The raw UPDATE that was supposed to put the original password back
     * failed (most likely cause: the original token got reused by another
     * share while this one sat in the bin — token is UNIQUE). The new share
     * only has its temporary password, so restore() must not report success:
     * it undoes the share it just created and keeps the retention entry so
     * the admin can retry.
     */
    public function testRestoreUndoesShareAndKeepsRetentionEntryWhenPasswordCannotBeRestored(): void {
        $entity = $this->retainedLinkEntity();
        $entity->setPassword('hashed-secret');
        $this->mapper->method('find')->with(1)->willReturn($entity);

        $node = $this->createMock(Node::class);
        $this->nodeResolver->method('resolve')->with('bob', 99)->willReturn($node);

        $this->shareManager->method('newShare')->willReturn($this->createMock(IShare::class));
        $created = $this->createMock(IShare::class);
        $created->method('getId')->willReturn('777');
        $this->shareManager->method('createShare')->willReturn($created);

        $qb = $this->stubTokenPasswordUpdateQueryThatFails();
        $qb->expects($this->once())->method('delete')->with('share')->willReturnSelf();

        // The backup must survive so a retry is possible — and losing it
        // here would mean losing the password hash for good.
        $this->mapper->expects($this->never())->method('delete');
        $this->analyzer->expects($this->never())->method('invalidate');

        $result = $this->service->restore(1);

        $this->assertFalse($result['success']);
        $this->assertSame('password_lost', $result['reason']);
    }

    /**
     * oc_share.token is NOT unique in the database (a plain index), so the
     * UPDATE would not fail when another link already answers to the original
     * token — it would quietly leave two links on one URL. restore() has to
     * look, and treat a taken token exactly like a failed UPDATE: with a
     * password that means undoing the share and keeping the retention entry.
     */
    public function testRestoreDoesNotWriteAndUndoesTheShareWhenAnotherShareHasTheOriginalToken(): void {
        $entity = $this->retainedLinkEntity();
        $entity->setPassword('hashed-secret');
        $this->mapper->method('find')->with(1)->willReturn($entity);
        $this->nodeResolver->method('resolve')->with('bob', 99)->willReturn($this->createMock(Node::class));
        $this->shareManager->method('newShare')->willReturn($this->createMock(IShare::class));
        $created = $this->createMock(IShare::class);
        $created->method('getId')->willReturn('777');
        $this->shareManager->method('createShare')->willReturn($created);

        $sets = [];
        $qb = $this->stubRestoreQueries(true, 0, $sets);
        // The only write is the DELETE that undoes the just-created share.
        $qb->expects($this->once())->method('executeStatement');
        $qb->expects($this->once())->method('delete')->with('share')->willReturnSelf();

        $this->mapper->expects($this->never())->method('delete');
        $this->analyzer->expects($this->never())->method('invalidate');

        $result = $this->service->restore(1);

        $this->assertFalse($result['success']);
        $this->assertSame('password_lost', $result['reason']);
        $this->assertSame([], $sets, 'neither the token nor the password was written over the other link\'s');
    }

    public function testRestoreKeepsTheShareWithANewTokenWhenTheOriginalOneIsTakenAndThereWasNoPassword(): void {
        $entity = $this->retainedLinkEntity();
        $this->assertNull($entity->getPassword());
        $this->mapper->method('find')->with(1)->willReturn($entity);
        $this->nodeResolver->method('resolve')->with('bob', 99)->willReturn($this->createMock(Node::class));
        $this->shareManager->method('newShare')->willReturn($this->createMock(IShare::class));
        $created = $this->createMock(IShare::class);
        $created->method('getId')->willReturn('777');
        $this->shareManager->method('createShare')->willReturn($created);

        $sets = [];
        $qb = $this->stubRestoreQueries(true, 0, $sets);
        $qb->expects($this->never())->method('executeStatement');
        $qb->expects($this->never())->method('delete');

        $this->mapper->expects($this->once())->method('delete')->with($entity);

        $result = $this->service->restore(1);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['tokenChanged']);
        $this->assertSame([], $sets);
    }

    public function testAnEmptyTokenIsNotAUrlAnotherShareCanBeOn(): void {
        // Deck and Talk rows carry '' in oc_share.token, and many of them share it.
        $entity = $this->retainedLinkEntity();
        $entity->setToken('');
        $this->mapper->method('find')->with(1)->willReturn($entity);
        $this->nodeResolver->method('resolve')->with('bob', 99)->willReturn($this->createMock(Node::class));
        $this->shareManager->method('newShare')->willReturn($this->createMock(IShare::class));
        $created = $this->createMock(IShare::class);
        $created->method('getId')->willReturn('777');
        $this->shareManager->method('createShare')->willReturn($created);

        $qb = $this->stubRestoreQueries(true);
        $qb->expects($this->never())->method('executeQuery');

        $result = $this->service->restore(1);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['tokenChanged']);
    }

    public function testRestoreLooksForAnotherShareWithTheTokenNotForItself(): void {
        $entity = $this->retainedLinkEntity();
        $this->mapper->method('find')->with(1)->willReturn($entity);
        $this->nodeResolver->method('resolve')->with('bob', 99)->willReturn($this->createMock(Node::class));
        $this->shareManager->method('newShare')->willReturn($this->createMock(IShare::class));
        $created = $this->createMock(IShare::class);
        $created->method('getId')->willReturn('777');
        $this->shareManager->method('createShare')->willReturn($created);

        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturn('expr');
        $expr->expects($this->once())->method('neq')->with('id', 777)->willReturn('expr');
        $result = $this->createMock(\OCP\DB\IResult::class);
        $result->method('fetchOne')->willReturn(false);
        $qb = $this->createMock(IQueryBuilder::class);
        foreach (['select', 'from', 'update', 'set', 'where', 'andWhere', 'setMaxResults'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('expr')->willReturn($expr);
        $qb->method('createNamedParameter')->willReturnArgument(0);
        $qb->method('executeQuery')->willReturn($result);
        $qb->method('executeStatement')->willReturn(1);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->assertTrue($this->service->restore(1)['success']);
    }

    /**
     * Contrast with the test above: when there was never a password to lose
     * (only the link's token could not be kept), the pre-existing, lower-
     * severity behaviour must still hold — the share stays, just with a new
     * URL, reported via tokenChanged.
     */
    public function testRestoreKeepsShareWithNewTokenWhenOnlyTokenCouldNotBeRestored(): void {
        $entity = $this->retainedLinkEntity();
        $this->assertNull($entity->getPassword());
        $this->mapper->method('find')->with(1)->willReturn($entity);

        $node = $this->createMock(Node::class);
        $this->nodeResolver->method('resolve')->with('bob', 99)->willReturn($node);

        $this->shareManager->method('newShare')->willReturn($this->createMock(IShare::class));
        $created = $this->createMock(IShare::class);
        $created->method('getId')->willReturn('777');
        $this->shareManager->method('createShare')->willReturn($created);

        $this->stubTokenPasswordUpdateQueryThatFails();

        $this->mapper->expects($this->once())->method('delete')->with($entity);
        $this->analyzer->expects($this->once())->method('invalidate')->with('bob', 'bob');

        $result = $this->service->restore(1);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['tokenChanged']);
    }

    /**
     * The moment the share exists it must already be protected: it is created
     * with a temporary password — set BEFORE createShare(), or there would be a
     * window where the link is open — and the original hash goes in afterwards.
     */
    public function testRestoreCreatesAPasswordedLinkWithATemporaryPasswordFromTheStart(): void {
        $entity = $this->retainedLinkEntity();
        $entity->setPassword('hashed-secret');
        $this->mapper->method('find')->with(1)->willReturn($entity);
        $this->nodeResolver->method('resolve')->with('bob', 99)->willReturn($this->createMock(Node::class));

        $order = [];
        $newShare = $this->createMock(IShare::class);
        $newShare->expects($this->once())->method('setPassword')->with('Temp-Passw0rd!')
            ->willReturnCallback(function () use (&$order, $newShare) {
                $order[] = 'setPassword';
                return $newShare;
            });
        $this->shareManager->method('newShare')->willReturn($newShare);
        $created = $this->createMock(IShare::class);
        $created->method('getId')->willReturn('777');
        $this->shareManager->method('createShare')->willReturnCallback(function () use (&$order, $created) {
            $order[] = 'createShare';
            return $created;
        });

        $sets = [];
        $this->stubTokenPasswordUpdateQuery($sets);

        $result = $this->service->restore(1);

        $this->assertSame(['setPassword', 'createShare'], $order);
        $this->assertTrue($result['success']);
        $this->assertFalse($result['tokenChanged']);
        // ...and what lands in the row is the ORIGINAL hash, not the temporary password.
        $this->assertSame('hashed-secret', $sets['password']);
        $this->assertSame('tok123', $sets['token']);
        $this->assertNotContains('Temp-Passw0rd!', $sets);
    }

    /**
     * A link that never had a password must not get one: generating and setting
     * a password nobody asked for would lock the owner's own link.
     */
    public function testRestoreDoesNotGiveALinkThatHadNoPasswordOne(): void {
        $entity = $this->retainedLinkEntity();
        $this->assertNull($entity->getPassword());
        $this->mapper->method('find')->with(1)->willReturn($entity);
        $this->nodeResolver->method('resolve')->with('bob', 99)->willReturn($this->createMock(Node::class));

        $newShare = $this->createMock(IShare::class);
        $newShare->expects($this->never())->method('setPassword');
        $this->shareManager->method('newShare')->willReturn($newShare);
        $created = $this->createMock(IShare::class);
        $created->method('getId')->willReturn('777');
        $this->shareManager->method('createShare')->willReturn($created);
        $this->stubTokenPasswordUpdateQuery();

        $this->assertTrue($this->service->restore(1)['success']);
    }

    /**
     * If the compensating DELETE fails as well, the share is left behind — but
     * with a temporary password nobody knows, i.e. closed, not open. restore()
     * must still answer with the failure it was already going to report (not
     * crash), keep the retention entry, and say where the leftover is.
     */
    public function testRestoreReportsPasswordLostEvenWhenUndoingTheShareFails(): void {
        $entity = $this->retainedLinkEntity();
        $entity->setPassword('hashed-secret');
        $this->mapper->method('find')->with(1)->willReturn($entity);
        $this->nodeResolver->method('resolve')->with('bob', 99)->willReturn($this->createMock(Node::class));
        $this->shareManager->method('newShare')->willReturn($this->createMock(IShare::class));
        $created = $this->createMock(IShare::class);
        $created->method('getId')->willReturn('777');
        $this->shareManager->method('createShare')->willReturn($created);

        // UPDATE fails, and so does the DELETE after it.
        $this->stubTokenPasswordUpdateQueryThatFails(2);

        $this->mapper->expects($this->never())->method('delete');
        $this->analyzer->expects($this->never())->method('invalidate');
        $this->logger->expects($this->once())->method('error')
            ->with($this->stringContains('temporary password'), $this->arrayHasKey('id'));

        $result = $this->service->restore(1);

        $this->assertFalse($result['success']);
        $this->assertSame('password_lost', $result['reason']);
    }

    /**
     * captureRow()'s own oc_filecache existence check (fileExistsInCache()).
     */
    private function stubFileExistsQuery(bool $exists): void {
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturn('expr');
        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('expr')->willReturn($expr);
        $qb->method('createNamedParameter')->willReturnArgument(0);
        $result = $this->createMock(\OCP\DB\IResult::class);
        $result->method('fetchOne')->willReturn($exists ? 55 : false);
        $result->method('closeCursor')->willReturn(true);
        $qb->method('executeQuery')->willReturn($result);
        $this->db->method('getQueryBuilder')->willReturn($qb);
    }

    /**
     * The query builder restore() writes through: the "is this token already
     * taken?" SELECT, the raw token/password UPDATE and, when it has to undo
     * itself, the DELETE. Permissive, same pattern as ShareMapperTest: these
     * tests care what restore() decides and reports, not the exact SQL shape.
     *
     * @param bool $tokenInUse what the "is the token taken by another share?" SELECT finds
     * @param int $failingWrites how many of the first writes (UPDATE, then the
     *        DELETE that undoes the share) throw
     * @param array<string, mixed>|null $sets filled with the column => value
     *        pairs the UPDATE sets, for a test that cares what was written
     */
    private function stubRestoreQueries(bool $tokenInUse = false, int $failingWrites = 0, ?array &$sets = null): IQueryBuilder&MockObject {
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturn('expr');
        $expr->method('neq')->willReturn('expr');

        $result = $this->createMock(\OCP\DB\IResult::class);
        $result->method('fetchOne')->willReturn($tokenInUse ? 55 : false);
        $result->method('closeCursor')->willReturn(true);

        $qb = $this->createMock(IQueryBuilder::class);
        foreach (['select', 'from', 'update', 'delete', 'where', 'andWhere', 'setMaxResults'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('set')->willReturnCallback(function (string $column, $value) use (&$sets, $qb) {
            $sets[$column] = $value;
            return $qb;
        });
        $qb->method('expr')->willReturn($expr);
        $qb->method('createNamedParameter')->willReturnArgument(0);
        $qb->method('executeQuery')->willReturn($result);
        $writes = 0;
        $qb->method('executeStatement')->willReturnCallback(function () use (&$writes, $failingWrites) {
            $writes++;
            if ($writes <= $failingWrites) {
                throw new \RuntimeException('database refused the write');
            }
            return 1;
        });
        $this->db->method('getQueryBuilder')->willReturn($qb);
        return $qb;
    }

    /**
     * The raw UPDATE goes through.
     *
     * @param array<string, mixed>|null $sets see stubRestoreQueries()
     */
    private function stubTokenPasswordUpdateQuery(?array &$sets = null): void {
        $this->stubRestoreQueries(false, 0, $sets);
    }

    /**
     * The raw UPDATE throws (a unique index, on an installation that has one)
     * and — by default — the DELETE that undoes the share afterwards works.
     * $failingCalls = 2 makes that DELETE fail as well.
     */
    private function stubTokenPasswordUpdateQueryThatFails(int $failingCalls = 1): IQueryBuilder&MockObject {
        return $this->stubRestoreQueries(false, $failingCalls);
    }

    // -------------------------------------------------------------------
    // purge() / purgeExpired() / count()
    // -------------------------------------------------------------------

    public function testPurgeReturnsFalseWhenEntryAlreadyGone(): void {
        $this->mapper->method('find')->willThrowException(new DoesNotExistException('gone'));
        $this->mapper->expects($this->never())->method('delete');

        $this->assertFalse($this->service->purge(1));
    }

    public function testPurgeDeletesAndReturnsTrueWhenFound(): void {
        $entity = $this->retainedLinkEntity();
        $this->mapper->method('find')->with(1)->willReturn($entity);
        $this->mapper->expects($this->once())->method('delete')->with($entity);
        // Once this runs, no copy of the share is left anywhere in the app —
        // the actual point of no return, unlike a revoke.
        $this->auditLogger->expects($this->once())->method('logPurge')->with([42]);

        $this->assertTrue($this->service->purge(1));
    }

    public function testPurgeDoesNotLogWhenTheEntryWasAlreadyGone(): void {
        $this->mapper->method('find')->willThrowException(new DoesNotExistException('gone'));
        $this->auditLogger->expects($this->never())->method('logPurge');

        $this->assertFalse($this->service->purge(1));
    }

    public function testPurgeExpiredDeletesEveryExpiredEntry(): void {
        $this->time->method('getTime')->willReturn(5000);
        $a = $this->retainedLinkEntity();
        $b = $this->retainedLinkEntity();
        $this->mapper->method('findExpired')->with(5000)->willReturn([$a, $b]);
        $this->mapper->expects($this->exactly(2))->method('delete');

        $this->assertSame(2, $this->service->purgeExpired());
    }

    public function testListExposesSourceExistsAtDeletion(): void {
        $entity = $this->retainedLinkEntity();
        $entity->setSourceExistsAtDeletion(false);
        $this->mapper->method('findPage')->willReturn([$entity]);
        $this->mapper->method('count')->willReturn(1);
        $this->displayNames->method('resolveMany')->willReturn([]);
        $this->recipientDetails->method('decorate')->willReturnArgument(0);

        $result = $this->service->list(1, 25);

        $this->assertFalse($result['items'][0]['sourceExistsAtDeletion']);
    }

    public function testListRunsARowsThroughTheTokenRedactionWhenTheCallerCannotSeeTokens(): void {
        $entity = $this->retainedLinkEntity();
        $entity->setShareType(IShare::TYPE_ROOM);
        $entity->setShareWith('abc12345');
        $this->mapper->method('findPage')->willReturn([$entity]);
        $this->mapper->method('count')->willReturn(1);
        $this->displayNames->method('resolveMany')->willReturn([]);
        $this->recipientDetails->method('decorate')->willReturnArgument(0);
        $this->recipientDetails->expects($this->once())->method('redactRoomTokens')
            ->willReturnCallback(static function (array $items): array {
                $items[0]['recipient'] = '';
                return $items;
            });

        $result = $this->service->list(1, 25, false);

        $this->assertSame('', $result['items'][0]['recipient']);
    }

    public function testListLeavesTheRecipientAloneForACallerWhoCanSeeTokens(): void {
        $entity = $this->retainedLinkEntity();
        $entity->setShareType(IShare::TYPE_ROOM);
        $entity->setShareWith('abc12345');
        $this->mapper->method('findPage')->willReturn([$entity]);
        $this->mapper->method('count')->willReturn(1);
        $this->displayNames->method('resolveMany')->willReturn([]);
        $this->recipientDetails->method('decorate')->willReturnArgument(0);
        $this->recipientDetails->expects($this->never())->method('redactRoomTokens');

        $this->assertSame('abc12345', $this->service->list(1, 25, true)['items'][0]['recipient']);
    }

    public function testCountDelegatesToMapper(): void {
        $this->mapper->method('count')->willReturn(9);
        $this->assertSame(9, $this->service->count());
    }
}
