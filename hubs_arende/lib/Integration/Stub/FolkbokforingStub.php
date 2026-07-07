<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Integration\Stub;

use OCA\HubsArende\Integration\Port\Exception\FolkbokforingException;
use OCA\HubsArende\Integration\Port\FolkbokforingPort;

/**
 * 🔌 SEAM[navet] / SEAM[navet.folkbokforing]
 *
 * DETERMINISTISK in-code-stub mot Navet (Skatteverkets folkbokföring via
 * kommunens interna Frends-API). Ingen slump, ingen extern lagring — en fast
 * fixture-karta i klassen driver ALLA kravscenarier ur
 * KRAVSTALLNING-NAVET-FOLKBOKFORING.md (par 5, K-NAV-2.7/2.8):
 *
 *  - 201204012380 "Elsa Teststrom"    — barn, skydd=ingen, två V-relationer
 *                                       (vårdnadshavare) till Maria och Johan.
 *  - 198505051230 "Maria Teststrom"   — skydd=ingen, VF-relation till Elsa.
 *  - 198707073450 "Johan Teststrom"   — skydd=SEKRETESSMARKERING: posten är HEL
 *                                       och kontaktadressen LEVERERAS — flaggan
 *                                       är en varningssignal till mottagaren,
 *                                       inte en maskning i källan.
 *  - 201002024560 "Skyddad Testperson"— skydd=SKYDDAD_FOLKBOKFORING: verklig
 *                                       adress finns INTE i Navet-svaret
 *                                       (kontaktadress=null); endast särskild
 *                                       postadress (Skatteverkets förmedlings-
 *                                       uppdrag, Box 2820, Göteborg) levereras.
 *  - 195011115670 "Avliden Testperson"— avregistrerad [kod=AV, 2025-11-01].
 *  - 200803036780 "Bytt Personnummer" — tidigareBeteckningar=[200803031111]
 *                                       (nya hänvisningsnummer-metoden: den
 *                                       AKTUELLA beteckningen bär de tidigare;
 *                                       uppslag på den gamla beteckningen
 *                                       upplöses till den aktuella posten).
 *
 * Fixtures speglar Skatteverkets testdata-scenarier för Navet
 * (testbeställning 00000236-FO01-0002 = uttag MED skyddade personer).
 * SAMTLIGA personnummer och namn är PÅHITTADE/SYNTETISKA testpersoner —
 * de motsvarar inga verkliga individer.
 *
 * INVARIANTER (PII-doktrinen + fail-closed, se ANALYS-HANDLING-FRAN-MALL.md
 * par 3.4 och K-NAV-3.3):
 *  - Okänt personnummer ⇒ null i retur-mappen (Navet-beteende: saknade
 *    personer specificeras i svaret, de är INTE ett HTTP-fel).
 *  - Fältet `skydd` är OBLIGATORISKT i varje levererad personpost. Saknat
 *    eller okänt värde ⇒ {@see FolkbokforingException}, ALDRIG default
 *    "ingen" (fail-closed, aldrig fail-open).
 *  - Vid skyddad_folkbokforing får verklig adress ALDRIG lämna porten:
 *    kontaktadress är null; endast sarskildPostadress levereras.
 *  - Undantagsmeddelanden innehåller ALDRIG personnummer eller namn — endast
 *    korrelations-id, antal och positionsindex (PII-doktrinen: identitet får
 *    aldrig skrivas till loggar eller Handelse.detalj).
 *  - `hamtaPerson()` kräver korrelations-id i kontexten och kastar annars —
 *    mocken UPPFOSTRAR anroparen att alltid skicka spårbarhetsnyckeln, så att
 *    den skarpa Frends-konnektorn aldrig blir första stället det upptäcks.
 *
 * Konstruktorn tar ren config (inga OCP-deps) så att stubben kan användas
 * både från DI (FolkbokforingService) och från enhetstester direkt.
 *
 * @phpstan-type Adress array{rader: string[], postnummer: string, postort: string}
 * @phpstan-type Personpost array{
 *   personnummer: string,
 *   tidigareBeteckningar: string[],
 *   namn: array{fornamn: string, mellannamn: ?string, efternamn: string},
 *   kontaktadress: ?Adress,
 *   sarskildPostadress: ?Adress,
 *   skydd: 'ingen'|'sekretessmarkering'|'skyddad_folkbokforing',
 *   avregistrering: ?array{kod: 'AV'|'UV', datum: string},
 *   relationer: array<int, array{typ: 'V'|'VF', personnummer: ?string, namn: string, tomDatum: ?string}>,
 *   fodelsetid: string
 * }
 */
class FolkbokforingStub implements FolkbokforingPort {
    /** Skyddsnivå: ingen markering i folkbokföringen. */
    public const SKYDD_INGEN = 'ingen';

