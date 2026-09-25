<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Tests\Integration;

use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Acting on a link that has already expired, through the real web server and
 * the real share manager.
 *
 * Asking Nextcloud for an expired share through its validity check deletes that
 * share and then throws "the requested share does not exist anymore". The app
 * used to ask that way for every action, so revoking expired links (the very
 * thing the "already expired" alert invites) removed them and reported a
 * failure, and a second attempt reported "0 of N" for shares that were by then
 * gone. It also meant that changing a password, accepting an alert or even
 * being refused as a non-owner could delete somebody's link.
 */
final class ExpiredShareRemediationTest extends TestCase {

    private static HttpSession $admin;
    private static HttpSession $owner;
    private static HttpSession $stranger;

    public static function setUpBeforeClass(): void {
        Fixtures::boot();
        self::$admin = Fixtures::session(Fixtures::ADMIN);
        self::$owner = Fixtures::session(Fixtures::OWNER);
        self::$stranger = Fixtures::session(Fixtures::USER);
    }

    protected function setUp(): void {
        Fixtures::purgeOwnerShares();
    }

    public static function tearDownAfterClass(): void {
        Fixtures::purgeOwnerShares();
    }

    /** A link of the owner whose expiration date is long past. */
    private function expiredLink(string $name): int {
        $id = (int)Fixtures::link(Fixtures::ownerFile($name))->getId();
        // The share manager refuses to create a link that is already expired, so
        // the date is moved back in the table, as time would have.
        Server::get(IDBConnection::class)->executeStatement(
            'UPDATE *PREFIX*share SET expiration = ? WHERE id = ?',
            ['2020-01-01 00:00:00', $id],
        );
        return $id;
    }

    private function exists(int $id): bool {
        return Fixtures::shareRow($id) !== null;
    }

    public function testTheOwnerCanRevokeAnExpiredLinkFromThePersonalView(): void {
        $id = $this->expiredLink('expired-mine.txt');

        $response = self::$owner->delete("/api/my/shares/$id");

        $this->assertSame(200, $response['status'], 'a revoke that worked must not be reported as a failure');
        $this->assertTrue($response['json']['success']);
        $this->assertFalse($this->exists($id));
        $this->assertNotNull(Fixtures::binIdOf($id), 'and it went through the recycle bin, as any revoke does');
    }

    public function testAnAdminCanRevokeAnExpiredLink(): void {
        $id = $this->expiredLink('expired-admin.txt');

        $response = self::$admin->delete("/api/shares/$id");

        $this->assertSame(200, $response['status']);
        $this->assertTrue($response['json']['success']);
        $this->assertFalse($this->exists($id));
    }

    /** The reported case: select all, revoke, and "0 of 10" on the second try. */
    public function testABulkRevokeOfExpiredLinksSucceedsForAllOfThem(): void {
        $ids = [$this->expiredLink('e1.txt'), $this->expiredLink('e2.txt'), $this->expiredLink('e3.txt')];

        $response = self::$admin->post('/api/shares/bulk', ['action' => 'revoke', 'ids' => $ids]);

        $this->assertSame(200, $response['status']);
        $this->assertSame(3, $response['json']['succeeded']);
        $this->assertSame(0, $response['json']['failed']);
        foreach ($ids as $id) {
            $this->assertFalse($this->exists($id));
        }
    }

    public function testRevokingTheSameLinkAgainIsStillDone(): void {
        $id = $this->expiredLink('twice.txt');
        $this->assertSame(200, self::$owner->delete("/api/my/shares/$id")['status']);

        $again = self::$owner->delete("/api/my/shares/$id");

        $this->assertSame(200, $again['status'], 'a share that is already gone is what the revoke was after');
        $this->assertTrue($again['json']['success']);
        $this->assertTrue($again['json']['alreadyGone']);
    }

    public function testAnAdminRevokingAGoneLinkAgainAlsoCountsItAsDone(): void {
        $id = $this->expiredLink('twice-admin.txt');
        self::$admin->delete("/api/shares/$id");

        $bulk = self::$admin->post('/api/shares/bulk', ['action' => 'revoke', 'ids' => [$id]]);

        $this->assertSame(1, $bulk['json']['succeeded']);
        $this->assertSame(0, $bulk['json']['failed']);
    }

    /** Changing an expired link would make Nextcloud delete it, so it is refused and the link is left alone. */
    public function testAPasswordCannotBeSetOnAnExpiredLinkAndTheLinkSurvives(): void {
        $id = $this->expiredLink('pw.txt');

        $mine = self::$owner->post("/api/my/shares/$id/password");
        $admin = self::$admin->post("/api/shares/$id/password");

        $this->assertSame(409, $mine['status']);
        $this->assertSame('expired', $mine['json']['reason']);
        $this->assertSame(409, $admin['status']);
        $this->assertSame('expired', $admin['json']['reason']);
        $this->assertTrue($this->exists($id), 'refusing is not the same as destroying');
    }

    public function testAnExpirationCannotBeSetOnAnExpiredLinkAndTheLinkSurvives(): void {
        $id = $this->expiredLink('exp.txt');

        $mine = self::$owner->post("/api/my/shares/$id/expiration", ['days' => 30]);
        $admin = self::$admin->post("/api/shares/$id/expiration", ['days' => 30]);

        $this->assertSame(409, $mine['status']);
        $this->assertSame(409, $admin['status']);
        $this->assertTrue($this->exists($id));
    }

    public function testAnAccountThatDoesNotOwnTheLinkIsRefusedWithoutTouchingIt(): void {
        $id = $this->expiredLink('not-yours.txt');

        $response = self::$stranger->delete("/api/my/shares/$id");

        $this->assertSame(403, $response['status']);
        $this->assertTrue($this->exists($id), 'being refused must not delete the link');
    }

    public function testChangingALinkThatIsGoneIsA404WithItsReason(): void {
        $id = $this->expiredLink('gone.txt');
        self::$owner->delete("/api/my/shares/$id");

        $response = self::$owner->post("/api/my/shares/$id/password");

        $this->assertSame(404, $response['status']);
        $this->assertSame('not_found', $response['json']['reason']);
    }

    public function testAcceptingTheAlertOfAnExpiredLinkDoesNotDeleteIt(): void {
        $id = $this->expiredLink('accept.txt');

        $response = self::$admin->post("/api/alerts/$id/ack", ['ruleCodes' => ['already_expired'], 'note' => 'kept on purpose']);

        $this->assertSame(200, $response['status'], json_encode($response['json']));
        $this->assertTrue($this->exists($id), 'accepting an alert is not a revoke');
    }

    /** A link that has not expired is changed as always. */
    public function testALinkThatHasNotExpiredIsStillChangedNormally(): void {
        $id = (int)Fixtures::link(Fixtures::ownerFile('alive.txt'))->getId();

        $response = self::$owner->post("/api/my/shares/$id/password");

        $this->assertSame(200, $response['status']);
        $this->assertTrue($response['json']['success']);
        $this->assertTrue($this->exists($id));
    }
}
