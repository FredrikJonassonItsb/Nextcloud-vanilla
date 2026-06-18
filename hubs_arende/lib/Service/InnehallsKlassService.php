<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Service;

use OCP\AppFramework\Services\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Innehållsklassning — DETERMINISTISK regelkaskad (Axel B′).
 *
 * Klassar en inflödesrad till EN av de 8 ärendetyperna (primärkategori, styr
 * ArendeTyp/routing/facksystem-modul) PLUS ett SET ortogonala cross-cutting
 * flaggor som beräknas PARALLELLT och oberoende av primärkategorin
 * (ARENDETYPER-FLODESANALYS.md §4–§5).
 *
 * Detta är ett klassningslager OVANPÅ de 5 kanaltyperna (MessageTypeService),
 * inte en ersättning för dem. Det är symmetriskt med MessageTypeService: en
 * deterministisk, datadriven uppslagning — men ett lager upp och därför MER
 * försiktig: felklassning här är inte ett UX-fel utan en SEKRETESSINCIDENT
 * (primärkategorin väljer facksystem-modul). Default vid otillräcklig/motstridig
 * signal är 'oklassad' (fail mot människa), aldrig en gissad primärkategori.
 *
 * SIGNALSTYRKA — fallande deterministisk ordning, stannar på första säkra
 * träffen (§5.2):
 *   (a) Strukturerade SDK-fält / X-headers (ärendetyp-/handlingskod) → EXAKT (1.0).
 *   (b) Avsändartyp / organisation mot konfigurerbart org-register → HÖG prior
 *       (men heuristisk; org-typ är en stark prior, inte ett facit — en region
 *       KAN skicka en orosanmälan).
 *   (c) Blankett-/formulärtyp (titel, formulär-id) → MEDEL-HÖG.
 *   (d) Nyckelord i ämne/innehåll → LÅG-MEDEL; får ALDRIG ensam ge auto-
 *       applicering på sekretess.
 *
 * Org-register-mappning (lager b): domstol→rättsligt_tvång, region/sjukhus→
 * vård_samverkan, skola+barn→orosanmälan, KFM/FK→ekonomi, HVB/familjehem→
 * verkställighet, tingsrätt+familjerätt→familjerätt.
 *
 * LLM är INTE här. Den deterministiska kaskaden går först; ett ev. lokalt,
 * avstängbart, människo-bekräftat LLM-förslagslager ligger UTANFÖR denna klass
 * och kommer aldrig autonomt/skarpt på sekretessbelagt innehåll (GAP-052/060).
 *
 * Tröskeln är SERVER-SIDE POLICY (granskad, per kund) via IAppConfig — inte en
 * demo-konstant i klienten. Org-registret är likaså en TODO[konfig]-hook,
 * datadriven per kund (à la DIGG-synk i UpdateAddressBookService).
 */
class InnehallsKlassService {
    // ---- De 8 primärkategorierna (ärendetyp-id, symmetriska med ArendeTypRegistry). ----
    public const KAT_OROSANMALAN = 'orosanmalan';         // 1
    public const KAT_ANSOKAN_BISTAND = 'ansokan_bistand'; // 2
    public const KAT_EKONOMI = 'ekonomi';                 // 3
    public const KAT_KOMPLETTERING = 'komplettering';     // 4
    public const KAT_VARD_SAMVERKAN = 'vard_samverkan';   // 5
    public const KAT_RATTSLIGT_TVANG = 'rattsligt_tvang'; // 6
    public const KAT_VERKSTALLIGHET = 'verkstallighet';   // 7
    public const KAT_FAMILJERATT = 'familjeratt';         // 8

    /** Fail mot människa — ingen gissad routing. */
    public const KAT_OKLASSAD = 'oklassad';

