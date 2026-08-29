<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Tests\Unit\Service\Brain;

use OCA\HubsArende\Db\Arende;
use OCA\HubsArende\Db\ArendeMapper;
use OCA\HubsArende\Db\Member;
use OCA\HubsArende\Db\MemberMapper;
use OCA\HubsArende\Db\Part;
use OCA\HubsArende\Db\PartMapper;
use OCA\HubsArende\Service\Brain\AuthzService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * P-A1 — AuthzService (SPEC kap 5.2). Rena enhetstester: alla mappers + IGroupManager
 * + IUserManager + IAppConfig mockas, entiteterna (Arende/Member/Part) är riktiga.
 *
 * Det asserterade kontraktet är exakt det brain-gw (Node) byggts mot:
 * {allow, roll, skal, skydd}. Testerna täcker rollmatrisen, R0-/fryst-/steg-grindarna,
 * v2-aktiveringen, skydd-flaggan och den fail-closed deny:n vid internt fel.
 */
final class AuthzServiceTest extends TestCase {
    private const CASE_ID = '11111111-2222-4333-8444-555555555555';
    private const ENHET = 'Barn-Familj';
    private const UID = 'anna.svensson';

    private ArendeMapper&MockObject $arendeMapper;
    private MemberMapper&MockObject $memberMapper;
    private PartMapper&MockObject $partMapper;
    private IGroupManager&MockObject $groupManager;
    private IUserManager&MockObject $userManager;
    private LoggerInterface&MockObject $logger;
    private IAppConfig&MockObject $appConfig;

    private AuthzService $service;

