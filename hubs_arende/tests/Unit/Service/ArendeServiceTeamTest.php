<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Tests\Unit\Service;

use OCA\HubsArende\Db\ArendeMapper;
use OCA\HubsArende\Db\ArendeTyp;
use OCA\HubsArende\Db\Pekare;
use OCA\HubsArende\Db\PekareMapper;
use OCA\HubsArende\Integration\Client\TeamClient;
use OCA\HubsArende\Service\ArendeService;
use OCA\HubsArende\Service\ArendeTypRegistry;
use OCA\HubsArende\Service\ArenderumGroupService;
use OCA\HubsArende\Service\FacksystemCommitService;
use OCA\HubsArende\Service\SakerhetsskyddGrind;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for saga step T — the ärenderum's PRESENTATION layer: one Team (circle)
 * per case with the per-case access group as its ONLY member. The team is a
 * MIRROR of the access list (ägarmodellen unchanged: gruppen förblir
 * åtkomstprimitiven); T degrades gracefully when the client, mapper or the
 * per-case group is absent — a missing presentation layer must never fell
 * createCase.
 */
final class ArendeServiceTeamTest extends TestCase {
    private ArendeMapper&MockObject $arendeMapper;
    private ArendeTypRegistry&MockObject $typRegistry;
    private SakerhetsskyddGrind&MockObject $grind;
    private FacksystemCommitService&MockObject $commitService;
    private ISecureRandom&MockObject $secureRandom;
    private ITimeFactory&MockObject $timeFactory;
    private LoggerInterface&MockObject $logger;
    private PekareMapper&MockObject $pekareMapper;
    private ArenderumGroupService&MockObject $arenderumGroupService;
    private TeamClient&MockObject $teamClient;

