<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Tests\Unit;

use OCA\ShareAuditDashboard\Controller\RecipientController;
use OCA\ShareAuditDashboard\Db\ShareMapper;
use OCA\ShareAuditDashboard\Service\AccessScope;
use OCA\ShareAuditDashboard\Service\AccessService;
use OCA\ShareAuditDashboard\Service\DisplayNameResolver;
use OCA\ShareAuditDashboard\Service\RecipientDetailsResolver;
use OCA\ShareAuditDashboard\Service\RecipientLookupService;
use OCA\ShareAuditDashboard\Service\ShareCollectorService;
use OCA\ShareAuditDashboard\Service\ShareDeletionService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\Security\ICrypto;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The reverse recipient lookup, for the two kinds of caller: an admin, who may
 * see a Talk conversation's token, and an auditor, who may not. The lookup
 * works BY that token (it is oc_share.share_with), so keeping it from an
 * auditor is not a matter of blanking a field: it has to be found, searched and
 * paged by something else. Everything an auditor is handed is checked for the
 * token with a plain string search of the whole JSON, the way a leak would be
 * noticed.
 */
class RecipientLookupServiceTest extends TestCase {

    private const TOKEN = 'synthetic-room-token';

    private IDBConnection&MockObject $db;
    private ShareMapper&MockObject $mapper;
    private RecipientDetailsResolver&MockObject $details;
    private RecipientLookupService $service;

    /**
     * What Talk knows, by token: token => a talk_rooms row. Mutable, so a test
     * can give the same conversations different tokens between two calls.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $talkRooms = [];

    /** @var string[] the tokens of the conversations whose NAME matches the query */
    private array $foundByName = [];

    /** @var array<int, array<string, mixed>> what the general recipient query returns */
    private array $generalRows = [];

    /** @var array<int, array{share_with: string, cnt: int}> what the per-conversation count query returns */
    private array $roomCountRows = [];

    /**
     * One record per query built, in order: the values bound as parameters,
     * every ORDER BY, and the LIMIT.
     *
     * @var array<int, array{params: mixed[], orders: string[], limit: ?int}>
     */
    private array $queries = [];

    protected function setUp(): void {
        $this->talkRooms = [];
        $this->foundByName = [];
        $this->generalRows = [];
        $this->roomCountRows = [];
        $this->queries = [];
        $this->db = $this->createMock(IDBConnection::class);
        $this->db->method('escapeLikeParameter')->willReturnArgument(0);
        $this->mapper = $this->createMock(ShareMapper::class);

        $displayNames = $this->createMock(DisplayNameResolver::class);
        $displayNames->method('searchUids')->willReturn([]);
        $displayNames->method('searchGroupIds')->willReturn([]);
        $displayNames->method('resolveMany')->willReturn([]);

        $this->details = $this->getMockBuilder(RecipientDetailsResolver::class)
            ->setConstructorArgs([$this->db, $displayNames, $this->createMock(LoggerInterface::class)])
            ->onlyMethods(['fetchRooms', 'fetchAttendeeCounts', 'searchRoomTokens'])
            ->getMock();
        $this->details->method('fetchAttendeeCounts')->willReturn([]);
        $this->details->method('fetchRooms')->willReturnCallback(
            fn (array $tokens) => array_intersect_key($this->talkRooms, array_flip($tokens)),
        );
        $this->details->method('searchRoomTokens')->willReturnCallback(fn () => $this->foundByName);
        $this->db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());

        $collector = $this->createMock(ShareCollectorService::class);
        $collector->method('normalizeRow')->willReturnCallback(static fn (array $row) => [
            'id' => (int)$row['id'], 'type' => (int)$row['share_type'],
            'owner' => (string)$row['uid_owner'], 'recipient' => (string)$row['share_with'],
        ]);

        // Like the real one, which hands back the hash's raw bytes, not hex.
        $crypto = $this->createMock(ICrypto::class);
        $crypto->method('calculateHMAC')->willReturnCallback(
            static fn (string $message) => hash_hmac('sha512', $message, 'the-instance-secret', true),
        );