    // ---- Cross-cutting flaggor (oberoende booleans, vilket antal som helst). ----
    public const FLAG_AKUT_FARA = 'akut_fara';                // routing-override → jour
    public const FLAG_BARN_BEROORS = 'barn_berors';           // barnskyddsspår + BBIC
    public const FLAG_VALD_HOT = 'vald_hot';                  // höjer prioritet
    public const FLAG_SKYDDADE_PU = 'skyddade_pu';            // behörighets-gate
    public const FLAG_LAGOVERTRADELSE = 'lagovertradelse';    // brott/tvång
    public const FLAG_RATTSLIG_FRIST = 'rattslig_frist';      // lagstadgad frist
    public const FLAG_PU_INCIDENT = 'personuppgiftsincident'; // PUB/felkoppling
    public const FLAG_FELMOTTAGET = 'felmottaget';            // fel mottagare/enhet

    // ---- Konfidens-nivåer per signallager (§5.2). ----
    public const KONF_EXAKT = 1.0;  // (a) strukturerat SDK-fält
    public const KONF_HOG = 0.85;   // (b) org-register (LOA3-stärkt)
    public const KONF_MEDEL = 0.6;  // (c) blankett-/formulärtyp
    public const KONF_LAG = 0.35;   // (d) nyckelord (aldrig ensam auto på sekretess)
    public const KONF_INGEN = 0.0;  // ingen träff

    /**
     * AppConfig-nyckel för tröskeln (server-side policy, per kund). Default är
     * medvetet HÖG: under tröskel = människo-bekräftad triage.
     */
    public const CONF_KEY_TROSKEL = 'innehallsklass_troskel';
    public const TROSKEL_DEFAULT = 0.75;

    /**
     * AppConfig-nyckel för org-registret (TODO[konfig]-hook, datadrivet per kund).
     * JSON: { "<avsandar-monster>": "<kategori>" }. Tom default → inbyggda
     * konservativa heuristiker används som fallback.
     */
    public const CONF_KEY_ORG_REGISTER = 'innehallsklass_org_register';

    /**
     * Strukturerade ärendetyp-/handlingskoder → primärkategori (lager a, EXAKT).
     * Det datadrivna SDK-kuvertet kräver ingen tolkning. Stabila, ej fritext.
     *
     * @var array<string,string>
     */
    private const HANDLINGSKOD_KAT = [
        'orosanmalan' => self::KAT_OROSANMALAN,
        'oros' => self::KAT_OROSANMALAN,
        'skyddsbedomning' => self::KAT_OROSANMALAN,
        'ansokan' => self::KAT_ANSOKAN_BISTAND,
        'begaran_insats' => self::KAT_ANSOKAN_BISTAND,
        'ekonomiskt_bistand' => self::KAT_EKONOMI,
        'forsorjningsstod' => self::KAT_EKONOMI,
        'komplettering' => self::KAT_KOMPLETTERING,
        'sip' => self::KAT_VARD_SAMVERKAN,
        'utskrivningsklar' => self::KAT_VARD_SAMVERKAN,
        'samordnad_plan' => self::KAT_VARD_SAMVERKAN,
        'lvu' => self::KAT_RATTSLIGT_TVANG,
        'lvm' => self::KAT_RATTSLIGT_TVANG,
        'domstol_yttrande' => self::KAT_RATTSLIGT_TVANG,
        'verkstallighet' => self::KAT_VERKSTALLIGHET,
        'placering' => self::KAT_VERKSTALLIGHET,
        'uppfoljning' => self::KAT_VERKSTALLIGHET,
        'manadsrapport' => self::KAT_VERKSTALLIGHET,
        'vardnadsutredning' => self::KAT_FAMILJERATT,
        'familjeratt' => self::KAT_FAMILJERATT,
        'umgange' => self::KAT_FAMILJERATT,
    ];

