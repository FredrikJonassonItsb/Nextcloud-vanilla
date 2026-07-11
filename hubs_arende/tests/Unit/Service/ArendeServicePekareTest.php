<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Tests\Unit\Service;

use OCA\HubsArende\Db\Arende;
use OCA\HubsArende\Db\ArendeMapper;
use OCA\HubsArende\Db\Pekare;
use OCA\HubsArende\Db\PekareMapper;
use OCA\HubsArende\Service\ArendeService;
use OCA\HubsArende\Service\ArendeTypRegistry;
use OCA\HubsArende\Service\FacksystemCommitService;
use OCA\HubsArende\Service\SakerhetsskyddGrind;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the SAGA-pekarblock surfaced on the full card + collapsed card
 * ({@see ArendeService::mapToFullCard()} / {@see ArendeService::mapToCard()}).
 *
 * ADDITIVE READ SURFACE: the card mappers resolve the case's coordination
 * pointers (ärenderum-token, groupfolder-id, deck-board/-kort, kalender-uri,
 * conversation) via the PekareMapper so the GUI can deep-länka. THIN + NEVER-SoR:
 * ENDAST id/token, aldrig PII. A missing PekareMapper degrades to TOM_PEKARE
 * (alla null) — never a kast, never a falskt 0.
 */
