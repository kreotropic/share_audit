<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Tests\Unit;

use OCA\ShareAuditDashboard\Db\ShareMapper;
use OCA\ShareAuditDashboard\Service\DisplayNameResolver;
use OCA\ShareAuditDashboard\Service\ExposureMapService;
use OCA\ShareAuditDashboard\Service\RecipientDetailsResolver;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The one classification behind the exposure map, the dashboard donut and the
 * "View" filter: what counts as internal / external / public, where a Talk
 * conversation lands, and — the point of several of these — that something this
 * app could not classify is never reported as safe.
 */
class ExposureMapServiceTest extends TestCase {

    private ShareMapper&MockObject $mapper;
    private RecipientDetailsResolver&MockObject $details;
    private ExposureMapService $service;

    protected function setUp(): void {
        $this->mapper = $this->createMock(ShareMapper::class);
        $this->details = $this->createMock(RecipientDetailsResolver::class);
        $this->service = new ExposureMapService($this->mapper, $this->createMock(DisplayNameResolver::class), $this->details);
    }

    /**
     * @param array<int, int> $byType share_type => count, as ShareMapper::countByType() returns it
     * @param array<string, int> $rooms token => shares into that room
     * @param array<string, string> $openness what Talk says about the rooms it resolves
     */
    private function instanceHas(array $byType, array $rooms = [], array $openness = []): void {
        $this->mapper->method('countByType')->willReturn($byType);
        $this->mapper->method('countRoomSharesByToken')->willReturn($rooms);
        $this->details->method('describeRoomOpenness')->willReturn($openness);
    }

    public function testEachShareTypeLandsInItsOwnCategory(): void {
        $this->instanceHas([
            IShare::TYPE_USER => 4, IShare::TYPE_GROUP => 2, IShare::TYPE_CIRCLE => 1,
            IShare::TYPE_EMAIL => 3, IShare::TYPE_REMOTE => 1,
            IShare::TYPE_LINK => 5,
            IShare::TYPE_DECK => 2,
        ]);

        $this->assertSame(['internal' => 7, 'external' => 4, 'public' => 5, 'other' => 2], $this->service->getCounts());
    }

    public function testAPublicConversationIsPublicExposureAndAPrivateOneIsInternal(): void {
        $this->instanceHas(
            [IShare::TYPE_ROOM => 5],
            ['pub12345' => 3, 'grp12345' => 2],
            ['pub12345' => 'public', 'grp12345' => 'internal'],
        );

        $this->assertSame(['internal' => 2, 'external' => 0, 'public' => 3, 'other' => 0], $this->service->getCounts());
    }

    /**
     * The regression: every room unresolved (Talk gone, schema changed) used to
     * be filed under "internal", so an instance of public conversations scored
     * zero. It must be "other", weighted like external.
     */
    public function testConversationsThatCannotBeResolvedAreOtherNotInternal(): void {
        $this->instanceHas([IShare::TYPE_ROOM => 1], ['synthetic-room-token' => 1], []);

        $overview = $this->service->getOverview();

        $this->assertSame(1, $overview['counts']['other']);
        $this->assertSame(0, $overview['counts']['internal']);
        $this->assertGreaterThan(0, $overview['score'], 'unknown exposure is not zero exposure');
    }

    public function testOnlyTheUnresolvedConversationsAreOtherWhenTalkKnowsTheRest(): void {
        $this->instanceHas(
            [IShare::TYPE_ROOM => 3],
            ['known123' => 1, 'stale123' => 2],
            ['known123' => 'internal'],
        );

        $counts = $this->service->getCounts();

        $this->assertSame(1, $counts['internal']);
        $this->assertSame(2, $counts['other']);
    }

    public function testNumericTokensAreClassifiedLikeAnyOther(): void {
        // PHP turns an all-digit array key into an int: the token must survive that.
        $this->instanceHas([IShare::TYPE_ROOM => 1], ['23456789' => 1], ['23456789' => 'public']);

        $this->assertSame(1, $this->service->getCounts()['public']);
    }