        $this->service = new RecipientLookupService(
            $this->db,
            $this->createMock(IUserManager::class),
            $this->createMock(IGroupManager::class),
            $this->mapper,
            $collector,
            $this->createMock(ShareDeletionService::class),
            $displayNames,
            $this->details,
            $crypto,
        );
    }

    /**
     * Talk knows a conversation under TOKEN, and it is the one whose name the query matches.
     */
    private function talkKnowsTheRoom(string $name = 'Equipa de Marketing'): void {
        $this->talkRooms[self::TOKEN] = ['id' => 10, 'token' => self::TOKEN, 'type' => 3, 'name' => $name, 'listable' => 0];
    }

    private function roomShareRow(int $id = 1): array {
        return ['id' => $id, 'share_type' => IShare::TYPE_ROOM, 'uid_owner' => 'alice', 'share_with' => self::TOKEN];
    }

    /**
     * The handle search() hands an auditor for TOKEN.
     */
    private function handleForTheRoom(): string {
        $this->foundByName = [self::TOKEN];
        $this->roomCountRows = [['share_with' => self::TOKEN, 'cnt' => 2]];
        return $this->service->search('marketing', 20, false)[0]['shareWith'];
    }

    /**
     * A query builder that records what search() builds and answers from
     * $generalRows / $roomCountRows, in the order the queries are made: the
     * general recipient query first, the per-conversation count second.
     */
    private function recordingBuilder(): IQueryBuilder {
        $index = count($this->queries);
        $this->queries[$index] = ['params' => [], 'orders' => [], 'limit' => null];

        $expr = $this->createMock(IExpressionBuilder::class);
        foreach (['eq', 'neq', 'iLike', 'in'] as $method) {
            $expr->method($method)->willReturn($method);
        }
        $expr->method('andX')->willReturn($this->createMock(ICompositeExpression::class));
        $expr->method('orX')->willReturn($this->createMock(ICompositeExpression::class));

        $func = $this->createMock(IFunctionBuilder::class);
        $func->method('count')->willReturn($this->createMock(IQueryFunction::class));

        $result = $this->createMock(IResult::class);
        $result->method('closeCursor')->willReturn(true);
        if ($index === 0) {
            $result->method('fetchAll')->willReturn($this->generalRows);
        } else {
            $rows = $this->roomCountRows;
            $result->method('fetch')->willReturnCallback(static function () use (&$rows) {
                return $rows === [] ? false : array_shift($rows);
            });
        }

        $qb = $this->createMock(IQueryBuilder::class);
        foreach (['select', 'selectAlias', 'from', 'where', 'andWhere', 'groupBy'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        foreach (['orderBy', 'addOrderBy'] as $method) {
            $qb->method($method)->willReturnCallback(function (string $column) use ($index, $qb) {
                $this->queries[$index]['orders'][] = $column;
                return $qb;
            });
        }
        $qb->method('setMaxResults')->willReturnCallback(function (int $limit) use ($index, $qb) {
            $this->queries[$index]['limit'] = $limit;
            return $qb;
        });
        $qb->method('expr')->willReturn($expr);
        $qb->method('func')->willReturn($func);
        $qb->method('createNamedParameter')->willReturnCallback(function ($value) use ($index) {
            $this->queries[$index]['params'][] = $value;
            return $value;
        });
        $qb->method('executeQuery')->willReturn($result);
        return $qb;
    }

    // -------------------------------------------------------------------
    // search()
    // -------------------------------------------------------------------

    public function testAnAuditorIsGivenAnOpaqueHandleAndTheNameNeverTheToken(): void {
        $this->foundByName = [self::TOKEN];
        $this->roomCountRows = [['share_with' => self::TOKEN, 'cnt' => 2]];
        $this->talkKnowsTheRoom();

        $items = $this->service->search('marketing', 20, false);

        $this->assertCount(1, $items);
        $this->assertSame('Equipa de Marketing', $items[0]['label']);
        $this->assertSame(2, $items[0]['count']);
        $this->assertTrue($items[0]['opaque']);
        $this->assertNotSame(self::TOKEN, $items[0]['shareWith']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $items[0]['shareWith']);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($items));
    }

    public function testAnUnnamedConversationIsOfferedToAnAuditorWithNoLabelAndNoToken(): void {
        $this->foundByName = [];
        $this->talkKnowsTheRoom('');
        // Not found by name (it has none): the only way in is a share_with the
        // caller is not allowed to match, so it is simply not offered.
        $items = $this->service->search('synthetic', 20, false);

        $this->assertSame([], $items);
    }

    public function testAnAdminStillGetsTheTokenAndTheNameToRecogniseItBy(): void {
        $this->generalRows = [['share_with' => self::TOKEN, 'share_type' => IShare::TYPE_ROOM, 'cnt' => 2]];
        $this->foundByName = [self::TOKEN];
        $this->talkKnowsTheRoom();

        $items = $this->service->search('marketing', 20, true);

        $this->assertSame(self::TOKEN, $items[0]['shareWith']);
        $this->assertSame('Equipa de Marketing', $items[0]['label']);
        $this->assertArrayNotHasKey('opaque', $items[0]);
    }

    public function testAnAdminFindsAnUnnamedConversationByItsToken(): void {
        $this->generalRows = [['share_with' => self::TOKEN, 'share_type' => IShare::TYPE_ROOM, 'cnt' => 1]];
        $this->talkKnowsTheRoom('');

        $items = $this->service->search('synthetic', 20, true);

        $this->assertSame(self::TOKEN, $items[0]['shareWith']);
        $this->assertSame(self::TOKEN, $items[0]['label'], 'for an admin the token is the only name it has');
    }

    public function testOtherRecipientsAreUntouchedForAnAuditor(): void {
        $this->generalRows = [['share_with' => 'bob', 'share_type' => IShare::TYPE_USER, 'cnt' => 2]];

        $items = $this->service->search('bob', 20, false);

        $this->assertSame('bob', $items[0]['shareWith']);
        $this->assertArrayNotHasKey('opaque', $items[0]);
    }

    /**
     * The other way to read a token: type a guess and see whether it comes
     * back. For an auditor no Talk row is a candidate of the general query at
     * all (so a query can only ever find a conversation by its NAME); an
     * admin's includes them.
     */
    public function testAnAuditorsGeneralQueryDoesNotIncludeTalkRows(): void {
        $this->service->search('synt', 20, false);

        $types = $this->queries[0]['params'][1];
        $this->assertNotContains(IShare::TYPE_ROOM, $types);
        $this->assertContains(IShare::TYPE_USER, $types);
    }

    public function testAnAdminsTextSearchStillReachesTokens(): void {
        $this->service->search('synt', 20, true);

        $this->assertContains(IShare::TYPE_ROOM, $this->queries[0]['params'][1]);
    }

    // -------------------------------------------------------------------
    // ...and their order. An ORDER BY on the token, or a LIMIT that cuts
    // where the token says, reads the token just as surely as printing it:
    // anyone who can make rooms of their own gets one bit per comparison
    // against a token they know, and 42 comparisons are enough for eight
    // characters.
    // -------------------------------------------------------------------

    public function testNoQueryAnAuditorCausesOrdersOrCutsConversationsByToken(): void {
        $this->foundByName = [self::TOKEN];
        $this->roomCountRows = [['share_with' => self::TOKEN, 'cnt' => 2]];
        $this->talkKnowsTheRoom();

        $this->service->search('marketing', 20, false);

        $this->assertCount(2, $this->queries, 'the general query, and the conversations counted on their own');
        $general = $this->queries[0];
        $rooms = $this->queries[1];
        // The general one may order and limit — it holds no Talk row at all.
        $this->assertNotContains(IShare::TYPE_ROOM, $general['params'][1]);
        // The conversations' own query has neither an order nor a limit for the database to decide with.
        $this->assertSame([], $rooms['orders']);
        $this->assertNull($rooms['limit']);
    }

    /**
     * Same conversations, same names, same counts — only the tokens differ,
     * and they are dealt out in every combination and handed back by the
     * "database" both in ascending and in descending token order. The list an
     * auditor gets must not change: if it followed the tokens, it would.
     */
    public function testTheListAnAuditorGetsDoesNotFollowTheTokens(): void {
        $names = ['Alpha', 'Beta', 'Gamma'];
        $pool = ['aaaa1111', 'kkkk2222', 'zzzz3333'];

        foreach ($this->permutations($pool) as $tokens) {
            foreach (['ascending', 'descending'] as $dbOrder) {
                $this->talkRooms = [];
                $counts = [];
                foreach ($names as $i => $name) {
                    $this->talkRooms[$tokens[$i]] = ['id' => $i + 1, 'token' => $tokens[$i], 'type' => 2, 'name' => $name, 'listable' => 0];
                    $counts[$tokens[$i]] = 2;   // a tie on everything the count says
                }
                $dbOrder === 'ascending' ? ksort($counts) : krsort($counts);
                $this->foundByName = array_keys($counts);
                $this->roomCountRows = array_map(
                    static fn ($token, $count) => ['share_with' => (string)$token, 'cnt' => $count],
                    array_keys($counts),
                    $counts,
                );
                $this->queries = [];

                $labels = array_column($this->service->search('aa', 20, false), 'label');

                $this->assertSame(['Alpha', 'Beta', 'Gamma'], $labels, 'tokens ' . implode(',', $tokens) . " from a $dbOrder scan");
            }
        }
    }

    public function testWhichConversationsSurviveTheLimitDoesNotFollowTheTokens(): void {
        $pool = ['aaaa1111', 'kkkk2222', 'zzzz3333'];

        foreach ($this->permutations($pool) as $tokens) {
            $this->talkRooms = [];
            foreach (['Alpha', 'Beta', 'Gamma'] as $i => $name) {
                $this->talkRooms[$tokens[$i]] = ['id' => $i + 1, 'token' => $tokens[$i], 'type' => 2, 'name' => $name, 'listable' => 0];
            }
            $this->foundByName = $tokens;
            $this->roomCountRows = array_map(static fn ($token) => ['share_with' => $token, 'cnt' => 2], $tokens);
            $this->queries = [];

            $labels = array_column($this->service->search('aa', 2, false), 'label');

            $this->assertSame(['Alpha', 'Beta'], $labels, 'tokens ' . implode(',', $tokens));
        }
    }

    public function testAMoreSharedConversationComesFirstWhateverItsToken(): void {
        foreach ([['zzzz3333', 'aaaa1111'], ['aaaa1111', 'zzzz3333']] as [$busy, $quiet]) {
            $this->talkRooms = [
                $busy => ['id' => 1, 'token' => $busy, 'type' => 2, 'name' => 'Zeta', 'listable' => 0],
                $quiet => ['id' => 2, 'token' => $quiet, 'type' => 2, 'name' => 'Alpha', 'listable' => 0],
            ];
            $this->foundByName = [$quiet, $busy];
            $this->roomCountRows = [['share_with' => $quiet, 'cnt' => 1], ['share_with' => $busy, 'cnt' => 5]];
            $this->queries = [];

            $this->assertSame(['Zeta', 'Alpha'], array_column($this->service->search('aa', 20, false), 'label'));
        }
    }

    /**
     * Two conversations that are the same in everything public (name, count)
     * can only be told apart by their handles: a keyed hash, so the order it
     * gives is not the order of the tokens. Over many token pairs it comes out
     * both ways — an order that followed the tokens would come out one way
     * every time.
     */
    public function testConversationsThatAreTheSameInEverythingPublicAreNotOrderedByToken(): void {
        $tokens = ['aaaa1111', 'bbbb2222', 'cccc3333', 'dddd4444', 'eeee5555', 'ffff6666', 'gggg7777', 'hhhh8888'];
        $tokenAscendingFirst = 0;
        $pairs = 0;
        for ($i = 0; $i < count($tokens); $i++) {
            for ($j = $i + 1; $j < count($tokens); $j++) {
                $this->talkRooms = [];
                foreach ([$tokens[$i], $tokens[$j]] as $k => $token) {
                    $this->talkRooms[$token] = ['id' => $k + 1, 'token' => $token, 'type' => 2, 'name' => 'Same name', 'listable' => 0];
                }
                $this->foundByName = [$tokens[$i], $tokens[$j]];
                $this->roomCountRows = [['share_with' => $tokens[$i], 'cnt' => 3], ['share_with' => $tokens[$j], 'cnt' => 3]];
                $this->queries = [];

                $handles = array_column($this->service->search('same', 20, false), 'shareWith');
                $this->assertCount(2, $handles);
                $tokenAscendingFirst += $handles[0] === $this->handleOf($tokens[$i]) ? 1 : 0;
                $pairs++;
            }
        }

        $this->assertGreaterThan(0, $tokenAscendingFirst, 'sometimes the smaller token is first');
        $this->assertLessThan($pairs, $tokenAscendingFirst, 'and sometimes it is not: the order is not the tokens\'');
    }

    /**
     * @param string[] $items
     * @return array<int, string[]>
     */
    private function permutations(array $items): array {
        if (count($items) <= 1) {
            return [$items];
        }
        $result = [];
        foreach ($items as $i => $item) {
            $rest = $items;
            unset($rest[$i]);
            foreach ($this->permutations(array_values($rest)) as $tail) {
                $result[] = [$item, ...$tail];
            }
        }
        return $result;
    }

    private function handleOf(string $token): string {
        return bin2hex(substr(hash_hmac('sha512', 'share_audit_dashboard:room:' . $token, 'the-instance-secret', true), 0, 16));
    }

    public function testASearchTooShortToBeUsefulRunsNoQuery(): void {
        $this->db = $this->createMock(IDBConnection::class);
        $this->db->expects($this->never())->method('getQueryBuilder');
        $service = new RecipientLookupService(
            $this->db,
            $this->createMock(IUserManager::class),
            $this->createMock(IGroupManager::class),
            $this->mapper,
            $this->createMock(ShareCollectorService::class),
            $this->createMock(ShareDeletionService::class),
            $this->createMock(DisplayNameResolver::class),
            $this->details,
            $this->createMock(ICrypto::class),
        );

        $this->assertSame([], $service->search('a', 20, false));
    }

    // -------------------------------------------------------------------
    // getShares()
    // -------------------------------------------------------------------

    public function testAnAuditorFindsTheConversationBySearchesHandleAndSeesNoToken(): void {
        $this->talkKnowsTheRoom();
        $handle = $this->handleForTheRoom();
        $this->mapper->method('countRoomSharesByToken')->willReturn([self::TOKEN => 2]);
        $this->mapper->method('countShares')->willReturn(2);
        $this->mapper->expects($this->once())->method('findShares')
            ->with(['shareWith' => self::TOKEN, 'shareType' => IShare::TYPE_ROOM])
            ->willReturn([$this->roomShareRow(1), $this->roomShareRow(2)]);

        $result = $this->service->getShares($handle, IShare::TYPE_ROOM, 1, 25, false);

        $this->assertSame(2, $result['total']);
        $this->assertCount(2, $result['items']);
        $this->assertSame('Equipa de Marketing', $result['recipient']['label']);
        $this->assertSame($handle, $result['recipient']['shareWith']);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($result));
    }

    /**
     * The reproduction that was reported: no shareWith at all used to skip the
     * filter and list every Talk share with its token in `recipient`.
     */
    public function testAnEmptyRecipientListsNothingInsteadOfEverySharesOfThatType(): void {
        $this->mapper->expects($this->never())->method('findShares');
        $this->mapper->expects($this->never())->method('countShares');

        foreach ([false, true] as $canSeeTokens) {
            $result = $this->service->getShares('', IShare::TYPE_ROOM, 1, 25, $canSeeTokens);

            $this->assertSame([], $result['items']);
            $this->assertSame(0, $result['total']);
        }
    }

    public function testAnAuditorCannotLookAConversationUpByItsToken(): void {
        $this->mapper->method('countRoomSharesByToken')->willReturn([self::TOKEN => 2]);
        $this->mapper->expects($this->never())->method('findShares');

        $result = $this->service->getShares(self::TOKEN, IShare::TYPE_ROOM, 1, 25, false);

        $this->assertSame([], $result['items']);
        $this->assertSame(0, $result['total']);
    }

    public function testAHandleThatMatchesNoConversationFindsNothing(): void {
        $this->mapper->method('countRoomSharesByToken')->willReturn([self::TOKEN => 2]);
        $this->mapper->expects($this->never())->method('findShares');

        $result = $this->service->getShares(str_repeat('0', 32), IShare::TYPE_ROOM, 1, 25, false);

        $this->assertSame(0, $result['total']);
    }

    public function testAnAdminStillLooksAConversationUpByItsToken(): void {
        $this->talkKnowsTheRoom();
        $this->mapper->method('countShares')->willReturn(1);
        $this->mapper->expects($this->once())->method('findShares')
            ->with(['shareWith' => self::TOKEN, 'shareType' => IShare::TYPE_ROOM])
            ->willReturn([$this->roomShareRow()]);

        $result = $this->service->getShares(self::TOKEN, IShare::TYPE_ROOM, 1, 25, true);

        $this->assertSame(1, $result['total']);
        $this->assertSame(self::TOKEN, $result['items'][0]['recipient']);
        $this->assertSame('Equipa de Marketing', $result['recipient']['label']);
    }

    public function testAShareTypeWithNoRecipientIsNotALookup(): void {
        $this->mapper->expects($this->never())->method('findShares');

        // A public link has no share_with: this would otherwise be a way to
        // page through every link with a made-up value.
        $this->assertSame(0, $this->service->getShares('anything', IShare::TYPE_LINK, 1, 25, true)['total']);
    }

    public function testAnOrdinaryRecipientIsLookedUpAsBefore(): void {
        $this->mapper->method('countShares')->willReturn(1);
        $this->mapper->expects($this->once())->method('findShares')
            ->with(['shareWith' => 'bob', 'shareType' => IShare::TYPE_USER])
            ->willReturn([['id' => 3, 'share_type' => IShare::TYPE_USER, 'uid_owner' => 'alice', 'share_with' => 'bob']]);

        $result = $this->service->getShares('bob', IShare::TYPE_USER, 1, 25, false);

        $this->assertSame('bob', $result['items'][0]['recipient']);
        $this->assertSame('alice', $result['items'][0]['ownerDisplayName']);
    }

    // -------------------------------------------------------------------
    // Through the controller, as an auditor and as an admin
    // -------------------------------------------------------------------

    private function controllerFor(AccessScope $scope): RecipientController {
        $access = $this->createMock(AccessService::class);
        $access->method('getScope')->willReturn($scope);
        return new RecipientController('share_audit_dashboard', $this->createMock(IRequest::class), $this->service, $access);
    }

    public function testTheControllerNeverHandsAnAuditorATokenOnEitherEndpoint(): void {
        $this->talkKnowsTheRoom();
        $handle = $this->handleForTheRoom();
        $this->mapper->method('countRoomSharesByToken')->willReturn([self::TOKEN => 1]);
        $this->mapper->method('countShares')->willReturn(1);
        $this->mapper->method('findShares')->willReturn([$this->roomShareRow()]);
        $controller = $this->controllerFor(AccessScope::auditor());

        $enumerated = $controller->shares('', IShare::TYPE_ROOM);
        $found = $controller->shares($handle, IShare::TYPE_ROOM);
        $byToken = $controller->shares(self::TOKEN, IShare::TYPE_ROOM);

        $this->assertSame(200, $enumerated->getStatus());
        $this->assertSame(0, $enumerated->getData()['total']);
        $this->assertSame(1, $found->getData()['total']);
        $this->assertSame(0, $byToken->getData()['total']);
        foreach ([$enumerated, $found, $byToken] as $response) {
            $this->assertStringNotContainsString(self::TOKEN, json_encode($response->getData()));
        }
    }

    public function testTheControllerHandsAnAdminTheTokenBecauseTheAdminMayHaveIt(): void {
        $this->talkKnowsTheRoom();
        $this->mapper->method('countShares')->willReturn(1);
        $this->mapper->method('findShares')->willReturn([$this->roomShareRow()]);

        $response = $this->controllerFor(AccessScope::admin())->shares(self::TOKEN, IShare::TYPE_ROOM);

        $this->assertSame(self::TOKEN, $response->getData()['items'][0]['recipient']);
    }

    public function testTheControllerPassesTheScopeToSearchToo(): void {
        $this->generalRows = [['share_with' => self::TOKEN, 'share_type' => IShare::TYPE_ROOM, 'cnt' => 1]];
        $this->roomCountRows = [['share_with' => self::TOKEN, 'cnt' => 1]];
        $this->foundByName = [self::TOKEN];
        $this->talkKnowsTheRoom();

        $auditor = $this->controllerFor(AccessScope::auditor())->search('marketing')->getData()['items'];
        $this->queries = [];   // the next search starts with its general query again
        $admin = $this->controllerFor(AccessScope::admin())->search('marketing')->getData()['items'];

        $this->assertStringNotContainsString(self::TOKEN, json_encode($auditor));
        $this->assertSame(self::TOKEN, $admin[0]['shareWith']);
    }
}
