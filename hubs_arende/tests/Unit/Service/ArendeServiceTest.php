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
use OCA\HubsArende\Exception\AvvisadException;
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
 * Unit tests for the case engine ({@see ArendeService}).
 *
 * Every collaborator is a mock — the saga's external side effects (R3–R9) are
 * TODO no-ops, so createCase() in this build exercises R0 (grind), idempotency,
 * the commit_destination NOT NULL invariant, the register INSERT (R2) and the
 * verified-commit provenance flip. Those are exactly the green smoke-path steps.
 */
final class ArendeServiceTest extends TestCase {
    private ArendeMapper&MockObject $arendeMapper;
    private ArendeTypRegistry&MockObject $typRegistry;
    private SakerhetsskyddGrind&MockObject $grind;
    private FacksystemCommitService&MockObject $commitService;
    private ISecureRandom&MockObject $secureRandom;
    private ITimeFactory&MockObject $timeFactory;
    private LoggerInterface&MockObject $logger;

    private ArendeService $service;

    protected function setUp(): void {
        parent::setUp();

        $this->arendeMapper = $this->createMock(ArendeMapper::class);
        $this->typRegistry = $this->createMock(ArendeTypRegistry::class);
        $this->grind = $this->createMock(SakerhetsskyddGrind::class);
        $this->commitService = $this->createMock(FacksystemCommitService::class);
        $this->secureRandom = $this->createMock(ISecureRandom::class);
        $this->timeFactory = $this->createMock(ITimeFactory::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Default time: deterministic and re-issued per call (entity setters keep
        // their own reference, so a fresh object each time is safest).
        $this->timeFactory->method('getDateTime')
            ->willReturnCallback(static fn (): \DateTime => new \DateTime('2026-06-17T08:00:00+00:00'));

        // Default CSPRNG: 16 deterministic bytes for the UUID mint.
        $this->secureRandom->method('generate')
            ->willReturn("\x01\x23\x45\x67\x89\xab\xcd\xef\x01\x23\x45\x67\x89\xab\xcd\xef");

        $this->service = new ArendeService(
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
    //  (1) Happy path — createCase mints a case with commit_destination set
    // ================================================================== //

    public function testCreateCaseHappyPathSetsCommitDestination(): void {
        $this->grind->method('evaluate')->willReturn($this->cleanGrindResult());

        // No idempotency hit.
        $this->arendeMapper->method('findByConversationId')->willReturn(null);

        $typ = $this->makeTyp('orosanmalan', 'facksystem');
        $this->typRegistry->method('get')->with('orosanmalan')->willReturn($typ);

        // insert()/update() echo the entity back (NC QBMapper contract).
        $this->arendeMapper->method('insert')->willReturnArgument(0);
        $this->arendeMapper->method('update')->willReturnArgument(0);

        $result = $this->service->createCase([
            'conversationId' => 'conv-happy-1',
            'arendeTyp' => 'orosanmalan',
            'enhet' => 'barn-familj@',
            'inkomDatum' => '2026-06-01',
        ]);

        self::assertInstanceOf(Arende::class, $result);
        self::assertSame('facksystem', $result->getCommitDestination());
        self::assertNotSame('', $result->getCommitDestination());
        self::assertSame('orosanmalan', $result->getArendeTyp());
        self::assertSame('otilldelat', $result->getStatus());
        // Case-skapande typer föds i 'forhandsbedomning' (spec ARENDETYPER §6 +
        // demo treserva.js): 'inflode' är råmeddelandets steg före ärendet finns.
        self::assertSame('forhandsbedomning', $result->getSteg());
        self::assertSame('ej_registrerad', $result->getProvenanceState());
        // R1 minted a UUID v4 (8-4-4-4-12, version nibble = 4).
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $result->getHubsCaseId(),
        );
    }

    // ================================================================== //
    //  (2) Unknown ärendetyp -> InvalidArgumentException
    // ================================================================== //

    public function testCreateCaseUnknownArendeTypThrows(): void {
        $this->grind->method('evaluate')->willReturn($this->cleanGrindResult());
        $this->arendeMapper->method('findByConversationId')->willReturn(null);

        // Registry returns null for an unknown typ.
        $this->typRegistry->method('get')->with('finns_inte')->willReturn(null);

        // Nothing must be inserted when the typ is unknown.
        $this->arendeMapper->expects(self::never())->method('insert');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Okänd ärendetyp');

        $this->service->createCase([
            'conversationId' => 'conv-unknown',
            'arendeTyp' => 'finns_inte',
        ]);
    }

    // ================================================================== //
    //  (3) commit_destination NOT NULL invariant — typ without destination
    // ================================================================== //

    public function testCreateCaseMissingCommitDestinationViolatesInvariant(): void {
        $this->grind->method('evaluate')->willReturn($this->cleanGrindResult());
        $this->arendeMapper->method('findByConversationId')->willReturn(null);

        // A known typ whose commit_destination is empty (invariant breach).
        $typ = $this->makeTyp('trasig_typ', '');
        $this->typRegistry->method('get')->with('trasig_typ')->willReturn($typ);

        // The INSERT must never be reached — the invariant is enforced first.
        $this->arendeMapper->expects(self::never())->method('insert');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('commit_destination saknas');

        $this->service->createCase([
            'conversationId' => 'conv-nodest',
            'arendeTyp' => 'trasig_typ',
        ]);
    }

    // ================================================================== //
    //  (4) Idempotency — same conversationId returns the existing case
    // ================================================================== //

    public function testCreateCaseIsIdempotentOnConversationId(): void {
        $this->grind->method('evaluate')->willReturn($this->cleanGrindResult());

        // An existing case already maps to this conversationId.
        $existing = new Arende();
        $existing->setHubsCaseId('existing-uuid-1234');
        $existing->setConversationId('conv-dup');
        $existing->setCommitDestination('facksystem');
        $existing->setArendeTyp('orosanmalan');

        $this->arendeMapper->expects(self::once())
            ->method('findByConversationId')
            ->with('conv-dup')
            ->willReturn($existing);

        // Idempotent hit returns BEFORE touching the registry or inserting.
        $this->typRegistry->expects(self::never())->method('get');
        $this->arendeMapper->expects(self::never())->method('insert');

        $result = $this->service->createCase([
            'conversationId' => 'conv-dup',
            'arendeTyp' => 'orosanmalan',
        ]);

        self::assertSame($existing, $result);
        self::assertSame('existing-uuid-1234', $result->getHubsCaseId());
    }

    // ================================================================== //
    //  (5) Säkerhetsskydd row -> AvvisadException, NO insert
    // ================================================================== //

    public function testCreateCaseRejectedBySakerhetsskyddGrindDoesNotInsert(): void {
        $this->grind->method('evaluate')->willReturn([
            'avvisad' => true,
            'reason' => SakerhetsskyddGrind::REASON_SAKERHETSSKYDD,
            'retroaktiv' => false,
            'indikator' => SakerhetsskyddGrind::IND_SAKERHETSSKYDD,
            'kvitto' => ['typ' => 'sakerhetsskydd_avvisning', 'avvisad' => true],
        ]);

        // R0 fires BEFORE any side effect: no idempotency lookup, no registry,
        // and critically NO insert.
        $this->arendeMapper->expects(self::never())->method('insert');
        $this->arendeMapper->expects(self::never())->method('findByConversationId');
        $this->typRegistry->expects(self::never())->method('get');

        try {
            $this->service->createCase([
                'arendeTyp' => 'orosanmalan',
                'subject' => 'Rör rikets säkerhet',
            ]);
            self::fail('Expected AvvisadException to be thrown.');
        } catch (AvvisadException $e) {
            self::assertSame(SakerhetsskyddGrind::REASON_SAKERHETSSKYDD, $e->getMessage());
            self::assertFalse($e->isRetroaktiv());
            self::assertSame('sakerhetsskydd_avvisning', $e->getKvitto()['typ'] ?? null);
        }
    }

    public function testRetroaktivRejectionQuarantinesExistingCase(): void {
        $existing = new Arende();
        $existing->setHubsCaseId('caseid-retro');
        $existing->setConversationId('conv-retro');

        $this->grind->method('evaluate')->willReturn([
            'avvisad' => true,
            'reason' => SakerhetsskyddGrind::REASON_SAKERHETSSKYDD,
            'retroaktiv' => true,
            'indikator' => SakerhetsskyddGrind::IND_SAKERHETSSKYDD,
            'kvitto' => ['typ' => 'sakerhetsskydd_avvisning'],
        ]);
        $this->arendeMapper->method('findByConversationId')
            ->with('conv-retro')->willReturn($existing);

        // The retro path must quarantine the already-created case and STILL insert nothing.
        $this->grind->expects(self::once())
            ->method('evaluateRetroaktiv')
            ->with('caseid-retro', SakerhetsskyddGrind::REASON_SAKERHETSSKYDD);
        $this->arendeMapper->expects(self::never())->method('insert');

        $this->expectException(AvvisadException::class);

        try {
            $this->service->createCase([
                'conversationId' => 'conv-retro',
                'arendeTyp' => 'orosanmalan',
            ]);
        } catch (AvvisadException $e) {
            self::assertTrue($e->isRetroaktiv());
            throw $e;
        }
    }

    // ================================================================== //
    //  (6) commit with a VERIFIED receipt -> provenanceState=registrerad
    // ================================================================== //

    public function testCommitWithVerifiedReceiptFlipsProvenance(): void {
        $arende = new Arende();
        $arende->setHubsCaseId('caseid-commit');
        $arende->setArendeTyp('orosanmalan');
        $arende->setCommitDestination('facksystem');
        $arende->setProvenanceState('ej_registrerad');
        $arende->setRetentionState('aktiv');

        // show() resolves by caseId first.
        $this->arendeMapper->method('findByCaseId')
            ->with('caseid-commit')->willReturn($arende);

        $typ = $this->makeTyp('orosanmalan', 'facksystem');
        $typ->setFrendsModul('ifo_barn');
        $this->typRegistry->method('get')->with('orosanmalan')->willReturn($typ);

        // A VERIFIED receipt (stub contract: verifierad=true, dnr + gallrasDatum set).
        $kvitto = [
            'ok' => true,
            'dnr' => '2026-IFO-0501',
            'committedAt' => '2026-06-17T08:00:00Z',
            'gallrasDatum' => '2026-09-15',
            'verifierad' => true,
            'hubsCaseId' => 'caseid-commit',
            'modul' => 'ifo_barn',
            'receipt' => [],
        ];
        $this->commitService->expects(self::once())
            ->method('commit')
            ->with('caseid-commit', self::callback(static function (array $payload): bool {
                // Engine enriches routing fields from coordination state.
                return ($payload['commit_destination'] ?? null) === 'facksystem'
                    && ($payload['frends_modul'] ?? null) === 'ifo_barn'
                    && ($payload['arendetyp'] ?? null) === 'orosanmalan';
            }))
            ->willReturn($kvitto);

        // On a verified receipt the register row is updated (provenance + retention + dnr).
        $this->arendeMapper->expects(self::once())
            ->method('update')
            ->willReturnArgument(0);

        $result = $this->service->commit('caseid-commit', ['typ' => 'anmalan']);

        self::assertSame($kvitto, $result);
        self::assertSame('registrerad', $arende->getProvenanceState());
        self::assertSame('gallras_efter_commit', $arende->getRetentionState());
        self::assertSame('2026-IFO-0501', $arende->getDnr());
    }

    public function testCommitWithUnverifiedReceiptDoesNotFlipProvenance(): void {
        $arende = new Arende();
        $arende->setHubsCaseId('caseid-prelim');
        $arende->setArendeTyp('orosanmalan');
        $arende->setCommitDestination('facksystem');
        $arende->setProvenanceState('ej_registrerad');
        $arende->setRetentionState('aktiv');

        $this->arendeMapper->method('findByCaseId')
            ->with('caseid-prelim')->willReturn($arende);
        $this->typRegistry->method('get')
            ->willReturn($this->makeTyp('orosanmalan', 'facksystem'));

        // Preliminary receipt: verifierad=false -> retention must NOT start (GAP-007).
        $this->commitService->method('commit')->willReturn([
            'ok' => true,
            'dnr' => '2026-IFO-0502',
            'verifierad' => false,
            'gallrasDatum' => null,
            'hubsCaseId' => 'caseid-prelim',
        ]);

        // No register update on an unverified receipt.
        $this->arendeMapper->expects(self::never())->method('update');

        $this->service->commit('caseid-prelim', ['typ' => 'anmalan']);

        self::assertSame('ej_registrerad', $arende->getProvenanceState());
        self::assertSame('aktiv', $arende->getRetentionState());
        self::assertNull($arende->getDnr());
    }

    // ================================================================== //
    //  Helpers
    // ================================================================== //

    /**
     * A clean säkerhetsskydd-grind result (the only path that passes R0).
     *
     * @return array<string,mixed>
     */
    private function cleanGrindResult(): array {
        return [
            'avvisad' => false,
            'reason' => SakerhetsskyddGrind::REASON_OK,
            'retroaktiv' => false,
            'indikator' => SakerhetsskyddGrind::IND_NONE,
            'kvitto' => [],
        ];
    }

    private function makeTyp(string $id, string $commitDestination): ArendeTyp {
        $typ = new ArendeTyp();
        $typ->setArendeTypId($id);
        $typ->setDisplayName($id);
        $typ->setCommitDestination($commitDestination);
        $typ->setDefaultEnhet('barn-familj@');
        // No own-clock fristPolicy keeps R8 a no-op (avoids date math in unit scope).
        $typ->setFristPolicy(json_encode(['typ' => 'ingen']));
        return $typ;
    }
}