    protected function setUp(): void {
        parent::setUp();
        $this->arendeMapper = $this->createMock(ArendeMapper::class);
        $this->memberMapper = $this->createMock(MemberMapper::class);
        $this->partMapper = $this->createMock(PartMapper::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->appConfig = $this->createMock(IAppConfig::class);

        // Koddefault: inga v2-funktioner aktiverade om testet inte säger annat.
        $this->appConfig->method('getAppValueString')->willReturnCallback(
            static fn (string $key, string $default = ''): string => $default,
        );

        $this->service = new AuthzService(
            arendeMapper: $this->arendeMapper,
            memberMapper: $this->memberMapper,
            partMapper: $this->partMapper,
            groupManager: $this->groupManager,
            userManager: $this->userManager,
            logger: $this->logger,
            appConfig: $this->appConfig,
        );
    }

    // ================================================================== //
    //  Rollmatris — läs
    // ================================================================== //

    public function testHandlaggareFarLasa(): void {
        $this->givenCase($this->makeArende());
        $this->givenMembers($this->member(self::UID, Member::ROLL_HANDLAGGARE));

        $res = $this->service->check(self::UID, self::CASE_ID, 'fn_briefing');

        self::assertTrue($res['allow']);
        self::assertSame(Member::ROLL_HANDLAGGARE, $res['roll']);
        self::assertSame('medlem_handlaggare', $res['skal']);
        self::assertFalse($res['skydd']);
    }

    public function testCoHandlaggareFarFraga(): void {
        $this->givenCase($this->makeArende());
        $this->givenMembers($this->member(self::UID, Member::ROLL_CO_HANDLAGGARE));

        $res = $this->service->check(self::UID, self::CASE_ID, 'ask');

        self::assertTrue($res['allow']);
        self::assertSame(Member::ROLL_CO_HANDLAGGARE, $res['roll']);
        self::assertSame('medlem_co_handlaggare', $res['skal']);
    }

    public function testObservatorFarLasa(): void {
        $this->givenCase($this->makeArende());
        $this->givenMembers($this->member(self::UID, Member::ROLL_OBSERVATOR));

        $res = $this->service->check(self::UID, self::CASE_ID, 'fn_lage');

        self::assertTrue($res['allow']);
        self::assertSame(Member::ROLL_OBSERVATOR, $res['roll']);
        self::assertSame('medlem_observator', $res['skal']);
    }

    public function testObservatorNekasCapture(): void {
        $this->givenCase($this->makeArende());
        $this->givenMembers($this->member(self::UID, Member::ROLL_OBSERVATOR));

        $res = $this->service->check(self::UID, self::CASE_ID, 'capture');

        self::assertFalse($res['allow']);
        self::assertSame('deny_observator_skrivning', $res['skal']);
    }

    public function testHandlaggareFarCapture(): void {
        $this->givenCase($this->makeArende());
        $this->givenMembers($this->member(self::UID, Member::ROLL_HANDLAGGARE));

        $res = $this->service->check(self::UID, self::CASE_ID, 'capture');

        self::assertTrue($res['allow']);
        self::assertSame('medlem_handlaggare', $res['skal']);
    }

    // ================================================================== //
    //  Mottagningskrets — endast otilldelat + H1-enhet
    // ================================================================== //

    public function testKretsFarLasaOtilldelatMedEnhetsmatch(): void {
        $this->givenCase($this->makeArende());
        $this->givenMembers($this->member(self::UID, Member::ROLL_MOTTAGNINGSKRETS));
        $this->givenUserInGroups(self::UID, [self::ENHET]);

        $res = $this->service->check(self::UID, self::CASE_ID, 'fn_briefing');

        self::assertTrue($res['allow']);
        self::assertSame(Member::ROLL_MOTTAGNINGSKRETS, $res['roll']);
        self::assertSame('krets_otilldelad', $res['skal']);
    }

    public function testKretsNekasUtanEnhetsmatch(): void {
        $this->givenCase($this->makeArende());
        $this->givenMembers($this->member(self::UID, Member::ROLL_MOTTAGNINGSKRETS));
        $this->givenUserInGroups(self::UID, ['Annan-Enhet']);

        $res = $this->service->check(self::UID, self::CASE_ID, 'fn_briefing');

        self::assertFalse($res['allow']);
        self::assertSame('deny_enhet', $res['skal']);
    }

    public function testKretsRevokerasNarArendetArTilldelat(): void {
        // Handoff har skett: en handläggare finns ⇒ kretsen ser inte längre ärendet.
        $this->givenCase($this->makeArende());
        $this->givenMembers(
            $this->member(self::UID, Member::ROLL_MOTTAGNINGSKRETS),
            $this->member('bengt.handlaggare', Member::ROLL_HANDLAGGARE),
        );
        $this->givenUserInGroups(self::UID, [self::ENHET]);

        $res = $this->service->check(self::UID, self::CASE_ID, 'fn_briefing');

        self::assertFalse($res['allow']);
        self::assertSame('deny_krets_tilldelad', $res['skal']);
    }

    public function testIckeMedlemNekas(): void {
        $this->givenCase($this->makeArende());
        $this->givenMembers($this->member('nagon.annan', Member::ROLL_HANDLAGGARE));

        $res = $this->service->check(self::UID, self::CASE_ID, 'fn_briefing');

        self::assertFalse($res['allow']);
        self::assertNull($res['roll']);
        self::assertSame('deny_ej_medlem', $res['skal']);
    }

    // ================================================================== //
    //  R0-karantän — ALLTID deny, oberoende roll
    // ================================================================== //

    public function testR0KarantanNekarAllaAvenHandlaggare(): void {
        $this->givenCase($this->makeArende(commitDestination: 'karantan'));
        $this->givenMembers($this->member(self::UID, Member::ROLL_HANDLAGGARE));

        $res = $this->service->check(self::UID, self::CASE_ID, 'fn_briefing');

        self::assertFalse($res['allow']);
        self::assertNull($res['roll']);
        self::assertSame('deny_r0_karantan', $res['skal']);
    }

    public function testPausadRetentionGerR0Deny(): void {
        $this->givenCase($this->makeArende(retentionState: 'pausad'));
        $this->givenMembers($this->member(self::UID, Member::ROLL_HANDLAGGARE));

        $res = $this->service->check(self::UID, self::CASE_ID, 'ask');

        self::assertFalse($res['allow']);
        self::assertSame('deny_r0_karantan', $res['skal']);
    }

    // ================================================================== //
    //  Frysning — avslutat + capture
    // ================================================================== //

    public function testFrystArendeNekarCapture(): void {
        $this->givenCase($this->makeArende(steg: 'avslutat'));
        $this->givenMembers($this->member(self::UID, Member::ROLL_HANDLAGGARE));

        $res = $this->service->check(self::UID, self::CASE_ID, 'capture');

        self::assertFalse($res['allow']);
        self::assertSame('deny_fryst', $res['skal']);
    }

    public function testFrystArendeTillaterLas(): void {
        // Läs är tillåten ända till gallring — endast capture fryses.
        $this->givenCase($this->makeArende(steg: 'avslutat'));
        $this->givenMembers($this->member(self::UID, Member::ROLL_HANDLAGGARE));

        $res = $this->service->check(self::UID, self::CASE_ID, 'fn_briefing');

        self::assertTrue($res['allow']);
    }

    // ================================================================== //
    //  Funktionsvalidering / systemfunktion / okänt ärende
    // ================================================================== //

    public function testOkandFunktionNekas(): void {
        $this->givenCase($this->makeArende());
        $this->givenMembers($this->member(self::UID, Member::ROLL_HANDLAGGARE));

        $res = $this->service->check(self::UID, self::CASE_ID, 'fn_hitta_pa');

        self::assertFalse($res['allow']);
        self::assertSame('deny_okand_funktion', $res['skal']);
    }

    public function testSystemIngestNekasViaUid(): void {
        $res = $this->service->check(self::UID, self::CASE_ID, 'system_ingest');

        self::assertFalse($res['allow']);
        self::assertSame('deny_system_ingest_uid', $res['skal']);
    }

    public function testOkantArendeNekas(): void {
        $this->arendeMapper->method('findByCaseId')
            ->willThrowException(new DoesNotExistException('nope'));

        $res = $this->service->check(self::UID, self::CASE_ID, 'fn_briefing');

        self::assertFalse($res['allow']);
        self::assertSame('deny_okant_arende', $res['skal']);
    }

    // ================================================================== //
    //  v2-utkastfunktioner — eval-grind + steg + roll
    // ================================================================== //

    public function testDraftNekasNarEjAktiverad(): void {
        // Koddefault: ork_fn_enabled tom ⇒ draft-funktioner nekas före allt annat.
        $this->givenCase($this->makeArende(steg: 'forhandsbedomning'));
        $this->givenMembers($this->member(self::UID, Member::ROLL_HANDLAGGARE));

        $res = $this->service->check(self::UID, self::CASE_ID, 'fn_draft_skyddsbedomning');

        self::assertFalse($res['allow']);
        self::assertSame('deny_fn_ej_aktiverad', $res['skal']);
    }

    public function testDraftTillatsIRattStegForHandlaggare(): void {
        $this->givenFnAktiverad('fn_draft_skyddsbedomning');
        $this->givenCase($this->makeArende(steg: 'forhandsbedomning'));
        $this->givenMembers($this->member(self::UID, Member::ROLL_HANDLAGGARE));

        $res = $this->service->check(self::UID, self::CASE_ID, 'fn_draft_skyddsbedomning');

        self::assertTrue($res['allow']);
        self::assertSame('medlem_handlaggare', $res['skal']);
    }

    public function testDraftNekasIFelSteg(): void {
        $this->givenFnAktiverad('fn_draft_skyddsbedomning');
        // skyddsbedömning-utkast tillåts endast i forhandsbedomning.
        $this->givenCase($this->makeArende(steg: 'utredning'));
        $this->givenMembers($this->member(self::UID, Member::ROLL_HANDLAGGARE));

        $res = $this->service->check(self::UID, self::CASE_ID, 'fn_draft_skyddsbedomning');

        self::assertFalse($res['allow']);
        self::assertSame('deny_steg_sparr', $res['skal']);
    }

    public function testDraftNekasForObservator(): void {
        $this->givenFnAktiverad('fn_draft_skyddsbedomning');
        $this->givenCase($this->makeArende(steg: 'forhandsbedomning'));
        $this->givenMembers($this->member(self::UID, Member::ROLL_OBSERVATOR));

        $res = $this->service->check(self::UID, self::CASE_ID, 'fn_draft_skyddsbedomning');

        self::assertFalse($res['allow']);
        self::assertSame('deny_observator_skrivning', $res['skal']);
    }

    // ================================================================== //
    //  skydd-flaggan
    // ================================================================== //

    public function testSkyddFlaggaNarPartHarSkydd(): void {
        $this->givenCase($this->makeArende());
        $this->givenMembers($this->member(self::UID, Member::ROLL_HANDLAGGARE));
        $this->partMapper->method('findByCaseId')->willReturn([
            $this->part(Part::SKYDD_INGEN),
            $this->part(Part::SKYDD_SEKRETESSMARKERING),
        ]);

        $res = $this->service->check(self::UID, self::CASE_ID, 'fn_briefing');

        self::assertTrue($res['allow']);
        self::assertTrue($res['skydd']);
    }

    public function testSkyddFalsktUtanSkyddadPart(): void {
        $this->givenCase($this->makeArende());
        $this->givenMembers($this->member(self::UID, Member::ROLL_HANDLAGGARE));
        $this->partMapper->method('findByCaseId')->willReturn([
            $this->part(Part::SKYDD_INGEN),
        ]);

        $res = $this->service->check(self::UID, self::CASE_ID, 'fn_briefing');

        self::assertFalse($res['skydd']);
    }

    // ================================================================== //
    //  Fail-closed vid internt fel
    // ================================================================== //

    public function testInterntFelGerFailClosedDeny(): void {
        $this->givenCase($this->makeArende());
        // Ledger-läsningen kastar ⇒ måste fångas och bli en deny, aldrig ett kast.
        $this->memberMapper->method('findByCaseId')
            ->willThrowException(new \RuntimeException('db nere'));

        $res = $this->service->check(self::UID, self::CASE_ID, 'fn_briefing');

        self::assertFalse($res['allow']);
        self::assertNull($res['roll']);
        self::assertSame('deny_internal_error', $res['skal']);
    }

    // ================================================================== //
    //  Byggare / fixtures
    // ================================================================== //

    private function givenCase(Arende $arende): void {
        $this->arendeMapper->method('findByCaseId')->willReturn($arende);
    }

    private function givenMembers(Member ...$members): void {
        $this->memberMapper->method('findByCaseId')->willReturn($members);
    }

    private function givenFnAktiverad(string $funktion): void {
        // Skriv över default-callbacken: aktivera exakt denna funktion.
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->appConfig->method('getAppValueString')->willReturnCallback(
            static fn (string $key, string $default = ''): string
                => $key === 'ork_fn_enabled' ? $funktion : $default,
        );
        $this->service = new AuthzService(
            arendeMapper: $this->arendeMapper,
            memberMapper: $this->memberMapper,
            partMapper: $this->partMapper,
            groupManager: $this->groupManager,
            userManager: $this->userManager,
            logger: $this->logger,
            appConfig: $this->appConfig,
        );
    }

    private function givenUserInGroups(string $uid, array $gids): void {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userManager->method('get')->willReturnCallback(
            static fn (string $u): ?IUser => $u === $uid ? $user : null,
        );
        $this->groupManager->method('getUserGroupIds')->willReturnCallback(
            static fn (IUser $u): array => $u->getUID() === $uid ? $gids : [],
        );
    }

    private function makeArende(
        string $steg = 'utredning',
        string $enhet = self::ENHET,
        string $commitDestination = 'facksystem',
        string $retentionState = 'aktiv',
    ): Arende {
        $arende = new Arende();
        $arende->setHubsCaseId(self::CASE_ID);
        $arende->setEnhet($enhet);
        $arende->setSteg($steg);
        $arende->setStatus('otilldelat');
        $arende->setArendeTyp('orosanmalan');
        $arende->setCommitDestination($commitDestination);
        $arende->setRetentionState($retentionState);
        return $arende;
    }

    private function member(string $uid, string $roll): Member {
        $m = new Member();
        $m->setHubsCaseId(self::CASE_ID);
        $m->setUid($uid);
        $m->setRoll($roll);
        return $m;
    }

    private function part(string $skydd): Part {
        $p = new Part();
        $p->setHubsCaseId(self::CASE_ID);
        $p->setRoll(Part::ROLL_BARN);
        $p->setSkydd($skydd);
        return $p;
    }
}
