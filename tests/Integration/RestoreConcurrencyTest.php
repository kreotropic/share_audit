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
 * Restoring a revoked share from the recycle bin, several times at once.
 *
 * A double click, or two admins, send the same restore twice within
 * milliseconds. Restore writes the share's ORIGINAL token back, and
 * oc_share.token is a plain index — nothing in the database refuses a second
 * row with the same value — so if both requests get through, two live links end
 * up on one URL, and revoking the one the owner knows leaves the file reachable
 * through the other. The requests here really are concurrent: separate
 * connections to the real web server, real transactions, the real database.
 */
final class RestoreConcurrencyTest extends TestCase {

    /** How many restores are sent at the same moment for one bin entry. */
    private const CONTENDERS = 4;

    /** How many entries are raced, one after the other: a race is not one that always happens. */
    private const ROUNDS = 10;

    private static HttpSession $admin;

    public static function setUpBeforeClass(): void {
        Fixtures::boot();
        self::$admin = Fixtures::session(Fixtures::ADMIN);
    }

    protected function setUp(): void {
        Fixtures::purgeOwnerShares();
    }

    public static function tearDownAfterClass(): void {
        Fixtures::purgeOwnerShares();
    }

    /**
     * A link with (or without) a password, revoked through the app's own API so
     * that the web request's listener is what puts it in the bin.
     *
     * @return array{id: int, token: string, password: ?string, bin: int}
     */
    private function revokedLink(string $name, ?string $password): array {
        $share = Fixtures::link(Fixtures::ownerFile($name), $password);
        $id = (int)$share->getId();
        $row = Fixtures::shareRow($id);
        $this->assertSame(200, self::$admin->delete('/api/shares/' . $id)['status']);
        $bin = Fixtures::binIdOf($id);
        $this->assertNotNull($bin, 'the revoke was captured in the recycle bin');
        return ['id' => $id, 'token' => $row['token'], 'password' => $row['password'], 'bin' => $bin];
    }

    /**
     * @param int $bin
     * @return array<int, array{status: int, body: string, json: mixed, headers: array<string, string>}>
     */
    private function restoreAtOnce(int $bin, int $times = self::CONTENDERS): array {
        return self::$admin->parallel(array_fill(0, $times, ['method' => 'POST', 'path' => "/api/deleted/$bin/restore", 'body' => []]));
    }

    public function testConcurrentRestoresOfOneEntryCreateExactlyOneLink(): void {
        for ($round = 1; $round <= self::ROUNDS; $round++) {
            $withPassword = $round % 2 === 0;
            $link = $this->revokedLink("race-$round.txt", $withPassword ? 'Original-Pass-42!' : null);

            $responses = $this->restoreAtOnce($link['bin']);

            $statuses = array_map(static fn (array $r) => $r['status'], $responses);
            $succeeded = array_filter($responses, static fn (array $r) => $r['status'] === 200);
            $this->assertCount(1, $succeeded, "round $round: exactly one restore may win, got " . implode(',', $statuses));
            foreach ($responses as $response) {
                if ($response['status'] !== 200) {
                    $this->assertSame(422, $response['status'], "round $round");
                    $this->assertSame('not_found', $response['json']['reason'] ?? null, "round $round: the others find the entry gone");
                }
            }

            $this->assertSame(
                1,
                Fixtures::countSharesWithToken($link['token']),
                "round $round: two links must never answer to one token (statuses " . implode(',', $statuses) . ')',
            );
            $restoredId = (int)array_values($succeeded)[0]['json']['id'];
            $restored = Fixtures::shareRow($restoredId);
            $this->assertSame($link['token'], $restored['token'], "round $round: the URL is the original one");
            $this->assertSame($link['password'], $restored['password'], "round $round: and so is the password");
            $this->assertSame(0, Fixtures::countBinEntries($link['id']), "round $round: the entry left the bin once");
        }
    }

    /**
     * The win is worth having only if the loser can be told apart from a
     * failure: the same click sent again, later, must also just say "gone".
     */
    public function testRestoringAnEntryThatIsAlreadyRestoredReportsItAsGone(): void {
        $link = $this->revokedLink('twice.txt', null);

        $first = self::$admin->post("/api/deleted/{$link['bin']}/restore");
        $second = self::$admin->post("/api/deleted/{$link['bin']}/restore");

        $this->assertSame(200, $first['status']);
        $this->assertSame(422, $second['status']);
        $this->assertSame('not_found', $second['json']['reason']);
        $this->assertSame(1, Fixtures::countSharesWithToken($link['token']));
    }

