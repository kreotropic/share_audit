<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * A Talk conversation's token is a credential: whoever has it can join a public
 * one. An admin may see it; an auditor may not — and that has to hold for what
 * an auditor can INFER as well as for what is printed: a search that only finds
 * a token when the guess is right, or an order that follows the tokens, hands
 * it over a piece at a time.
 *
 * These run the real SQL on the real database (the unit tests' query builders
 * are mocks, and say nothing about what a WHERE or an ORDER BY does) through the
 * real web server, as an auditor and as an admin, so that whatever the
 * auditor's requests leak, an admin's would show, and the difference is the
 * test.
 */
final class AuditorTokenSecrecyTest extends TestCase {

    private static HttpSession $admin;
    private static HttpSession $auditor;

    public static function setUpBeforeClass(): void {
        Fixtures::boot();
        self::$admin = Fixtures::session(Fixtures::ADMIN);
        self::$auditor = Fixtures::session(Fixtures::AUDITOR);
    }

    protected function setUp(): void {
        $this->clean();
    }

    protected function tearDown(): void {
        $this->clean();
    }

    private function clean(): void {
        Fixtures::purgeRooms();
        Fixtures::purgeOwnerShares();
    }

    /**
     * @return array<string, string> name => token, each with one share of $file
     */
    private function rooms(int $count, string $file = 'rooms.txt', string $prefix = 'sai-Ordered'): array {
        $node = Fixtures::ownerFile($file);
        $rooms = [];
        for ($i = 1; $i <= $count; $i++) {
            $name = sprintf('%s %02d', $prefix, $i);
            $rooms[$name] = Fixtures::createRoom($name);
            Fixtures::shareIntoRoom($rooms[$name], $node);
        }
        return $rooms;
    }

    /**
     * Deal every conversation a brand new token: same names, same shares.
     *
     * @param array<string, string> $rooms
     * @return array<string, string>
     */
    private function retoken(array $rooms): array {
        foreach ($rooms as $name => $old) {
            $new = Fixtures::randomToken();
            Fixtures::retokenRoom($old, $new);
            $rooms[$name] = $new;
        }
        return $rooms;
    }

    /**
     * @param array<string, mixed> $query
     * @return array<int, array<string, mixed>>
     */
    private function search(HttpSession $who, string $q): array {
        $response = $who->get('/api/recipients/search', ['q' => $q]);
        $this->assertSame(200, $response['status'], $response['body']);
        return $response['json']['items'];
    }

    // -------------------------------------------------------------------
    // Order and cut-off
    // -------------------------------------------------------------------

    /**
     * Twenty-five conversations, one share each — so nothing public tells them
     * apart but their names, and the search shows twenty. Their tokens are
     * dealt out again four times over. If the order or the cut-off followed the
     * tokens the auditor's list would change every time; the list must not move
     * (and an admin's, which does follow them, must — or this proves nothing).
     */
    public function testWhichConversationsAnAuditorSeesAndInWhatOrderDoesNotFollowTheirTokens(): void {
        $rooms = $this->rooms(25);
        $extra = Fixtures::ownerFile('more.txt');
        $busy = 'sai-Ordered 25';
        Fixtures::shareIntoRoom($rooms[$busy], $extra);
        Fixtures::shareIntoRoom($rooms[$busy], $extra);
        // The most shared first; then, all being equal, by name; cut at twenty.
        $expected = [$busy, ...array_map(static fn (int $i) => sprintf('sai-Ordered %02d', $i), range(1, 19))];

        $adminOrders = [];
        $handlesSeen = [];
        for ($round = 1; $round <= 4; $round++) {
            $rooms = $this->retoken($rooms);

            $auditorItems = $this->search(self::$auditor, 'sai-Ordered');
            $this->assertSame($expected, array_column($auditorItems, 'label'), "round $round");
            foreach ($auditorItems as $item) {
                $handlesSeen[$item['shareWith']] = true;
            }

            $adminOrders[] = implode(',', array_column($this->search(self::$admin, 'sai-Ordered'), 'label'));
        }

        $this->assertGreaterThan(1, count(array_unique($adminOrders)), 'the harness does change what a token-ordered list would show');
        $this->assertGreaterThan(20, count($handlesSeen), 'and the tokens did change under the auditor: its handles moved with them, its list did not');
    }