    protected function setUp(): void {
        parent::setUp();
        $this->arendeMapper = $this->createMock(ArendeMapper::class);
        $this->typRegistry = $this->createMock(ArendeTypRegistry::class);
        $this->grind = $this->createMock(SakerhetsskyddGrind::class);
        $this->commitService = $this->createMock(FacksystemCommitService::class);
        $this->secureRandom = $this->createMock(ISecureRandom::class);
        $this->timeFactory = $this->createMock(ITimeFactory::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->pekareMapper = $this->createMock(PekareMapper::class);
        $this->arenderumGroupService = $this->createMock(ArenderumGroupService::class);
        $this->teamClient = $this->createMock(TeamClient::class);

        $this->timeFactory->method('getDateTime')
            ->willReturnCallback(static fn (): \DateTime => new \DateTime('2026-07-02T08:00:00+00:00'));
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
        $this->pekareMapper->method('findByCaseAndTyp')->willReturn([]);

        $typ = new ArendeTyp();
        $typ->setArendeTypId('orosanmalan');
        $typ->setDisplayName('orosanmalan');
        $typ->setCommitDestination('facksystem');
        $typ->setDefaultEnhet('barn-familj@');
        $typ->setFristPolicy(json_encode(['typ' => 'domstol', 'speglasUrTreserva' => true]));
        $this->typRegistry->method('get')->with('orosanmalan')->willReturn($typ);
    }

    private function service(?TeamClient $teamClient): ArendeService {
        return new ArendeService(
            $this->arendeMapper,
            $this->typRegistry,
            $this->grind,
            $this->commitService,
            $this->secureRandom,
            $this->timeFactory,
            $this->logger,
            pekareMapper: $this->pekareMapper,
            arenderumGroupService: $this->arenderumGroupService,
            teamClient: $teamClient,
        );
    }

    /** @return array<string,mixed> */
    private function rad(string $conversationId): array {
        return [
            'conversationId' => $conversationId,
            'arendeTyp' => 'orosanmalan',
            'objektRef' => 'barn-team-1',
        ];
    }

    // ================================================================== //
    //  (1) T creates the team from the per-case group + records the pekare
    // ================================================================== //

    public function testTeamCreatedWithPerCaseGroupAndPekareRecorded(): void {
        $this->arenderumGroupService->method('ensure')
            ->willReturnCallback(static fn (string $id): string => 'hubs-case-' . $id);

        $this->teamClient->expects(self::once())
            ->method('createTeam')
            ->with(
                self::callback(static fn (string $name): bool => str_starts_with($name, 'Ärende ')),
                self::callback(static fn (string $gid): bool => str_starts_with($gid, 'hubs-case-')),
            )
            ->willReturn('team-single-id');

        $recorded = [];
        $this->pekareMapper->method('record')
            ->willReturnCallback(function (string $caseId, string $typ, string $objektId) use (&$recorded): Pekare {
                $recorded[] = [$typ, $objektId];
                return new Pekare();
            });

        $this->service($this->teamClient)->createCase($this->rad('conv-team-1'));

        self::assertContains(['team', 'team-single-id'], $recorded);
    }

    // ================================================================== //
    //  (2) createTeam failure (null) ⇒ no team pekare, case still born
    // ================================================================== //

    public function testTeamFailureIsGracefulAndRecordsNoPekare(): void {
        $this->arenderumGroupService->method('ensure')
            ->willReturnCallback(static fn (string $id): string => 'hubs-case-' . $id);
        $this->teamClient->method('createTeam')->willReturn(null);

        $recorded = [];
        $this->pekareMapper->method('record')
            ->willReturnCallback(function (string $caseId, string $typ, string $objektId) use (&$recorded): Pekare {
                $recorded[] = $typ;
                return new Pekare();
            });

        $result = $this->service($this->teamClient)->createCase($this->rad('conv-team-2'));

        self::assertNotContains('team', $recorded);
        self::assertSame('otilldelat', $result->getStatus());
    }

    // ================================================================== //
    //  (3) No per-case group (ensure ⇒ null) ⇒ T is a logged skip
    // ================================================================== //

    public function testNoPerCaseGroupSkipsTeamEntirely(): void {
        $this->arenderumGroupService->method('ensure')->willReturn(null);

        // Utan grupp finns ingen åtkomstspegel att koppla — teamet får inte skapas
        // "tomt" (skulle presentera ett rum utan medlemmar).
        $this->teamClient->expects(self::never())->method('createTeam');

        $result = $this->service($this->teamClient)->createCase($this->rad('conv-team-3'));

        self::assertSame('otilldelat', $result->getStatus());
    }

    // ================================================================== //
    //  (4) Positional harness (no TeamClient) ⇒ unchanged behaviour
    // ================================================================== //

    public function testWithoutTeamClientCreateCaseStillSucceeds(): void {
        $this->arenderumGroupService->method('ensure')
            ->willReturnCallback(static fn (string $id): string => 'hubs-case-' . $id);

        $result = $this->service(null)->createCase($this->rad('conv-team-4'));

        self::assertSame('otilldelat', $result->getStatus());
        self::assertSame('facksystem', $result->getCommitDestination());
    }

    // ================================================================== //
    //  (5) laggTillTalkrum: EXTRA chatt får teamet som deltagare
    // ================================================================== //

    public function testLaggTillTalkrumAddsTeamAsParticipant(): void {
        $arende = new \OCA\HubsArende\Db\Arende();
        $arende->setHubsCaseId('caseid-chatt');
        $arende->setArendeTyp('orosanmalan');
        $arende->setCommitDestination('facksystem');
        $this->arendeMapper->method('findByCaseId')->with('caseid-chatt')->willReturn($arende);

        $pekareMapper = $this->createMock(PekareMapper::class);
        $pekareMapper->method('findByCaseAndTyp')->willReturnCallback(
            function (string $id, string $typ): array {
                if ($typ === 'team') {
                    $p = new Pekare();
                    $p->setHubsCaseId($id);
                    $p->setObjektTyp('team');
                    $p->setObjektId('team-single-id');
                    return [$p];
                }
                return [];
            },
        );
        $pekareMapper->method('record')->willReturn(new Pekare());

        $spreedClient = $this->createMock(\OCA\HubsArende\Integration\Client\SpreedClient::class);
        $spreedClient->method('createRoom')->willReturn('tok-extra');
        // Kärnan: den nya chatten kopplas till TEAMET (listas som team-resurs).
        $spreedClient->expects(self::once())
            ->method('addCircleParticipant')
            ->with('tok-extra', 'team-single-id')
            ->willReturn(true);

        $service = new ArendeService(
            $this->arendeMapper,
            $this->typRegistry,
            $this->grind,
            $this->commitService,
            $this->secureRandom,
            $this->timeFactory,
            $this->logger,
            pekareMapper: $pekareMapper,
            spreedClient: $spreedClient,
        );

        self::assertSame('tok-extra', $service->laggTillTalkrum('caseid-chatt', 'Samverkan skola'));
    }
}
