<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Tests\Unit;

use OCA\ShareAuditDashboard\Db\ShareMapper;
use OCA\ShareAuditDashboard\Service\DisplayNameResolver;
use OCA\ShareAuditDashboard\Service\PathFormatter;
use OCA\ShareAuditDashboard\Service\RecipientDetailsResolver;
use OCA\ShareAuditDashboard\Service\SecurityAnalyzerService;
use OCA\ShareAuditDashboard\Service\ShareCollectorService;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * normalizeRow() only has one branch of conditional logic worth a test on
 * its own (the rest is straight field mapping) — the "hasExpiration" flag,
 * which means "still has a future expiration" rather than just "the column
 * isn't null". getShares() also
 * gets a test for its ownerDisplayName attachment (see DisplayNameResolver).
 */
class ShareCollectorServiceTest extends TestCase {

    private ShareMapper&MockObject $mapper;
    private DisplayNameResolver&MockObject $displayNames;
    private RecipientDetailsResolver&MockObject $recipientDetails;

    protected function setUp(): void {
        $this->mapper = $this->createMock(ShareMapper::class);
        $this->displayNames = $this->createMock(DisplayNameResolver::class);
        $this->recipientDetails = $this->createMock(RecipientDetailsResolver::class);
        // Pass-through unless a test says otherwise, as for a list with no Talk or Deck rows.
        $this->recipientDetails->method('decorate')->willReturnArgument(0);
    }

    private function collector(?RecipientDetailsResolver $details = null): ShareCollectorService {
        return new ShareCollectorService(
            $this->mapper,
            $this->createMock(SecurityAnalyzerService::class),
            new PathFormatter(),
            $this->displayNames,
            $details ?? $this->recipientDetails,
        );
    }

    private function row(?string $expiration): array {
        return [
            'id' => 1,
            'share_type' => 3,
            'uid_owner' => 'alice',
            'permissions' => 1,
            'expiration' => $expiration,
        ];
    }

    public function testNoExpirationIsFalse(): void {
        $share = $this->collector()->normalizeRow($this->row(null));
        $this->assertFalse($share['hasExpiration']);
    }

    public function testFutureExpirationIsTrue(): void {
        $future = (new \DateTimeImmutable('+30 days'))->format('Y-m-d H:i:s');
        $share = $this->collector()->normalizeRow($this->row($future));
        $this->assertTrue($share['hasExpiration']);
    }

