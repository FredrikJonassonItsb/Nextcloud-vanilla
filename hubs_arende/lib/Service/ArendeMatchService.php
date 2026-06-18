<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Service;

use OCA\HubsArende\Db\Arende;
use OCA\HubsArende\Db\ArendeMapper;
use OCP\AppFramework\Services\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Matchningskaskaden — sätter `arendekoppling` (nytt|hor_till|ej_kopplat) på ett
 * inflöde genom DETERMINISTISK matchning FÖRST, med en konfidenspoäng.
 *
 * Källa: HUBS-INTERNALS-ARENDEMOTOR.md §2.3 (kaskaden + tröskel) och §2.4
 * (beslutslogik-tabellen signal→konfidens→utfall), korsläst mot
 * KOMMUNROLLER-SOR-INTEGRATIONER.md (`partsModell`/`joinNyckel` §2.7; ROLL 1 §G:
 * anonymitetskravet TF 2:18 stänger AV matchningskaskadens SSN/orgId-steg).
 *
 * KASKADEN (fallande styrka, deterministisk):
 *   1. Explicit `case:{hubsCaseId}`-tagg / dnr i meddelandet → EXAKT (konfidens 1.0).
 *      Joinnyckeln bärs redan av objektet (ItslMessageTag / ämnesrad).
 *   2. `conversationId`-träff mot registret ({@see ArendeMapper::findByConversationId})
 *      → HÖG. Trådankaret är provenans-säkrat, så detta är auto-kopplingsbart.
 *   3. Avsändar-SSN / orgId + funktionsadress mot ärendepart → MEDEL (svagare,
 *      heuristiskt). TODO[register]-hook; FAIL-CLOSED bakom en positiv allow-grind
 *      ({@see partStegTillatet}) — steget körs ENDAST vid explicit allow-partsModell
 *      OCH person-joinNyckel; saknad/okänd signal → steget hoppas helt över
 *      (TF 2:18: sökandens identitet röjs aldrig på spekulation).
 *
 * UTFALL mot SERVER-SIDE tröskel ({@see IAppConfig} `hubs_arende.match.troskel`,
 * default 0.9 — GAP-060: tröskeln är granskad server-policy, inte klientlogik):
 *   - konfidens ≥ tröskel → `hor_till`, AUTO-kopplad (men bilagan speglas INTE
 *     automatiskt — bilaga speglas först vid bekräftad koppling, GAP-043).
 *   - konfidens < tröskel MEN kandidat finns → `arendekoppling=ej_kopplat` med
 *     status `foreslagen` (kandidatRef satt; människa bekräftar; bilaga vid
 *     bekräftelse).
 *   - ingen kandidat → `nytt` (om inflödet är en ny aktualisering) eller
 *     `ej_kopplat` (löst inflöde). DEFAULT vid otillräcklig signal = `ej_kopplat`.
 *
 * GRUNDREGEL (§3.2-A steg 4): "bättre obesvarat än feltaggat; felkoppling är en
 * sekretessincident." Vi auto-kopplar ALDRIG tyst över en sekretessgräns — fail
 * mot människa. LLM-lagret är INTE här (människo-bekräftat assist är en separat,
 * senare fas — GAP-052/060). Inga externa anrop mockas: `conversationId`-steget
 * går mot {@see ArendeMapper}; SSN/orgId-steget är en TODO[register]-hook med en
 * lokal konfidens-heuristik tills part-registret är wirat.
 */
class ArendeMatchService {
    // --- arendekoppling-utfall (Axel C, §2.1) ------------------------------ //
    public const KOPPLING_NYTT = 'nytt';
    public const KOPPLING_HOR_TILL = 'hor_till';
    public const KOPPLING_EJ_KOPPLAT = 'ej_kopplat';

    // --- kopplingsstatus (en svag kandidat under tröskel) ------------------ //
    /** Auto-kopplad: konfidens ≥ tröskel, hor_till. */
    public const STATUS_AUTO = 'auto';
    /** Föreslagen: kandidat under tröskel — människa måste bekräfta. */
    public const STATUS_FORESLAGEN = 'foreslagen';
    /** Ingen koppling — nytt eller ej_kopplat. */
    public const STATUS_INGEN = 'ingen';