final class ArendeServicePekareTest extends TestCase {
    private ArendeMapper&MockObject $arendeMapper;
    private ArendeTypRegistry&MockObject $typRegistry;
    private SakerhetsskyddGrind&MockObject $grind;
    private FacksystemCommitService&MockObject $commitService;
    private ISecureRandom&MockObject $secureRandom;
    private ITimeFactory&MockObject $timeFactory;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void {
        parent::setUp();

        $this->arendeMapper = $this->createMock(ArendeMapper::class);
        $this->typRegistry = $this->createMock(ArendeTypRegistry::class);
        $this->grind = $this->createMock(SakerhetsskyddGrind::class);
        $this->commitService = $this->createMock(FacksystemCommitService::class);
        $this->secureRandom = $this->createMock(ISecureRandom::class);
        $this->timeFactory = $this->createMock(ITimeFactory::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->timeFactory->method('getDateTime')
            ->willReturnCallback(static fn (): \DateTime => new \DateTime('2026-06-17T08:00:00+00:00'));
    }

    // ================================================================== //
    //  (1) Full card carries a complete pekarblock from a known pointer set
    // ================================================================== //

    public function testMapToFullCardPekareEqualsExpectedShape(): void {
        $pekareMapper = $this->createMock(PekareMapper::class);
        // findByCaseId() returns id-DESC (newest first); the SAGA-originalet (oldest)
        // is the LAST-seen value per type. Put a NEWER duplicate talk_room first so
        // the test proves the oldest wins.
        $pekareMapper->method('findByCaseId')->with('case-pek-1')->willReturn([
            $this->pekare('talk_room', 'token-NEW'),           // newer dup (ignoreras)
            $this->pekare('groupfolder', '42'),
            $this->pekare('conversation', 'conv-abc'),
            $this->pekare('deck_card', '7', '99'),             // objekt_id=cardId, riktning=boardId
            $this->pekare('calendar', 'case-cal.ics', 'agare-uid'),
            $this->pekare('team', 'team-single-id'),           // T — presentationsteamet
            $this->pekare('dokumentchatt', 'filtok-1', '05-bbic'), // P1.3b — filchatt-rum (objektId=token, riktning=fil)
            $this->pekare('talk_room', 'token-ORIG'),          // SAGA-original (äldst)
        ]);
        // mapToCard (inherited) reuses findByCaseAndTyp for talkToken + bevakningBoardId.
        $pekareMapper->method('findByCaseAndTyp')->willReturnCallback(
            fn (string $id, string $typ): array => match ($typ) {
                'talk_room' => [$this->pekare('talk_room', 'token-NEW')],
                'deck_card' => [$this->pekare('deck_card', '7', '99')],
                default => [],
            },
        );

        $service = $this->makeService($pekareMapper);
        $full = $service->mapToFullCard($this->arende('case-pek-1'));

        self::assertArrayHasKey('pekare', $full);
        self::assertSame([
            'talkToken' => 'token-ORIG',
            'groupfolderId' => 42,
            'conversationId' => 'conv-abc',
            'deckBoardId' => 99,
            'deckCardId' => 7,
            'calendarUri' => 'case-cal.ics',
            'bevakningBoardId' => 99,
            'teamId' => 'team-single-id',
            // 1:n — ALLA rum, äldst först (saga-originalet med namn=null först).
            'talkRooms' => [
                ['token' => 'token-ORIG', 'namn' => null],
                ['token' => 'token-NEW', 'namn' => null],
            ],
            // P1.3b — dokumentchatt-rum (filchatt) som förstaklassiga pekare.
            'dokumentchattar' => [
                ['token' => 'filtok-1', 'fil' => '05-bbic'],
            ],
        ], $full['pekare']);
        // bevakningBoardId === deckBoardId invariant.
        self::assertSame($full['pekare']['deckBoardId'], $full['pekare']['bevakningBoardId']);
    }

    // ================================================================== //
    //  (2) No PekareMapper -> TOM_PEKARE (all 7 keys null), never a kast
    // ================================================================== //

    public function testMapToFullCardWithoutPekareMapperIsTomPekare(): void {
        $service = $this->makeService(null);
        $full = $service->mapToFullCard($this->arende('case-tom'));

        self::assertSame([
            'talkToken' => null,
            'groupfolderId' => null,
            'conversationId' => null,
            'deckBoardId' => null,
            'deckCardId' => null,
            'calendarUri' => null,
            'bevakningBoardId' => null,
            'teamId' => null,
            'talkRooms' => [],
            'dokumentchattar' => [],
        ], $full['pekare']);
        // Collapsed keys (inherited) are also honest-empty.
        self::assertNull($full['talkToken']);
        self::assertNull($full['bevakningBoardId']);
        self::assertNull($full['teamId']);
    }

    // ================================================================== //
    //  (3) Absent type / empty objekt_id -> null, NEVER a falskt 0
    // ================================================================== //

    public function testAbsentTypeYieldsNullNotZero(): void {
        $pekareMapper = $this->createMock(PekareMapper::class);
        // Only a talk_room exists — deck/groupfolder/calendar/conversation absent.
        // The deck_card riktning is '' to prove the int-guard maps '' -> null, not 0.
        $pekareMapper->method('findByCaseId')->with('case-sparse')->willReturn([
            $this->pekare('talk_room', 'tok-1'),
            $this->pekare('deck_card', '', ''),
        ]);
        $pekareMapper->method('findByCaseAndTyp')->willReturnCallback(
            fn (string $id, string $typ): array => match ($typ) {
                'talk_room' => [$this->pekare('talk_room', 'tok-1')],
                'deck_card' => [$this->pekare('deck_card', '', '')],
                default => [],
            },
        );

        $service = $this->makeService($pekareMapper);
        $pek = $service->mapToFullCard($this->arende('case-sparse'))['pekare'];

        self::assertSame('tok-1', $pek['talkToken']);
        // Absent types -> null (honest-empty), not 0 / '' .
        self::assertNull($pek['groupfolderId']);
        self::assertNull($pek['conversationId']);
        self::assertNull($pek['calendarUri']);
        self::assertNull($pek['deckCardId']);
        self::assertNull($pek['teamId']);
        // '' riktning guarded to null, not a falskt 0.
        self::assertNull($pek['deckBoardId']);
        self::assertNull($pek['bevakningBoardId']);
    }

    // ================================================================== //
    //  (4) Collapsed card carries talkToken + bevakningBoardId (för /arende-summary)
    // ================================================================== //

    public function testMapToCardCarriesTalkTokenAndBevakningBoardId(): void {
        $pekareMapper = $this->createMock(PekareMapper::class);
        $pekareMapper->method('findByCaseAndTyp')->willReturnCallback(
            fn (string $id, string $typ): array => match ($typ) {
                'talk_room' => [$this->pekare('talk_room', 'collapsed-tok')],
                'deck_card' => [$this->pekare('deck_card', '12', '88')],
                default => [],
            },
        );

        $service = $this->makeService($pekareMapper);
        $card = $service->mapToCard($this->arende('case-collapsed'));

        self::assertArrayHasKey('talkToken', $card);
        self::assertArrayHasKey('bevakningBoardId', $card);
        self::assertSame('collapsed-tok', $card['talkToken']);
        self::assertSame(88, $card['bevakningBoardId']);
        // Existing engine-state badge is untouched.
        self::assertTrue($card['kallaMotor']);
    }

    // ================================================================== //
    //  (5) losRum — reverse-lookup rum→ärende (P1.3b): boten läser registret
    // ================================================================== //

    public function testLosRumResolvarTalkRoomOchDokumentchatt(): void {
        $pekareMapper = $this->createMock(PekareMapper::class);
        $pekareMapper->method('findByTypAndObjektId')->willReturnCallback(
            function (string $typ, string $token): array {
                if ($typ === 'talk_room' && $token === 'arenderum-tok') {
                    return [$this->pekareMed('case-A', 'talk_room', 'arenderum-tok')];
                }
                if ($typ === 'dokumentchatt' && $token === 'fil-tok') {
                    return [$this->pekareMed('case-B', 'dokumentchatt', 'fil-tok', '05-bbic')];
                }
                return [];
            },
        );
        $service = $this->makeService($pekareMapper);

        self::assertSame(
            ['hubsCaseId' => 'case-A', 'typ' => 'talk_room', 'fil' => null],
            $service->losRum('arenderum-tok'),
        );
        self::assertSame(
            ['hubsCaseId' => 'case-B', 'typ' => 'dokumentchatt', 'fil' => '05-bbic'],
            $service->losRum('fil-tok'),
        );
        self::assertNull($service->losRum('okant-rum'), 'okänt rum ⇒ null');
        self::assertNull($service->losRum(''), 'tom token ⇒ null');
    }

    public function testLosRumUtanPekareMapperArNull(): void {
        self::assertNull($this->makeService(null)->losRum('vilken-som-helst'));
    }

    // ================================================================== //
    //  Helpers
    // ================================================================== //

    private function makeService(?PekareMapper $pekareMapper): ArendeService {
        return new ArendeService(
            $this->arendeMapper,
            $this->typRegistry,
            $this->grind,
            $this->commitService,
            $this->secureRandom,
            $this->timeFactory,
            $this->logger,
            $pekareMapper,
        );
    }

    private function arende(string $hubsCaseId): Arende {
        $arende = new Arende();
        $arende->setHubsCaseId($hubsCaseId);
        $arende->setArendeTyp('orosanmalan');
        $arende->setStatus('otilldelat');
        $arende->setSteg('forhandsbedomning');
        $arende->setProvenanceState('ej_registrerad');
        return $arende;
    }

    private function pekare(string $objektTyp, string $objektId, ?string $riktning = null): Pekare {
        $p = new Pekare();
        $p->setObjektTyp($objektTyp);
        $p->setObjektId($objektId);
        $p->setRiktning($riktning);
        return $p;
    }

    /** Pekare med hubs_case_id satt (losRum returnerar getHubsCaseId()). */
    private function pekareMed(string $hubsCaseId, string $objektTyp, string $objektId, ?string $riktning = null): Pekare {
        $p = $this->pekare($objektTyp, $objektId, $riktning);
        $p->setHubsCaseId($hubsCaseId);
        return $p;
    }
}
