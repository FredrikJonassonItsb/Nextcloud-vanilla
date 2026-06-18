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
use OCA\HubsArende\Service\ArendeService;
use OCA\HubsArende\Service\ArendeTypRegistry;
use OCA\HubsArende\Service\FacksystemCommitService;
use OCA\HubsArende\Service\SakerhetsskyddGrind;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * H3 — commit() idempotency + existence guard (double-/orphaned facksystem-registration).
 *
 * CONTRACT (granskningsrapport H3, "Åtgärd" (2) + (3)):
 *  - A second commit() on an already-registered case must return a receipt derived
 *    from the existing dnr WITHOUT calling the FacksystemCommitService a second time
 *    (no double-write to the facksystem). I.e. after the first verified commit flips
 *    provenanceState='registrerad', the second call short-circuits on that state.
 *  - commit() on an unknown ref must throw DoesNotExistException (existence check via
 *    show()) — never an orphaned facksystem-registration for a non-existent row.
 *
 * These tests pin the IDEMPOTENCY behaviour, complementary to the existing
 * ArendeServiceTest::testCommitWithVerifiedReceiptFlipsProvenance happy path.
 *
 * ── RECONCILIATION NOTES ──
 *   * The idempotency guard is asserted as: when the resolved case already has
 *     provenanceState==='registrerad', commitService->commit() is NOT called a
 *     second time and a receipt is returned (verifierad=true, dnr=existing dnr).
 *     If the implementer keys idempotency on a different state value (e.g. a
 *     dedicated `committed` flag or an idempotency key on the payload) rather than
 *     provenanceState, only {@see makeRegisteredArende()} needs adjusting — the
 *     "commitService called exactly once across two commits" assertion is the
 *     stable contract.
 *   * The receipt shape returned on the idempotent short-circuit is assumed to be
 *     the same {ok, dnr, verifierad} contract as a fresh receipt. If the build
 *     returns a leaner "already registered" receipt, relax the dnr assertion but
 *     keep the call-count assertion.
 */
final class ArendeServiceCommitIdempotensTest extends TestCase {
    private const CASE_ID = 'caseid-idem-0001';
    private const EXISTING_DNR = '2026-IFO-0777';

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

        $this->timeFactory->method('getDateTime')
            ->willReturnCallback(static fn (): \DateTime => new \DateTime('2026-06-17T08:00:00+00:00'));

        // 7-arg positional harness: trailing-optional session/group collaborators
        // are left null (commit authz, if added, degrades to allow without a user).
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
    //  Double commit → commitService called exactly once (no double-write).
    // ================================================================== //

    public function testSecondCommitIsIdempotentAndDoesNotDoubleCommit(): void {
        $arende = $this->makeArende();
        $this->arendeMapper->method('findByCaseId')
            ->with(self::CASE_ID)->willReturn($arende);
        $this->typRegistry->method('get')->willReturn($this->makeTyp());
        $this->arendeMapper->method('update')->willReturnArgument(0);

        // The facksystem MUST be hit AT MOST once across two commit() calls.
        $verifiedReceipt = [
            'ok' => true,
            'dnr' => self::EXISTING_DNR,
            'committedAt' => '2026-06-17T08:00:00Z',
            'gallrasDatum' => '2026-09-15',
            'verifierad' => true,
            'hubsCaseId' => self::CASE_ID,
        ];
        $this->commitService->expects(self::once())
            ->method('commit')
            ->willReturnCallback(function (string $id, array $payload) use ($arende, $verifiedReceipt): array {
                // First call performs the real commit; mirror the engine's own
                // register flip so the second call sees provenance='registrerad'.
                $arende->setProvenanceState('registrerad');
                $arende->setRetentionState('gallras_efter_commit');
                $arende->setDnr(self::EXISTING_DNR);
                return $verifiedReceipt;
            });

        // First commit — performs the facksystem-write.
        $first = $this->service->commit(self::CASE_ID, ['typ' => 'anmalan']);
        self::assertTrue($first['verifierad']);
        self::assertSame(self::EXISTING_DNR, $first['dnr']);

        // Second commit — must short-circuit (commitService expects(once) enforces
        // no second port call) and still return a receipt carrying the existing dnr.
        $second = $this->service->commit(self::CASE_ID, ['typ' => 'anmalan']);
        self::assertSame(self::EXISTING_DNR, $second['dnr'] ?? null);
        self::assertSame('registrerad', $arende->getProvenanceState());
    }

    public function testCommitOnAlreadyRegisteredCaseSkipsCommitServiceEntirely(): void {
        // A case that is ALREADY registrerad (e.g. committed in a previous request)
        // must not re-enter the facksystem on a fresh commit() call at all.
        $arende = $this->makeRegisteredArende();
        $this->arendeMapper->method('findByCaseId')
            ->with(self::CASE_ID)->willReturn($arende);
        $this->typRegistry->method('get')->willReturn($this->makeTyp());

        // ZERO port calls — the idempotency guard fires before commitService.
        $this->commitService->expects(self::never())->method('commit');

        $receipt = $this->service->commit(self::CASE_ID, ['typ' => 'anmalan']);

        // The receipt is derived from the existing dnr (no new registration).
        self::assertSame(self::EXISTING_DNR, $receipt['dnr'] ?? null);
        self::assertTrue(($receipt['verifierad'] ?? null) === true || ($receipt['ok'] ?? null) === true);
        self::assertSame('registrerad', $arende->getProvenanceState());
    }

    // ================================================================== //
    //  Commit on an UNKNOWN ref → DoesNotExistException (no orphan registration).
    // ================================================================== //

    public function testCommitOnUnknownRefThrowsDoesNotExist(): void {
        // show() resolves by caseId then dnr; both miss → DoesNotExistException.
        $this->arendeMapper->method('findByCaseId')
            ->with('finns-inte')
            ->willThrowException(new DoesNotExistException('nope'));
        $this->arendeMapper->method('findByDnr')
            ->with('finns-inte')->willReturn(null);

        // The facksystem must NEVER be touched for a non-existent case.
        $this->commitService->expects(self::never())->method('commit');

        $this->expectException(DoesNotExistException::class);
        $this->service->commit('finns-inte', ['typ' => 'anmalan']);
    }

    // ================================================================== //
    //  Helpers
    // ================================================================== //

    private function makeArende(): Arende {
        $arende = new Arende();
        $arende->setHubsCaseId(self::CASE_ID);
        $arende->setArendeTyp('orosanmalan');
        $arende->setCommitDestination('facksystem');
        $arende->setProvenanceState('ej_registrerad');
        $arende->setRetentionState('aktiv');
        return $arende;
    }

    private function makeRegisteredArende(): Arende {
        $arende = $this->makeArende();
        $arende->setProvenanceState('registrerad');
        $arende->setRetentionState('gallras_efter_commit');
        $arende->setDnr(self::EXISTING_DNR);
        return $arende;
    }

    private function makeTyp(): ArendeTyp {
        $typ = new ArendeTyp();
        $typ->setArendeTypId('orosanmalan');
        $typ->setDisplayName('orosanmalan');
        $typ->setCommitDestination('facksystem');
        $typ->setFrendsModul('ifo_barn');
        $typ->setDefaultEnhet('barn-familj@');
        $typ->setFristPolicy(json_encode(['typ' => 'ingen']));
        return $typ;
    }
}