    // --- varför-koder (stabila, maskinläsbara; bär kaskad-steget) ---------- //
    public const VARFOR_CASE_TAGG = 'explicit_case_tagg';
    public const VARFOR_DNR = 'explicit_dnr';
    public const VARFOR_CONVERSATION = 'conversation_id_traff';
    public const VARFOR_PART = 'avsandare_part_heuristik';
    public const VARFOR_INGEN_NY = 'ingen_traff_nytt';
    public const VARFOR_INGEN_LOST = 'ingen_traff_ej_kopplat';

    // --- konfidens-nivåer per kaskadsteg (§2.4) ---------------------------- //
    /** Steg 1: explicit joinnyckel bärs redan av objektet → exakt. */
    public const KONF_EXAKT = 1.0;
    /** Steg 2: conversationId-trådankaret är provenans-säkrat → hög. */
    public const KONF_CONVERSATION = 0.95;
    /**
     * Steg 3-tak: avsändar-part-heuristiken når MEDEL men ALDRIG tröskel av sig
     * själv — den får aldrig ensam auto-koppla över sekretessgräns (GAP-060).
     * Den faktiska poängen beräknas av heuristiken under detta tak.
     */
    public const KONF_PART_TAK = 0.7;
    public const KONF_INGEN = 0.0;

    /** AppConfig-nyckel för server-side tröskeln (granskad per-kund-policy). */
    public const CONFIG_KEY_TROSKEL = 'match.troskel';
    /** Default-tröskel (GAP-060: 0.9 — auto-koppling kräver mycket hög signal). */
    public const DEFAULT_TROSKEL = 0.9;

    /**
     * partsModell-värden där SSN/orgId-steget (3) är EXPLICIT TILLÅTET — joinnyckeln
     * ÄR en personidentitet och identiteten är en legitim partsuppgift (ingen
     * anonymitet/sekretess på sökanden). Detta är en POSITIV ALLOW-lista: grinden
     * är fail-closed, så ENDAST dessa partsmodeller släpper igenom SSN-steget.
     * Allt annat (saknad/okänd/tom/anonym/objekt) → steget AVSTÄNGT (default =
     * hoppa, fail mot människa, default = ej-röjt; TF 2:18).
     *
     * - 'partsidentifierat' : joinNyckel = ssn/personnummer; sökanden är en känd,
     *   icke-anonym part i ärendet (t.ex. egen ansökan/överklagan i eget namn).
     * - 'namngiven_sokande' : likställd; sökanden uppträder under egen identitet
     *   och har inte påkallat anonymitetsskydd (TF 2:18 ej tillämpligt).
     *
     * Listan hålls medvetet snäv: nya allow-värden läggs till först när server-side
     * härledning bekräftat att de aldrig kan bära en anonym/sekretesskyddad sökande.
     *
     * @var list<string>
     */
    private const PARTSMODELL_MED_SSN_MATCH = [
        'partsidentifierat',
        'namngiven_sokande',
        'namngiven_sökande',
    ];

    /**
     * joinNyckel-värden som faktiskt pekar på en personidentitet. SSN-steget får
     * köras ENDAST när joinNyckel ∈ denna mängd (utöver partsModell-allow ovan).
     * En joinNyckel utanför mängden (objektRef/upphandlingsRef/saknad/okänd) →
     * AVSTÄNGT.
     *
     * @var list<string>
     */
    private const JOINNYCKEL_PERSONIDENTITET = [
        'ssn',
        'personnummer',
    ];

