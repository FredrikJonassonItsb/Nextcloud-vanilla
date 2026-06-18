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
use OCA\HubsArende\Integration\Port\EdiariumPort;
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
 * Tests for the data-driven saga-hook mechanism (HOOK-INFRA) and its two declared
 * consumers: kat6 preSagaHook 'diariefor_direkt' (RT-1, reverse-order direct
 * diarieföring → born registered) and kat8 postCommitHook 'familjeratt_yttrande'
 * (FAM-1, best-effort yttrande after a verified commit). Spec §2.5/§3.2/§7.1 Δ7.
 */
final class ArendeServiceHookTest extends TestCase {
    private ArendeMapper&MockObject $arendeMapper;
    private ArendeTypRegistry&MockObject $typRegistry;
    private SakerhetsskyddGrind&MockObject $grind;
    private FacksystemCommitService&MockObject $commitService;
    private ISecureRandom&MockObject $secureRandom;
    private ITimeFactory&MockObject $timeFactory;
    private LoggerInterface&MockObject $logger;
    private EdiariumPort&MockObject $ediarium;

    protected function setUp(): void {
        parent::setUp();
        $this->arendeMapper = $this->createMock(ArendeMapper::class);
        $this->typRegistry = $this->createMock(ArendeTypRegistry::class);
        $this->grind = $this->createMock(SakerhetsskyddGrind::class);
        $this->commitService = $this->createMock(FacksystemCommitService::class);
        $this->secureRandom = $this->createMock(ISecureRandom::class);
        $this->timeFactory = $this->createMock(ITimeFactory::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->ediarium = $this->createMock(EdiariumPort::class);

        $this->timeFactory->method('getDateTime')
            ->willReturnCallback(static fn (): \DateTime => new \DateTime('2026-06-18T08:00:00+00:00'));
        $this->secureRandom->method('generate')
            ->willReturn("\x01\x23\x45\x67\x89\xab\xcd\xef\x01\x23\x45\x67\x89\xab\xcd\xef");
    }

    /** ArendeService with the EdiariumPort wired (named trailing-optional arg). */
    private function serviceWithEdiarium(): ArendeService {
        return new ArendeService(
            $this->arendeMapper,
            $this->typRegistry,
            $this->grind,
            $this->commitService,
            $this->secureRandom,
            $this->timeFactory,
            $this->logger,
            ediariumPort: $this->ediarium,
        );
    }

    /** ArendeService WITHOUT the EdiariumPort (positional unit harness ⇒ null). */
    private function serviceWithoutEdiarium(): ArendeService {
        return new ArendeService(
            $this->arendeMapper,
            $this->typRegistry,
            $this->grind,
            $this->commitService,
            $this->secureRandom,
            $this->timeFactory,
            $this->logger,
        );
    }

    // ================================================================== //
    //  RT-1 — kat6 'diariefor_direkt' (reverse order: born registered).
    // ================================================================== //

    public function testDiariomforDirektBornRegistered(): void {
        $this->grind->method('evaluate')->willReturn($this->cleanGrind());
        $this->arendeMapper->method('findByConversationId')->willReturn(null);
        $this->arendeMapper->method('insert')->willReturnArgument(0);
        $this->arendeMapper->method('update')->willReturnArgument(0);

        $typ = $this->makeTyp('rattsligt_tvang', 'diarium', preSagaHook: 'diariefor_direkt');
        $typ->setDhpHandlingstyp('lvu_lvm_akt');
        $typ->setSekretessGrund('osl_10_partsinsyn');
        $this->typRegistry->method('get')->with('rattsligt_tvang')->willReturn($typ);

        // The pre-saga hook diarieför DIRECT and returns a registrering-kvitto.
        $this->ediarium->expects(self::once())
            ->method('registrera')
            ->with(
                self::isType('string'),
                self::callback(static function (array $h): bool {
                    return ($h['handlingstyp'] ?? null) === 'lvu_lvm_akt'
                        && ($h['riktning'] ?? null) === 'upprattad'
                        && ($h['arendetyp'] ?? null) === 'rattsligt_tvang';
                }),
            )
            ->willReturn([
                'ok' => true,
                'diarienummer' => 'SN-2026-0101',
                'registreradAt' => '2026-06-18T08:00:00Z',
                'provenanceState' => 'registrerad',
                'handlingId' => 'h-abc123',
            ]);

        $result = $this->serviceWithEdiarium()->createCase([
            'conversationId' => 'conv-rt-1',
            'arendeTyp' => 'rattsligt_tvang',
            'objektRef' => 'akt-rt-1',
            'inkomDatum' => '2026-06-10',
        ]);

        // Omvänd ordning: ärendet föds 'registrerad' med dnr ur diariet.
        self::assertSame('registrerad', $result->getProvenanceState());
        self::assertSame('SN-2026-0101', $result->getDnr());
        self::assertSame('diarium', $result->getCommitDestination());
    }

    public function testDiariomforDirektFailsClosedWhenPortMissing(): void {
        $this->grind->method('evaluate')->willReturn($this->cleanGrind());
        $this->arendeMapper->method('findByConversationId')->willReturn(null);

        $typ = $this->makeTyp('rattsligt_tvang', 'diarium', preSagaHook: 'diariefor_direkt');
        $this->typRegistry->method('get')->with('rattsligt_tvang')->willReturn($typ);

        // En typ vars enda särlogik är direkt-diarieföringen får INTE skapas
        // oregistrerad: utan port måste createCase fail-closa, ingen register-rad.
        $this->arendeMapper->expects(self::never())->method('insert');

        $this->expectException(\RuntimeException::class);

        $this->serviceWithoutEdiarium()->createCase([
            'conversationId' => 'conv-rt-noport',
            'arendeTyp' => 'rattsligt_tvang',
            'objektRef' => 'akt-rt-2',
        ]);
    }

    // ================================================================== //
    //  HOOK-INFRA — no-op for types without a hook / unknown hook ids.
    // ================================================================== //

    public function testTypeWithoutHookNeverCallsEdiarium(): void {
        $this->grind->method('evaluate')->willReturn($this->cleanGrind());
        $this->arendeMapper->method('findByConversationId')->willReturn(null);
        $this->arendeMapper->method('insert')->willReturnArgument(0);
        $this->arendeMapper->method('update')->willReturnArgument(0);

        $typ = $this->makeTyp('orosanmalan', 'facksystem'); // no preSagaHook
        $this->typRegistry->method('get')->with('orosanmalan')->willReturn($typ);

        // No hook ⇒ the dispatcher is a no-op; the e-diarium port is never touched.
        $this->ediarium->expects(self::never())->method('registrera');

        $result = $this->serviceWithEdiarium()->createCase([
            'conversationId' => 'conv-oro-nohook',
            'arendeTyp' => 'orosanmalan',
            'objektRef' => 'barn-1',
        ]);

        self::assertSame('ej_registrerad', $result->getProvenanceState());
        self::assertNull($result->getDnr());
    }

    public function testUnknownHookIdIsLoggedNoOpNotAThrow(): void {
        $this->grind->method('evaluate')->willReturn($this->cleanGrind());
        $this->arendeMapper->method('findByConversationId')->willReturn(null);
        $this->arendeMapper->method('insert')->willReturnArgument(0);
        $this->arendeMapper->method('update')->willReturnArgument(0);

        $typ = $this->makeTyp('orosanmalan', 'facksystem', preSagaHook: 'finns_inte_hook');
        $this->typRegistry->method('get')->with('orosanmalan')->willReturn($typ);

        // Unknown hook id ⇒ silent no-op (never a throw), case born normally.
        $this->ediarium->expects(self::never())->method('registrera');

        $result = $this->serviceWithEdiarium()->createCase([
            'conversationId' => 'conv-unknown-hook',
            'arendeTyp' => 'orosanmalan',
            'objektRef' => 'barn-2',
        ]);

        self::assertSame('ej_registrerad', $result->getProvenanceState());
    }

    // ================================================================== //
    //  FAM-1 — kat8 'familjeratt_yttrande' post-commit hook.
    // ================================================================== //

    public function testFamiljerattPostCommitHookFires(): void {
        $arende = $this->makeArende('caseid-fam', 'familjeratt', 'facksystem', 'ej_registrerad');
        $this->arendeMapper->method('findByCaseId')->with('caseid-fam')->willReturn($arende);
        $this->arendeMapper->method('update')->willReturnArgument(0);

        $typ = $this->makeTyp('familjeratt', 'facksystem', postCommitHook: 'familjeratt_yttrande');
        $typ->setFrendsModul('familjeratt');
        $typ->setDhpHandlingstyp('familjeratt_akt');
        $this->typRegistry->method('get')->with('familjeratt')->willReturn($typ);

        $kvitto = [
            'ok' => true,
            'dnr' => '2026-FAM-0007',
            'gallrasDatum' => '2026-09-16',
            'verifierad' => true,
            'hubsCaseId' => 'caseid-fam',
        ];
        $this->commitService->method('commit')->willReturn($kvitto);

        // The post-commit hook registers the tingsrätts-yttrande exactly once.
        $this->ediarium->expects(self::once())
            ->method('registrera')
            ->with('caseid-fam', self::callback(static fn (array $h): bool => ($h['handlingstyp'] ?? null) === 'familjeratt_akt'))
            ->willReturn(['ok' => true, 'diarienummer' => 'SN-2026-0102', 'provenanceState' => 'registrerad', 'handlingId' => 'h-y']);

        $result = $this->serviceWithEdiarium()->commit('caseid-fam', ['typ' => 'yttrande']);

        self::assertSame($kvitto, $result);
        self::assertSame('registrerad', $arende->getProvenanceState());
    }

    public function testPostCommitHookFailureIsGraceful(): void {
        $arende = $this->makeArende('caseid-fam2', 'familjeratt', 'facksystem', 'ej_registrerad');
        $this->arendeMapper->method('findByCaseId')->with('caseid-fam2')->willReturn($arende);
        $this->arendeMapper->method('update')->willReturnArgument(0);

        $typ = $this->makeTyp('familjeratt', 'facksystem', postCommitHook: 'familjeratt_yttrande');
        $typ->setFrendsModul('familjeratt');
        $typ->setDhpHandlingstyp('familjeratt_akt');
        $this->typRegistry->method('get')->with('familjeratt')->willReturn($typ);

        $kvitto = ['ok' => true, 'dnr' => '2026-FAM-0008', 'gallrasDatum' => '2026-09-16', 'verifierad' => true, 'hubsCaseId' => 'caseid-fam2'];
        $this->commitService->method('commit')->willReturn($kvitto);

        // A failing post-hook must NEVER fell the already-verified kvitto.
        $this->ediarium->method('registrera')->willThrowException(new \RuntimeException('diariet nere'));

        $result = $this->serviceWithEdiarium()->commit('caseid-fam2', ['typ' => 'yttrande']);

        self::assertTrue($result['verifierad']);
        self::assertSame('registrerad', $arende->getProvenanceState());
    }

    public function testIdempotentCommitDoesNotRefirePostHook(): void {
        // Already registered ⇒ commit() short-circuits to receiptFromRegistered():
        // neither the commit port nor the post-hook fire again.
        $arende = $this->makeArende('caseid-fam3', 'familjeratt', 'facksystem', 'registrerad');
        $arende->setDnr('2026-FAM-0009');
        $this->arendeMapper->method('findByCaseId')->with('caseid-fam3')->willReturn($arende);

        $typ = $this->makeTyp('familjeratt', 'facksystem', postCommitHook: 'familjeratt_yttrande');
        $this->typRegistry->method('get')->with('familjeratt')->willReturn($typ);

        $this->commitService->expects(self::never())->method('commit');
        $this->ediarium->expects(self::never())->method('registrera');

        $result = $this->serviceWithEdiarium()->commit('caseid-fam3', ['typ' => 'yttrande']);

        self::assertTrue($result['verifierad']);
        self::assertTrue($result['idempotent'] ?? false);
    }

    // ================================================================== //
    //  Helpers
    // ================================================================== //

    /** @return array<string,mixed> */
    private function cleanGrind(): array {
        return [
            'avvisad' => false,
            'reason' => SakerhetsskyddGrind::REASON_OK,
            'retroaktiv' => false,
            'indikator' => SakerhetsskyddGrind::IND_NONE,
            'kvitto' => [],
        ];
    }

    private function makeTyp(
        string $id,
        string $commitDestination,
        ?string $preSagaHook = null,
        ?string $postCommitHook = null,
    ): ArendeTyp {
        $typ = new ArendeTyp();
        $typ->setArendeTypId($id);
        $typ->setDisplayName($id);
        $typ->setCommitDestination($commitDestination);
        $typ->setDefaultEnhet('barn-familj@');
        $typ->setPreSagaHook($preSagaHook);
        $typ->setPostCommitHook($postCommitHook);
        // speglas-policy ⇒ computeFristDue returns null (no date math in unit scope).
        $typ->setFristPolicy(json_encode(['typ' => 'domstol', 'speglasUrTreserva' => true]));
        return $typ;
    }

    private function makeArende(string $hubsCaseId, string $typ, string $dest, string $provenance): Arende {
        $arende = new Arende();
        $arende->setHubsCaseId($hubsCaseId);
        $arende->setArendeTyp($typ);
        $arende->setCommitDestination($dest);
        $arende->setProvenanceState($provenance);
        $arende->setRetentionState('aktiv');
        return $arende;
    }
}
