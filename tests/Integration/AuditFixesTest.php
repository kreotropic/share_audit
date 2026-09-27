<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Tests\Integration;

use OCA\ShareAuditDashboard\Db\FileMoveMapper;
use OCA\ShareAuditDashboard\Service\OrphanFileMoveService;
use OCA\ShareAuditDashboard\Service\RecipientLookupService;
use OCA\ShareAuditDashboard\Service\SoftDeleteService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;

/**
 * The defects of the September 2026 audit (AUDIT_REPORT.md, F01–F07), each
 * reproduced the way the audit did — real core, real database, real web
 * server — and asserted fixed.
 */
final class AuditFixesTest extends TestCase {

    public static function setUpBeforeClass(): void {
        Fixtures::boot();
    }

    private function user(string $kind): string {
        $uid = 'saf_' . $kind . '_' . bin2hex(random_bytes(3));
        Server::get(IUserManager::class)->createUser($uid, Fixtures::PASSWORD);
        return $uid;
    }

    private function home(string $uid): Folder {
        \OC_Util::setupFS($uid);
        return Server::get(IRootFolder::class)->getUserFolder($uid);
    }

    private function share(Node $node, string $by, int $type = IShare::TYPE_LINK, ?string $with = null, int $permissions = 17): IShare {
        $manager = Server::get(IManager::class);
        $share = $manager->newShare()->setNode($node)->setShareType($type)->setSharedBy($by)->setPermissions($permissions);
        if ($with !== null) {
            $share->setSharedWith($with);
        }
        return $manager->createShare($share);
    }

    // -------------------------------------------------------------------
    // F01 — one deletion, one bin entry; one token, one live share
    // -------------------------------------------------------------------

    public function testTwoRequestsDeletingTheSameShareLeaveOneBinEntry(): void {
        $uid = $this->user('dupdelete');
        $share = $this->share($this->home($uid)->newFile('duplicate.txt', 'synthetic'), $uid);
        $manager = Server::get(IManager::class);
        // What a second request loaded before the first one deleted it.
        $stale = $manager->getShareById($share->getFullId());

        $manager->deleteShare($share);
        $manager->deleteShare($stale);

        $this->assertSame(1, Fixtures::countBinEntries((int)$share->getId()));
    }

    /**
     * Two entries carrying one token (as the bin could hold before there was
     * one entry per share) restored at the same moment, over and over: the
     * token must never end up on two shares.
     */
    public function testRestoringTwoEntriesWithTheSameTokenAtOnceNeverDuplicatesIt(): void {
        $admin = Fixtures::session(Fixtures::ADMIN);
        $db = Server::get(IDBConnection::class);
        for ($round = 1; $round <= 10; $round++) {
            $link = Fixtures::link(Fixtures::ownerFile("dup-token-$round.txt"));
            $id = (int)$link->getId();
            Server::get(IManager::class)->deleteShare($link);
            $bin = Fixtures::binIdOf($id);
            $this->assertNotNull($bin);
            // A second entry for the same link, under another original id so
            // the one-entry-per-share index lets it in.
            $db->executeStatement(
                'INSERT INTO *PREFIX*shareaudit_deleted (original_share_id, share_type, share_with, uid_owner, uid_initiator, item_type, file_source, file_target, permissions, token, password, share_name, expiration, stime, deleted_at, deleted_by, purge_after, source_exists_at_deletion, hide_download, attributes) '
                . 'SELECT original_share_id + 100000000, share_type, share_with, uid_owner, uid_initiator, item_type, file_source, file_target, permissions, token, password, share_name, expiration, stime, deleted_at, deleted_by, purge_after, source_exists_at_deletion, hide_download, attributes FROM *PREFIX*shareaudit_deleted WHERE id = ?',
                [$bin],
            );
            $copy = Fixtures::binIdOf($id + 100000000);
            $this->assertNotNull($copy);

            $responses = $admin->parallel([
                ['method' => 'POST', 'path' => "/api/deleted/$bin/restore", 'body' => []],
                ['method' => 'POST', 'path' => "/api/deleted/$copy/restore", 'body' => []],
            ]);

            foreach ($responses as $response) {
                $this->assertContains($response['status'], [200, 422], "round $round: " . $response['body']);
            }
            $this->assertSame(1, Fixtures::countSharesWithToken($link->getToken()), "round $round: statuses " . implode(',', array_column($responses, 'status')));
        }
        Fixtures::purgeOwnerShares();
    }

    // -------------------------------------------------------------------
    // F02 — a restore gives back no more than the share had
    // -------------------------------------------------------------------

