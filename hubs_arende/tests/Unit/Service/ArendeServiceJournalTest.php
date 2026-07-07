<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Tests\Unit\Service;

use OCA\HubsArende\Db\Arende;
use OCA\HubsArende\Db\ArendeMapper;
use OCA\HubsArende\Db\ArendeTyp;
use OCA\HubsArende\Db\Handelse;
use OCA\HubsArende\Db\HandelseMapper;
use OCA\HubsArende\Db\Member;
use OCA\HubsArende\Db\MemberMapper;
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
 * Tests for the HÄNDELSEJOURNAL (Historik & beslut) and the MEDLEMSBASERADE
 * dashboard ("Mina ärenden" = cases where the caller is in the member ledger).
 * The journal is BEST-EFFORT: a journal failure must never fell the mutation.
 */
final class ArendeServiceJournalTest extends TestCase {
    private ArendeMapper&MockObject $arendeMapper;
    private ArendeTypRegistry&MockObject $typRegistry;
    private SakerhetsskyddGrind&MockObject $grind;
    private FacksystemCommitService&MockObject $commitService;
    private ISecureRandom&MockObject $secureRandom;
    private ITimeFactory&MockObject $timeFactory;
    private LoggerInterface&MockObject $logger;
    private HandelseMapper&MockObject $handelseMapper;