    public function __construct(
        private ArendeMapper $arendeMapper,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Kör matchningskaskaden mot ett inflöde och returnera kopplingsutfallet.
     *
     * @param array<string,mixed> $rad Inflödesraden. Lästa fält (alla valfria):
     *        conversationId, caseTag/caseTags/tags (case:{id}-bärare), dnr/subject/
     *        amne/body (dnr-mönster), from/fromEmail/avsandare, fromSsn/fromOrgId,
     *        to/toEmail (funktionsadress), partsModell/joinNyckel, arInflodeNytt.
     *
     * @return array{
     *     arendekoppling: self::KOPPLING_*,
     *     konfidens: float,
     *     status: self::STATUS_*,
     *     hubsCaseId?: string,
     *     kandidatRef?: string,
     *     varfor: string,
     *     speglaBilaga: bool,
     *     troskel: float
     * } `hubsCaseId` sätts vid auto-koppling (≥tröskel). `kandidatRef` sätts vid
     *   `foreslagen` (kandidatens hubsCaseId, för människans bekräftelse).
     *   `speglaBilaga=true` ENDAST vid exakt match (steg 1, redan kopplat); annars
     *   speglas bilagan först vid bekräftad koppling (GAP-043).
     */
    public function match(array $rad): array {
        $troskel = $this->troskel();

        // --- Steg 1: explicit joinnyckel i meddelandet (EXAKT) ------------- //
        // case:{hubsCaseId}-tagg eller dnr bärs redan av objektet. Detta är den
        // enda vägen där bilagan får speglas automatiskt (redan kopplat).
        $explicit = $this->matchaExplicit($rad, $troskel);
        if ($explicit !== null) {
            return $explicit;
        }

        // --- Steg 2: conversationId-träff mot registret (HÖG) -------------- //
        $conversation = $this->matchaConversation($rad, $troskel);
        if ($conversation !== null) {
            return $conversation;
        }

        // --- Steg 3: avsändar-SSN/orgId + funktionsadress (MEDEL, svag) ---- //
        // joinNyckel-betingat avstängbart för objektärenden/anonyma (TF 2:18).
        $part = $this->matchaPart($rad, $troskel);
        if ($part !== null) {
            return $part;
        }

        // --- Ingen träff: nytt (ny) eller ej_kopplat (löst inflöde) -------- //
        return $this->ingenTraff($rad, $troskel);
    }

    /**
     * Server-side tröskel (granskad policy, per-kund via IAppConfig). Klampas till
     * (0,1]; ett orimligt värde (≤0 eller >1) faller tillbaka till defaulten så att
     * en felkonfiguration aldrig sänker grinden till "auto-koppla allt".
     */
    public function troskel(): float {
        $v = $this->appConfig->getAppValueFloat(self::CONFIG_KEY_TROSKEL, self::DEFAULT_TROSKEL);
        if ($v <= 0.0 || $v > 1.0) {
            $this->logger->warning('hubs_arende: ogiltig match.troskel, faller tillbaka till default', [
                'app' => 'hubs_arende',
                'konfigurerat' => $v,
                'default' => self::DEFAULT_TROSKEL,
            ]);
            return self::DEFAULT_TROSKEL;
        }
        return $v;
    }

    // ------------------------------------------------------------------ //
    // Kaskadsteg
    // ------------------------------------------------------------------ //

    /**
     * Steg 1 — explicit `case:{hubsCaseId}`-tagg eller dnr i meddelandet.
     *
     * Joinnyckeln bärs redan av objektet (ItslMessageTag / ämnesrad). Ett
     * registeruppslag verifierar att kandidaten faktiskt existerar; gör den inte
     * det degraderar vi till de svagare stegen (taggen kan vara stale).
     *
     * @param array<string,mixed> $rad
     * @return array<string,mixed>|null
     */
    private function matchaExplicit(array $rad, float $troskel): ?array {
        // 1a: case:{hubsCaseId}-tagg redan satt på meddelandet.
        $taggadCaseId = $this->extractCaseTag($rad);
        if ($taggadCaseId !== null) {
            $arende = $this->slaUppCaseId($taggadCaseId);
            if ($arende !== null) {
                return $this->traff(
                    self::KOPPLING_HOR_TILL,
                    self::KONF_EXAKT,
                    self::STATUS_AUTO,
                    $arende->getHubsCaseId(),
                    self::VARFOR_CASE_TAGG,
                    // Redan kopplat objekt → bilagan är redan i akten.
                    speglaBilaga: true,
                    troskel: $troskel,
                );
            }
        }

        // 1b: dnr i ämne/ärendemening matchar hubs_arende_case.dnr.
        $dnr = $this->extractDnr($rad);
        if ($dnr !== null) {
            $arende = $this->slaUppDnr($dnr);
            if ($arende !== null) {
                return $this->traff(
                    self::KOPPLING_HOR_TILL,
                    self::KONF_EXAKT,
                    self::STATUS_AUTO,
                    $arende->getHubsCaseId(),
                    self::VARFOR_DNR,
                    // dnr-match är exakt men bilaga speglas vid bekräftelse
                    // (dnr i fritext kan vara felskrivet/citerat) — §2.4 rad 2.
                    speglaBilaga: false,
                    troskel: $troskel,
                );
            }
        }

        return null;
    }

    /**
     * Steg 2 — conversationId-träff mot registret (HÖG).
     *
     * Trådankaret ({@see ArendeMapper::findByConversationId}) är provenans-säkrat,
     * så en träff är auto-kopplingsbar när konfidensen (0.95) ≥ tröskel. Detta är
     * det enda steget som gör ett RIKTIGT externt uppslag (mot vår egen DB) — inget
     * mockas.
     *
     * @param array<string,mixed> $rad
     * @return array<string,mixed>|null
     */
    private function matchaConversation(array $rad, float $troskel): ?array {
        $conversationId = $this->str($rad, ['conversationId', 'conversation_id']);
        if ($conversationId === null) {
            return null;
        }

        try {
            $arende = $this->arendeMapper->findByConversationId($conversationId);
        } catch (\Throwable $e) {
            // En fallerande DB-koll får aldrig auto-koppla; degradera till svagare
            // steg/ingen-träff (fail mot människa, aldrig tyst auto).
            $this->logger->warning('hubs_arende: conversationId-uppslag fallerade', [
                'app' => 'hubs_arende',
                'conversationId' => $conversationId,
                'exception' => $e,
            ]);
            return null;
        }

        if ($arende === null) {
            return null;
        }

        return $this->utfallFranKonfidens(
            self::KONF_CONVERSATION,
            $arende->getHubsCaseId(),
            self::VARFOR_CONVERSATION,
            $troskel,
        );
    }

    /**
     * Steg 3 — avsändar-SSN/orgId + funktionsadress mot ärendepart (MEDEL, svag).
     *
     * joinNyckel-betingat AVSTÄNGBART: för objektärenden (joinNyckel ≠ ssn) och
     * anonyma begäranden (TF 2:18) hoppas steget HELT över — då finns ingen
     * personidentitet att matcha mot och en "träff" vore en sekretessincident.
     *
     * TODO[register]: det riktiga partsregister-uppslaget (SITHS/LOA3-stärkt
     * avsändar-org mot ärendets parter) saknas tills part-registret är wirat. Tills
     * dess kör en lokal konfidens-heuristik som ALDRIG når tröskeln av sig själv
     * (taket {@see KONF_PART_TAK} < default-tröskel), så utfallet blir på sin höjd
     * `foreslagen` — människa bekräftar. Ingen mockning av externt anrop; kroppen
     * är en deterministisk heuristik + en tydlig hook-punkt.
     *
     * @param array<string,mixed> $rad
     * @return array<string,mixed>|null null = steget gav ingen kandidat (eller var
     *         avstängt); kaskaden faller vidare till ingen-träff.
     */
    private function matchaPart(array $rad, float $troskel): ?array {
        if (!$this->partStegTillatet($rad)) {
            // Fail-closed default: utan POSITIV allow-signal hoppas SSN/orgId-steget
            // helt över (TF 2:18 — sökandens identitet röjs aldrig på spekulation).
            $this->logger->debug('hubs_arende: SSN/orgId-steg avstängt (ingen positiv allow-signal)', [
                'app' => 'hubs_arende',
                'partsModell' => $this->str($rad, ['partsModell', 'partsmodell']),
                'joinNyckel' => $this->str($rad, ['joinNyckel', 'joinnyckel']),
            ]);
            return null;
        }

        $ssn = $this->str($rad, ['fromSsn', 'avsandareSsn', 'ssn', 'personnummer']);
        $orgId = $this->str($rad, ['fromOrgId', 'avsandareOrgId', 'orgId', 'organisationsnummer']);
        $funktionsadress = $this->str($rad, ['to', 'toEmail', 'funktionsadress']);

        if (($ssn === null && $orgId === null) || $funktionsadress === null) {
            // Otillräcklig signal för partsmatch — låt kaskaden falla vidare.
            return null;
        }

        // TODO[register]: ersätt heuristiken nedan med ett riktigt parts-uppslag:
        //   findKandidatByPart($enhet=funktionsadress, $ssn|$orgId) mot ärendets
        //   parter (org-cert/LOA3). Returnera kandidatens hubsCaseId + en träff-
        //   styrka. Tills dess: ingen kandidat-källa → ingen partsträff.
        $kandidat = $this->registerPartHook($ssn, $orgId, $funktionsadress);
        if ($kandidat === null) {
            return null;
        }

        // Konfidens-heuristik (lokal, deterministisk): SSN-match är starkare än
        // orgId-match; kandidatens egen träffstyrka modulerar. Allt under part-
        // taket, så detta steg ensamt aldrig når tröskeln → max `foreslagen`.
        $konfidens = $this->partKonfidens($ssn !== null, $orgId !== null, (float)($kandidat['styrka'] ?? 0.5));

        return $this->utfallFranKonfidens(
            $konfidens,
            (string)$kandidat['hubsCaseId'],
            self::VARFOR_PART,
            $troskel,
        );
    }

    /**
     * Ingen träff i någon kaskad-gren. Default vid otillräcklig signal är
     * `ej_kopplat` — fail mot människa, aldrig tyst auto över sekretessgräns.
     *
     * Skillnaden nytt vs ej_kopplat är en routnings-hint (§2.4 rad 6/7), inte en
     * styrkebedömning: ett inflöde som anmälaren markerat som ny aktualisering →
     * `nytt` (band 1a "Att ta emot"); annars löst inflöde → `ej_kopplat` (band 1c).
     *
     * @param array<string,mixed> $rad
     * @return array<string,mixed>
     */
    private function ingenTraff(array $rad, float $troskel): array {
        $arNytt = $this->bool($rad, ['arInflodeNytt', 'nyAktualisering', 'isNewConversation']);

        $koppling = $arNytt ? self::KOPPLING_NYTT : self::KOPPLING_EJ_KOPPLAT;
        $varfor = $arNytt ? self::VARFOR_INGEN_NY : self::VARFOR_INGEN_LOST;

        return $this->traff(
            $koppling,
            self::KONF_INGEN,
            self::STATUS_INGEN,
            null,
            $varfor,
            speglaBilaga: false,
            troskel: $troskel,
        );
    }

    // ------------------------------------------------------------------ //
    // Utfalls-byggare och hjälpare
    // ------------------------------------------------------------------ //

    /**
     * Avgör utfall för en kandidat utifrån dess konfidens mot tröskeln:
     *   ≥ tröskel → hor_till AUTO (men bilaga ej speglad — GAP-043);
     *   <  tröskel → ej_kopplat + status foreslagen (kandidatRef satt, människa
     *               bekräftar; bilaga vid bekräftelse).
     *
     * @return array<string,mixed>
     */
    private function utfallFranKonfidens(
        float $konfidens,
        string $kandidatCaseId,
        string $varfor,
        float $troskel,
    ): array {
        if ($konfidens >= $troskel) {
            return $this->traff(
                self::KOPPLING_HOR_TILL,
                $konfidens,
                self::STATUS_AUTO,
                $kandidatCaseId,
                $varfor,
                // Auto-kopplad MEN bilaga speglas ALDRIG automatiskt här —
                // endast vid bekräftad koppling (GAP-043). Exakt steg 1 är
                // undantaget (redan kopplat objekt).
                speglaBilaga: false,
                troskel: $troskel,
            );
        }

        // Under tröskel men kandidat finns → människa bekräftar.
        $resultat = $this->traff(
            self::KOPPLING_EJ_KOPPLAT,
            $konfidens,
            self::STATUS_FORESLAGEN,
            null,
            $varfor,
            speglaBilaga: false,
            troskel: $troskel,
        );
        // Kandidaten exponeras som FÖRSLAG (inte som satt hubsCaseId) så att
        // klienten kan rendera "Kopplad till …? bekräfta" utan att kopplingen
        // är gjord.
        $resultat['kandidatRef'] = $kandidatCaseId;
        return $resultat;
    }

    /**
     * Bygg ett kopplingsutfall. `hubsCaseId` sätts ENDAST när kopplingen faktiskt
     * är gjord (status=auto, hor_till) — aldrig för ett blott förslag.
     *
     * @return array<string,mixed>
     */
    private function traff(
        string $arendekoppling,
        float $konfidens,
        string $status,
        ?string $hubsCaseId,
        string $varfor,
        bool $speglaBilaga,
        float $troskel,
    ): array {
        $resultat = [
            'arendekoppling' => $arendekoppling,
            'konfidens' => round($konfidens, 4),
            'status' => $status,
            'varfor' => $varfor,
            'speglaBilaga' => $speglaBilaga,
            'troskel' => round($troskel, 4),
        ];
        if ($hubsCaseId !== null && $hubsCaseId !== '') {
            $resultat['hubsCaseId'] = $hubsCaseId;
        }
        return $resultat;
    }

    /**
     * Extrahera en `case:{hubsCaseId}`-tagg ur inflödet. Bärs som ItslMessageTag
     * imap_label; här accepteras både ett enskilt fält och en lista taggar.
     *
     * @param array<string,mixed> $rad
     */
    private function extractCaseTag(array $rad): ?string {
        $kandidater = [];
        foreach (['caseTag', 'case_tag'] as $key) {
            $v = $rad[$key] ?? null;
            if (is_string($v) && $v !== '') {
                $kandidater[] = $v;
            }
        }
        foreach (['caseTags', 'tags', 'imapLabels', 'labels'] as $key) {
            $v = $rad[$key] ?? null;
            if (is_array($v)) {
                foreach ($v as $tag) {
                    if (is_string($tag)) {
                        $kandidater[] = $tag;
                    }
                }
            }
        }

        foreach ($kandidater as $tag) {
            if (preg_match('/^case:([0-9a-fA-F-]{8,})$/', trim($tag), $m) === 1) {
                return $m[1];
            }
        }
        return null;
    }

    /**
     * Extrahera ett dnr ur strukturerat fält eller ämne/ärendemening. Konservativt
     * mönster (kommunal dnr t.ex. 'SN 2026-0142' / 'SN-2026-0142'); fler format
     * wiras vid behov.
     *
     * @param array<string,mixed> $rad
     */
    private function extractDnr(array $rad): ?string {
        // Explicit strukturerat dnr-fält först (starkast, ingen tolkning).
        $explicit = $this->str($rad, ['dnr', 'diarienummer']);
        if ($explicit !== null) {
            return $explicit;
        }

        $haystack = '';
        foreach (['subject', 'amne', 'arendemening', 'body', 'innehall', 'preview'] as $key) {
            $v = $rad[$key] ?? null;
            if (is_string($v) && $v !== '') {
                $haystack .= ' ' . $v;
            }
        }
        if ($haystack === '') {
            return null;
        }

        if (preg_match('/\b([A-ZÅÄÖ]{2,4})[\s-]?(\d{4})-(\d{3,5})\b/u', $haystack, $m) === 1) {
            return $m[1] . ' ' . $m[2] . '-' . $m[3];
        }
        return null;
    }

    /**
     * Är SSN/orgId-steget TILLÅTET för detta inflöde? FAIL-CLOSED: returnerar true
     * ENBART vid en POSITIV allow-signal, annars false (default = avstängt).
     *
     * Anonymitetsgrinden (TF 2:18 / OSL 26 kap) är vänd till fail-closed: avsaknad
     * av signal, okänd/tom partsModell, en joinNyckel som inte är en personidentitet,
     * eller minsta tecken på anonymitet/sekretess → steget hoppas över. Klient-
     * fälten får bara SKÄRPA skyddet (en anonymitets-flagga stänger av även med
     * allow-modell), aldrig lätta på det: ett positivt utfall kräver att BÅDA
     * server-grindade villkoren är uppfyllda OCH att ingen anonymitets-signal finns.
     *
     * Villkor för ALLOW (alla måste hålla):
     *   1. partsModell ∈ {@see PARTSMODELL_MED_SSN_MATCH} (explicit allow-lista), och
     *   2. joinNyckel ∈ {@see JOINNYCKEL_PERSONIDENTITET} (pekar på personidentitet),
     *   3. ingen anonymitets-/sekretess-signal är satt (skärper, kan bara neka).
     *
     * @param array<string,mixed> $rad
     */
    private function partStegTillatet(array $rad): bool {
        // Klientfält får bara SKÄRPA: en explicit anonymitets-/sekretess-signal
        // nekar oavsett allow-modell (TF 2:18 — never-de-anonymise).
        if ($this->bool($rad, ['anonym', 'anonymitetsskydd', 'sekretessSokande'])) {
            return false;
        }

        // Villkor 1: partsModell måste finnas OCH stå i den positiva allow-listan.
        // Saknad/tom/okänd partsModell → fail-closed (default = avstängt).
        $partsModell = strtolower((string)($this->str($rad, ['partsModell', 'partsmodell']) ?? ''));
        if ($partsModell === '' || !in_array($partsModell, self::PARTSMODELL_MED_SSN_MATCH, true)) {
            return false;
        }

        // Villkor 2: joinNyckel måste explicit peka på en personidentitet.
        // Saknad/tom/objektRef/upphandlingsRef → fail-closed.
        $joinNyckel = strtolower((string)($this->str($rad, ['joinNyckel', 'joinnyckel']) ?? ''));
        if (!in_array($joinNyckel, self::JOINNYCKEL_PERSONIDENTITET, true)) {
            return false;
        }

        // Båda server-grindade villkoren uppfyllda och ingen anonymitets-signal:
        // SSN/orgId-steget får köras (men når ändå aldrig tröskeln av sig själv —
        // KONF_PART_TAK < DEFAULT_TROSKEL → max `foreslagen`, aldrig tyst auto).
        return true;
    }

    /**
     * Lokal konfidens-heuristik för partssteget. Alltid under {@see KONF_PART_TAK}
     * så att steget ensamt aldrig når tröskeln (max `foreslagen`).
     */
    private function partKonfidens(bool $harSsn, bool $harOrgId, float $kandidatStyrka): float {
        // SSN-match är starkare än orgId-match.
        $bas = $harSsn ? 0.6 : ($harOrgId ? 0.45 : 0.3);
        $styrka = max(0.0, min(1.0, $kandidatStyrka));
        // Kandidatens egen träffstyrka modulerar, men taket håller.
        $konf = $bas * $styrka + $bas * 0.4;
        return min(self::KONF_PART_TAK, round($konf, 4));
    }

    /**
     * TODO[register]-hook: slå upp en ärendekandidat på (funktionsadress, ssn|orgId)
     * mot ärendets parter. Tills part-registret är wirat finns ingen kandidat-källa,
     * så detta returnerar null (ingen mockning av ett externt anrop). När registret
     * finns: returnera ['hubsCaseId' => string, 'styrka' => float(0..1)].
     *
     * @return array{hubsCaseId: string, styrka: float}|null
     */
    private function registerPartHook(?string $ssn, ?string $orgId, string $funktionsadress): ?array {
        // INTENTIONALLY no candidate until the parts register is wired. Returning
        // null keeps the cascade fail-closed (faller till ingen-träff/ej_kopplat).
        return null;
    }

    /**
     * Registeruppslag på hubsCaseId; null om saknas/fel (degradera, aldrig auto).
     */
    private function slaUppCaseId(string $hubsCaseId): ?Arende {
        try {
            return $this->arendeMapper->findByCaseId($hubsCaseId);
        } catch (\Throwable $e) {
            $this->logger->warning('hubs_arende: case:-tagg pekar på okänt/ofunnet ärende', [
                'app' => 'hubs_arende',
                'hubsCaseId' => $hubsCaseId,
                'exception' => $e,
            ]);
            return null;
        }
    }

    /**
     * Registeruppslag på dnr; null om saknas/fel.
     */
    private function slaUppDnr(string $dnr): ?Arende {
        try {
            return $this->arendeMapper->findByDnr($dnr);
        } catch (\Throwable $e) {
            $this->logger->warning('hubs_arende: dnr-uppslag fallerade', [
                'app' => 'hubs_arende',
                'dnr' => $dnr,
                'exception' => $e,
            ]);
            return null;
        }
    }

    /**
     * Läs första icke-tomma strängfältet ur raden under de givna nycklarna.
     *
     * @param array<string,mixed> $rad
     * @param list<string> $keys
     */
    private function str(array $rad, array $keys): ?string {
        foreach ($keys as $key) {
            $v = $rad[$key] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }
        return null;
    }

    /**
     * Läs ett booleskt fält (true/'1'/'true') ur raden under de givna nycklarna.
     *
     * @param array<string,mixed> $rad
     * @param list<string> $keys
     */
    private function bool(array $rad, array $keys): bool {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $rad)) {
                continue;
            }
            $v = $rad[$key];
            if ($v === true || $v === 1 || $v === '1' || $v === 'true') {
                return true;
            }
            if ($v === false || $v === 0 || $v === '0' || $v === 'false') {
                return false;
            }
        }
        return false;
    }
}