    /**
     * Restore while somebody purges the same entry: whichever gets there
     * first wins, and the loser must not resurrect it or fail loudly.
     */
    public function testARestoreRacingAPurgeNeverLeavesALinkWithNoBackingEntryTwice(): void {
        for ($round = 1; $round <= 6; $round++) {
            $link = $this->revokedLink("purge-race-$round.txt", null);

            $responses = self::$admin->parallel([
                ['method' => 'POST', 'path' => "/api/deleted/{$link['bin']}/restore", 'body' => []],
                ['method' => 'DELETE', 'path' => "/api/deleted/{$link['bin']}"],
                ['method' => 'POST', 'path' => "/api/deleted/{$link['bin']}/restore", 'body' => []],
            ]);

            foreach ($responses as $response) {
                $this->assertNotContains($response['status'], [500, 401, 403, 412], "round $round: " . $response['body']);
            }
            $this->assertLessThanOrEqual(1, Fixtures::countSharesWithToken($link['token']), "round $round");
            $this->assertSame(0, Fixtures::countBinEntries($link['id']), "round $round: nothing is left in the bin");
        }
    }

    // -------------------------------------------------------------------
    // When the original token is not free any more
    // -------------------------------------------------------------------

    /**
     * Make another link answer to $token, as if it had been created while the
     * original sat in the bin (which the token index would not stop).
     */
    private function takeTheToken(string $token, string $name): int {
        $other = (int)Fixtures::link(Fixtures::ownerFile($name))->getId();
        Server::get(IDBConnection::class)->executeStatement('UPDATE *PREFIX*share SET token = ? WHERE id = ?', [$token, $other]);
        return $other;
    }

    /**
     * A link that had a password cannot come back with a different one, and
     * cannot come back on a URL somebody else holds: the restore is refused,
     * the entry stays in the bin, and nothing is left behind — in particular
     * no half-restored link protected by a password nobody knows.
     */
    public function testAPasswordedLinkWhoseTokenWasTakenIsRefusedAndLeavesNothingBehind(): void {
        $link = $this->revokedLink('taken-pw.txt', 'Original-Pass-42!');
        $other = $this->takeTheToken($link['token'], 'taker-pw.txt');
        $sharesBefore = $this->countOwnerShares();

        $response = self::$admin->post("/api/deleted/{$link['bin']}/restore");

        $this->assertSame(422, $response['status']);
        $this->assertSame('password_lost', $response['json']['reason']);
        $this->assertSame(1, Fixtures::countBinEntries($link['id']), 'the backup is still there to retry from');
        $this->assertSame($sharesBefore, $this->countOwnerShares(), 'and no link was left behind');
        $this->assertSame(1, Fixtures::countSharesWithToken($link['token']), 'the other link keeps its token, alone');
        $this->assertNotNull(Fixtures::shareRow($other));

        // Once the other link is gone, the same restore works, on the original URL.
        $this->assertSame(200, self::$admin->delete('/api/shares/' . $other)['status']);
        $retry = self::$admin->post("/api/deleted/{$link['bin']}/restore");
        $this->assertSame(200, $retry['status']);
        $restored = Fixtures::shareRow((int)$retry['json']['id']);
        $this->assertSame($link['token'], $restored['token']);
        $this->assertSame($link['password'], $restored['password']);
    }

    /**
     * Without a password there is nothing to lose but the URL: the link comes
     * back, on a new token — and the owner is told (tokenChanged) — instead of
     * sharing one with somebody else's.
     */
    public function testAnUnprotectedLinkWhoseTokenWasTakenComesBackOnANewToken(): void {
        $link = $this->revokedLink('taken-open.txt', null);
        $this->takeTheToken($link['token'], 'taker-open.txt');

        $response = self::$admin->post("/api/deleted/{$link['bin']}/restore");

        $this->assertSame(200, $response['status']);
        $this->assertTrue($response['json']['tokenChanged']);
        $restored = Fixtures::shareRow((int)$response['json']['id']);
        $this->assertNotSame($link['token'], $restored['token']);
        $this->assertSame(1, Fixtures::countSharesWithToken($link['token']), 'the original token still belongs to one link only');
        $this->assertSame(1, Fixtures::countSharesWithToken($restored['token']));
    }

    private function countOwnerShares(): int {
        $db = Server::get(IDBConnection::class);
        $result = $db->executeQuery('SELECT COUNT(*) FROM *PREFIX*share WHERE uid_owner = ?', [Fixtures::OWNER]);
        $count = (int)$result->fetchOne();
        $result->closeCursor();
        return $count;
    }
}