    protected function setUp(): void {
        parent::setUp();
        $this->arendeMapper = $this->createMock(ArendeMapper::class);
        $this->typRegistry = $this->createMock(ArendeTypRegistry::class);
        $this->grind = $this->createMock(SakerhetsskyddGrind::class);
        $this->commitService = $this->createMock(FacksystemCommitService::class);
        $this->secureRandom = $this->createMock(ISecureRandom::class);
        $this->timeFactory = $this->createMock(ITimeFactory::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->handelseMapper = $this->createMock(HandelseMapper::class);

        $this->timeFactory->method('getDateTime')
            ->willReturnCallback(static fn (): \DateTime => new \DateTime('2026-07-03T08:00:00+00:00'));
        $this->secureRandom->method('generate')
            ->willReturn("\x01\x23\x45\x67\x89\xab\xcd\xef\x01\x23\x45\x67\x89\xab\xcd\xef");
        $this->grind->method('evaluate')->willReturn([
            'avvisad' => false,
            'reason' => SakerhetsskyddGrind::REASON_OK,
            'retroaktiv' => false,
            'indikator' => SakerhetsskyddGrind::IND_NONE,
            'kvitto' => [],
        ]);
        $this->arendeMapper->method('findByConversationId')->willReturn(null);
        $this->arendeMapper->method('insert')->willReturnArgument(0);
        $this->arendeMapper->method('update')->willReturnArgument(0);
    }

    private function typ(): ArendeTyp {
        $typ = new ArendeTyp();
        $typ->setArendeTypId('orosanmalan');
        $typ->setDisplayName('orosanmalan');
        $typ->setCommitDestination('facksystem');
        $typ->setDefaultEnhet('barn-familj@');
        $typ->setFristPolicy(json_encode(['typ' => 'domstol', 'speglasUrTreserva' => true]));
        return $typ;
    }

    public function testCreateCaseJournalsSkapad(): void {
        $this->typRegistry->method('get')->willReturn($this->typ());

        $typer = [];
        $this->handelseMapper->method('record')
            ->willReturnCallback(function (string $caseId, string $typ) use (&$typer): Handelse {
                $typer[] = $typ;
                return new Handelse();
            });

        $service = new ArendeService(
            $this->arendeMapper, $this->typRegistry, $this->grind, $this->commitService,
            $this->secureRandom, $this->timeFactory, $this->logger,
            handelseMapper: $this->handelseMapper,
        );
        $service->createCase(['conversationId' => 'conv-j1', 'arendeTyp' => 'orosanmalan', 'objektRef' => 'barn-j1']);

        self::assertContains(Handelse::TYP_SKAPAD, $typer);
    }

    public function testJournalFailureNeverFellsTheMutation(): void {
        $this->typRegistry->method('get')->willReturn($this->typ());
        $this->handelseMapper->method('record')->willThrowException(new \RuntimeException('journal nere'));

        $service = new ArendeService(
            $this->arendeMapper, $this->typRegistry, $this->grind, $this->commitService,
            $this->secureRandom, $this->timeFactory, $this->logger,
            handelseMapper: $this->handelseMapper,
        );
        $result = $service->createCase(['conversationId' => 'conv-j2', 'arendeTyp' => 'orosanmalan', 'objektRef' => 'barn-j2']);

        self::assertSame('otilldelat', $result->getStatus());
    }

    public function testTilldelaJournalsTilldelad(): void {
        $arende = new Arende();
        $arende->setHubsCaseId('case-j3');
        $arende->setArendeTyp('orosanmalan');
        $this->arendeMapper->method('findByCaseId')->with('case-j3')->willReturn($arende);

        $rows = [];
        $this->handelseMapper->method('record')
            ->willReturnCallback(function (string $caseId, string $typ, array $detalj) use (&$rows): Handelse {
                $rows[] = [$typ, $detalj];
                return new Handelse();
            });

        $service = new ArendeService(
            $this->arendeMapper, $this->typRegistry, $this->grind, $this->commitService,
            $this->secureRandom, $this->timeFactory, $this->logger,
            handelseMapper: $this->handelseMapper,
        );
        $service->tilldela('case-j3', 'anna-uid');

        self::assertContains([Handelse::TYP_TILLDELAD, ['uid' => 'anna-uid']], $rows);
    }

    public function testHistorikReadsViaShowAuthz(): void {
        $arende = new Arende();
        $arende->setHubsCaseId('case-j4');
        $this->arendeMapper->method('findByCaseId')->with('case-j4')->willReturn($arende);

        $h = new Handelse();
        $h->setHubsCaseId('case-j4');
        $h->setTyp(Handelse::TYP_STEG);
        $h->setDetalj(json_encode(['fran' => 'forhandsbedomning', 'till' => 'utredning']));
        $h->setTid(new \DateTime('2026-07-03T08:00:00+00:00'));
        $this->handelseMapper->method('findByCaseId')->with('case-j4')->willReturn([$h]);

        $service = new ArendeService(
            $this->arendeMapper, $this->typRegistry, $this->grind, $this->commitService,
            $this->secureRandom, $this->timeFactory, $this->logger,
            handelseMapper: $this->handelseMapper,
        );
        $historik = $service->historik('case-j4');

        self::assertCount(1, $historik);
        self::assertSame(Handelse::TYP_STEG, $historik[0]['typ']);
        self::assertSame('utredning', $historik[0]['detalj']['till']);
    }

    public function testDashboardArendenMineOnlyFiltersOnLedger(): void {
        $mitt = new Arende();
        $mitt->setHubsCaseId('case-mitt');
        $mitt->setArendeTyp('orosanmalan');
        $mitt->setStatus('otilldelat');
        $mitt->setSteg('forhandsbedomning');
        $mitt->setProvenanceState('ej_registrerad');
        $mitt->setEnhet('barn-familj@');
        $annans = new Arende();
        $annans->setHubsCaseId('case-annans');
        $annans->setArendeTyp('orosanmalan');
        $annans->setStatus('otilldelat');
        $annans->setSteg('forhandsbedomning');
        $annans->setProvenanceState('ej_registrerad');
        $annans->setEnhet('barn-familj@');
        $this->arendeMapper->method('findAll')->willReturn([$mitt, $annans]);

        $memberMapper = $this->createMock(MemberMapper::class);
        $memberMapper->method('findCaseIdsByUid')->with('fredrik')->willReturn(['case-mitt']);

        $user = $this->createMock(\OCP\IUser::class);
        $user->method('getUID')->willReturn('fredrik');
        $userSession = $this->createMock(\OCP\IUserSession::class);
        $userSession->method('getUser')->willReturn($user);
        // H1: enhet-gaten släpper via grupptillhörighet (barn-familj) — mineOnly-
        // filtret är det som testas ovanpå.
        $groupManager = $this->createMock(\OCP\IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn(false);
        $groupManager->method('getUserGroupIds')->willReturn(['barn-familj']);

        $service = new ArendeService(
            $this->arendeMapper, $this->typRegistry, $this->grind, $this->commitService,
            $this->secureRandom, $this->timeFactory, $this->logger,
            userSession: $userSession,
            groupManager: $groupManager,
            memberMapper: $memberMapper,
        );

        $alla = $service->dashboardArenden(false);
        $mina = $service->dashboardArenden(true);

        self::assertCount(2, $alla);
        self::assertCount(1, $mina);
        self::assertSame('case-mitt', $mina[0]['hubsCaseId']);
    }
}