    /** Skyddsnivå: sekretessmarkering (varningssignal — posten levereras hel). */
    public const SKYDD_SEKRETESSMARKERING = 'sekretessmarkering';

    /** Skyddsnivå: skyddad folkbokföring (verklig adress finns EJ i svaret). */
    public const SKYDD_SKYDDAD_FOLKBOKFORING = 'skyddad_folkbokforing';

    /** Sanktionerade skyddsnivåer — allt annat är fail-closed-brott. */
    private const GILTIGA_SKYDD = [
        self::SKYDD_INGEN,
        self::SKYDD_SEKRETESSMARKERING,
        self::SKYDD_SKYDDAD_FOLKBOKFORING,
    ];

    /**
     * @param bool $available Styr {@see isAvailable()} — false simulerar att
     *             Navet/Frends är nere (för fallback-/degraderingstester).
     * @param array<string, ?array<string,mixed>> $extraFixtures Extra/överskuggande
     *             fixture-poster per 12-siffrigt pnr (enhetstest-injektion).
     *             Poster valideras med samma fail-closed-regler som de inbyggda;
     *             explicit null tar bort en inbyggd person ("finns ej i Navet").
     */
    public function __construct(
        private bool $available = true,
        private array $extraFixtures = [],
    ) {
    }

    /**
     * Stubben är alltid "uppe" om inte annat injicerats — det låter
     * degraderingsvägen (Navet nere ⇒ manuell registrering) testas
     * deterministiskt utan nätverk.
     */
    public function isAvailable(): bool {
        return $this->available;
    }

    /**
     * Slå upp personposter i "folkbokföringen" (fixture-kartan).
     *
     * Navet-semantik: retur-mappen är nycklad på det EFTERFRÅGADE person-
     * numret; saknade personer får värdet null (aldrig HTTP-fel). Uppslag på
     * en tidigare beteckning upplöses till den aktuella posten — fältet
     * `personnummer` i posten visar då den AKTUELLA beteckningen medan
     * `tidigareBeteckningar` bär den gamla (hänvisningsnummer-metoden).
     *
     * @param string[] $personnummer 12-siffriga personnummer (AAAAMMDDNNNN).
     * @param array<string,mixed> $kontext Anropskontext; MÅSTE innehålla en
     *        icke-tom sträng 'korrelationsId' (spårbarhet utan PII).
     *
     * @return array<string, ?array<string,mixed>> Karta pnr ⇒ personpost
     *         (shape enligt @phpstan-type Personpost) eller null om personen
     *         inte finns i folkbokföringen.
     *
     * @throws FolkbokforingException om korrelations-id saknas i kontexten,
     *         om ett personnummer har ogiltigt format, eller om en fixture-
     *         post bryter fail-closed-reglerna (saknat/okänt `skydd`, eller
     *         kontaktadress trots skyddad_folkbokforing). Meddelandet
     *         innehåller aldrig personnummer eller namn.
     */
    public function hamtaPerson(array $personnummer, array $kontext): array {
        // --- UPPFOSTRAN: korrelations-id är obligatoriskt (spårbarhet utan PII) ---
        $korrelationsId = $kontext['korrelationsId'] ?? null;
        if (!is_string($korrelationsId) || trim($korrelationsId) === '') {
            throw new FolkbokforingException(
                'Stub: kontext saknar korrelationsId — folkbokföringsuppslag utan '
                . 'spårbarhetsnyckel är inte tillåtna (antal efterfrågade: '
                . count($personnummer) . ')'
            );
        }

        $fixtures = $this->fixtures();
        $alias = $this->aliasIndex($fixtures);

        $resultat = [];
        foreach (array_values($personnummer) as $i => $pnr) {
            $normaliserat = $this->normaliseraPnr((string)$pnr, $i, $korrelationsId);

            // Hänvisningsnummer-metoden: gammal beteckning ⇒ aktuell post.
            $uppslagsnyckel = $alias[$normaliserat] ?? $normaliserat;

            $post = $fixtures[$uppslagsnyckel] ?? null;
            if ($post !== null) {
                $this->assertFailClosed($post, $i, $korrelationsId);
            }

            // Nyckla på det EFTERFRÅGADE numret (Navet specificerar saknade i svaret).
            $resultat[$normaliserat] = $post;
        }

        return $resultat;
    }

    // ------------------------------------------------------------------ //
    //  Fixtures — syntetiska testpersoner (K-NAV-2.7/2.8, par 5)
    // ------------------------------------------------------------------ //