    public function testARestoredLinkKeepsItsDownloadRestrictions(): void {
        $uid = $this->user('restore');
        $file = $this->home($uid)->newFile('restricted.pdf', 'synthetic confidential document');
        $manager = Server::get(IManager::class);
        $share = $manager->newShare()->setNode($file)->setShareType(IShare::TYPE_LINK)->setSharedBy($uid)->setPermissions(1)->setHideDownload(true);
        $attributes = $share->newAttributes();
        $attributes->setAttribute('permissions', 'download', false);
        $share->setAttributes($attributes);
        $share = $manager->createShare($share);
        $before = Fixtures::shareRow((int)$share->getId());
        $manager->deleteShare($share);

        $result = Server::get(SoftDeleteService::class)->restore(Fixtures::binIdOf((int)$share->getId()));

        $this->assertTrue($result['success'], json_encode($result));
        $this->assertFalse($result['downloadHiddenAssumed']);
        $after = Fixtures::shareRow($result['id']);
        $this->assertSame($before['token'], $after['token']);
        $this->assertEquals(1, $after['hide_download']);
        $this->assertSame(json_decode($before['attributes'], true), json_decode((string)$after['attributes'], true));
        $restored = $manager->getShareByToken($before['token']);
        $this->assertTrue($restored->getHideDownload());
        $this->assertFalse($restored->getAttributes()?->getAttribute('permissions', 'download'));
    }

    public function testALinkKeptWithoutItsRestrictionsIsRestoredWithDownloadsHidden(): void {
        $link = Fixtures::link(Fixtures::ownerFile('legacy-entry.txt'));
        Server::get(IManager::class)->deleteShare($link);
        $bin = Fixtures::binIdOf((int)$link->getId());
        // What an entry captured before 0.8.0 looks like.
        Server::get(IDBConnection::class)->executeStatement(
            'UPDATE *PREFIX*shareaudit_deleted SET hide_download = NULL, attributes = NULL WHERE id = ?', [$bin]);

        $result = Server::get(SoftDeleteService::class)->restore($bin);

        $this->assertTrue($result['success'], json_encode($result));
        $this->assertTrue($result['downloadHiddenAssumed']);
        $this->assertEquals(1, Fixtures::shareRow($result['id'])['hide_download']);
        Fixtures::purgeOwnerShares();
    }

    // -------------------------------------------------------------------
    // F03 — the queue moves what was selected, or nothing
    // -------------------------------------------------------------------

    public function testAQueuedMoveWhosePathNowHoldsAnotherFileMovesNothing(): void {
        $owner = $this->user('move');
        $target = $this->user('target');
        $home = $this->home($owner);
        $file = $home->newFile('selected.txt', 'selected');
        $share = $this->share($file, $owner);
        Server::get(IUserManager::class)->get($owner)->setEnabled(false);
        $service = Server::get(OrphanFileMoveService::class);
        $queued = $service->enqueue([(int)$share->getId()], $target, 'path');
        $this->assertCount(1, $queued['queued']);
        $file->move($home->getPath() . '/renamed.txt');
        $replacement = $home->newFile('selected.txt', 'different and never selected');

        $service->run($queued['queued'][0]['id']);

        $move = Server::get(FileMoveMapper::class)->find($queued['queued'][0]['id']);
        $this->assertSame('failed', $move->getStatus());
        $this->assertSame(OrphanFileMoveService::ERROR_SOURCE_CHANGED, $move->getError());
        $this->assertEmpty($this->home($target)->getById($replacement->getId()), 'the file nobody selected stays');
        $this->assertTrue($home->nodeExists('renamed.txt'));
        $this->assertSame($owner, Fixtures::shareRow((int)$share->getId())['uid_owner']);
    }

    public function testAQueuedMoveOfAnUnchangedFileStillMovesIt(): void {
        $owner = $this->user('move');
        $target = $this->user('target');
        $file = $this->home($owner)->newFile('selected.txt', 'selected');
        $share = $this->share($file, $owner);
        Server::get(IUserManager::class)->get($owner)->setEnabled(false);
        $service = Server::get(OrphanFileMoveService::class);
        $queued = $service->enqueue([(int)$share->getId()], $target, 'path');

        $service->run($queued['queued'][0]['id']);

        $this->assertSame('done', Server::get(FileMoveMapper::class)->find($queued['queued'][0]['id'])->getStatus());
        $this->assertSame($target, Fixtures::shareRow((int)$share->getId())['uid_owner']);
    }