    public function testPastExpirationIsFalse(): void {
        $past = (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s');
        $share = $this->collector()->normalizeRow($this->row($past));
        $this->assertFalse($share['hasExpiration']);
        // The raw date is still surfaced separately so the admin can see it expired.
        $this->assertNotNull($share['expiration']);
    }

    public function testUnparsableExpirationIsFalseRatherThanCrashing(): void {
        $share = $this->collector()->normalizeRow($this->row('not-a-date'));
        $this->assertFalse($share['hasExpiration']);
    }

    public function testNameComesFromTheLabelColumn(): void {
        // The custom name of a link share lives in oc_share.label; the
        // legacy oc_share.share_name column is always NULL.
        $row = $this->row(null);
        $row['label'] = 'Contrato 2026';
        $row['share_name'] = null;
        $this->assertSame('Contrato 2026', $this->collector()->normalizeRow($row)['name']);
    }

    public function testEmptyLabelMeansNoName(): void {
        foreach (['', null] as $label) {
            $row = $this->row(null);
            $row['label'] = $label;
            $this->assertNull($this->collector()->normalizeRow($row)['name']);
        }
        $this->assertNull($this->collector()->normalizeRow($this->row(null))['name']);
    }

    // -------------------------------------------------------------------
    // getShares() — ownerDisplayName/initiatorDisplayName attachment.
    // -------------------------------------------------------------------

    public function testGetSharesAttachesOwnerAndInitiatorDisplayNames(): void {
        $this->mapper->method('findShares')->willReturn([array_merge(
            $this->row(null),
            ['uid_owner' => 'alice', 'uid_initiator' => 'bob'],
        )]);
        $this->mapper->method('countShares')->willReturn(1);
        $this->displayNames->method('resolveMany')->willReturn([
            'alice' => 'Alice Silva',
            'bob' => 'Bob Santos',
        ]);

        $result = $this->collector()->getShares([], 1, 25);

        $this->assertSame('Alice Silva', $result['items'][0]['ownerDisplayName']);
        $this->assertSame('Bob Santos', $result['items'][0]['initiatorDisplayName']);
    }

    public function testGetSharesFallsBackToUidWhenNameIsUnresolved(): void {
        $this->mapper->method('findShares')->willReturn([$this->row(null)]);
        $this->mapper->method('countShares')->willReturn(1);
        $this->displayNames->method('resolveMany')->willReturn([]);

        $result = $this->collector()->getShares([], 1, 25);

        $this->assertSame('alice', $result['items'][0]['ownerDisplayName']);
    }

    // -------------------------------------------------------------------
    // getShares() — ownerSearch expanded to matching uids, so a search for
    // the display name shown in the Owner column (e.g. "Renato") also
    // matches an LDAP-style opaque uid like "FBEAD109".
    // -------------------------------------------------------------------

    public function testGetSharesExpandsOwnerSearchToMatchingUids(): void {
        $this->mapper->method('countShares')->willReturn(0);
        $this->displayNames->method('resolveMany')->willReturn([]);
        $this->displayNames->expects($this->once())->method('searchUids')
            ->with('Renato')->willReturn(['FBEAD109']);

        $this->mapper->expects($this->once())->method('findShares')
            ->with($this->callback(fn (array $filters) => $filters['ownerSearchUids'] === ['FBEAD109']),
                25, 0, 'created', 'desc')
            ->willReturn([]);

        $this->collector()->getShares(['ownerSearch' => 'Renato'], 1, 25);
    }

    public function testGetSharesSkipsOwnerSearchExpansionWhenNoTerm(): void {
        $this->mapper->method('findShares')->willReturn([]);
        $this->mapper->method('countShares')->willReturn(0);
        $this->displayNames->expects($this->never())->method('searchUids');

        $this->collector()->getShares([], 1, 25);
    }

    // -------------------------------------------------------------------
    // getShares() — recipientDisplayName attachment. A recipient is only
    // resolved for TYPE_USER (via resolveMany) and TYPE_GROUP (via
    // resolveManyGroups) — every other recipient shape (email, federated
    // address, link/room token) is already human-readable as stored.
    // -------------------------------------------------------------------

    public function testGetSharesAttachesRecipientDisplayNameForUserShare(): void {
        $this->mapper->method('findShares')->willReturn([array_merge(
            $this->row(null),
            ['share_type' => IShare::TYPE_USER, 'share_with' => 'FBEAD109'],
        )]);
        $this->mapper->method('countShares')->willReturn(1);
        $this->displayNames->method('resolveMany')->willReturn(['FBEAD109' => 'Renato Silva']);

        $result = $this->collector()->getShares([], 1, 25);

        $this->assertSame('Renato Silva', $result['items'][0]['recipientDisplayName']);
    }

    public function testGetSharesAttachesRecipientDisplayNameForGroupShare(): void {
        $this->mapper->method('findShares')->willReturn([array_merge(
            $this->row(null),
            ['share_type' => IShare::TYPE_GROUP, 'share_with' => 'marketing'],
        )]);
        $this->mapper->method('countShares')->willReturn(1);
        $this->displayNames->method('resolveManyGroups')->willReturn(['marketing' => 'Marketing Team']);

        $result = $this->collector()->getShares([], 1, 25);

        $this->assertSame('Marketing Team', $result['items'][0]['recipientDisplayName']);
    }

    public function testGetSharesDoesNotResolveRecipientForEmailShare(): void {
        $this->mapper->method('findShares')->willReturn([array_merge(
            $this->row(null),
            ['share_type' => IShare::TYPE_EMAIL, 'share_with' => 'someone@example.com'],
        )]);
        $this->mapper->method('countShares')->willReturn(1);
        $this->displayNames->method('resolveMany')->willReturn([]);

        $result = $this->collector()->getShares([], 1, 25);

        $this->assertArrayNotHasKey('recipientDisplayName', $result['items'][0]);
    }

    // -------------------------------------------------------------------
    // getShares() — recipientSearch expanded to matching uids/gids, so a
    // search for what's shown in the Recipient column also matches an
    // opaque uid/gid — same reasoning as ownerSearch above.
    // -------------------------------------------------------------------

    public function testGetSharesExpandsRecipientSearchToMatchingIds(): void {
        $this->mapper->method('countShares')->willReturn(0);
        $this->displayNames->method('resolveMany')->willReturn([]);
        $this->displayNames->expects($this->once())->method('searchUids')
            ->with('Renato')->willReturn(['FBEAD109']);
        $this->displayNames->expects($this->once())->method('searchGroupIds')
            ->with('Renato')->willReturn(['renato-fans']);

        $this->mapper->expects($this->once())->method('findShares')
            ->with($this->callback(
                fn (array $filters) => $filters['recipientSearchIds'] === ['FBEAD109', 'renato-fans']
            ), 25, 0, 'created', 'desc')
            ->willReturn([]);

        $this->collector()->getShares(['recipientSearch' => 'Renato'], 1, 25);
    }

    public function testGetSharesSkipsRecipientSearchExpansionWhenNoTerm(): void {
        $this->mapper->method('findShares')->willReturn([]);
        $this->mapper->method('countShares')->willReturn(0);
        $this->displayNames->expects($this->never())->method('searchGroupIds');

        $this->collector()->getShares([], 1, 25);
    }

    // -------------------------------------------------------------------
    // Talk and Deck
    // -------------------------------------------------------------------

    public function testAListIsHandedToTheRecipientDetailsBeforeItIsReturned(): void {
        $this->mapper->method('findShares')->willReturn([['id' => 9, 'share_type' => 10, 'uid_owner' => 'alice', 'permissions' => 1, 'share_with' => 'iitqa25e']]);
        $this->mapper->method('countShares')->willReturn(1);
        $details = $this->createMock(RecipientDetailsResolver::class);
        $details->expects($this->once())->method('decorate')->willReturnCallback(static function (array $items) {
            $items[0]['recipientDisplayName'] = 'Equipa de Marketing';
            return $items;
        });

        $result = $this->collector($details)->getShares([], 1, 25);

        $this->assertSame('Equipa de Marketing', $result['items'][0]['recipientDisplayName']);
        $this->assertSame('iitqa25e', $result['items'][0]['recipient'], 'the stored key is still there for whoever needs it');
    }

    /**
     * A Talk conversation's bare token is functionally a credential (anyone
     * holding it can join a public room), unlike a uid/gid — so a caller
     * without token visibility (see AccessScope::canSeeTokens()) must get
     * the resolved name instead, not the raw key.
     */
    public function testGetSharesRedactsRoomTokenWhenCallerCannotSeeTokens(): void {
        $this->mapper->method('findShares')->willReturn([
            ['id' => 9, 'share_type' => IShare::TYPE_ROOM, 'uid_owner' => 'alice', 'permissions' => 1, 'share_with' => 'iitqa25e'],
        ]);
        $this->mapper->method('countShares')->willReturn(1);
        $details = $this->createMock(RecipientDetailsResolver::class);
        $details->method('decorate')->willReturnCallback(static function (array $items) {
            $items[0]['recipientDisplayName'] = 'Equipa de Marketing';
            return $items;
        });

        $result = $this->collector($details)->getShares([], 1, 25, 'created', 'desc', false);

        $this->assertSame('Equipa de Marketing', $result['items'][0]['recipient']);
    }

    /**
     * $canSeeTokens defaults to true, matching every caller that existed
     * before this parameter was added — the raw token stays for them.
     */
    public function testGetSharesKeepsRawRoomTokenByDefault(): void {
        $this->mapper->method('findShares')->willReturn([
            ['id' => 9, 'share_type' => IShare::TYPE_ROOM, 'uid_owner' => 'alice', 'permissions' => 1, 'share_with' => 'iitqa25e'],
        ]);
        $this->mapper->method('countShares')->willReturn(1);
        $details = $this->createMock(RecipientDetailsResolver::class);
        $details->method('decorate')->willReturnCallback(static function (array $items) {
            $items[0]['recipientDisplayName'] = 'Equipa de Marketing';
            return $items;
        });

        $result = $this->collector($details)->getShares([], 1, 25);

        $this->assertSame('iitqa25e', $result['items'][0]['recipient']);
    }

    public function testGetAllForExportRedactsRoomTokenUnlessTokensAreIncluded(): void {
        $this->mapper->method('findShares')->willReturn([
            ['id' => 9, 'share_type' => IShare::TYPE_ROOM, 'uid_owner' => 'alice', 'permissions' => 1, 'share_with' => 'iitqa25e'],
        ]);
        $details = $this->createMock(RecipientDetailsResolver::class);
        $details->method('decorate')->willReturnCallback(static function (array $items) {
            $items[0]['recipientDisplayName'] = 'Equipa de Marketing';
            return $items;
        });

        $redacted = $this->collector($details)->getAllForExport([]);
        $this->assertSame('Equipa de Marketing', $redacted[0]['recipient']);

        $withTokens = $this->collector($details)->getAllForExport([], true);
        $this->assertSame('iitqa25e', $withTokens[0]['recipient']);
    }

    public function testTheRecipientSearchAlsoLooksUpConversationsAndCardsByName(): void {
        $details = $this->createMock(RecipientDetailsResolver::class);
        $details->method('decorate')->willReturnArgument(0);
        $details->method('searchRoomTokens')->with('market')->willReturn(['iitqa25e']);
        $details->method('searchCardIds')->with('market')->willReturn(['6']);
        $this->displayNames->method('searchUids')->willReturn([]);
        $this->displayNames->method('searchGroupIds')->willReturn([]);
        $seen = null;
        $this->mapper->method('findShares')->willReturnCallback(function (array $filters) use (&$seen) {
            $seen = $filters;
            return [];
        });

        $this->collector($details)->getShares(['recipientSearch' => 'market'], 1, 25);

        $this->assertSame(['iitqa25e'], $seen['recipientSearchRooms']);
        $this->assertSame(['6'], $seen['recipientSearchCards']);
    }

    public function testNoRecipientSearchMeansNoLookupInTalkOrDeck(): void {
        $details = $this->createMock(RecipientDetailsResolver::class);
        $details->method('decorate')->willReturnArgument(0);
        $details->expects($this->never())->method('searchRoomTokens');
        $details->expects($this->never())->method('searchCardIds');
        $this->mapper->method('findShares')->willReturn([]);

        $this->collector($details)->getShares(['ownerSearch' => ''], 1, 25);
    }

    public function testADeckShareIsLabelledDeckOnItsRow(): void {
        $share = $this->collector()->normalizeRow(['id' => 1, 'share_type' => 12, 'uid_owner' => 'alice', 'permissions' => 1]);

        $this->assertSame('deck', $share['category']);
        $this->assertSame('deck', $share['typeLabel']);
    }

    public function testADeckShareStillCountsAsOtherOnTheDashboard(): void {
        // No bucket, colour or internal/external call for it yet: the cards and
        // the donut must still add up to the total.
        $this->mapper->method('countByType')->willReturn([0 => 3, 12 => 2]);
        $this->mapper->method('countRecentBuckets')->willReturn([]);
        $this->mapper->method('findCreatedTimestampsSince')->willReturn([]);
        $this->mapper->method('topOwners')->willReturn([]);

        $stats = $this->collector()->getStats();

        $this->assertSame(2, $stats['byType']['other']);
        $this->assertArrayNotHasKey('deck', $stats['byType']);
        $this->assertSame(5, $stats['total']);
        $this->assertSame($stats['total'], array_sum($stats['byType']));
    }
}
