<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Tests\Unit\Service;

use OCA\HubsArende\Db\Arende;
use OCA\HubsArende\Db\ArendeMapper;
use OCA\HubsArende\Db\Handelse;
use OCA\HubsArende\Db\HandelseMapper;
use OCA\HubsArende\Db\Member;
use OCA\HubsArende\Db\MemberMapper;
use OCA\HubsArende\Db\PekareMapper;
use OCA\HubsArende\Db\Signering;
use OCA\HubsArende\Db\SigneringMapper;
use OCA\HubsArende\Integration\Port\Exception\SigningRequestException;
use OCA\HubsArende\Integration\Stub\SigneringStub;
use OCA\HubsArende\Service\ArendeService;
use OCA\HubsArende\Service\BevakningService;
use OCA\HubsArende\Service\DokumenttypRegistry;
use OCA\HubsArende\Service\GallringService;
use OCA\HubsArende\Service\SigneringService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for {@see SigneringService} — signeringslivscykeln (KRAV-
 * SIGNERING-2026-07 fas 1) körd mot den RIKTIGA {@see SigneringStub} som port
 * (K-SIGN-21: stubben SKA bära hela flödet — instant/poll/reject/expire —
 * utan live-beroenden) medan övriga kollaboratörer mockas.
 *
 * What the suite pins down:
 *
 *  - NIVÅMATRISEN (K-SIGN-1): kod-default beslut ⇒ ades / övrigt ⇒ godkann;
 *    app-config-JSON överlagrar; ogiltiga nivåer ignoreras (aldrig en tyst
 *    nedgradering).
 *  - GODKÄNN (K-SIGN-2): journalförs med roll + hash-prefix + sessionens LoA
 *    — och renderas aldrig som underskrift (niva='godkann' i kvittot).
 *  - BEGÄR (K-SIGN-3/4): pending-post + journal TYP_SIGNERING_BEGARD; Sign-
 *    Message NEUTRALISERAD (kortref + dokumenttyp + hash-prefix) — det
 *    (potentiellt röjande) filnamnet når varken SignMessage eller journalen.
 *  - REFRESH-PROGRESSION (K-SIGN-5/9/22): pending → partially_signed (per-
 *    part-markering i ordning, U4) → signed (padesLevel + journal KLAR +
 *    bevakningsvillkoret signering_kvitterad släcks via utvardera('signering')).
 *  - IDEMPOTENS (K-SIGN-22): dubbel-refresh på signed pollar/journalför aldrig om.
 *  - REJECT/EXPIRE/FÖRNYA/AVBRYT (K-SIGN-7): avvisad begäran journalförs;
 *    lokal expiry ⇒ utgången; förnya ger NY begäran med journalförd kedja;
 *    avbryt är journalförd UTAN fritext-skäl (endast skalRef).
 *  - GALLRING (K-SIGN-19 lokal del): destruktionsspegeln river
 *    hubs_arende_signering-raderna med ärendet.
 *
 * KONTRAKTS-BINDNING: som {@see PartServiceTest} binder sviten till tjänstens
 * PUBLIKA kontrakt — konstruktorordningen är inte del av kontraktet, så
 * {@see byggMed()} wirar beroenden per TYP via reflection.
 */
final class SigneringServiceTest extends TestCase {
    /** Ärendereferens som ArendeService::show löser i alla tester. */
    private const REF = 'case-sign-0001-aaaa-bbbb';
    /** kortRef för self::REF (substr utan bindestreck, 6 tecken). */
    private const KORTREF = 'casesi';
    /** Beslutshandling (mall 15 ⇒ dokumenttyp 'beslut' i DokumenttypRegistry). */
    private const FILNAMN_BESLUT = '15-beslut-om-bistand-636663.pdf';
    /** PII-laddat filnamn — får ALDRIG nå journal/SignMessage (K-SIGN-15/U6). */
    private const FILNAMN_PII = 'beslut-LVU-Anna-Andersson.pdf';