    // -------------------------------------------------------------------
    // F05 — a move is not "done" while shares stayed behind
    // -------------------------------------------------------------------

    public function testAMoveWhoseSharesTheCoreCouldNotHandOverIsPartlyDone(): void {
        $owner = $this->user('partial');
        $target = $this->user('target');
        $file = $this->home($owner)->newFile('partial.txt', 'synthetic');
        $share = $this->share($file, $owner);
        Server::get(IUserManager::class)->get($owner)->setEnabled(false);
        $service = Server::get(OrphanFileMoveService::class);
        $queued = $service->enqueue([(int)$share->getId()], $target, 'path');
        $this->assertCount(1, $queued['queued']);

        // The share provider refuses every update; the rest of the transfer is real.
        $core = Server::get(\OCA\Files\Service\OwnershipTransferService::class);
        $property = new \ReflectionProperty($core, 'shareManager');
        $original = $property->getValue($core);
        $faulty = $this->createMock(IManager::class);
        foreach (['getSharesBy', 'getSharedWith', 'getShareById'] as $method) {
            $faulty->method($method)->willReturnCallback(fn (...$args) => $original->$method(...$args));
        }
        $faulty->method('updateShare')->willThrowException(new \RuntimeException('Synthetic provider update failure'));
        $property->setValue($core, $faulty);
        try {
            $service->run($queued['queued'][0]['id']);
        } finally {
            $property->setValue($core, $original);
        }

        $move = Server::get(FileMoveMapper::class)->find($queued['queued'][0]['id']);
        $this->assertNotEmpty($this->home($target)->getById($file->getId()), 'the file did move');
        $this->assertSame($owner, Fixtures::shareRow((int)$share->getId())['uid_owner'], 'the share did not');
        $this->assertSame('partial', $move->getStatus());
        $this->assertSame('shares_not_moved:' . $share->getId(), $move->getError());
    }

    // -------------------------------------------------------------------
    // F06 — the personal view never names the owner's folders
    // -------------------------------------------------------------------

    public function testAReshareIsListedUnderThePathTheResharerSees(): void {
        $owner = $this->user('owner');
        $recipient = $this->user('resharer');
        $file = $this->home($owner)->newFolder('SecretMerger')->newFolder('BoardOnly')->newFile('public.txt', 'synthetic');
        $this->share($file, $owner, IShare::TYPE_USER, $recipient);
        $recipientHome = $this->home($recipient);
        $received = $recipientHome->getById($file->getId())[0];
        $link = $this->share($received, $recipient);

        $session = new HttpSession(Fixtures::BASE_URL, $recipient, Fixtures::PASSWORD);
        $shares = $session->get('/api/my/shares');
        $alerts = $session->get('/api/my/alerts');

        $this->assertSame(200, $shares['status']);
        $visible = '/' . ltrim((string)$recipientHome->getRelativePath($received->getPath()), '/');
        foreach ([$shares['json']['items'], $alerts['json']['items']] as $items) {
            $mine = array_values(array_filter($items, fn ($s) => $s['id'] === (int)$link->getId()));
            $this->assertCount(1, $mine);
            $this->assertSame($visible, $mine[0]['path']);
            $this->assertStringNotContainsString('SecretMerger', $mine[0]['path']);
        }
    }

    // -------------------------------------------------------------------
    // F07 — the lookup says what it covers, and what reaches the user besides
    // -------------------------------------------------------------------

    public function testTheLookupOfAUserReachedOnlyThroughAGroupReportsThatGroup(): void {
        $owner = $this->user('groupowner');
        $member = $this->user('member');
        $group = Server::get(IGroupManager::class)->createGroup('saf_group_' . bin2hex(random_bytes(3)));
        $group->addUser(Server::get(IUserManager::class)->get($member));
        $this->share($this->home($owner)->newFile('group-only.txt', 'synthetic group data'), $owner, IShare::TYPE_GROUP, $group->getGID());
        $lookup = Server::get(RecipientLookupService::class);

        $listed = $lookup->getShares($member, IShare::TYPE_USER);
        $found = $lookup->search($member);

        $this->assertSame(0, $listed['total']);
        $this->assertSame([['shareWith' => $group->getGID(), 'label' => $group->getDisplayName(), 'count' => 1]], $listed['viaGroups']);
        $this->assertContains($member, array_column(array_filter($found, fn ($r) => $r['shareType'] === IShare::TYPE_USER), 'shareWith'),
            'an account with no share of its own can still be looked up');
    }
}