    /**
     * Den deterministiska fixture-kartan, nycklad på AKTUELLT 12-siffrigt pnr.
     * Injicerade extraFixtures skuggar/kompletterar de inbyggda posterna.
     *
     * @return array<string, ?array<string,mixed>>
     */
    private function fixtures(): array {
        $inbyggda = [
            // Barn utan skydd, med två vårdnadshavare (V) — grundscenariot.
            '201204012380' => [
                'personnummer'         => '201204012380',
                'tidigareBeteckningar' => [],
                'namn'                 => ['fornamn' => 'Elsa', 'mellannamn' => null, 'efternamn' => 'Teststrom'],
                'kontaktadress'        => ['rader' => ['Testgatan 1'], 'postnummer' => '12345', 'postort' => 'Teststad'],
                'sarskildPostadress'   => null,
                'skydd'                => self::SKYDD_INGEN,
                'avregistrering'       => null,
                'relationer'           => [
                    ['typ' => 'V', 'personnummer' => '198505051230', 'namn' => 'Maria Teststrom', 'tomDatum' => null],
                    ['typ' => 'V', 'personnummer' => '198707073450', 'namn' => 'Johan Teststrom', 'tomDatum' => null],
                ],
                'fodelsetid'           => '2012-04-01',
            ],
            // Vårdnadshavare utan skydd (VF = vårdnadshavare för).
            '198505051230' => [
                'personnummer'         => '198505051230',
                'tidigareBeteckningar' => [],
                'namn'                 => ['fornamn' => 'Maria', 'mellannamn' => null, 'efternamn' => 'Teststrom'],
                'kontaktadress'        => ['rader' => ['Testgatan 1'], 'postnummer' => '12345', 'postort' => 'Teststad'],
                'sarskildPostadress'   => null,
                'skydd'                => self::SKYDD_INGEN,
                'avregistrering'       => null,
                'relationer'           => [
                    ['typ' => 'VF', 'personnummer' => '201204012380', 'namn' => 'Elsa Teststrom', 'tomDatum' => null],
                ],
                'fodelsetid'           => '1985-05-05',
            ],
            // SEKRETESSMARKERING: posten är HEL och kontaktadressen LEVERERAS —
            // markeringen är en varningssignal som mottagaren måste hantera
            // (aviseringsskyddet ligger hos KONSUMENTEN, inte i källan).
            '198707073450' => [
                'personnummer'         => '198707073450',
                'tidigareBeteckningar' => [],
                'namn'                 => ['fornamn' => 'Johan', 'mellannamn' => null, 'efternamn' => 'Teststrom'],
                'kontaktadress'        => ['rader' => ['Provgatan 2'], 'postnummer' => '12345', 'postort' => 'Teststad'],
                'sarskildPostadress'   => null,
                'skydd'                => self::SKYDD_SEKRETESSMARKERING,
                'avregistrering'       => null,
                'relationer'           => [
                    ['typ' => 'VF', 'personnummer' => '201204012380', 'namn' => 'Elsa Teststrom', 'tomDatum' => null],
                ],
                'fodelsetid'           => '1987-07-07',
            ],
            // SKYDDAD FOLKBOKFÖRING: verklig adress finns INTE i Navet-svaret
            // (kontaktadress=null). Endast särskild postadress levereras —
            // Skatteverkets förmedlingsuppdrag vidarebefordrar posten.
            '201002024560' => [
                'personnummer'         => '201002024560',
                'tidigareBeteckningar' => [],
                'namn'                 => ['fornamn' => 'Skyddad', 'mellannamn' => null, 'efternamn' => 'Testperson'],
                'kontaktadress'        => null,
                'sarskildPostadress'   => [
                    'rader'      => ['Skatteverket, formedlingsuppdrag', 'Box 2820'],
                    'postnummer' => '40320',
                    'postort'    => 'Goteborg',
                ],
                'skydd'                => self::SKYDD_SKYDDAD_FOLKBOKFORING,
                'avregistrering'       => null,
                'relationer'           => [],
                'fodelsetid'           => '2010-02-02',
            ],
            // Avregistrerad: avliden (kod AV). Posten levereras med sista
            // kända uppgifter + avregistreringsblocket.
            '195011115670' => [
                'personnummer'         => '195011115670',
                'tidigareBeteckningar' => [],
                'namn'                 => ['fornamn' => 'Avliden', 'mellannamn' => null, 'efternamn' => 'Testperson'],
                'kontaktadress'        => ['rader' => ['Gamla vagen 3'], 'postnummer' => '12347', 'postort' => 'Teststad'],
                'sarskildPostadress'   => null,
                'skydd'                => self::SKYDD_INGEN,
                'avregistrering'       => ['kod' => 'AV', 'datum' => '2025-11-01'],
                'relationer'           => [],
                'fodelsetid'           => '1950-11-11',
            ],
            // Bytt personnummer (nya hänvisningsnummer-metoden): den AKTUELLA
            // beteckningen bär de tidigare; uppslag på 200803031111 upplöses
            // hit via aliasIndex().
            '200803036780' => [
                'personnummer'         => '200803036780',
                'tidigareBeteckningar' => ['200803031111'],
                'namn'                 => ['fornamn' => 'Bytt', 'mellannamn' => null, 'efternamn' => 'Personnummer'],
                'kontaktadress'        => ['rader' => ['Testgatan 4'], 'postnummer' => '12345', 'postort' => 'Teststad'],
                'sarskildPostadress'   => null,
                'skydd'                => self::SKYDD_INGEN,
                'avregistrering'       => null,
                'relationer'           => [],
                'fodelsetid'           => '2008-03-03',
            ],
        ];

        // Test-injektion skuggar inbyggda poster; explicit null = "finns ej".
        // OBS: union (+), ALDRIG array_merge — PHP int-castar de 12-siffriga
        // pnr-nycklarna vid literal-definitionen och array_merge RENUMRERAR
        // integer-nycklar (0..n), vilket tyst tömmer hela fixture-uppslaget
        // (samma numeriska-nyckel-gotcha som uid-buggen i aclKretsUids).
        // Union bevarar nycklarna; vänster operand (extraFixtures) skuggar.
        return $this->extraFixtures + $inbyggda;
    }

