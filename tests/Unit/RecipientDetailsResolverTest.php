<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Tests\Unit;

use OCA\ShareAuditDashboard\Service\DisplayNameResolver;
use OCA\ShareAuditDashboard\Service\RecipientDetailsResolver;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers RecipientDetailsResolver: what a Talk conversation or a Deck card is
 * called, and how many people it reaches. The database reads are stubbed (they
 * are the other apps' tables); what is under test is what is made of them — and
 * that a missing Talk or Deck can never turn a listing into an error.
 */
class RecipientDetailsResolverTest extends TestCase {

    private RecipientDetailsResolver&MockObject $resolver;
    private DisplayNameResolver&MockObject $displayNames;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void {
        $this->displayNames = $this->createMock(DisplayNameResolver::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->resolver = $this->getMockBuilder(RecipientDetailsResolver::class)
            ->setConstructorArgs([$this->createMock(IDBConnection::class), $this->displayNames, $this->logger])
            ->onlyMethods(['fetchRooms', 'fetchAttendeeCounts', 'fetchCards', 'fetchDeckPeople'])
            ->getMock();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $type, string $recipient, int $id = 1): array {
        return ['id' => $id, 'type' => $type, 'recipient' => $recipient, 'owner' => 'alice'];
    }

    /**
     * @return array<string, mixed>
     */
    private function room(int $id, string $token, string $name, int $type = 2, int $listable = 0): array {
        return ['id' => $id, 'token' => $token, 'name' => $name, 'type' => $type, 'listable' => $listable];
    }

    /**
     * Talk knows these rooms; $attendees is the count by actor type of each.
     *
     * @param array<int, array<string, mixed>> $rooms
     * @param array<int, array<string, int>> $attendees room id => actor type => count
     */
    private function talkHas(array $rooms, array $attendees = []): void {
        $byToken = [];
        foreach ($rooms as $room) {
            $byToken[$room['token']] = $room;
        }
        $this->resolver->method('fetchRooms')->willReturn($byToken);
        $this->resolver->method('fetchAttendeeCounts')->willReturn($attendees);
    }

    // -------------------------------------------------------------------
    // What is left alone
    // -------------------------------------------------------------------

    public function testRowsThatAreNeitherTalkNorDeckAreLeftAloneAndNothingIsRead(): void {
        $this->resolver->expects($this->never())->method('fetchRooms');
        $this->resolver->expects($this->never())->method('fetchCards');
        $items = [$this->row(IShare::TYPE_USER, 'bob'), $this->row(IShare::TYPE_LINK, '')];

        $this->assertSame($items, $this->resolver->decorate($items));
    }

    public function testAConversationTalkDoesNotKnowLeavesTheRowAsItWas(): void {
        $this->talkHas([]);
        $items = [$this->row(IShare::TYPE_ROOM, 'gone1234')];

        $this->assertSame($items, $this->resolver->decorate($items));
    }

    // -------------------------------------------------------------------
    // Talk
    // -------------------------------------------------------------------

    public function testAGroupConversationShowsItsNameAndWhoIsInIt(): void {
        $this->talkHas(
            [$this->room(7, 'iitqa25e', 'Equipa de Marketing')],
            [7 => ['users' => 3, 'guests' => 1, 'groups' => 1]],
        );

        [$item] = $this->resolver->decorate([$this->row(IShare::TYPE_ROOM, 'iitqa25e')]);

        $this->assertSame('Equipa de Marketing', $item['recipientDisplayName']);
        $this->assertSame([
            'kind' => 'talk',
            'roomType' => 'group',
            'label' => 'Equipa de Marketing',
            'people' => [],
            'participants' => 4,
            'groups' => 1,
            'openTo' => null,
        ], $item['recipientInfo']);
    }

    public function testACircleOrAGroupIsNotCountedAsAPerson(): void {
        // A group in a conversation stands for however many members it has.
        $this->talkHas(
            [$this->room(7, 'abcd1234', 'Direção')],
            [7 => ['users' => 2, 'circles' => 1, 'groups' => 1, 'emails' => 1]],
        );

        [$item] = $this->resolver->decorate([$this->row(IShare::TYPE_ROOM, 'abcd1234')]);

        $this->assertSame(3, $item['recipientInfo']['participants']);
        $this->assertSame(2, $item['recipientInfo']['groups']);
    }

    public function testAPublicConversationIsOpenToWhoeverHasTheLink(): void {
        $this->talkHas([$this->room(8, 'pub12345', 'Sala pública', 3)], [8 => ['users' => 1]]);

        [$item] = $this->resolver->decorate([$this->row(IShare::TYPE_ROOM, 'pub12345')]);

        $this->assertSame('public', $item['recipientInfo']['roomType']);
        $this->assertSame('link', $item['recipientInfo']['openTo']);
    }

    public function testAListedConversationIsOpenToEveryUserOfTheInstance(): void {
        $this->talkHas([$this->room(9, 'lst12345', 'Aberta', 2, 1)], [9 => ['users' => 1]]);

        [$item] = $this->resolver->decorate([$this->row(IShare::TYPE_ROOM, 'lst12345')]);

        $this->assertSame('users', $item['recipientInfo']['openTo']);
    }

    public function testAnInviteOnlyConversationIsOpenToNobody(): void {
        $this->talkHas([$this->room(9, 'inv12345', 'Fechada', 2, 0)], [9 => ['users' => 2]]);

        [$item] = $this->resolver->decorate([$this->row(IShare::TYPE_ROOM, 'inv12345')]);

        $this->assertNull($item['recipientInfo']['openTo']);
    }

    /**
     * The token is what a conversation is stored under, and a credential: an
     * unnamed one must come out with no name at all — never the token as a
     * stand-in, or every place that "hides" the token by showing the name
     * shows the token.
     */
    public function testAConversationWithoutANameHasNoNameNotItsToken(): void {
        $this->talkHas([$this->room(7, 'noname12', '   ')], [7 => ['users' => 2]]);

        [$item] = $this->resolver->decorate([$this->row(IShare::TYPE_ROOM, 'noname12')]);

        $this->assertArrayNotHasKey('recipientDisplayName', $item);
        $this->assertSame('', $item['recipientInfo']['label']);
        $this->assertSame('noname12', $item['recipient'], 'the raw key is still the row\'s, for whoever may see it');
    }

    public function testAOneToOneIsNamedAfterItsTwoPeople(): void {
        $this->talkHas([$this->room(5, 'one12345', '["sat_reader","sat_taker"]', 1)]);
        $this->displayNames->method('resolveMany')->willReturn(['sat_reader' => 'Ana Silva', 'sat_taker' => 'Bruno Costa']);

        [$item] = $this->resolver->decorate([$this->row(IShare::TYPE_ROOM, 'one12345')]);

        $this->assertSame('Ana Silva ↔ Bruno Costa', $item['recipientDisplayName']);
        $this->assertSame('one_to_one', $item['recipientInfo']['roomType']);
        $this->assertSame(['Ana Silva', 'Bruno Costa'], $item['recipientInfo']['people']);
        $this->assertNull($item['recipientInfo']['participants'], 'two people are not a headcount to report');
    }

    public function testAOneToOneNeverAsksTalkForAttendees(): void {
        $this->resolver->method('fetchRooms')->willReturn(['one12345' => $this->room(5, 'one12345', '["a","b"]', 1)]);
        $this->resolver->expects($this->never())->method('fetchAttendeeCounts');
        $this->displayNames->method('resolveMany')->willReturn([]);

        $this->resolver->decorate([$this->row(IShare::TYPE_ROOM, 'one12345')]);
    }

    public function testAnAccountWithNoDisplayNameIsShownByItsId(): void {
        $this->talkHas([$this->room(5, 'one12345', '["ghost","bob"]', 1)]);
        $this->displayNames->method('resolveMany')->willReturn(['bob' => 'Bob']);

        [$item] = $this->resolver->decorate([$this->row(IShare::TYPE_ROOM, 'one12345')]);

        $this->assertSame('ghost ↔ Bob', $item['recipientDisplayName']);
    }

    public function testAOneToOneWhoseNameIsNotAListOfAccountsHasNoNameNotItsToken(): void {
        $this->talkHas([$this->room(5, 'one12345', 'not json', 1)]);
        $this->displayNames->method('resolveMany')->willReturn([]);

        [$item] = $this->resolver->decorate([$this->row(IShare::TYPE_ROOM, 'one12345')]);

        $this->assertArrayNotHasKey('recipientDisplayName', $item);
        $this->assertSame('', $item['recipientInfo']['label']);
        $this->assertSame([], $item['recipientInfo']['people']);
    }

    public function testSeveralSharesIntoTheSameConversationAreReadOnce(): void {
        $this->resolver->expects($this->once())->method('fetchRooms')->with(['iitqa25e'])
            ->willReturn(['iitqa25e' => $this->room(7, 'iitqa25e', 'Equipa')]);
        $this->resolver->method('fetchAttendeeCounts')->willReturn([7 => ['users' => 2]]);

        $items = $this->resolver->decorate([
            $this->row(IShare::TYPE_ROOM, 'iitqa25e', 1),
            $this->row(IShare::TYPE_ROOM, 'iitqa25e', 2),
        ]);

        $this->assertSame('Equipa', $items[0]['recipientDisplayName']);
        $this->assertSame('Equipa', $items[1]['recipientDisplayName']);
    }

    // -------------------------------------------------------------------
    // Deck
    // -------------------------------------------------------------------

    public function testADeckShareShowsItsCardItsBoardAndHowManyPeopleItReaches(): void {
        $this->resolver->method('fetchCards')->willReturn([6 => ['title' => 'Preparar proposta', 'board' => 'Projeto Alfa']]);
        $this->resolver->method('fetchDeckPeople')->willReturn([3801 => 3]);

        [$item] = $this->resolver->decorate([$this->row(IShare::TYPE_DECK, '6', 3801)]);

        $this->assertSame('Preparar proposta', $item['recipientDisplayName']);
        $this->assertSame([
            'kind' => 'deck',
            'label' => 'Preparar proposta',
            'card' => 'Preparar proposta',
            'board' => 'Projeto Alfa',
            'people' => 3,
        ], $item['recipientInfo']);
    }

    public function testTwoSharesIntoTheSameCardEachKeepTheirOwnCount(): void {
        $this->resolver->method('fetchCards')->willReturn([6 => ['title' => 'Cartão', 'board' => 'Quadro']]);
        $this->resolver->method('fetchDeckPeople')->willReturn([10 => 2, 11 => 5]);

        $items = $this->resolver->decorate([
            $this->row(IShare::TYPE_DECK, '6', 10),
            $this->row(IShare::TYPE_DECK, '6', 11),
        ]);

        $this->assertSame([2, 5], [$items[0]['recipientInfo']['people'], $items[1]['recipientInfo']['people']]);
    }

    public function testACardNobodyElseCanSeeHasNoCount(): void {
        $this->resolver->method('fetchCards')->willReturn([6 => ['title' => 'Cartão', 'board' => 'Quadro']]);
        $this->resolver->method('fetchDeckPeople')->willReturn([]);

        [$item] = $this->resolver->decorate([$this->row(IShare::TYPE_DECK, '6', 10)]);

        $this->assertNull($item['recipientInfo']['people']);
    }

    public function testACardWithoutATitleShowsItsNumber(): void {
        $this->resolver->method('fetchCards')->willReturn([6 => ['title' => '', 'board' => 'Quadro']]);
        $this->resolver->method('fetchDeckPeople')->willReturn([]);

        [$item] = $this->resolver->decorate([$this->row(IShare::TYPE_DECK, '6')]);

        $this->assertSame('6', $item['recipientDisplayName']);
    }

    public function testADeckRecipientThatIsNotACardNumberIsIgnored(): void {
        $this->resolver->expects($this->never())->method('fetchCards');
        $items = [$this->row(IShare::TYPE_DECK, 'abc')];

        $this->assertSame($items, $this->resolver->decorate($items));
    }

    public function testACardDeckDoesNotKnowLeavesTheRowAsItWas(): void {
        $this->resolver->method('fetchCards')->willReturn([]);
        $this->resolver->method('fetchDeckPeople')->willReturn([]);
        $items = [$this->row(IShare::TYPE_DECK, '99')];

        $this->assertSame($items, $this->resolver->decorate($items));
    }

    // -------------------------------------------------------------------
    // redactRoomTokens(): what a caller who may not see tokens is handed
    // -------------------------------------------------------------------

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function roomRow(string $token, array $extra = []): array {
        return $this->row(IShare::TYPE_ROOM, $token) + $extra;
    }

    public function testARedactedRoomRowCarriesItsNameInPlaceOfItsToken(): void {
        $this->talkHas([$this->room(7, 'iitqa25e', 'Equipa de Marketing')], [7 => ['users' => 2]]);

        $items = $this->resolver->redactRoomTokens($this->resolver->decorate([$this->roomRow('iitqa25e')]));

        $this->assertSame('Equipa de Marketing', $items[0]['recipient']);
        $this->assertSame('Equipa de Marketing', $items[0]['recipientDisplayName']);
        $this->assertSame('Equipa de Marketing', $items[0]['recipientInfo']['label']);
    }

    /**
     * The reported leak, end to end: an unnamed public conversation, through
     * decorate() and then the redaction. No field may still hold the token.
     */
    public function testAnUnnamedConversationLeaksItsTokenInNoFieldOnceRedacted(): void {
        $this->talkHas([$this->room(10, 'synthetic-room-token', '', 3)], []);

        $items = $this->resolver->redactRoomTokens($this->resolver->decorate([$this->roomRow('synthetic-room-token')]));

        $this->assertSame('', $items[0]['recipient']);
        $this->assertArrayNotHasKey('recipientDisplayName', $items[0]);
        $this->assertSame('', $items[0]['recipientInfo']['label']);
        $this->assertStringNotContainsString('synthetic-room-token', json_encode($items));
    }

    public function testAOneToOneWithNoNameableParticipantsLeaksItsTokenInNoFieldOnceRedacted(): void {
        $this->talkHas([$this->room(5, 'one12345', 'not json', 1)]);
        $this->displayNames->method('resolveMany')->willReturn([]);

        $items = $this->resolver->redactRoomTokens($this->resolver->decorate([$this->roomRow('one12345')]));

        $this->assertStringNotContainsString('one12345', json_encode($items));
    }

    /**
     * Defence in depth: whatever put the token into a name field — an older
     * resolver, another code path — the redaction still refuses to let it
     * through, rather than trusting that the fields it copies from are clean.
     */
    public function testANameThatIsTheTokenItselfIsStillRemoved(): void {
        $items = $this->resolver->redactRoomTokens([[
            'id' => 1, 'type' => IShare::TYPE_ROOM, 'recipient' => 'tok12345',
            'recipientDisplayName' => 'tok12345',
            'recipientInfo' => ['kind' => 'talk', 'label' => 'tok12345', 'people' => []],
        ]]);

        $this->assertStringNotContainsString('tok12345', json_encode($items));
        $this->assertSame('', $items[0]['recipient']);
    }

    public function testARoomRowThatWasNeverDecoratedIsBlankedNotLeftWithItsToken(): void {
        // Talk not installed, or the room deleted: nothing resolved a name.
        $items = $this->resolver->redactRoomTokens([$this->roomRow('gone1234')]);

        $this->assertSame('', $items[0]['recipient']);
    }

    public function testRedactionLeavesEveryOtherKindOfRecipientAlone(): void {
        $items = [$this->row(IShare::TYPE_USER, 'bob'), $this->row(IShare::TYPE_GROUP, 'staff'), $this->row(IShare::TYPE_EMAIL, 'a@b.c')];

        $this->assertSame($items, $this->resolver->redactRoomTokens($items));
    }

    public function testRoomLabelsOnlyReturnsTheConversationsThatHaveAName(): void {
        $this->talkHas([
            $this->room(7, 'named123', 'Equipa de Marketing'),
            $this->room(8, 'blank123', ''),
        ], [7 => ['users' => 1], 8 => ['users' => 1]]);

        $this->assertSame(['named123' => 'Equipa de Marketing'], $this->resolver->roomLabels(['named123', 'blank123', 'gone1234']));
    }

    // -------------------------------------------------------------------
    // A missing Talk or Deck must never fail a listing
    // -------------------------------------------------------------------

    public function testATalkThatCannotBeReadLeavesItsRowsAsTheyWere(): void {
        $this->resolver->method('fetchRooms')->willThrowException(new \RuntimeException('no such table: talk_rooms'));
        $this->logger->expects($this->once())->method('debug');
        $items = [$this->row(IShare::TYPE_ROOM, 'iitqa25e')];

        $this->assertSame($items, $this->resolver->decorate($items));
    }

    public function testADeckThatCannotBeReadLeavesItsRowsAsTheyWere(): void {
        $this->resolver->method('fetchCards')->willThrowException(new \RuntimeException('no such table: deck_cards'));
        $items = [$this->row(IShare::TYPE_DECK, '6')];

        $this->assertSame($items, $this->resolver->decorate($items));
    }

    public function testABrokenDeckDoesNotTakeTalkWithIt(): void {
        $this->talkHas([$this->room(7, 'iitqa25e', 'Equipa')], [7 => ['users' => 2]]);
        $this->resolver->method('fetchCards')->willThrowException(new \RuntimeException('boom'));

        $items = $this->resolver->decorate([
            $this->row(IShare::TYPE_ROOM, 'iitqa25e', 1),
            $this->row(IShare::TYPE_DECK, '6', 2),
        ]);

        $this->assertSame('Equipa', $items[0]['recipientDisplayName']);
        $this->assertArrayNotHasKey('recipientInfo', $items[1]);
    }

    public function testACardStillShowsWhenItsPeopleCannotBeCounted(): void {
        $this->resolver->method('fetchCards')->willReturn([6 => ['title' => 'Cartão', 'board' => 'Quadro']]);
        $this->resolver->method('fetchDeckPeople')->willThrowException(new \RuntimeException('boom'));

        [$item] = $this->resolver->decorate([$this->row(IShare::TYPE_DECK, '6', 10)]);

        $this->assertSame('Cartão', $item['recipientDisplayName']);
        $this->assertNull($item['recipientInfo']['people']);
    }
}