    /**
     * Avsändartyp → primär kandidat (lager b, §5.2). Org-typ är en STARK PRIOR,
     * inte ett facit. Inbyggd konservativ fallback när kundens org-register
     * (CONF_KEY_ORG_REGISTER) är tomt.
     *
     * @var array<string,string>
     */
    private const AVSANDARTYP_KAT = [
        'domstol' => self::KAT_RATTSLIGT_TVANG,
        'tingsratt' => self::KAT_RATTSLIGT_TVANG,
        'aklagare' => self::KAT_RATTSLIGT_TVANG,
        'kriminalvard' => self::KAT_RATTSLIGT_TVANG,
        'sis' => self::KAT_RATTSLIGT_TVANG,
        'offentligt_bitrade' => self::KAT_RATTSLIGT_TVANG,
        'region' => self::KAT_VARD_SAMVERKAN,
        'sjukhus' => self::KAT_VARD_SAMVERKAN,
        'psykiatri' => self::KAT_VARD_SAMVERKAN,
        'vardcentral' => self::KAT_VARD_SAMVERKAN,
        'skola' => self::KAT_OROSANMALAN, // + barn-indikation (se lagerAvsandartyp)
        'forskola' => self::KAT_OROSANMALAN,
        'kfm' => self::KAT_EKONOMI,
        'kronofogden' => self::KAT_EKONOMI,
        'forsakringskassan' => self::KAT_EKONOMI,
        'fk' => self::KAT_EKONOMI,
        'arbetsformedlingen' => self::KAT_EKONOMI,
        'af' => self::KAT_EKONOMI,
        'hyresvard' => self::KAT_EKONOMI,
        'bank' => self::KAT_EKONOMI,
        'hvb' => self::KAT_VERKSTALLIGHET,
        'familjehem' => self::KAT_VERKSTALLIGHET,
        'utforare' => self::KAT_VERKSTALLIGHET,
    ];

    /**
     * Avsändartyper där en familjerätts-indikation skiftar kategorin 6 → 8
     * (tingsrätt + familjerätt → familjeratt).
     *
     * @var list<string>
     */
    private const AVSANDARTYP_FAMILJERATT = [
        'tingsratt',
        'domstol',
    ];

    /**
     * Blankett-/formulär-id → primärkategori (lager c, MEDEL-HÖG). Stabila markörer.
     *
     * @var array<string,string>
     */
    private const BLANKETT_KAT = [
        'orosanmalan_blankett' => self::KAT_OROSANMALAN,
        'sol_anmalan' => self::KAT_OROSANMALAN,
        'sip_kallelse' => self::KAT_VARD_SAMVERKAN,
        'lvu_ansokan' => self::KAT_RATTSLIGT_TVANG,
        'lvm_ansokan' => self::KAT_RATTSLIGT_TVANG,
        'ekb_ansokan' => self::KAT_EKONOMI,
        'forsorjningsstod_ansokan' => self::KAT_EKONOMI,
        'insats_ansokan' => self::KAT_ANSOKAN_BISTAND,
        'familjeratt_yttrande' => self::KAT_FAMILJERATT,
    ];

    /**
     * Nyckelord → primärkategori (lager d, LÅG-MEDEL). SVAGAST. Får aldrig ENSAM
     * ge auto-applicering: konfidensen ligger under default-tröskeln så en ren
     * nyckelordsträff blir 'oklassad' (människa bekräftar), i synnerhet på
     * sekretessbärande kategorier.
     *
     * @var array<string,list<string>>
     */
    private const NYCKELORD_KAT = [
        self::KAT_OROSANMALAN => ['orosanmälan', 'oroar mig', 'far illa', 'misstanke om', 'anmälan oro'],
        self::KAT_ANSOKAN_BISTAND => ['ansöker om', 'ansökan om insats', 'begär bistånd', 'begäran om'],
        self::KAT_EKONOMI => ['försörjningsstöd', 'ekonomiskt bistånd', 'hyresavi', 'avhysning', 'avhyst', 'hyresskuld'],
        self::KAT_VARD_SAMVERKAN => ['utskrivningsklar', 'samordnad individuell plan', 'utskrivning', 'hemgång'],
        self::KAT_RATTSLIGT_TVANG => ['lvu', 'lvm', 'tvångsvård', 'omhändertagande', 'överklagan', 'domstolsförhandling'],
        self::KAT_VERKSTALLIGHET => ['månadsrapport', 'uppföljning av placering', 'verkställighet', 'placerad'],
        self::KAT_FAMILJERATT => ['vårdnad', 'umgänge', 'boendeutredning', 'samarbetssamtal', 'vårdnadstvist'],
    ];

