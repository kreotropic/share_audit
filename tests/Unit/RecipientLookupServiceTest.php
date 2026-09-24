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

    /** @var string[] the columns every neq() was built on */
    private array $neq = [];

    protected function setUp(): void {
        $this->neq = [];
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
     * Talk knows a conversation under TOKEN.
     */
    private function talkKnowsTheRoom(string $name = 'Equipa de Marketing'): void {
        $this->details->method('fetchRooms')->willReturn([self::TOKEN => [
            'id' => 10, 'token' => self::TOKEN, 'type' => 3, 'name' => $name, 'listable' => 0,
        ]]);
    }

    private function roomShareRow(int $id = 1): array {
        return ['id' => $id, 'share_type' => IShare::TYPE_ROOM, 'uid_owner' => 'alice', 'share_with' => self::TOKEN];
    }

    /**
     * The handle search() hands an auditor for TOKEN.
     */
    private function handleForTheRoom(): string {
        $this->stubSearchQuery([['share_with' => self::TOKEN, 'share_type' => IShare::TYPE_ROOM, 'cnt' => 2]]);
        $this->details->method('searchRoomTokens')->willReturn([self::TOKEN]);
        return $this->service->search('marketing', 20, false)[0]['shareWith'];
    }

    /**
     * A query builder that records the conditions search() builds and returns
     * $found from fetchAll().
     *
     * @param array<int, array<string, mixed>> $found
     */
    private function stubSearchQuery(array $found): void {
        $expr = $this->createMock(IExpressionBuilder::class);
        foreach (['eq', 'iLike', 'in'] as $method) {
            $expr->method($method)->willReturn($method);
        }
        $expr->method('neq')->willReturnCallback(function (string $column) {
            $this->neq[] = $column;
            return 'neq';
        });
        $expr->method('andX')->willReturn($this->createMock(ICompositeExpression::class));
        $expr->method('orX')->willReturn($this->createMock(ICompositeExpression::class));

        $func = $this->createMock(IFunctionBuilder::class);
        $func->method('count')->willReturn($this->createMock(IQueryFunction::class));

        $result = $this->createMock(IResult::class);
        $result->method('fetchAll')->willReturn($found);
        $result->method('closeCursor')->willReturn(true);

        $qb = $this->createMock(IQueryBuilder::class);
        foreach (['select', 'selectAlias', 'from', 'where', 'andWhere', 'groupBy', 'orderBy', 'addOrderBy', 'setMaxResults'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('expr')->willReturn($expr);
        $qb->method('func')->willReturn($func);
        $qb->method('createNamedParameter')->willReturnArgument(0);
        $qb->method('executeQuery')->willReturn($result);
        $this->db->method('getQueryBuilder')->willReturn($qb);
    }

    // -------------------------------------------------------------------
    // search()
    // -------------------------------------------------------------------

    public function testAnAuditorIsGivenAnOpaqueHandleAndTheNameNeverTheToken(): void {
        $this->stubSearchQuery([['share_with' => self::TOKEN, 'share_type' => IShare::TYPE_ROOM, 'cnt' => 2]]);
        $this->details->method('searchRoomTokens')->willReturn([self::TOKEN]);
        $this->talkKnowsTheRoom();

        $items = $this->service->search('marketing', 20, false);

        $this->assertCount(1, $items);
        $this->assertSame('Equipa de Marketing', $items[0]['label']);
        $this->assertTrue($items[0]['opaque']);
        $this->assertNotSame(self::TOKEN, $items[0]['shareWith']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $items[0]['shareWith']);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($items));
    }

    public function testAnUnnamedConversationIsOfferedToAnAuditorWithNoLabelAndNoToken(): void {
        $this->stubSearchQuery([['share_with' => self::TOKEN, 'share_type' => IShare::TYPE_ROOM, 'cnt' => 1]]);
        $this->details->method('searchRoomTokens')->willReturn([]);
        $this->talkKnowsTheRoom('');

        $items = $this->service->search('synthetic', 20, false);

        $this->assertSame('', $items[0]['label']);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($items));
    }

    public function testAnAdminStillGetsTheTokenAndTheNameToRecogniseItBy(): void {
        $this->stubSearchQuery([['share_with' => self::TOKEN, 'share_type' => IShare::TYPE_ROOM, 'cnt' => 2]]);
        $this->details->method('searchRoomTokens')->willReturn([self::TOKEN]);
        $this->talkKnowsTheRoom();

        $items = $this->service->search('marketing', 20, true);

        $this->assertSame(self::TOKEN, $items[0]['shareWith']);
        $this->assertSame('Equipa de Marketing', $items[0]['label']);
        $this->assertArrayNotHasKey('opaque', $items[0]);
    }

    public function testOtherRecipientsAreUntouchedForAnAuditor(): void {
        $this->stubSearchQuery([['share_with' => 'bob', 'share_type' => IShare::TYPE_USER, 'cnt' => 2]]);
        $this->details->method('searchRoomTokens')->willReturn([]);

        $items = $this->service->search('bob', 20, false);

        $this->assertSame('bob', $items[0]['shareWith']);
        $this->assertArrayNotHasKey('opaque', $items[0]);
    }

    /**
     * The other way to read a token: type a guess and see whether it comes
     * back. For an auditor the text match on share_with must leave Talk rows
     * out (so a query can only ever find a room by its NAME); an admin's is
     * unchanged.
     */
    public function testAnAuditorCannotProbeTokensWithTheTextSearch(): void {
        $this->stubSearchQuery([]);
        $this->details->method('searchRoomTokens')->willReturn([]);

        $this->service->search('synt', 20, false);
        $this->assertSame(['share_type'], $this->neq, 'the LIKE on share_with is limited to rows that are not Talk');
    }

    public function testAnAdminsTextSearchStillReachesTokens(): void {
        $this->stubSearchQuery([]);
        $this->details->method('searchRoomTokens')->willReturn([]);

        $this->service->search('synt', 20, true);
        $this->assertSame([], $this->neq);
    }

    public function testASearchTooShortToBeUsefulRunsNoQuery(): void {
        $this->db->expects($this->never())->method('getQueryBuilder');

        $this->assertSame([], $this->service->search('a', 20, false));
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
        $this->stubSearchQuery([['share_with' => self::TOKEN, 'share_type' => IShare::TYPE_ROOM, 'cnt' => 1]]);
        $this->details->method('searchRoomTokens')->willReturn([self::TOKEN]);
        $this->talkKnowsTheRoom();

        $auditor = $this->controllerFor(AccessScope::auditor())->search('marketing')->getData()['items'];
        $admin = $this->controllerFor(AccessScope::admin())->search('marketing')->getData()['items'];

        $this->assertStringNotContainsString(self::TOKEN, json_encode($auditor));
        $this->assertSame(self::TOKEN, $admin[0]['shareWith']);
    }
}
