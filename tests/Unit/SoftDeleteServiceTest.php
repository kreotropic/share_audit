<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Tests\Unit;

use OCA\ShareAuditDashboard\Db\DeletedShare;
use OCA\ShareAuditDashboard\Db\DeletedShareMapper;
use OCA\ShareAuditDashboard\Service\DisplayNameResolver;
use OCA\ShareAuditDashboard\Service\FileNodeResolver;
use OCA\ShareAuditDashboard\Service\SettingsService;
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

        $this->service = new SoftDeleteService(
            $this->mapper,
            $this->shareManager,
            $this->nodeResolver,
            $this->db,
            $this->time,
            $this->settings,
            $this->userSession,
            $this->displayNames,
            $this->createMock(LoggerInterface::class),
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
        $share->method('getId')->willReturn(42);
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
    }

    public function testCaptureRowBuildsEntityFromRawArray(): void {
        $this->time->method('getTime')->willReturn(500);
        $this->settings->method('getRetentionDays')->willReturn(7);
        $this->userSession->method('getUser')->willReturn(null);

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
            'share_name' => null,
            'expiration' => null,
            'stime' => 100,
        ]);

        $this->assertSame(7, $captured->getOriginalShareId());
        $this->assertSame('carol', $captured->getShareWith());
        $this->assertSame('dave', $captured->getUidOwner());
        $this->assertNull($captured->getDeletedBy());
        $this->assertSame(500 + 7 * 86400, $captured->getPurgeAfter());
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

        $result = $this->service->restore(1);

        $this->assertFalse($result['success']);
    }

    public function testRestoreReturnsNotFoundMessageWhenRetentionEntryIsGone(): void {
        $this->mapper->method('find')->with(1)->willThrowException(new DoesNotExistException('gone'));

        $this->shareManager->expects($this->never())->method('newShare');

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
        $created->method('getId')->willReturn(777);
        $this->shareManager->method('createShare')->willReturn($created);

        // Raw token/password restore UPDATE — permissive query builder mock,
        // same pattern as ShareMapperTest: this test cares that the retention
        // row is deleted and the new id is reported, not the exact SQL shape.
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturn('expr');
        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('update')->willReturnSelf();
        $qb->method('set')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('expr')->willReturn($expr);
        $qb->method('createNamedParameter')->willReturnArgument(0);
        $qb->method('executeStatement')->willReturn(1);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->mapper->expects($this->once())->method('delete')->with($entity);

        $result = $this->service->restore(1);

        $this->assertTrue($result['success']);
        $this->assertSame(777, $result['id']);
        $this->assertFalse($result['tokenChanged']);
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

        $this->assertTrue($this->service->purge(1));
    }

    public function testPurgeExpiredDeletesEveryExpiredEntry(): void {
        $this->time->method('getTime')->willReturn(5000);
        $a = $this->retainedLinkEntity();
        $b = $this->retainedLinkEntity();
        $this->mapper->method('findExpired')->with(5000)->willReturn([$a, $b]);
        $this->mapper->expects($this->exactly(2))->method('delete');

        $this->assertSame(2, $this->service->purgeExpired());
    }

    public function testCountDelegatesToMapper(): void {
        $this->mapper->method('count')->willReturn(9);
        $this->assertSame(9, $this->service->count());
    }
}