    public function __construct(
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Klassificera en inflödesrad: EN primär kategori + ett SET cross-cutting
     * flaggor (beräknas parallellt, oberoende av primärkategorin).
     *
     * @param array<string,mixed> $rad Inflödesraden. Igenkända nycklar:
     *        sdkFields|itsl (strukturerade fält), avsandartyp|orgTyp|senderType,
     *        from|fromEmail|avsandare, blankett|formularId|formId, subject|amne,
     *        preview|body|innehall|text, barnRef|objektRef. Alla valfria.
     *
     * @return array{
     *     primarKat: string,
     *     konfidens: float,
     *     flaggor: list<string>,
     *     varfor: array{lager: string, signal: string, detalj?: string}
     * } primarKat är ett av de 8 ärendetyp-id:na eller 'oklassad'. konfidens<tröskel
     *   → primarKat='oklassad' (mänsklig triage). flaggor sätts ALLTID, även när
     *   primärkategorin är oklassad (akut_fara/skyddade_pu får aldrig tappas).
     */
    public function klassificera(array $rad): array {
        // Flaggor beräknas PARALLELLT och oberoende av primärkategorin — de får
        // aldrig tappas även om kategorin blir oklassad (akut_fara = jour-override,
        // skyddade_pu = behörighets-gate).
        $flaggor = $this->beraknaFlaggor($rad);

        // Deterministisk kaskad — stannar på första säkra träffen (§5.2).
        $traff = $this->korKaskad($rad);

        $troskel = $this->troskel();
        $primarKat = $traff['kat'];
        $konfidens = $traff['konfidens'];

        // Under tröskel (eller ingen träff) → fail mot människa, ALDRIG gissad routing.
        if ($primarKat === self::KAT_OKLASSAD || $konfidens < $troskel) {
            $this->logger->info('InnehallsKlass: under tröskel → oklassad (triage)', [
                'app' => 'hubs_arende',
                'kandidat' => $primarKat,
                'konfidens' => $konfidens,
                'troskel' => $troskel,
                'lager' => $traff['varfor']['lager'] ?? '-',
            ]);
            return [
                'primarKat' => self::KAT_OKLASSAD,
                'konfidens' => $konfidens,
                'flaggor' => $flaggor,
                'varfor' => [
                    'lager' => (string)($traff['varfor']['lager'] ?? 'ingen'),
                    'signal' => 'under_troskel',
                    'detalj' => 'kandidat=' . $primarKat . ' konf=' . $konfidens . ' < troskel=' . $troskel,
                ],
            ];
        }

        return [
            'primarKat' => $primarKat,
            'konfidens' => $konfidens,
            'flaggor' => $flaggor,
            'varfor' => $traff['varfor'],
        ];
    }

    // ================================================================== //
    //  Deterministisk kaskad (a → b → c → d), stannar på första säkra träffen
    // ================================================================== //

    /**
     * Kör de fyra lagren i fallande determinism. Returnerar första säkra träffen.
     *
     * @param array<string,mixed> $rad
     * @return array{kat: string, konfidens: float, varfor: array{lager: string, signal: string, detalj?: string}}
     */
    private function korKaskad(array $rad): array {
        // (a) Strukturerade SDK-fält / X-headers — EXAKT, ingen tolkning.
        $a = $this->lagerStrukturerat($rad);
        if ($a !== null) {
            return $a;
        }

        // (b) Avsändartyp / organisation mot org-register — HÖG prior (heuristisk).
        $b = $this->lagerAvsandartyp($rad);
        if ($b !== null) {
            return $b;
        }

        // (c) Blankett-/formulärtyp — MEDEL-HÖG.
        $c = $this->lagerBlankett($rad);
        if ($c !== null) {
            return $c;
        }

        // (d) Nyckelord — SVAGAST, aldrig ensam auto på sekretess (konfidens cappas).
        $d = $this->lagerNyckelord($rad);
        if ($d !== null) {
            return $d;
        }

        // Noll / motstridig signal → oklassad.
        return [
            'kat' => self::KAT_OKLASSAD,
            'konfidens' => self::KONF_INGEN,
            'varfor' => ['lager' => 'ingen', 'signal' => 'ingen_traff'],
        ];
    }

    /**
     * Lager (a): strukturerad ärendetyp-/handlingskod i SDK-kuvertet (EXAKT).
     *
     * @param array<string,mixed> $rad
     * @return array{kat: string, konfidens: float, varfor: array{lager: string, signal: string, detalj?: string}}|null
     */
    private function lagerStrukturerat(array $rad): ?array {
        $sdk = $rad['sdkFields'] ?? $rad['itsl'] ?? null;
        if (!is_array($sdk)) {
            return null;
        }

        // Direkt ärendetyp-/handlingskod i kuvertet.
        $kod = strtolower(trim((string)(
            $sdk['arendetyp']
            ?? $sdk['arendeTyp']
            ?? $sdk['handlingskod']
            ?? $sdk['handlingstyp']
            ?? $sdk['errandType']
            ?? ''
        )));
        if ($kod === '') {
            return null;
        }

        // Exakt match mot en av de 8 ärendetyp-id:na vinner direkt.
        if ($this->arGiltigKat($kod)) {
            return $this->traff($kod, self::KONF_EXAKT, 'a_strukturerat', 'arendetyp=' . $kod);
        }

        // Annars mappa handlingskoden → kategori.
        if (isset(self::HANDLINGSKOD_KAT[$kod])) {
            return $this->traff(self::HANDLINGSKOD_KAT[$kod], self::KONF_EXAKT, 'a_strukturerat', 'handlingskod=' . $kod);
        }

        return null;
    }

    /**
     * Lager (b): avsändartyp / organisation mot org-register (HÖG prior).
     *
     * @param array<string,mixed> $rad
     * @return array{kat: string, konfidens: float, varfor: array{lager: string, signal: string, detalj?: string}}|null
     */
    private function lagerAvsandartyp(array $rad): ?array {
        $avsandartyp = $this->resolveAvsandartyp($rad);
        if ($avsandartyp === '') {
            return null;
        }

        // Org-register (TODO[konfig]): kundspecifik mappning vinner före inbyggd default.
        $kund = $this->orgRegister();
        if (isset($kund[$avsandartyp]) && $this->arGiltigKat($kund[$avsandartyp])) {
            return $this->traff($kund[$avsandartyp], self::KONF_HOG, 'b_avsandartyp', 'orgregister=' . $avsandartyp);
        }

        if (!isset(self::AVSANDARTYP_KAT[$avsandartyp])) {
            return null;
        }
        $kat = self::AVSANDARTYP_KAT[$avsandartyp];

        // Specialregel: tingsrätt/domstol + familjerätts-indikation → kat 8 (ej 6).
        if (in_array($avsandartyp, self::AVSANDARTYP_FAMILJERATT, true) && $this->harFamiljerattIndikation($rad)) {
            return $this->traff(self::KAT_FAMILJERATT, self::KONF_HOG, 'b_avsandartyp', 'domstol+familjeratt');
        }

        // Specialregel: skola/förskola utan barn-indikation är svagare — låt
        // kaskaden falla vidare i stället för att hårdklassa kat 1.
        if (in_array($avsandartyp, ['skola', 'forskola'], true) && !$this->harBarnIndikation($rad)) {
            return null;
        }

        return $this->traff($kat, self::KONF_HOG, 'b_avsandartyp', 'avsandartyp=' . $avsandartyp);
    }

    /**
     * Lager (c): blankett-/formulärtyp (MEDEL-HÖG).
     *
     * @param array<string,mixed> $rad
     * @return array{kat: string, konfidens: float, varfor: array{lager: string, signal: string, detalj?: string}}|null
     */
    private function lagerBlankett(array $rad): ?array {
        $blankett = strtolower(trim((string)(
            $rad['blankett'] ?? $rad['formularId'] ?? $rad['formId'] ?? $rad['blankettId'] ?? ''
        )));
        if ($blankett === '') {
            return null;
        }
        if (isset(self::BLANKETT_KAT[$blankett])) {
            return $this->traff(self::BLANKETT_KAT[$blankett], self::KONF_MEDEL, 'c_blankett', 'blankett=' . $blankett);
        }
        return null;
    }

    /**
     * Lager (d): nyckelord (SVAGAST). Konfidensen ligger UNDER default-tröskeln
     * så en ren nyckelordsträff aldrig auto-applicerar på sekretess — den blir
     * 'oklassad' (människa bekräftar). Flest distinkta träffar vinner; lika
     * toppoäng → motstridigt → oklassad.
     *
     * @param array<string,mixed> $rad
     * @return array{kat: string, konfidens: float, varfor: array{lager: string, signal: string, detalj?: string}}|null
     */
    private function lagerNyckelord(array $rad): ?array {
        $haystack = strtolower($this->concatTextFields($rad));
        if ($haystack === '') {
            return null;
        }

        $poang = [];
        foreach (self::NYCKELORD_KAT as $kat => $ord) {
            foreach ($ord as $kw) {
                if (str_contains($haystack, $kw)) {
                    $poang[$kat] = ($poang[$kat] ?? 0) + 1;
                }
            }
        }
        if ($poang === []) {
            return null;
        }

        arsort($poang);
        $topp = (string)array_key_first($poang);
        $toppPoang = $poang[$topp];

        // Motstridigt: två kategorier delar toppoäng → fail mot människa.
        $delarTopp = array_filter($poang, static fn (int $p): bool => $p === $toppPoang);
        if (count($delarTopp) > 1) {
            return [
                'kat' => self::KAT_OKLASSAD,
                'konfidens' => self::KONF_INGEN,
                'varfor' => [
                    'lager' => 'd_nyckelord',
                    'signal' => 'motstridig',
                    'detalj' => implode(',', array_keys($delarTopp)),
                ],
            ];
        }

        // Ren nyckelordsträff: konfidens UNDER tröskel → 'oklassad', aldrig auto.
        return $this->traff($topp, self::KONF_LAG, 'd_nyckelord', 'traffar=' . $toppPoang);
    }

    // ================================================================== //
    //  Cross-cutting flaggor — beräknas PARALLELLT, oberoende av primärkategorin
    // ================================================================== //

    /**
     * Beräkna SET:et av cross-cutting flaggor. Strukturerade SDK-fält först
     * (auktoritativa), därefter konservativa nyckelords-heuristiker. Flaggorna är
     * ortogonala mot primärkategorin: en kat-7-månadsrapport som bär akut_fara
     * dubbelmärks (kat 7 + flagga), ingen kategori-kombinatorik.
     *
     * @param array<string,mixed> $rad
     * @return list<string>
     */
    private function beraknaFlaggor(array $rad): array {
        $flaggor = [];
        $sdk = is_array($rad['sdkFields'] ?? $rad['itsl'] ?? null) ? ($rad['sdkFields'] ?? $rad['itsl']) : [];
        $text = strtolower($this->concatTextFields($rad));

        // skyddade_pu — behörighets-gate. Strukturerat fält är auktoritativt.
        if ($this->sdkBool($sdk, ['skyddade_personuppgifter', 'skyddadePu', 'protectedIdentity', 'sekretessmarkering'])
            || $this->textHar($text, ['skyddad identitet', 'skyddade personuppgifter', 'sekretessmarkering', 'kvarskrivning'])) {
            $flaggor[] = self::FLAG_SKYDDADE_PU;
        }

        // akut_fara — routing-override → jour. Bredast: hellre falskt positivt.
        if ($this->sdkBool($sdk, ['akut', 'akut_fara', 'jour', 'emergency'])
            || $this->textHar($text, ['akut fara', 'omedelbar fara', 'livsfara', 'akut hot', 'självmord', 'suicid', 'överdos', 'akut placering'])) {
            $flaggor[] = self::FLAG_AKUT_FARA;
        }

        // barn_berörs — barnskyddsspår + BBIC; påverkar facksystem-modul.
        if ($this->sdkBool($sdk, ['barn_berors', 'barnBerors', 'childInvolved'])
            || (isset($rad['barnRef']) && (string)$rad['barnRef'] !== '')
            || $this->textHar($text, ['barnet', 'minderårig', 'placerade barnet', 'eleven', 'förskolebarn'])) {
            $flaggor[] = self::FLAG_BARN_BEROORS;
        }

        // våld_hot — höjer prioritet; ev. säkerhetsrutin.
        if ($this->sdkBool($sdk, ['vald', 'vald_hot', 'violence', 'threat'])
            || $this->textHar($text, ['våld', 'misshandel', 'hot om', 'hotfull', 'våld i nära'])) {
            $flaggor[] = self::FLAG_VALD_HOT;
        }

        // lagöverträdelse — brott/tvång indikerat.
        if ($this->sdkBool($sdk, ['brott', 'lagovertradelse', 'tvang'])
            || $this->textHar($text, ['brott', 'polisanmälan', 'tvångsvård', 'lvu', 'lvm', 'omhändertagande'])) {
            $flaggor[] = self::FLAG_LAGOVERTRADELSE;
        }

        // rättslig_frist — lagstadgad frist (kat 6/8).
        if ($this->sdkBool($sdk, ['domstolsfrist', 'rattslig_frist', 'frist_kritisk'])
            || $this->textHar($text, ['domstolsfrist', 'inställelse', 'yttrande senast', 'svarstid', 'överklagandefrist'])) {
            $flaggor[] = self::FLAG_RATTSLIG_FRIST;
        }

        // personuppgiftsincident — PUB/felkoppling signalerad.
        if ($this->sdkBool($sdk, ['pu_incident', 'personuppgiftsincident', 'dataIncident'])
            || $this->textHar($text, ['personuppgiftsincident', 'felaktig mottagare', 'av misstag skickat', 'läckt'])) {
            $flaggor[] = self::FLAG_PU_INCIDENT;
        }

        // felmottaget — fel mottagare/enhet.
        if ($this->sdkBool($sdk, ['felmottaget', 'fel_mottagare', 'misdelivered'])
            || $this->textHar($text, ['fel mottagare', 'inte rätt enhet', 'vidarebefordra till', 'hör inte hit'])) {
            $flaggor[] = self::FLAG_FELMOTTAGET;
        }

        // Stabil ordning, inga dubbletter.
        return array_values(array_unique($flaggor));
    }

    // ================================================================== //
    //  Konfig-hooks (server-side policy / datadrivet per kund)
    // ================================================================== //

    /**
     * Klassnings-tröskeln (server-side policy, per kund). Klamras till [0,1];
     * en ogiltig/saknad konfig faller tillbaka på den höga default-tröskeln
     * (fail mot människa), aldrig en lös gräns.
     */
    private function troskel(): float {
        try {
            $raw = $this->appConfig->getAppValueString(self::CONF_KEY_TROSKEL, '');
        } catch (\Throwable $e) {
            $this->logger->warning('InnehallsKlass: kunde inte läsa tröskel-konfig, använder default', [
                'app' => 'hubs_arende',
                'exception' => $e,
            ]);
            return self::TROSKEL_DEFAULT;
        }
        if ($raw === '' || !is_numeric($raw)) {
            return self::TROSKEL_DEFAULT;
        }
        $v = (float)$raw;
        if ($v < 0.0) {
            return 0.0;
        }
        if ($v > 1.0) {
            return 1.0;
        }
        return $v;
    }

    /**
     * Kundspecifikt organisationsregister (TODO[konfig]-hook, datadrivet per kund,
     * à la DIGG-synk). JSON-objekt { "<avsandar-monster>": "<kategori>" }. Tomt
     * eller ogiltigt → inbyggd konservativ AVSANDARTYP_KAT-fallback används.
     *
     * @return array<string,string>
     */
    private function orgRegister(): array {
        try {
            $raw = $this->appConfig->getAppValueString(self::CONF_KEY_ORG_REGISTER, '');
        } catch (\Throwable $e) {
            $this->logger->warning('InnehallsKlass: kunde inte läsa org-register-konfig', [
                'app' => 'hubs_arende',
                'exception' => $e,
            ]);
            return [];
        }
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $k => $v) {
            if (is_string($k) && is_string($v) && $v !== '') {
                $out[strtolower(trim($k))] = $v;
            }
        }
        return $out;
    }

