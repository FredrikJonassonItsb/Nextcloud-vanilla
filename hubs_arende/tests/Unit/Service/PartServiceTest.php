<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Tests\Unit\Service;

use OCA\HubsArende\Db\Arende;
use OCA\HubsArende\Db\Handelse;
use OCA\HubsArende\Db\HandelseMapper;
use OCA\HubsArende\Db\Part;
use OCA\HubsArende\Db\PartMapper;
use OCA\HubsArende\Integration\Port\FolkbokforingPort;
use OCA\HubsArende\Integration\Stub\FolkbokforingStub;
use OCA\HubsArende\Service\ArendeService;
use OCA\HubsArende\Service\PartService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for {@see PartService} — PARTSREGISTRET (oc_hubs_arende_part),
 * motorns ENDA sanktionerade PII-tabell (beslut Fredrik 2026-07-06, se
 * hubs_start/docs/ANALYS-HANDLING-FRAN-MALL.md §3.4): transient arbetsdata,
 * gallras med ärendet, ALDRIG SoR.
 *
 * The service is exercised against the REAL {@see FolkbokforingStub}
 * (deterministic test persons mirroring Skatteverkets testbeställning
 * 00000236-FO01-0002 — MED skyddade poster, K-NAV-7.1) while every other
 * collaborator is mocked. What the suite pins down:
 *
 *  - NAVET-UPPSLAG → upsert into partsregistret with kalla=navet + fail-open
 *    on nothing: okänt pnr / ogiltigt format / tomt ändamål all THROW
 *    (K-NAV-4.2 — uppslag endast i ärendekontext med journalfört ändamål).
 *  - FAIL-CLOSED SKYDD (K-NAV-3.3/K-NAV-5.1): skydd är OBLIGATORISKT;
 *    laggTill utan (eller med okänt) skydd kastar — ALDRIG default "ingen".
 *  - SKYDDAD FOLKBOKFÖRING (K-NAV-5.2): verklig adress lagras ALDRIG
 *    (adress=null); endast särskild postadress får förekomma.
 *  - SEKRETESSMARKERING: posten levereras HEL (adressen finns) — visning för
 *    behörig handläggare är avsedd (jfr hubs-pii-authorization-principle);
 *    invarianten är behörighetsgränsen, inte PII-döljande.
 *  - AVREGISTRERING (K-NAV-2.8): AV=avliden blir en egen fbfStatus — aldrig
 *    tyst behandlad som en vanlig person.
 *  - VÅRDNADSHAVARE (K-NAV-2.7/4.3): relationer typ V ger egna parts-rader
 *    med roll=vardnadshavare när inkluderaVardnadshavare=true.
 *  - PII-DOKTRINEN i journalen: Händelse.detalj bär antal/roll/skydd/
 *    korrelationsId — ALDRIG personnummer/namn (test 10 fångar varje
 *    record()-anrop och regex-vaktar).
 *
 * KONTRAKTS-BINDNING: the tests bind to the service's PUBLIC contract
 * (methods + collaborator TYPES, as fixed by {@see \OCA\HubsArende\Controller\PartController}
 * and {@see FolkbokforingPort}) — constructor parameter ORDER is not part of
 * that contract, so {@see bygg()} wires dependencies by type via reflection.
 */
final class PartServiceTest extends TestCase {
    /** Ärendereferens som ArendeService::show löser i alla tester. */
    private const REF = 'case-part-1';

    /** Normal person (barnet) — skydd=ingen, två vårdnadshavare (typ V). */
    private const PNR_BARN = '201204012380';
    /** Skyddad folkbokföring — verklig adress finns inte ens i Navet. */
    private const PNR_SKYDDAD = '201002024560';
    /** Sekretessmarkerad — posten levereras normalt hel (adressen finns). */
    private const PNR_SEKRETESS = '198707073450';
    /** Avregistrerad med kod AV (avliden). */
    private const PNR_AVLIDEN = '195011115670';
    /** Korrekt format men finns INTE i folkbokföringen (stubben svarar null). */
    private const PNR_OKAND = '199901019999';

    private ArendeService&MockObject $arendeService;
    private PartMapper&MockObject $partMapper;
    private HandelseMapper&MockObject $handelseMapper;
    private LoggerInterface&MockObject $logger;
    private ITimeFactory&MockObject $timeFactory;
    private ISecureRandom&MockObject $secureRandom;
    private IUserSession&MockObject $userSession;
    private FolkbokforingPort $port;

