<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Tests\Unit\Service;

use OCA\HubsArende\Db\Arende;
use OCA\HubsArende\Db\Part;
use OCA\HubsArende\Db\PartMapper;
use OCA\HubsArende\Service\ArendedataService;
use OCA\HubsArende\Service\ArendeService;
use OCA\HubsArende\Service\MallService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for {@see ArendedataService} — datakällelagret för HANDLING-FRÅN-MALL
 * fas 1 (design: hubs_start/docs/ANALYS-HANDLING-FRAN-MALL.md §5.2): aggregerar
 * ärendebilden (register via {@see ArendeService} + PARTSREGISTRET via
 * {@see PartMapper}) till en fält→värde-karta som fyllningsmotorn substituerar
 * in i mallens platshållare.
 *
 * Vad sviten pinnar:
 *
 *  - GENERISKA FÄLT FAS 1: exakt nycklarna arendeRef/enhet/handlaggare/
 *    barnNamn/barnPnr med sina etiketter och EXAKTA platshållarsträngar ur
 *    mallbiblioteket (grep-verifierade mot
 *    hubs_start/mallbibliotek/socialsekreterare-barn-familj/*.md).
 *    Datum-platshållare ([ÅÅÅÅ-MM-DD] m.fl.) fylls MEDVETET INTE — ärlighet
 *    före täckning; oersatta platshållare lämnas åt handläggaren i
 *    dokumentredigeraren.
 *  - SKYDDSGRINDEN (K-NAV-6.1, KRAVSTALLNING-NAVET-FOLKBOKFORING.md): barn-part
 *    med skydd=sekretessmarkering ELLER skyddad_folkbokforing ⇒ namn-fältet
 *    UTELÄMNAS som default (värde tomt) med varning; identifiering sker med
 *    personnummer, som DÄRFÖR fylls ändå. Grinden är en default, inte ett
 *    förbud — behörig visning är avsedd (jfr hubs-pii-authorization-principle),
 *    men ett medvetet override är anroparens aktiva beslut, aldrig tjänstens.
 *  - FALLBACKAR: dnr saknas ⇒ arendeRef = 'Ärende ' + kortRef (6 hex,
 *    {@see ArendeService::kortRef()}); IUserManager-miss ⇒ handläggarfältet
 *    faller tillbaka på uid-strängen; inga parter alls ⇒ inga kast, tomt
 *    barn-fält med varning (graceful — handläggaren kompletterar i dialogen).
 *  - PII-DOKTRINEN: personnummer/namn når ALDRIG LoggerInterface — varje
 *    logganrop fångas och regex-/strängvaktas (test 8).
 *
 * KONTRAKTS-BINDNING: sviten binder till tjänstens PUBLIKA kontrakt
 * (hamtaArendedata/platshallareFor/PLATSHALLARE + kollaboratörernas TYPER) —
 * konstruktorparameter-ORDNING är inte en del av kontraktet, så {@see bygg()}
 * kopplar beroenden per typ via reflection (samma mönster som PartServiceTest).
 */
final class ArendedataServiceTest extends TestCase {
    /**
     * Tekniska nyckeln (UUID v4-format) — kortRef blir de 6 första
     * hex-tecknen utan bindestreck: 'ab12cd'.
     */
    private const HUBS_CASE_ID = 'ab12cd34-90ef-4a01-8b23-4567890abcde';

    /** Treserva-dnr när registrerad — primär ärendereferens för människor. */
    private const DNR = 'SN-2026/0042';

    /** Enheten som äger ärendet (registerfältet, inte en PII-uppgift). */
    private const ENHET = 'mottagning-barn-familj';

    /** Handläggarens uid — resolvas till displayName via IUserManager. */
    private const AGARE_UID = 'anna.handlaggare';
    private const AGARE_NAMN = 'Anna Andersson';

    /** Barnets uppgifter (Skatteverkets testpersonnummer — aldrig riktiga). */
    private const BARN_NAMN = 'Bo Barnesson';
    private const BARN_PNR = '201204012380';
    private const BARN_PNR_FORMATERAT = '20120401-2380';

    /** Skyddat barn (test 3/4) — namnet får ALDRIG defaultas in i dokumentet. */
    private const SKYDDAT_NAMN = 'Skyddat Skyddsson';
    private const SKYDDAT_PNR = '201002024560';
    private const SKYDDAT_PNR_FORMATERAT = '20100202-4560';

    /** Fält-kontraktet — fas 1 + VÅG A (ANALYS-FORIFYLLNAD-FALTKARTLAGGNING.md §5) + S4, varken mer eller mindre. */
    private const FAS1_NYCKLAR = [
        'arendeRef', 'enhet', 'handlaggare', 'barnNamn', 'barnPnr',
        'anmalareNamn', 'anmalareKontakt', 'inkomTidpunkt', 'utredningInledd', 'medutredare',
        // S4: konfig-driven branding (sidhuvudets brand-slot, app-config blankett_kommun).
        'kommunNamn',
    ];

    private ArendeService&MockObject $arendeService;
    private PartMapper&MockObject $partMapper;
    private LoggerInterface&MockObject $logger;
    private IUserManager&MockObject $userManager;

    /** Ärendet som ArendeService::show löser i det aktuella testet. */
    private Arende $arende;
    /** @var Part[] Partsregistret som PartMapper::findByCaseId returnerar. */
    private array $parter = [];
    /** @var array<string,string> uid => displayName som IUserManager::get resolvar. */
    private array $anvandare = [];
    /** @var string[] Varje meddelande + json(context) som nådde LoggerInterface. */
    private array $loggat = [];

    protected function setUp(): void {
        parent::setUp();

        $this->arendeService = $this->createMock(ArendeService::class);
        $this->partMapper = $this->createMock(PartMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->arende = $this->nyArende(self::DNR);
        $this->parter = [];
        $this->anvandare = [self::AGARE_UID => self::AGARE_NAMN];
        $this->loggat = [];

        // Authz-grinden bor i ArendeService::show (missing case OCH obehörig
        // enhet => DoesNotExistException); här är handläggaren alltid behörig
        // och show löser till testets ärende.
        $this->arendeService->method('show')
            ->willReturnCallback(fn (string $ref): Arende => $this->arende);

        // Partsregistret — testet styr innehållet per fall via $this->parter.
        $this->partMapper->method('findByCaseId')
            ->willReturnCallback(fn (string $hubsCaseId): array => $this->parter);
        $this->partMapper->method('countByCase')
            ->willReturnCallback(fn (string $hubsCaseId): int => count($this->parter));

        // Användarupplösning: träff i $this->anvandare => IUser med displayName,
        // annars null (=> tjänsten SKA falla tillbaka på uid-strängen, test 7).
        $this->userManager->method('get')->willReturnCallback(function (string $uid): ?IUser {
            if (!isset($this->anvandare[$uid])) {
                return null;
            }
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($uid);
            $user->method('getDisplayName')->willReturn($this->anvandare[$uid]);
            return $user;
        });
        $this->userManager->method('userExists')
            ->willReturnCallback(fn (string $uid): bool => isset($this->anvandare[$uid]));

        // PII-DOKTRINEN: fånga VARJE logganrop (alla PSR-3-nivåer + log()) så
        // att test 8 kan vakta att personnummer/namn aldrig når loggarna.
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $niva) {
            $this->logger->method($niva)->willReturnCallback(
                function (string|\Stringable $message, array $context = []): void {
                    $this->loggat[] = (string)$message . ' ' . json_encode($context, JSON_THROW_ON_ERROR);
                },
            );
        }
        $this->logger->method('log')->willReturnCallback(
            function ($level, string|\Stringable $message, array $context = []): void {
                $this->loggat[] = (string)$message . ' ' . json_encode($context, JSON_THROW_ON_ERROR);
            },
        );
    }

    // ================================================================== //
    //  (1) Normalt barn => alla fas 1-fält fyllda ur register + partsregister
    // ================================================================== //

    public function testNormaltBarnFyllerFaltenUrRegisterOchPartsregister(): void {
        $this->parter = [$this->nyPart(Part::ROLL_BARN, Part::SKYDD_INGEN, self::BARN_NAMN, self::BARN_PNR)];

        $data = $this->faltKarta($this->nyService()->byggUtkast(self::HUBS_CASE_ID));

        // Kartan bär hela fas 1-kontraktet — varje nyckel finns.
        foreach (self::FAS1_NYCKLAR as $nyckel) {
            self::assertArrayHasKey($nyckel, $data, "fält-kartan saknar fas 1-nyckeln '$nyckel'");
        }

        // Registerfälten: dnr är satt => arendeRef ÄR dnr:t; enhet passthrough.
        self::assertSame(self::DNR, $data['arendeRef']['varde']);
        self::assertSame(self::ENHET, $data['enhet']['varde']);

        // Handläggaren resolvas till displayName via IUserManager.
        self::assertSame(self::AGARE_NAMN, $data['handlaggare']['varde']);

        // Barnet (skydd=ingen): namnet fylls UTAN varning, pnr formateras
        // ÅÅÅÅMMDD-XXXX (mallens platshållarformat, inte råa 12 siffror).
        self::assertSame(self::BARN_NAMN, $data['barnNamn']['varde']);
        self::assertSame('', (string)($data['barnNamn']['varning'] ?? ''), 'oskyddat barn ska inte ge någon varning');
        self::assertSame(self::BARN_PNR_FORMATERAT, $data['barnPnr']['varde']);

        // Etiketterna är en del av fält-kontraktet (visas i förhandsmodalen).
        self::assertSame('Ärende/dnr', $data['arendeRef']['etikett']);
        self::assertSame('Enhet', $data['enhet']['etikett']);
        self::assertSame('Handläggare', $data['handlaggare']['etikett']);
        self::assertSame('Barnets namn', $data['barnNamn']['etikett']);
        self::assertSame('Barnets personnummer', $data['barnPnr']['etikett']);
    }

    // ================================================================== //
    //  (2) dnr saknas => arendeRef = 'Ärende ' + kortRef (6 hex)
    // ================================================================== //

    public function testArendeRefFallerTillbakaPaKortRefNarDnrSaknas(): void {
        $this->arende = $this->nyArende(null);
        $this->parter = [$this->nyPart(Part::ROLL_BARN, Part::SKYDD_INGEN, self::BARN_NAMN, self::BARN_PNR)];

        $data = $this->faltKarta($this->nyService()->byggUtkast(self::HUBS_CASE_ID));

        // Samma pseudonyma människo-referens som rums-/team-/kort-namnen
        // ({@see ArendeService::kortRef()}): 6 första hex-tecknen utan streck.
        self::assertSame(
            'Ärende ' . ArendeService::kortRef(self::HUBS_CASE_ID),
            $data['arendeRef']['varde'],
        );
        self::assertMatchesRegularExpression(
            '/^Ärende [0-9a-f]{6}$/',
            (string)$data['arendeRef']['varde'],
            'fallback-referensen ska vara "Ärende " + exakt 6 hex-tecken',
        );
    }

    // ================================================================== //
    //  (3) SKYDDSGRIND: sekretessmarkering => namn utelämnas, pnr fylls ändå
    // ================================================================== //

    public function testSekretessmarkeratBarnUtelamnarNamnMenFyllerPersonnummer(): void {
        $this->parter = [$this->nyPart(Part::ROLL_BARN, Part::SKYDD_SEKRETESSMARKERING, self::SKYDDAT_NAMN, self::SKYDDAT_PNR)];

        $data = $this->faltKarta($this->nyService()->byggUtkast(self::HUBS_CASE_ID));

        // K-NAV-6.1: namn-fältet UTELÄMNAS som default — tomt värde, aldrig namnet.
        self::assertSame('', $data['barnNamn']['varde'], 'namnet får inte defaultas in i dokumentet vid sekretessmarkering');
        // ...med en varning som talar om VARFÖR, så handläggaren kan fatta
        // ett aktivt beslut i förhandsdialogen (override = anroparens val).
        self::assertStringContainsString(
            'Sekretessmarkerad',
            (string)($data['barnNamn']['varning'] ?? ''),
            'varningen ska peka ut sekretessmarkeringen',
        );
        // Identifiering sker med personnummer — det fylls DÄRFÖR ändå.
        self::assertSame(self::SKYDDAT_PNR_FORMATERAT, $data['barnPnr']['varde']);
    }

    // ================================================================== //
    //  (4) SKYDDSGRIND: skyddad folkbokföring => namn utelämnas + förmedlingsvarning
    // ================================================================== //

    public function testSkyddadFolkbokforingUtelamnarNamnMedFormedlingsvarning(): void {
        $this->parter = [$this->nyPart(Part::ROLL_BARN, Part::SKYDD_SKYDDAD_FOLKBOKFORING, self::SKYDDAT_NAMN, self::SKYDDAT_PNR)];

        $data = $this->faltKarta($this->nyService()->byggUtkast(self::HUBS_CASE_ID));

        self::assertSame('', $data['barnNamn']['varde'], 'namnet får inte defaultas in i dokumentet vid skyddad folkbokföring');
        // Vid skyddad folkbokföring går all kontakt via Skatteverkets
        // förmedlingstjänst (K-NAV-5.2) — varningen ska bära det ordet.
        self::assertStringContainsString(
            'förmedlingstjänst',
            (string)($data['barnNamn']['varning'] ?? ''),
            'varningen ska hänvisa till förmedlingstjänsten',
        );
        // Personnumret är identifieringsvägen — fylls ändå.
        self::assertSame(self::SKYDDAT_PNR_FORMATERAT, $data['barnPnr']['varde']);
    }

    // ================================================================== //
    //  (5) Inga parter alls => graceful: inga kast, tomt barn-fält med varning
    // ================================================================== //

    public function testIngaParterGerTomtBarnfaltMedVarningUtanKast(): void {
        $this->parter = [];

        // Får INTE kasta — saknas källa lämnas fältet åt handläggaren i
        // dialogen (graceful degradation, ANALYS §5.2a).
        $data = $this->faltKarta($this->nyService()->byggUtkast(self::HUBS_CASE_ID));

        self::assertSame('', $data['barnNamn']['varde']);
        self::assertNotSame(
            '',
            (string)($data['barnNamn']['varning'] ?? ''),
            'ett ofyllbart fält ska bära en varning så att handläggaren ser att det lämnats',
        );
    }

    // ================================================================== //
    //  (6) PLATSHÅLLARE-kartan täcker exakt fas 1-nycklarna med EXAKTA strängar
    // ================================================================== //

    public function testPlatshallareKartanTackerExaktFas1Nycklarna(): void {
        // Kartans nyckelmängd är HELA fas 1-kontraktet — varken mer (inga
        // datum-nycklar: [ÅÅÅÅ-MM-DD] m.fl. fylls medvetet inte) eller mindre.
        self::assertEqualsCanonicalizing(
            self::FAS1_NYCKLAR,
            array_keys(ArendedataService::PLATSHALLARE),
        );

        // EXAKTA platshållarsträngar ur mallbiblioteket (grep-verifierade mot
        // hubs_start/mallbibliotek/socialsekreterare-barn-familj/*.md) —
        // substitutionen är strängexakt, en avvikelse => fältet fylls aldrig.
        $service = $this->nyService();
        self::assertEqualsCanonicalizing(
            [
                '[hubsCaseId / Treserva-dnr om det finns]',
                '[hubsCaseId / Treserva-dnr]',
                '[Personakt / Treserva dnr]',
            ],
            $service->platshallareFor('arendeRef'),
        );
        self::assertEqualsCanonicalizing(
            [
                '[Mottagning / Barn och familj]',
                '[Barn och familj / utredningsgrupp]',
                '[Mottagning / Barn och familj / utredningsgrupp]',
            ],
            $service->platshallareFor('enhet'),
        );
        self::assertEqualsCanonicalizing(['[Namn, titel]'], $service->platshallareFor('handlaggare'));
        self::assertEqualsCanonicalizing(['[För- och efternamn]'], $service->platshallareFor('barnNamn'));
        self::assertEqualsCanonicalizing(['[ÅÅÅÅMMDD-XXXX]'], $service->platshallareFor('barnPnr'));

        // VÅG A-fälten (grep-verifierade 2026-07-07 — globalt entydiga strängar).
        self::assertEqualsCanonicalizing(
            [
                '[Namn — eller "okänd/anonym", se handledning]',
                '[Namn/funktion — eller "anonym" om anmälaren är okänd/skyddad]',
            ],
            $service->platshallareFor('anmalareNamn'),
        );
        self::assertEqualsCanonicalizing(['[Telefon / e-post / adress om lämnad]'], $service->platshallareFor('anmalareKontakt'));
        self::assertEqualsCanonicalizing(['[ÅÅÅÅ-MM-DD klockslag]'], $service->platshallareFor('inkomTidpunkt'));
        self::assertEqualsCanonicalizing(['[ÅÅÅÅ-MM-DD, dagen utredning inleddes]'], $service->platshallareFor('utredningInledd'));
        self::assertEqualsCanonicalizing(['[Namn, titel — utredningar görs ofta av två handläggare]'], $service->platshallareFor('medutredare'));

        // Okänd nyckel => tom array — aldrig kast, aldrig null.
        self::assertSame([], $service->platshallareFor('finns-inte'));
    }

    // ================================================================== //
    //  (7) IUserManager-miss => handläggarfältet faller tillbaka på uid:t
    // ================================================================== //

    public function testHandlaggareFallerTillbakaPaUidNarAnvandarenSaknas(): void {
        // Ingen användarkatalog-träff (t.ex. avprovisionerad handläggare) —
        // uid-strängen är bättre än ett tomt fält och aldrig fel.
        $this->anvandare = [];

        $data = $this->faltKarta($this->nyService()->byggUtkast(self::HUBS_CASE_ID));

        self::assertSame(self::AGARE_UID, $data['handlaggare']['varde']);
    }

    // ================================================================== //
    //  (8) PII-DOKTRINEN: personnummer/namn når ALDRIG LoggerInterface
    // ================================================================== //

    public function testLoggarnaInnehallerAldrigPersonnummerEllerNamn(): void {
        $service = $this->nyService();

        // Driv alla flöden som kan vilja logga: normalt barn, skyddat barn
        // (varningsvägen), dnr-fallback och tomt partsregister.
        $this->parter = [$this->nyPart(Part::ROLL_BARN, Part::SKYDD_INGEN, self::BARN_NAMN, self::BARN_PNR)];
        $service->byggUtkast(self::HUBS_CASE_ID);
        $this->parter = [$this->nyPart(Part::ROLL_BARN, Part::SKYDD_SKYDDAD_FOLKBOKFORING, self::SKYDDAT_NAMN, self::SKYDDAT_PNR)];
        $service->byggUtkast(self::HUBS_CASE_ID);
        $this->arende = $this->nyArende(null);
        $this->parter = [];
        $service->byggUtkast(self::HUBS_CASE_ID);

        foreach ($this->loggat as $i => $rad) {
            // Kärninvarianten: inga 12-siffriga identitetsbeteckningar i loggen.
            self::assertDoesNotMatchRegularExpression(
                '/\d{12}/',
                $rad,
                "logganrop #$i läcker ett personnummer",
            );
            foreach ([
                self::BARN_PNR_FORMATERAT,
                self::SKYDDAT_PNR_FORMATERAT,
                'Barnesson',
                'Skyddsson',
            ] as $forbjudet) {
                self::assertStringNotContainsString(
                    $forbjudet,
                    $rad,
                    "logganrop #$i läcker identitet ('$forbjudet')",
                );
            }
        }
    }

    // ================================================================== //
    //  Helpers
    // ================================================================== //

    // ================================================================== //
    //  S4 — kommunNamn ur konfig + per-mall-filtrering via malldefinitionen
    // ================================================================== //

    public function testKommunNamnFyllsUrAppKonfigMedKallaKonfig(): void {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getAppValueString')
            ->willReturnCallback(static fn (string $nyckel, string $default = ''): string =>
                $nyckel === 'blankett_kommun' ? 'Teststads kommun' : $default);

        $service = $this->nyService(appConfig: $appConfig);
        $data = $this->faltKarta($service->byggUtkast(self::HUBS_CASE_ID));

        self::assertSame('Teststads kommun', $data['kommunNamn']['varde']);
        self::assertSame('konfig', $data['kommunNamn']['kalla']);
    }

    public function testUtkastFiltrerasPerMallViaDefinitionensTokens(): void {
        // Malldefinition som BARA innehåller arendeRef- och handlaggare-tokens
        // (t.ex. kallelsen) ⇒ barn-/anmälar-/journalfälten filtreras bort.
        $mallService = $this->createMock(MallService::class);
        $mallService->method('lasDefinition')->willReturn([
            'mallId' => 'X/10 Kallelse.docx',
            'tokens' => ['[hubsCaseId / Treserva-dnr]', '[Namn, titel]'],
        ]);

        $service = $this->nyService(mallService: $mallService);
        $data = $this->faltKarta($service->byggUtkast(self::HUBS_CASE_ID, 'X/10 Kallelse.docx'));

        self::assertArrayHasKey('arendeRef', $data);
        self::assertArrayHasKey('handlaggare', $data);
        self::assertArrayNotHasKey('barnNamn', $data, 'fält utan token i mallen ska filtreras bort');
        self::assertArrayNotHasKey('kommunNamn', $data, 'kommunNamn-token finns inte i denna definition');

        // Utan mallId: OFILTRERAT (hela kartan) — bakåtkompatibelt.
        $alla = $this->faltKarta($service->byggUtkast(self::HUBS_CASE_ID));
        self::assertArrayHasKey('barnNamn', $alla);

        // Saknad definition ⇒ graceful ofiltrerat.
        $mallService2 = $this->createMock(MallService::class);
        $mallService2->method('lasDefinition')->willReturn(null);
        $service2 = $this->nyService(mallService: $mallService2);
        $data2 = $this->faltKarta($service2->byggUtkast(self::HUBS_CASE_ID, 'okand.docx'));
        self::assertArrayHasKey('barnNamn', $data2);
    }

    private function nyService(?IAppConfig $appConfig = null, ?MallService $mallService = null): ArendedataService {
        if ($appConfig === null && $mallService === null) {
            $service = $this->bygg(ArendedataService::class);
            self::assertInstanceOf(ArendedataService::class, $service);
            return $service;
        }
        // S4-fallen: direkta positionsargument (konstruktorordningen är känd här;
        // bygg()-hjälparen typmatchar och kan inte skilja på konfigurerade mocks).
        return new ArendedataService(
            $this->arendeService,
            $this->partMapper,
            $this->logger,
            $this->userManager,
            null,
            null,
            null,
            $appConfig,
            $mallService,
        );
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
            $this->logger,
            $this->userManager,
        ];

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

    /** Register-raden som ArendeService::show löser — dnr styrs per test. */
    private function nyArende(?string $dnr): Arende {
        $arende = new Arende();
        $arende->setHubsCaseId(self::HUBS_CASE_ID);
        $arende->setArendeTyp('orosanmalan');
        $arende->setStatus('pagaende');
        $arende->setSteg('utredning');
        $arende->setProvenanceState($dnr !== null ? 'registrerad' : 'ej_registrerad');
        $arende->setEnhet(self::ENHET);
        $arende->setAgareUid(self::AGARE_UID);
        $arende->setDnr($dnr);
        return $arende;
    }

    /** En partsregister-rad (Skatteverkets testpersonnummer — aldrig riktiga). */
    private function nyPart(string $roll, string $skydd, string $namn, ?string $personnummer): Part {
        $part = new Part();
        $part->setHubsCaseId(self::HUBS_CASE_ID);
        $part->setRoll($roll);
        $part->setNamn($namn);
        $part->setPersonnummer($personnummer);
        $part->setSkydd($skydd);
        $part->setKalla(Part::KALLA_MANUELL);
        return $part;
    }
    /** Indexera utkastets fält-LISTA (kontraktets shape) till karta nyckel => fält-def. */
    private function faltKarta(array $utkast): array {
        $karta = [];
        foreach (($utkast["falt"] ?? []) as $def) {
            $karta[(string)($def["nyckel"] ?? "")] = $def;
        }
        return $karta;
    }
}