    // ================================================================== //
    //  Interna hjälpare
    // ================================================================== //

    /**
     * @return array{kat: string, konfidens: float, varfor: array{lager: string, signal: string, detalj?: string}}
     */
    private function traff(string $kat, float $konfidens, string $lager, string $detalj): array {
        return [
            'kat' => $kat,
            'konfidens' => $konfidens,
            'varfor' => ['lager' => $lager, 'signal' => $kat, 'detalj' => $detalj],
        ];
    }

    private function arGiltigKat(string $kat): bool {
        return in_array($kat, [
            self::KAT_OROSANMALAN, self::KAT_ANSOKAN_BISTAND, self::KAT_EKONOMI,
            self::KAT_KOMPLETTERING, self::KAT_VARD_SAMVERKAN, self::KAT_RATTSLIGT_TVANG,
            self::KAT_VERKSTALLIGHET, self::KAT_FAMILJERATT,
        ], true);
    }

    /**
     * Resolve avsändartyp: explicit strukturerat fält först, annars härled ur
     * avsändar-adressen mot de inbyggda mönstren (konservativt substring-test).
     *
     * @param array<string,mixed> $rad
     */
    private function resolveAvsandartyp(array $rad): string {
        $explicit = strtolower(trim((string)(
            $rad['avsandartyp'] ?? $rad['orgTyp'] ?? $rad['senderType'] ?? ''
        )));
        if ($explicit !== '') {
            return $explicit;
        }

        $avsandare = strtolower((string)(
            $rad['from'] ?? $rad['fromEmail'] ?? $rad['avsandare'] ?? ''
        ));
        if ($avsandare === '') {
            return '';
        }
        // Härled typ ur adressen mot de kända nyckelmönstren (svagare än explicit).
        foreach (array_keys(self::AVSANDARTYP_KAT) as $monster) {
            if (str_contains($avsandare, $monster)) {
                return $monster;
            }
        }
        return '';
    }