    /**
     * The same question about the shares list: sorted by recipient, the rows
     * into conversations must fall in the same place whatever their tokens are.
     */
    public function testSortingSharesByRecipientDoesNotOrderConversationsByToken(): void {
        $rooms = $this->rooms(12, 'sortme.txt', 'sai-Sorted');
        $user = Fixtures::ownerFile('user-share.txt');
        $manager = \OCP\Server::get(\OCP\Share\IManager::class);
        $share = $manager->newShare();
        $share->setNode($user)->setShareType(\OCP\Share\IShare::TYPE_USER)->setSharedWith(Fixtures::USER)->setSharedBy(Fixtures::OWNER)->setPermissions(1);
        $manager->createShare($share);

        $orders = ['auditor' => [], 'admin' => []];
        for ($round = 1; $round <= 4; $round++) {
            $rooms = $this->retoken($rooms);
            foreach (['auditor' => self::$auditor, 'admin' => self::$admin] as $who => $session) {
                foreach (['asc', 'desc'] as $direction) {
                    $response = $session->get('/api/shares', ['limit' => 500, 'sort' => 'recipient', 'sortDir' => $direction]);
                    $this->assertSame(200, $response['status'], $response['body']);
                    $orders[$who][$direction][] = implode(',', array_column(
                        array_filter($response['json']['items'], static fn (array $i) => $i['type'] === 10),
                        'id',
                    ));
                }
            }
        }

        foreach (['asc', 'desc'] as $direction) {
            $this->assertCount(1, array_unique($orders['auditor'][$direction]), "an auditor's rows sorted $direction fall the same way whatever the tokens");
            $this->assertGreaterThan(1, count(array_unique($orders['admin'][$direction])), "an admin's follow them ($direction) — the check has teeth");
        }
    }

    // -------------------------------------------------------------------
    // Finding by a piece of the token
    // -------------------------------------------------------------------

    public function testATokenCannotBeGuessedAtThroughAnySearchAnAuditorHas(): void {
        $rooms = $this->rooms(8, 'probe.txt', 'sai-Probed');

        foreach ($rooms as $name => $token) {
            foreach ([substr($token, 0, 5), substr($token, 2, 5), substr($token, -5)] as $probe) {
                // The shares list: its general search and its recipient filter.
                foreach (['search', 'recipientSearch'] as $param) {
                    $auditor = self::$auditor->get('/api/shares', [$param => $probe]);
                    $admin = self::$admin->get('/api/shares', [$param => $probe]);
                    $this->assertSame(0, $auditor['json']['total'], "auditor $param=$probe");
                    $this->assertGreaterThanOrEqual(1, $admin['json']['total'], "admin $param=$probe finds it (the probe is real)");
                }
                // The access lookup's autocomplete.
                $this->assertSame([], $this->search(self::$auditor, $probe), "auditor lookup $probe");
                $this->assertNotSame([], $this->search(self::$admin, $probe), "admin lookup $probe");
                // And the export, which takes the same filters.
                $export = self::$auditor->get('/api/export', ['recipientSearch' => $probe]);
                $this->assertSame(1, substr_count(trim($export['body']), "\n") + 1, "auditor export $probe has no row, only its header");
            }
        }
    }

    public function testAConversationIsStillFoundByItsNameEverywhere(): void {
        $rooms = $this->rooms(3, 'byname.txt', 'sai-Findable');

        $items = $this->search(self::$auditor, 'sai-Findable');
        $this->assertSame(['sai-Findable 01', 'sai-Findable 02', 'sai-Findable 03'], array_column($items, 'label'));

        $list = self::$auditor->get('/api/shares', ['recipientSearch' => 'sai-Findable 02']);
        $this->assertSame(1, $list['json']['total']);
        $this->assertSame('sai-Findable 02', $list['json']['items'][0]['recipientInfo']['label']);
        $this->assertSame(array_sum(array_map('intval', [1])), $list['json']['total']);
        unset($rooms);
    }

    // -------------------------------------------------------------------
    // The handle
    // -------------------------------------------------------------------