    private ArendeService&MockObject $arendeService;
    private SigneringMapper&MockObject $signeringMapper;
    private HandelseMapper&MockObject $handelseMapper;
    private LoggerInterface&MockObject $logger;
    private ITimeFactory&MockObject $timeFactory;
    private IAppConfig&MockObject $appConfig;
    private IUserSession&MockObject $userSession;
    private BevakningService&MockObject $bevakningService;
    private MemberMapper&MockObject $memberMapper;
    private DokumenttypRegistry $dokumenttypRegistry;

    /** Kanonisk SHA-256 för testdokumentet. */
    private string $hash;

    /** @var Signering[] Every Signering handed to SigneringMapper::insert(), in call order. */
    private array $inserts = [];
    /** @var array<string,Signering> Persisterade rader per signRequestId (findBySignRequestId-fixturen). */
    private array $radPerReqId = [];
    /** @var array<int,array{typ:string,detalj:array<string,mixed>}> Every HandelseMapper::record()-anrop. */
    private array $journal = [];
    /** @var array<int,array<int,mixed>> Every BevakningService::utvardera()-anrop (args). */
    private array $bevakningsAnrop = [];
    /** @var array<string,string> App-config-värden (getAppValueString-fixturen). */
    private array $configVarden = [];

    protected function setUp(): void {
        parent::setUp();

        $this->arendeService = $this->createMock(ArendeService::class);
        $this->signeringMapper = $this->createMock(SigneringMapper::class);
        $this->handelseMapper = $this->createMock(HandelseMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->timeFactory = $this->createMock(ITimeFactory::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->bevakningService = $this->createMock(BevakningService::class);
        $this->memberMapper = $this->createMock(MemberMapper::class);
        $this->dokumenttypRegistry = new DokumenttypRegistry();

        $this->hash = hash('sha256', 'testdokument-1');
        $this->inserts = [];
        $this->radPerReqId = [];
        $this->journal = [];
        $this->bevakningsAnrop = [];
        $this->configVarden = ['signering_session_loa' => 'LOA3'];

        // Authz-grinden bor i ArendeService::show (saknat ärende OCH obehörig
        // enhet => DoesNotExistException); här är handläggaren alltid behörig.
        $this->arendeService->method('show')
            ->willReturnCallback(fn (string $ref): Arende => $this->arende());

        $this->timeFactory->method('getDateTime')
            ->willReturnCallback(static fn (): \DateTime => new \DateTime('2026-07-14T09:00:00+00:00'));

        $this->appConfig->method('getAppValueString')
            ->willReturnCallback(fn (string $key, string $default = ''): string => $this->configVarden[$key] ?? $default);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('anna');
        $this->userSession->method('getUser')->willReturn($user);

        $medlem = new Member();
        $medlem->setHubsCaseId(self::REF);
        $medlem->setUid('anna');
        $medlem->setRoll(Member::ROLL_HANDLAGGARE);
        $this->memberMapper->method('findByCaseId')->willReturn([$medlem]);

        // In-memory-persistens: insert/update/findBySignRequestId/findByCaseId
        // speglar en riktig tabell så refresh() hittar det begar() skrev.
        $this->signeringMapper->method('insert')->willReturnCallback(function (Signering $post): Signering {
            $this->inserts[] = $post;
            $post->setId(count($this->inserts));
            if ($post->getSignRequestId() !== null) {
                $this->radPerReqId[$post->getSignRequestId()] = $post;
            }
            return $post;
        });
        $this->signeringMapper->method('update')->willReturnArgument(0);
        $this->signeringMapper->method('findBySignRequestId')->willReturnCallback(
            fn (string $id): ?Signering => $this->radPerReqId[$id] ?? null,
        );
        $this->signeringMapper->method('findByCaseId')->willReturnCallback(
            fn (string $caseId): array => array_values(array_filter(
                $this->inserts,
                static fn (Signering $p): bool => $p->getHubsCaseId() === $caseId,
            )),
        );

        // Journalen (best-effort i motorn) — fånga varje record()-anrop så att
        // PII-doktrinen kan regex-vaktas.
        $this->handelseMapper->method('record')->willReturnCallback(
            function (string $hubsCaseId, string $typ, array $detalj = [], string $aktorUid = ''): Handelse {
                $this->journal[] = ['typ' => $typ, 'detalj' => $detalj];
                $handelse = new Handelse();
                $handelse->setHubsCaseId($hubsCaseId);
                $handelse->setTyp($typ);
                return $handelse;
            },
        );

        // Bevakningsmotorn — fånga utvardera()-anropen (signering_kvitterad, K-SIGN-8).
        $this->bevakningService->method('utvardera')->willReturnCallback(
            function (...$args): void {
                $this->bevakningsAnrop[] = $args;
            },
        );
    }

    // ================================================================== //
    //  (1) Nivåmatrisen — kod-default (K-SIGN-1)
    // ================================================================== //

    public function testNivaMatrisDefaultBeslutAdesOvrigtGodkann(): void {
        $service = $this->nyService($this->nyStub());

        self::assertSame(Signering::NIVA_ADES, $service->nivaFor('beslut'));
        self::assertSame(Signering::NIVA_GODKANN, $service->nivaFor('journalanteckning'));
        self::assertSame(Signering::NIVA_GODKANN, $service->nivaFor('helt-okand-typ'));

        $matris = $service->nivaMatris();
        self::assertSame(Signering::NIVA_ADES, $matris['beslut']);
        self::assertSame(Signering::NIVA_GODKANN, $matris['*']);
    }

    // ================================================================== //
    //  (2) Nivåmatrisen — app-config överlagrar; ogiltig nivå ignoreras
    // ================================================================== //

    public function testNivaMatrisConfigOverlagrarOchIgnorerarOgiltigt(): void {
        $this->configVarden[SigneringService::CONFIG_NIVA_MATRIS] =
            json_encode(['kallelse' => 'ades', 'beslut' => 'gummistampel']);
        $service = $this->nyService($this->nyStub());

        self::assertSame(Signering::NIVA_ADES, $service->nivaFor('kallelse'), 'config-posten ska gälla');
        // Ogiltig nivå ('gummistampel') får ALDRIG tyst nedgradera beslutet.
        self::assertSame(Signering::NIVA_ADES, $service->nivaFor('beslut'));
    }

    // ================================================================== //
    //  (3) Godkänn journalför med roll + hash + LoA — aldrig som underskrift
    // ================================================================== //

    public function testGodkannJournalforOchPersisterar(): void {
        $service = $this->nyService($this->nyStub());

        $kvitto = $service->godkann(self::REF, 'ref-1', self::FILNAMN_BESLUT, $this->hash);

        self::assertTrue($kvitto['journalfort']);
        self::assertSame(Signering::NIVA_GODKANN, $kvitto['niva'], 'godkänn är ALDRIG en underskrift');
        self::assertNotSame('', (string)$kvitto['tidpunkt']);

        // Persisterat kvitto (utan portbegäran — signRequestId=null).
        self::assertCount(1, $this->inserts);
        $post = $this->inserts[0];
        self::assertSame(Signering::STATUS_GODKAND, $post->getStatus());
        self::assertSame(Signering::NIVA_GODKANN, $post->getNiva());
        self::assertNull($post->getSignRequestId());

        // K-SIGN-2: journal bär roll + hash-prefix + sessionens LoA — aldrig filnamn.
        $godkand = $this->journalAv(SigneringService::TYP_SIGNERING_GODKAND);
        self::assertCount(1, $godkand);
        $detalj = $godkand[0]['detalj'];
        self::assertSame(Member::ROLL_HANDLAGGARE, $detalj['roll']);
        self::assertSame('LOA3', $detalj['loa']);
        self::assertSame(substr($this->hash, 0, 12), $detalj['hashPrefix']);
        self::assertSame('beslut', $detalj['dokumenttyp']);
        self::assertStringNotContainsString(self::FILNAMN_BESLUT, json_encode($detalj));
    }

    public function testGodkannOgiltigHashKastar(): void {
        $service = $this->nyService($this->nyStub());

        $this->expectException(\InvalidArgumentException::class);
        $service->godkann(self::REF, 'ref-1', self::FILNAMN_BESLUT, 'inte-en-sha256');
    }

    public function testTomHashUtanFiltradKastar(): void {
        // Tom hash ⇒ server-side-beräkning ur fileid (U2) — utan IRootFolder
        // (testharnessens läge) ska begäran avvisas, inte tyst persisteras.
        $service = $this->nyService($this->nyStub());

        $this->expectException(\InvalidArgumentException::class);
        $service->godkann(self::REF, '12345', self::FILNAMN_BESLUT, '');
    }

    // ================================================================== //
    //  (4) Begär ⇒ pending-post + neutraliserad SignMessage (K-SIGN-3/4)
    // ================================================================== //

    public function testBegarSkaparPendingPostMedNeutraliseradSignMessage(): void {
        $service = $this->nyService($this->nyStub(pollsUntilSigned: 2));

        $dto = $service->begar(self::REF, 'ref-1', self::FILNAMN_PII, $this->hash, [
            ['uid' => 'anna', 'role' => 'foredragande'],
            ['uid' => 'bertil', 'role' => 'beslutsfattare'],
        ]);

        self::assertSame(Signering::STATUS_PENDING, $dto['status']);
        self::assertSame(Signering::NIVA_ADES, $dto['niva']);
        self::assertNotSame('', (string)$dto['signRequestId']);
        self::assertNotNull($dto['expiresAt'], 'stubben sätter en expiry (+7 d)');
        self::assertCount(2, $dto['signers']);
        self::assertSame(Signering::SIGNER_VANTAR, $dto['signers'][0]['status']);
        self::assertSame(Signering::SIGNER_VANTAR, $dto['signers'][1]['status']);

        // K-SIGN-4/15: SignMessage är kortref + dokumenttyp + hash-prefix —
        // det PII-laddade filnamnet får ALDRIG förekomma där.
        $post = $this->inserts[0];
        $signMessage = (string)$post->getSignMessage();
        self::assertStringContainsString(self::KORTREF, $signMessage);
        self::assertStringContainsString(substr($this->hash, 0, 12), $signMessage);
        self::assertStringNotContainsString('Anna-Andersson', $signMessage);

        // K-SIGN-3: journalförd begäran med signRequestId + hash-prefix, PII-fri.
        $begard = $this->journalAv(SigneringService::TYP_SIGNERING_BEGARD);
        self::assertCount(1, $begard);
        self::assertSame($dto['signRequestId'], $begard[0]['detalj']['signRequestId']);
        self::assertSame(substr($this->hash, 0, 12), $begard[0]['detalj']['hashPrefix']);
        self::assertSame(2, $begard[0]['detalj']['antalSigners']);
        self::assertStringNotContainsString('Anna-Andersson', json_encode($begard[0]['detalj']));
    }

    // ================================================================== //
    //  (5) Refresh-progression pending → partially (per-part) → signed
    // ================================================================== //

    public function testRefreshProgressionTillSignedMedPerPartStatus(): void {
        $service = $this->nyService($this->nyStub(pollsUntilSigned: 2));

        $dto = $service->begar(self::REF, 'ref-1', self::FILNAMN_BESLUT, $this->hash, [
            ['uid' => 'anna', 'role' => 'foredragande'],
            ['uid' => 'bertil', 'role' => 'beslutsfattare'],
        ]);
        $id = (string)$dto['signRequestId'];

        // Poll 1: partially_signed — FÖRSTA signern (i ordning) är klar (U4/K-SIGN-9).
        $dto1 = $service->refresh(self::REF, $id);
        self::assertSame(Signering::STATUS_PARTIALLY_SIGNED, $dto1['status']);
        self::assertSame(Signering::SIGNER_SIGNERAD, $dto1['signers'][0]['status']);
        self::assertNotNull($dto1['signers'][0]['tidpunkt']);
        self::assertSame(Signering::SIGNER_VANTAR, $dto1['signers'][1]['status']);
        self::assertNull($dto1['padesLevel']);

        // Poll 2: signed — alla parter, padesLevel, journal KLAR, bevakning släckt.
        $dto2 = $service->refresh(self::REF, $id);
        self::assertSame(Signering::STATUS_SIGNED, $dto2['status']);
        self::assertSame(Signering::SIGNER_SIGNERAD, $dto2['signers'][0]['status']);
        self::assertSame(Signering::SIGNER_SIGNERAD, $dto2['signers'][1]['status']);
        self::assertSame('PAdES-B-LTA', $dto2['padesLevel']);

        $klar = $this->journalAv(SigneringService::TYP_SIGNERING_KLAR);
        self::assertCount(1, $klar);
        self::assertSame('PAdES-B-LTA', $klar[0]['detalj']['padesLevel']);

        // K-SIGN-8: bevakningsvillkoret signering_kvitterad släcks via
        // villkorsmotorns händelsetyp 'signering'.
        self::assertCount(1, $this->bevakningsAnrop);
        self::assertSame(self::REF, $this->bevakningsAnrop[0][0]);
        self::assertSame('signering', $this->bevakningsAnrop[0][1]);
    }

    // ================================================================== //
    //  (6) Instant-läge (stub-demo): begar ⇒ signed direkt (K-SIGN-21)
    // ================================================================== //

    public function testInstantLageSignerarDirekt(): void {
        $service = $this->nyService($this->nyStub(instantSign: true));

        $dto = $service->begar(self::REF, 'ref-1', self::FILNAMN_BESLUT, $this->hash, [
            ['uid' => 'anna', 'role' => 'beslutsfattare'],
        ]);

        self::assertSame(Signering::STATUS_SIGNED, $dto['status']);
        self::assertSame('PAdES-B-LTA', $dto['padesLevel']);
        self::assertSame(Signering::SIGNER_SIGNERAD, $dto['signers'][0]['status']);
        self::assertCount(1, $this->journalAv(SigneringService::TYP_SIGNERING_BEGARD));
        self::assertCount(1, $this->journalAv(SigneringService::TYP_SIGNERING_KLAR));
        self::assertCount(1, $this->bevakningsAnrop);
    }

    // ================================================================== //
    //  (7) Avvisad begäran (rejectDocumentRefs) journalförs + kastar
    // ================================================================== //

    public function testBegarAvvisadRefJournalforOchKastar(): void {
        $service = $this->nyService($this->nyStub(rejectDocumentRefs: 'ref-avvisad'));

        try {
            $service->begar(self::REF, 'ref-avvisad', self::FILNAMN_BESLUT, $this->hash, [
                ['uid' => 'anna', 'role' => 'beslutsfattare'],
            ]);
            self::fail('en avvisad begäran ska kasta SigningRequestException');
        } catch (SigningRequestException) {
            // förväntat — fail-safe, aldrig tyst
        }

        $avvisad = $this->journalAv(SigneringService::TYP_SIGNERING_AVVISAD);
        self::assertCount(1, $avvisad);
        self::assertSame('begaran_avvisad', $avvisad[0]['detalj']['handling']);
        self::assertCount(0, $this->inserts, 'ingen post persisteras för en avvisad begäran');
    }

    // ================================================================== //
    //  (8) Lokal expiry ⇒ utgången (K-SIGN-7)
    // ================================================================== //

    public function testRefreshEfterExpiryBlirUtgangen(): void {
        $service = $this->nyService($this->nyStub(pollsUntilSigned: 5));

        $dto = $service->begar(self::REF, 'ref-1', self::FILNAMN_BESLUT, $this->hash, [
            ['uid' => 'anna', 'role' => 'beslutsfattare'],
            ['uid' => 'bertil', 'role' => 'beslutsfattare'],
        ]);
        $id = (string)$dto['signRequestId'];
        // Tvinga expiry i det persisterade statet (porten är fortfarande 'inte klar').
        $this->radPerReqId[$id]->setExpiresAt(new \DateTime('2026-07-01T00:00:00+00:00'));

        $dto1 = $service->refresh(self::REF, $id);
        self::assertSame(Signering::STATUS_EXPIRED, $dto1['status']);
        self::assertCount(1, $this->journalAv(SigneringService::TYP_SIGNERING_UTGANGEN));

        // Idempotent: en andra refresh journalför inte om.
        $dto2 = $service->refresh(self::REF, $id);
        self::assertSame(Signering::STATUS_EXPIRED, $dto2['status']);
        self::assertCount(1, $this->journalAv(SigneringService::TYP_SIGNERING_UTGANGEN));
    }

    // ================================================================== //
    //  (9) Förnya ⇒ NY begäran med journalförd kedja (K-SIGN-7)
    // ================================================================== //

    public function testFornyaSkaparNyBegaranMedKedja(): void {
        $service = $this->nyService($this->nyStub(pollsUntilSigned: 5));

        $dto = $service->begar(self::REF, 'ref-1', self::FILNAMN_BESLUT, $this->hash, [
            ['uid' => 'anna', 'role' => 'beslutsfattare'],
        ]);
        $gammaltId = (string)$dto['signRequestId'];
        // En AKTIV begäran kan inte förnyas (avbryt först).
        try {
            $service->fornya(self::REF, $gammaltId);
            self::fail('aktiv begäran ska inte kunna förnyas');
        } catch (\InvalidArgumentException) {
            // förväntat
        }

        $service->avbryt(self::REF, $gammaltId, 'Fel underlag bifogat');
        $ny = $service->fornya(self::REF, $gammaltId);

        self::assertNotSame($gammaltId, $ny['signRequestId'], 'förnya ger en NY begäran');
        self::assertSame(Signering::STATUS_PENDING, $ny['status']);
        self::assertSame($gammaltId, $ny['kedjaFran'], 'kedjan till den gamla begäran bärs i posten');
        self::assertSame(Signering::SIGNER_VANTAR, $ny['signers'][0]['status'], 'per-part-status nollas');

        $begard = $this->journalAv(SigneringService::TYP_SIGNERING_BEGARD);
        self::assertCount(2, $begard);
        self::assertSame($gammaltId, $begard[1]['detalj']['kedjaFran'], 'kedjan är journalförd');
    }

    // ================================================================== //
    //  (10) Avbryt ⇒ lokalt avbruten; skälet journalförs ALDRIG i fritext
    // ================================================================== //

    public function testAvbrytJournalforUtanFritextSkal(): void {
        $service = $this->nyService($this->nyStub(pollsUntilSigned: 5));

        $dto = $service->begar(self::REF, 'ref-1', self::FILNAMN_BESLUT, $this->hash, [
            ['uid' => 'anna', 'role' => 'beslutsfattare'],
        ]);
        $id = (string)$dto['signRequestId'];

        $avbruten = $service->avbryt(self::REF, $id, 'Anna Andersson återkallade beslutet');
        self::assertSame(Signering::STATUS_AVBRUTEN, $avbruten['status']);
        // Fritext-skälet bor i DTO:n/kolumnen (inom behörighetsgränsen)...
        self::assertSame('Anna Andersson återkallade beslutet', $avbruten['avvisadSkal']);

        // ...men journal-detaljen bär ENDAST en icke-reversibel skalRef (K-SIGN-15).
        $avvisad = $this->journalAv(SigneringService::TYP_SIGNERING_AVVISAD);
        self::assertCount(1, $avvisad);
        self::assertSame('avbruten', $avvisad[0]['detalj']['handling']);
        self::assertArrayHasKey('skalRef', $avvisad[0]['detalj']);
        self::assertStringNotContainsString('Anna', json_encode($avvisad[0]['detalj']));

        // Idempotent: dubbel-avbryt journalför inte om.
        $service->avbryt(self::REF, $id, 'igen');
        self::assertCount(1, $this->journalAv(SigneringService::TYP_SIGNERING_AVVISAD));
    }

    // ================================================================== //
    //  (11) Idempotent dubbel-refresh på signed (K-SIGN-22)
    // ================================================================== //

    public function testDubbelRefreshPaSignedArIdempotent(): void {
        $service = $this->nyService($this->nyStub(pollsUntilSigned: 1));

        $dto = $service->begar(self::REF, 'ref-1', self::FILNAMN_BESLUT, $this->hash, [
            ['uid' => 'anna', 'role' => 'beslutsfattare'],
        ]);
        $id = (string)$dto['signRequestId'];

        $forsta = $service->refresh(self::REF, $id);
        self::assertSame(Signering::STATUS_SIGNED, $forsta['status']);

        $andra = $service->refresh(self::REF, $id);
        self::assertSame($forsta, $andra, 'terminalt läge ska returnera identisk DTO');
        self::assertCount(1, $this->journalAv(SigneringService::TYP_SIGNERING_KLAR), 'KLAR journalförs EXAKT en gång');
        self::assertCount(1, $this->bevakningsAnrop, 'bevakningen släcks EXAKT en gång');
    }

    // ================================================================== //
    //  (12) IDOR/okänd begäran ⇒ DoesNotExistException (404, läcker inte)
    // ================================================================== //

    public function testRefreshOkandBegaranKastarNotFound(): void {
        $service = $this->nyService($this->nyStub());

        $this->expectException(DoesNotExistException::class);
        $service->refresh(self::REF, 'sig-9999-deadbeef');
    }

    public function testRefreshFrammandeArendeKastarNotFound(): void {
        $service = $this->nyService($this->nyStub(pollsUntilSigned: 5));
        $dto = $service->begar(self::REF, 'ref-1', self::FILNAMN_BESLUT, $this->hash, [
            ['uid' => 'anna', 'role' => 'beslutsfattare'],
        ]);
        // Raden hör plötsligt till ett ANNAT ärende (IDOR-guarden ska ge 404).
        $this->radPerReqId[(string)$dto['signRequestId']]->setHubsCaseId('case-annat');

        $this->expectException(DoesNotExistException::class);
        $service->refresh(self::REF, (string)$dto['signRequestId']);
    }

    // ================================================================== //
    //  (13) Påminnelse journalförs (v1) — endast på aktiv begäran
    // ================================================================== //

    public function testPaminnJournalforPaAktivBegaran(): void {
        $service = $this->nyService($this->nyStub(pollsUntilSigned: 5));
        $dto = $service->begar(self::REF, 'ref-1', self::FILNAMN_BESLUT, $this->hash, [
            ['uid' => 'anna', 'role' => 'beslutsfattare'],
        ]);

        $svar = $service->paminn(self::REF, (string)$dto['signRequestId']);
        self::assertTrue($svar['paminnelse']);
        self::assertCount(1, $this->journalAv(SigneringService::TYP_SIGNERING_PAMINNELSE));

        // På en icke-aktiv begäran är påminnelsen ogiltig.
        $service->avbryt(self::REF, (string)$dto['signRequestId'], 'test');
        $this->expectException(\InvalidArgumentException::class);
        $service->paminn(self::REF, (string)$dto['signRequestId']);
    }

    // ================================================================== //
    //  (14) Gallringsstädning: destruktionsspegeln river signeringsraderna
    // ================================================================== //

    public function testGallringRiverSigneringsrader(): void {
        $arendeMapper = $this->createMock(ArendeMapper::class);
        $pekareMapper = $this->createMock(PekareMapper::class);

        $rad = new Arende();
        $rad->setHubsCaseId(self::REF);
        $rad->setProvenanceState('registrerad');
        $rad->setGallrasDatum(new \DateTime('2026-07-01T00:00:00+00:00'));
        $arendeMapper->method('findGallringsbara')->willReturn([$rad]);
        $pekareMapper->method('findByCaseId')->willReturn([]);
        $pekareMapper->method('findByCaseAndTyp')->willReturn([]);

        $this->signeringMapper->expects($this->once())
            ->method('deleteByCaseId')
            ->with(self::REF);

        $service = $this->byggMed(GallringService::class, [
            $arendeMapper,
            $pekareMapper,
            $this->logger,
            $this->timeFactory,
            $this->signeringMapper,
        ]);
        self::assertInstanceOf(GallringService::class, $service);

        $resultat = $service->gallra(null, true);
        self::assertSame(1, $resultat['antal']);
    }

    // ================================================================== //
    //  Helpers
    // ================================================================== //

    /** Journalposter av en viss typ, i ordning. */
    private function journalAv(string $typ): array {
        return array_values(array_filter(
            $this->journal,
            static fn (array $p): bool => $p['typ'] === $typ,
        ));
    }

    private function nyStub(
        bool $instantSign = false,
        int $pollsUntilSigned = 1,
        string $rejectDocumentRefs = '',
    ): SigneringStub {
        return new SigneringStub(
            instantSign: $instantSign,
            pollsUntilSigned: $pollsUntilSigned,
            rejectDocumentRefs: $rejectDocumentRefs,
        );
    }

    private function nyService(SigneringStub $port): SigneringService {
        $service = $this->byggMed(SigneringService::class, [
            $this->arendeService,
            $this->signeringMapper,
            $port,
            $this->logger,
            $this->timeFactory,
            $this->appConfig,
            $this->dokumenttypRegistry,
            $this->handelseMapper,
            $this->userSession,
            $this->bevakningService,
            $this->memberMapper,
        ]);
        self::assertInstanceOf(SigneringService::class, $service);
        return $service;
    }

    /**
     * Wire a class by TYPE, not by constructor parameter order (mönstret från
     * {@see PartServiceTest::bygg()}): for every constructor parameter the
     * matching collaborator is injected; optional parameters keep their
     * defaults, unknown non-builtin types get an auto-mock. Konstruktor-
     * ordningen är INTE en del av kontraktet som den här sviten pinnar.
     *
     * @param array<int,object> $deps
     */
    private function byggMed(string $klass, array $deps): object {
        $ref = new \ReflectionClass($klass);
        $ctor = $ref->getConstructor();
        if ($ctor === null) {
            return $ref->newInstance();
        }

        $args = [];
        foreach ($ctor->getParameters() as $param) {
            $typ = $param->getType();
            $typNamn = $typ instanceof \ReflectionNamedType ? $typ->getName() : null;

            if ($typNamn !== null && !$typ->isBuiltin()) {
                $matchad = null;
                foreach ($deps as $dep) {
                    if ($dep instanceof $typNamn) {
                        $matchad = $dep;
                        break;
                    }
                }
                if ($matchad !== null) {
                    $args[] = $matchad;
                    continue;
                }
            }
            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }
            if ($typ !== null && $typ->allowsNull()) {
                $args[] = null;
                continue;
            }
            if ($typNamn !== null && !$typ->isBuiltin()) {
                $args[] = $this->createMock($typNamn);
                continue;
            }
            $args[] = match ($typNamn) {
                'string' => 'test-varde',
                'int' => 0,
                'float' => 0.0,
                'bool' => false,
                'array' => [],
                default => self::fail(sprintf(
                    'Kan inte auto-koppla konstruktorparametern $%s (%s) i %s',
                    $param->getName(),
                    $typNamn ?? 'okänd typ',
                    $klass,
                )),
            };
        }

        return $ref->newInstanceArgs($args);
    }

    private function arende(): Arende {
        $arende = new Arende();
        $arende->setHubsCaseId(self::REF);
        $arende->setArendeTyp('orosanmalan');
        $arende->setStatus('tilldelat');
        $arende->setSteg('beslut');
        $arende->setProvenanceState('ej_registrerad');
        return $arende;
    }
}