    /**
     * @param array<string,mixed> $rad
     */
    private function harFamiljerattIndikation(array $rad): bool {
        $text = strtolower($this->concatTextFields($rad));
        return $this->textHar($text, ['vårdnad', 'umgänge', 'familjerätt', 'boendeutredning', 'vårdnadstvist']);
    }

    /**
     * @param array<string,mixed> $rad
     */
    private function harBarnIndikation(array $rad): bool {
        if (isset($rad['barnRef']) && (string)$rad['barnRef'] !== '') {
            return true;
        }
        $text = strtolower($this->concatTextFields($rad));
        return $this->textHar($text, ['barn', 'elev', 'minderårig', 'förskola']);
    }

    /**
     * @param mixed $sdk
     * @param list<string> $keys
     */
    private function sdkBool(mixed $sdk, array $keys): bool {
        if (!is_array($sdk)) {
            return false;
        }
        foreach ($keys as $k) {
            $v = $sdk[$k] ?? null;
            if ($v === true || $v === 1 || $v === '1' || (is_string($v) && strtolower($v) === 'true')) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<string> $needles
     */
    private function textHar(string $haystack, array $needles): bool {
        if ($haystack === '') {
            return false;
        }
        foreach ($needles as $n) {
            if (str_contains($haystack, $n)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Slå ihop de människo-läsbara textfälten för svag nyckelords-/flagg-matchning.
     *
     * @param array<string,mixed> $rad
     */
    private function concatTextFields(array $rad): string {
        $parts = [];
        foreach (['subject', 'amne', 'from', 'fromEmail', 'avsandare', 'to', 'toEmail',
                  'preview', 'body', 'innehall', 'text'] as $key) {
            $val = $rad[$key] ?? null;
            if (is_string($val) && $val !== '') {
                $parts[] = $val;
            }
        }
        return implode(' ', $parts);
    }
}