    public function testAnAuditorReachesAConversationsSharesByItsHandleAndNotByItsToken(): void {
        $rooms = $this->rooms(2, 'handle.txt', 'sai-Handled');
        $extra = Fixtures::ownerFile('handle-more.txt');
        Fixtures::shareIntoRoom($rooms['sai-Handled 01'], $extra);
        $token = $rooms['sai-Handled 01'];

        $item = $this->search(self::$auditor, 'sai-Handled 01')[0];
        $byHandle = self::$auditor->get('/api/recipients/shares', ['shareWith' => $item['shareWith'], 'shareType' => 10]);
        $byToken = self::$auditor->get('/api/recipients/shares', ['shareWith' => $token, 'shareType' => 10]);
        $byNothing = self::$auditor->get('/api/recipients/shares', ['shareWith' => '', 'shareType' => 10]);
        $byNothingAdmin = self::$admin->get('/api/recipients/shares', ['shareWith' => '', 'shareType' => 10]);
        $adminByToken = self::$admin->get('/api/recipients/shares', ['shareWith' => $token, 'shareType' => 10]);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $item['shareWith']);
        $this->assertSame(2, $byHandle['json']['total'], 'by handle: both files shared into that conversation');
        $this->assertSame('sai-Handled 01', $byHandle['json']['recipient']['label']);
        $this->assertSame(0, $byToken['json']['total'], 'a token is not a handle');
        $this->assertSame(0, $byNothing['json']['total'], 'an empty recipient is not "every conversation"');
        $this->assertSame(0, $byNothingAdmin['json']['total'], 'nor for an admin');
        $this->assertSame(2, $adminByToken['json']['total'], 'an admin still looks it up by token');
        $this->assertStringNotContainsString($token, $byHandle['body']);
        $this->assertStringNotContainsString($token, $byToken['body'], 'and the refusal does not repeat it back either');
    }

    // -------------------------------------------------------------------
    // Nowhere at all
    // -------------------------------------------------------------------

    /**
     * Every read an auditor has, with a spread of parameters: the token of
     * every conversation, and of a public link too, must be in none of the
     * answers. The same crawl as an admin does contain them — otherwise a
     * clean result would just mean the crawl looked in the wrong place.
     */
    public function testNoTokenAppearsAnywhereInAnythingAnAuditorCanRead(): void {
        $rooms = $this->rooms(6, 'crawl.txt', 'sai-Crawled');
        $unnamed = Fixtures::createRoom('', 3);
        Fixtures::shareIntoRoom($unnamed, Fixtures::ownerFile('crawl-unnamed.txt'));
        $unknown = Fixtures::randomToken();   // a share into a conversation Talk does not know
        Fixtures::shareIntoRoom($unknown, Fixtures::ownerFile('crawl-unknown.txt'));
        $link = Fixtures::link(Fixtures::ownerFile('crawl-link.txt'));
        // A revoked one as well: the recycle bin lists it.
        $revoked = Fixtures::link(Fixtures::ownerFile('crawl-revoked.txt'));
        $revokedToken = $revoked->getToken();
        $this->assertSame(200, self::$admin->delete('/api/shares/' . $revoked->getId())['status']);

        $secrets = [...array_values($rooms), $unnamed, $unknown, $link->getToken(), $revokedToken];

        $reads = [
            ['/api/stats', []],
            ['/api/exposure', []],
            ['/api/alerts', ['limit' => 0]],
            ['/api/alerts', ['limit' => 500, 'includeAcknowledged' => 'true']],
            ['/api/orphans', ['limit' => 0]],
            ['/api/deleted', ['limit' => 500]],
            ['/api/export', []],
            ['/api/export', ['includeTokens' => 'true']],
            ['/api/export', ['sort' => 'recipient', 'sortDir' => 'asc']],
            ['/api/recipients/search', ['q' => 'sai-']],
            ['/api/recipients/search', ['q' => 'crawl']],
            ['/api/recipients/shares', ['shareWith' => '', 'shareType' => 10, 'limit' => 0]],
            ['/api/recipients/shares', ['shareWith' => $unnamed, 'shareType' => 10, 'limit' => 0]],
        ];
        foreach (['created', 'path', 'owner', 'recipient', 'type', 'expires', 'password'] as $sort) {
            foreach (['asc', 'desc'] as $direction) {
                $reads[] = ['/api/shares', ['limit' => 500, 'sort' => $sort, 'sortDir' => $direction]];
            }
        }
        foreach (['internal', 'external', 'public', 'other'] as $exposure) {
            $reads[] = ['/api/shares', ['limit' => 500, 'exposure' => $exposure]];
            $reads[] = ['/api/export', ['exposure' => $exposure]];
        }
        foreach (array_values($rooms) as $token) {
            $reads[] = ['/api/recipients/shares', ['shareWith' => $token, 'shareType' => 10]];
        }

        $adminSaw = [];
        foreach ($reads as [$path, $query]) {
            $auditorResponse = self::$auditor->get($path, $query);
            $this->assertSame(200, $auditorResponse['status'], "$path " . json_encode($query) . ': ' . substr($auditorResponse['body'], 0, 200));
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $auditorResponse['body'], "$path " . json_encode($query));
            }
            $adminBody = self::$admin->get($path, $query)['body'];
            foreach ($secrets as $secret) {
                if (str_contains($adminBody, $secret)) {
                    $adminSaw[$secret] = true;
                }
            }
        }

        $this->assertNotEmpty($adminSaw, 'an admin, doing the same, does see tokens: the crawl is looking in the right places');
    }

    // -------------------------------------------------------------------
    // One classification behind the counts and the lists
    // -------------------------------------------------------------------

    /**
     * A category's number and the list behind its "View" button are made by
     * the same code, so they must agree — for each category, on a database
     * holding every kind of share: public and private conversations, one Talk
     * does not know, links, user shares.
     */
    public function testEveryExposureCountIsTheSizeOfTheListBehindIt(): void {
        $publicRoom = Fixtures::createRoom('sai-Open to all', 3);
        $privateRoom = Fixtures::createRoom('sai-Closed', 2);
        $file = Fixtures::ownerFile('exposure.txt');
        Fixtures::shareIntoRoom($publicRoom, $file);
        Fixtures::shareIntoRoom($publicRoom, Fixtures::ownerFile('exposure2.txt'));
        Fixtures::shareIntoRoom($privateRoom, $file);
        Fixtures::shareIntoRoom(Fixtures::randomToken(), $file);   // Talk has never heard of it
        Fixtures::link(Fixtures::ownerFile('exposure-link.txt'));
        $manager = \OCP\Server::get(\OCP\Share\IManager::class);
        $share = $manager->newShare();
        $share->setNode($file)->setShareType(\OCP\Share\IShare::TYPE_USER)->setSharedWith(Fixtures::USER)->setSharedBy(Fixtures::OWNER)->setPermissions(1);
        $manager->createShare($share);

        $overview = self::$admin->get('/api/exposure')['json'];
        $stats = self::$admin->get('/api/stats')['json'];
        $this->assertSame($overview['counts'], $stats['exposure'], 'the dashboard donut and the exposure map read one classification');
        $this->assertSame($overview['total'], array_sum($overview['counts']));

        foreach (['internal', 'external', 'public', 'other'] as $category) {
            foreach ([self::$admin, self::$auditor] as $who) {
                $list = $who->get('/api/shares', ['exposure' => $category, 'limit' => 500]);
                $this->assertSame(200, $list['status']);
                $this->assertSame($overview['counts'][$category], $list['json']['total'], "$category: the list is what was counted");
                $this->assertCount($overview['counts'][$category], $list['json']['items']);
            }
        }

        $publicList = self::$admin->get('/api/shares', ['exposure' => 'public', 'limit' => 500])['json']['items'];
        $rooms = array_filter($publicList, static fn (array $i) => $i['type'] === 10);
        $this->assertCount(2, $rooms, 'the public conversation is public exposure — both its shares');
        foreach ($rooms as $row) {
            $this->assertSame('sai-Open to all', $row['recipientInfo']['label']);
        }
        $this->assertGreaterThanOrEqual(1, count(array_filter($publicList, static fn (array $i) => $i['type'] === 3)), 'and so is the public link');

        $other = self::$admin->get('/api/shares', ['exposure' => 'other', 'limit' => 500])['json']['items'];
        $this->assertCount(1, $other, 'what Talk could not resolve is "other", not "internal"');
        $this->assertSame(10, $other[0]['type']);
        $internal = self::$admin->get('/api/shares', ['exposure' => 'internal', 'limit' => 500])['json']['items'];
        $this->assertContains('sai-Closed', array_map(static fn (array $i) => $i['recipientInfo']['label'] ?? null, $internal));
        $this->assertNotContains('sai-Open to all', array_map(static fn (array $i) => $i['recipientInfo']['label'] ?? null, $internal));
    }

    public function testAnExposureCategoryThatDoesNotExistIsRefusedNotIgnored(): void {
        $this->assertSame(400, self::$admin->get('/api/shares', ['exposure' => 'nonsense'])['status']);
        $this->assertSame(400, self::$admin->get('/api/export', ['exposure' => 'nonsense'])['status']);
    }
}