    /**
     * De kända fixture-personnumren (aktuella beteckningar, som strängar).
     *
     * Introspektionsyta för tester — OBS array_map('strval', …): PHP har
     * int-castat de numeriska nycklarna, och strikta strängjämförelser i
     * testerna skulle annars tyst fallera.
     *
     * @return string[] 12-siffriga pnr vars post finns (null-skuggade utelämnas)
     */
    public function getKandaPersonnummer(): array {
        return array_values(array_map(
            'strval',
            array_keys(array_filter($this->fixtures(), static fn ($post) => $post !== null)),
        ));
    }

    /**
     * Bygg index tidigare beteckning ⇒ aktuell beteckning (hänvisningsnummer).
     *
     * @param array<string, ?array<string,mixed>> $fixtures
     * @return array<string,string>
     */
    private function aliasIndex(array $fixtures): array {
        $alias = [];
        foreach ($fixtures as $aktuell => $post) {
            foreach (($post['tidigareBeteckningar'] ?? []) as $tidigare) {
                $alias[(string)$tidigare] = (string)$aktuell;
            }
        }
        return $alias;
    }

    // ------------------------------------------------------------------ //
    //  Fail-closed-vakter (K-NAV-3.3) — meddelanden UTAN PII
    // ------------------------------------------------------------------ //

    /**
     * Normalisera och validera personnummerformat (12 siffror AAAAMMDDNNNN).
     * Mellanslag/bindestreck tolereras i indata (sekelskiljare) men svaret
     * nycklas alltid på den rena 12-siffriga formen.
     *
     * Felmeddelandet pekar ut POSITION i frågan — aldrig själva numret.
     */
    private function normaliseraPnr(string $pnr, int $index, string $korrelationsId): string {
        $rensat = str_replace([' ', '-', '+'], '', trim($pnr));
        if (!preg_match('/^\d{12}$/', $rensat)) {
            throw new FolkbokforingException(
                'Stub: ogiltigt personnummerformat på position ' . $index
                . ' i uppslaget (förväntar 12 siffror AAAAMMDDNNNN), korrelationsId='
                . $korrelationsId
            );
        }
        return $rensat;
    }

    /**
     * Fail-closed-vakt per levererad post:
     *  - `skydd` MÅSTE finnas och vara ett sanktionerat värde — saknas/okänt
     *    kastas det, ALDRIG default "ingen".
     *  - Vid skyddad_folkbokforing får kontaktadress ALDRIG levereras
     *    (verklig adress lämnar aldrig porten).
     *
     * Vakten körs även på test-injicerade fixtures så att stubben uppfostrar
     * både anropare och testförfattare. Meddelanden innehåller aldrig PII.
     *
     * @param array<string,mixed> $post
     */
    private function assertFailClosed(array $post, int $index, string $korrelationsId): void {
        $skydd = $post['skydd'] ?? null;
        if (!is_string($skydd) || !in_array($skydd, self::GILTIGA_SKYDD, true)) {
            throw new FolkbokforingException(
                'Stub: personpost på position ' . $index . ' saknar giltigt skydd-fält '
                . '(fail-closed, K-NAV-3.3) — posten släpps INTE igenom, korrelationsId='
                . $korrelationsId
            );
        }
        if ($skydd === self::SKYDD_SKYDDAD_FOLKBOKFORING && ($post['kontaktadress'] ?? null) !== null) {
            throw new FolkbokforingException(
                'Stub: personpost på position ' . $index . ' har kontaktadress trots '
                . 'skyddad folkbokföring — verklig adress får aldrig lämna porten, korrelationsId='
                . $korrelationsId
            );
        }
    }
}