    /** @var Part[] Every Part handed to PartMapper::insert(), in call order. */
    private array $inserts = [];
    /** @var array<int,array{typ:string,detalj:array<string,mixed>}> Every HandelseMapper::record()-anrop. */
    private array $journal = [];

    protected function setUp(): void {
        parent::setUp();

        $this->arendeService = $this->createMock(ArendeService::class);
        $this->partMapper = $this->createMock(PartMapper::class);
        $this->handelseMapper = $this->createMock(HandelseMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->timeFactory = $this->createMock(ITimeFactory::class);
        $this->secureRandom = $this->createMock(ISecureRandom::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->inserts = [];
        $this->journal = [];

        // Authz-grinden bor i ArendeService::show (missing case OCH obehörig
        // enhet => DoesNotExistException); här är handläggaren alltid behörig.
        $this->arendeService->method('show')
            ->willReturnCallback(fn (string $ref): Arende => $this->arende(self::REF));

        $this->timeFactory->method('getDateTime')
            ->willReturnCallback(static fn (): \DateTime => new \DateTime('2026-07-06T08:00:00+00:00'));
        $this->timeFactory->method('now')
            ->willReturnCallback(static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-07-06T08:00:00+00:00'));

        // Deterministiskt korrelations-id UTAN siffror — så att pnr-regexen i
        // test 10 aldrig kan trigga på korrelationsId:t av misstag.
        $this->secureRandom->method('generate')
            ->willReturn('korrid-deterministiskt-testvarde');

        // Upsert-nyckeln: inget finns sedan tidigare => varje upsert är INSERT.
        $this->partMapper->method('findByCasePnrRoll')->willReturn(null);
        $this->partMapper->method('insert')->willReturnCallback(function (Part $part): Part {
            $this->inserts[] = $part;
            $part->setId(count($this->inserts));
            return $part;
        });
        $this->partMapper->method('update')->willReturnArgument(0);

        // Journalen (best-effort i motorn) — fånga varje record()-anrop så att
        // PII-doktrinen kan vaktas i test 10.
        $this->handelseMapper->method('record')->willReturnCallback(
            function (string $hubsCaseId, string $typ, array $detalj = [], string $aktorUid = ''): Handelse {
                $this->journal[] = ['typ' => $typ, 'detalj' => $detalj];
                $handelse = new Handelse();
                $handelse->setHubsCaseId($hubsCaseId);
                $handelse->setTyp($typ);
                return $handelse;
            },
        );

        // RIKTIG stub som port — den deterministiska testpersonkatalogen är
        // själva testfixturen (Skatteverkets testpersonnummer, aldrig riktiga).
        $port = $this->bygg(FolkbokforingStub::class);
        self::assertInstanceOf(FolkbokforingPort::class, $port);
        $this->port = $port;
    }

    // ================================================================== //
    //  (1) Normal person => Part med kalla=navet, namn+adress, skydd=ingen
    // ================================================================== //

    public function testUppslagNormalPersonSkaparNavetPart(): void {
        self::assertTrue($this->port->isAvailable(), 'stubben ska alltid vara tillgänglig');

        $service = $this->nyPartService();
        $service->uppslag(self::REF, self::PNR_BARN, Part::ROLL_BARN, 'dokumentifyllnad');

        // Upsert-insert kallad exakt en gång (inga vårdnadshavare utan flaggan).
        self::assertCount(1, $this->inserts);
        $part = $this->inserts[0];
        self::assertSame(self::REF, $part->getHubsCaseId());
        self::assertSame(Part::ROLL_BARN, $part->getRoll());
        self::assertSame(self::PNR_BARN, $part->getPersonnummer());
        self::assertSame(Part::KALLA_NAVET, $part->getKalla());
        self::assertNotSame('', $part->getNamn(), 'namn ska vara satt från folkbokföringen');
        self::assertNotNull($part->getAdress(), 'kontaktadressen ska vara lagrad för oskyddad person');
        self::assertNotSame('', (string)$part->getAdress());
        self::assertSame(Part::SKYDD_INGEN, $part->getSkydd());
    }

    // ================================================================== //
    //  (2) Skyddad folkbokföring => adress=NULL, särskild postadress lagrad
    // ================================================================== //

    public function testUppslagSkyddadFolkbokforingLagrarAldrigVerkligAdress(): void {
        $service = $this->nyPartService();
        $service->uppslag(self::REF, self::PNR_SKYDDAD, Part::ROLL_BARN, 'partsregistrering');

        $part = $this->hittaInsert(self::PNR_SKYDDAD);
        self::assertSame(Part::SKYDD_SKYDDAD_FOLKBOKFORING, $part->getSkydd());
        // K-NAV-5.2: verklig adress får ALDRIG lagras — den finns inte ens i Navet.
        self::assertNull($part->getAdress(), 'adress MÅSTE vara null vid skyddad folkbokföring');
        // ...men förmedlingsvägen (särskild postadress) är den enda tillåtna och SKA med.
        self::assertNotNull($part->getSarskildPostadress());
        self::assertNotSame('', (string)$part->getSarskildPostadress());
    }

    // ================================================================== //
    //  (3) Sekretessmarkering => flaggan satt OCH posten levereras hel
    // ================================================================== //

    public function testUppslagSekretessmarkeradLevererasHelMedFlagga(): void {
        $service = $this->nyPartService();
        $service->uppslag(self::REF, self::PNR_SEKRETESS, Part::ROLL_VARDNADSHAVARE, 'partsregistrering');

        $part = $this->hittaInsert(self::PNR_SEKRETESS);
        self::assertSame(Part::SKYDD_SEKRETESSMARKERING, $part->getSkydd());
        // Sekretessmarkering är en VARNINGSSIGNAL — posten (inkl. adress)
        // levereras normalt hel; visning för behörig handläggare är avsedd.
        self::assertNotNull($part->getAdress(), 'adressen SKA finnas vid sekretessmarkering');
        self::assertNotSame('', (string)$part->getAdress());
    }

    // ================================================================== //
    //  (4) Okänt pnr (ej i folkbokföringen) => InvalidArgumentException
    // ================================================================== //

    public function testUppslagOkantPersonnummerKastar(): void {
        $service = $this->nyPartService();

        $this->expectException(\InvalidArgumentException::class);
        $service->uppslag(self::REF, self::PNR_OKAND, Part::ROLL_ANNAN, 'partsregistrering');
    }

    // ================================================================== //
    //  (5) Ogiltigt pnr-format => InvalidArgumentException
    // ================================================================== //

    public function testUppslagOgiltigtPersonnummerFormatKastar(): void {
        $service = $this->nyPartService();

        $this->expectException(\InvalidArgumentException::class);
        $service->uppslag(self::REF, 'ABC123', Part::ROLL_ANNAN, 'partsregistrering');
    }

    // ================================================================== //
    //  (6) Tomt ändamål => InvalidArgumentException (K-NAV-4.2)
    // ================================================================== //

    public function testUppslagTomtAndamalKastar(): void {
        $service = $this->nyPartService();

        $this->expectException(\InvalidArgumentException::class);
        $service->uppslag(self::REF, self::PNR_BARN, Part::ROLL_BARN, '');
    }

    // ================================================================== //
    //  (7) Avregistrerad AV (avliden) => fbfStatus=avliden (K-NAV-2.8)
    // ================================================================== //

    public function testUppslagAvlidenSatterFbfStatus(): void {
        $service = $this->nyPartService();
        $service->uppslag(self::REF, self::PNR_AVLIDEN, Part::ROLL_ANNAN, 'partsregistrering');

        $part = $this->hittaInsert(self::PNR_AVLIDEN);
        // Aldrig tyst som vanlig person — avregistreringen blir en egen status.
        self::assertSame('avliden', $part->getFbfStatus());
    }

    // ================================================================== //
    //  (8) laggTill utan / med okänt skydd => kast (fail-closed, K-NAV-5.1)
    // ================================================================== //

    public function testLaggTillUtanSkyddKastarFailClosed(): void {
        $service = $this->nyPartService();

        $this->expectException(\InvalidArgumentException::class);
        // Tomt skydd får ALDRIG defaultas till "ingen" — det ska kasta.
        $service->laggTill(self::REF, ['roll' => Part::ROLL_ANMALARE, 'skydd' => '', 'namn' => 'Anna Anmälare']);
    }

    public function testLaggTillOkantSkyddVardeKastarFailClosed(): void {
        $service = $this->nyPartService();

        $this->expectException(\InvalidArgumentException::class);
        // Okänt värde utanför whitelisten (Part::tillatnaSkydd()) ska också kasta.
        $service->laggTill(self::REF, ['roll' => Part::ROLL_ANMALARE, 'skydd' => 'hemligt', 'namn' => 'Anna Anmälare']);
    }

    // ================================================================== //
    //  (9) Barn + inkluderaVardnadshavare => 2 vårdnadshavar-parter (K-NAV-4.3)
    // ================================================================== //

    public function testUppslagBarnMedVardnadshavareSkaparVardnadshavarParter(): void {
        $service = $this->nyPartService();
        $service->uppslag(self::REF, self::PNR_BARN, Part::ROLL_BARN, 'partsregistrering', true);

        $vardnadshavare = array_values(array_filter(
            $this->inserts,
            static fn (Part $p): bool => $p->getRoll() === Part::ROLL_VARDNADSHAVARE,
        ));
        self::assertCount(2, $vardnadshavare, 'relationer typ V ska ge egna parts-rader med roll=vardnadshavare');
        foreach ($vardnadshavare as $vh) {
            self::assertSame(self::REF, $vh->getHubsCaseId());
            self::assertSame(Part::KALLA_NAVET, $vh->getKalla());
            self::assertNotSame('', $vh->getNamn());
        }
        // Barnet självt registreras också — totalt 3 upsert-inserts.
        self::assertCount(3, $this->inserts);
        self::assertSame(
            self::PNR_BARN,
            $this->hittaInsert(self::PNR_BARN)->getPersonnummer(),
        );
    }

    // ================================================================== //
    //  (10) PII-DOKTRINEN: Händelse.detalj innehåller ALDRIG personnummer
    // ================================================================== //

    public function testJournalDetaljInnehallerAldrigPersonnummer(): void {
        $service = $this->nyPartService();

        // Driv ALLA journalskrivande flöden: uppslag (normal + VH), uppslag
        // skyddad, samt manuell laggTill med både namn och pnr.
        $service->uppslag(self::REF, self::PNR_BARN, Part::ROLL_BARN, 'partsregistrering', true);
        $service->uppslag(self::REF, self::PNR_SKYDDAD, Part::ROLL_ANNAN, 'dokumentifyllnad');
        $service->laggTill(self::REF, [
            'roll' => Part::ROLL_ANMALARE,
            'skydd' => Part::SKYDD_INGEN,
            'namn' => 'Ville Vittne',
            'personnummer' => '198001011234',
            'adress' => 'Vittnesgatan 1, 111 11 Stockholm',
        ]);

        self::assertNotEmpty($this->journal, 'varje partsregister-mutation ska journalföras');

        foreach ($this->journal as $i => $post) {
            $json = json_encode($post['detalj'], JSON_THROW_ON_ERROR);
            // Kärninvarianten: inga 12-siffriga identitetsbeteckningar i detalj.
            self::assertDoesNotMatchRegularExpression(
                '/\d{12}/',
                $json,
                "journalpost #$i ({$post['typ']}) läcker ett personnummer i detalj",
            );
            // ...inte heller bindestrecks-formaterade varianter eller namn.
            foreach ([
                '20120401-2380',
                '20100202-4560',
                '19800101-1234',
                'Ville',
                'Vittne',
            ] as $forbjudet) {
                self::assertStringNotContainsString(
                    $forbjudet,
                    $json,
                    "journalpost #$i ({$post['typ']}) läcker identitet ('$forbjudet') i detalj",
                );
            }
        }
    }

    // ================================================================== //
    //  Helpers
    // ================================================================== //

    private function nyPartService(): PartService {
        $service = $this->bygg(PartService::class);
        self::assertInstanceOf(PartService::class, $service);
        return $service;
    }

    /**
     * Wire a class by TYPE, not by constructor parameter order: for every
     * constructor parameter the matching collaborator (below) is injected;
     * unknown non-builtin types get an auto-mock, optional parameters keep
     * their defaults. Konstruktor-ordningen är INTE en del av kontraktet som
     * den här sviten pinnar — bara typerna och de publika metoderna är det.
     */
    private function bygg(string $klass): object {
        $deps = [
            $this->arendeService,
            $this->partMapper,
            $this->handelseMapper,
            $this->logger,
            $this->timeFactory,
            $this->secureRandom,
            $this->userSession,
        ];
        // Porten finns först när stubben själv är byggd (setUp bygger den).
        if (isset($this->port)) {
            $deps[] = $this->port;
        }

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

    /** Den insert:ade parten med givet personnummer — failar testet om ingen finns. */
    private function hittaInsert(string $personnummer): Part {
        foreach ($this->inserts as $part) {
            if ($part->getPersonnummer() === $personnummer) {
                return $part;
            }
        }
        self::fail("ingen Part med det efterfrågade personnumret nådde PartMapper::insert()");
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
}