    public function testTheOverviewTotalIsEverySharesOnceAndTheScoreFollowsTheCategories(): void {
        $this->instanceHas(
            [IShare::TYPE_USER => 2, IShare::TYPE_LINK => 1, IShare::TYPE_ROOM => 1],
            ['pub12345' => 1],
            ['pub12345' => 'public'],
        );
        $this->mapper->method('topOwnersByType')->willReturn([]);

        $overview = $this->service->getOverview();

        $this->assertSame(4, $overview['total']);
        // Two public out of four, weight 2 each; two internal, weight 0: 100 * 4 / 8.
        $this->assertSame(50, $overview['score']);
    }

    // -------------------------------------------------------------------
    // filterFor(): the list behind a category is the set that was counted
    // -------------------------------------------------------------------

    public function testThePublicFilterIncludesPublicConversationsNotJustPublicLinks(): void {
        $this->instanceHas(
            [IShare::TYPE_LINK => 1, IShare::TYPE_ROOM => 2],
            ['pub12345' => 1, 'grp12345' => 1],
            ['pub12345' => 'public', 'grp12345' => 'internal'],
        );

        $filter = $this->service->filterFor('public');

        $this->assertSame([IShare::TYPE_LINK], $filter['types']);
        $this->assertSame(['pub12345'], $filter['roomTokens']);
    }

    public function testTheInternalFilterTakesPrivateConversationsAndLeavesPublicOnesOut(): void {
        $this->instanceHas(
            [IShare::TYPE_USER => 1, IShare::TYPE_ROOM => 2],
            ['pub12345' => 1, 'grp12345' => 1],
            ['pub12345' => 'public', 'grp12345' => 'internal'],
        );

        $filter = $this->service->filterFor('internal');

        $this->assertEqualsCanonicalizing([IShare::TYPE_USER, IShare::TYPE_GROUP, IShare::TYPE_CIRCLE], $filter['types']);
        $this->assertSame(['grp12345'], $filter['roomTokens']);
    }

    public function testTheExternalFilterHasNoConversations(): void {
        $this->instanceHas([IShare::TYPE_ROOM => 1], ['pub12345' => 1], ['pub12345' => 'public']);

        $filter = $this->service->filterFor('external');

        $this->assertSame([], $filter['roomTokens']);
        $this->assertEqualsCanonicalizing([IShare::TYPE_EMAIL, IShare::TYPE_REMOTE, IShare::TYPE_REMOTE_GROUP], $filter['types']);
    }

    public function testNumericTokensSurviveInTheFilterAsStrings(): void {
        $this->instanceHas([IShare::TYPE_ROOM => 1], ['23456789' => 1], ['23456789' => 'public']);

        $this->assertSame(['23456789'], $this->service->filterFor('public')['roomTokens']);
    }

    public function testACategoryNothingCanFilterOnHasNoFilter(): void {
        $this->instanceHas([]);

        $this->assertNull($this->service->filterFor('other'));
        $this->assertNull($this->service->filterFor('nonsense'));
        $this->assertNull($this->service->filterFor(''));
    }

    /**
     * What a category counts and what its filter selects must be the same
     * shares: sum the tokens' shares under the filter and compare.
     */
    public function testTheCountAndTheFilterOfACategoryDescribeTheSameShares(): void {
        $rooms = ['pub12345' => 4, 'grp12345' => 3, 'stale123' => 2];
        $this->instanceHas(
            [IShare::TYPE_LINK => 1, IShare::TYPE_ROOM => 9],
            $rooms,
            ['pub12345' => 'public', 'grp12345' => 'internal'],
        );

        $publicFilter = $this->service->filterFor('public');
        $inRooms = array_sum(array_intersect_key($rooms, array_flip($publicFilter['roomTokens'])));

        $this->assertSame($this->service->getCounts()['public'], 1 + $inRooms);
    }
}
