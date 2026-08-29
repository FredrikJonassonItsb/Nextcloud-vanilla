<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Service\Brain;

use OCA\HubsArende\Db\Arende;
use OCA\HubsArende\Db\ArendeMapper;
use OCA\HubsArende\Db\Member;
use OCA\HubsArende\Db\MemberMapper;
use OCA\HubsArende\Db\Part;
use OCA\HubsArende\Db\PartMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * P-A1 — gateway-authz för brain-per-ärende (SPEC kap 5.2).
 *
 * Denna tjänst är hubs_arendes SERVER-sida av kontraktet mot brain-gw (Node): den
 * besvarar frågan "får uid X köra funktion F mot ärende C?" och returnerar ett
 * beslut i den slutna formen {allow, roll, skal, skydd}. brain-gw läser `allow`
 * (grinden) och `skal` (audit-reason_code, 1:1) och skärper utkastflöden på `skydd`.
 *
 * ── DESIGNPRINCIPER ────────────────────────────────────────────────────────
 *  - FAIL-CLOSED: {@see check()} kastar ALDRIG. Varje ohanterat fel fångas och
 *    mappas till allow=false / skal=deny_internal_error (SPEC 5.2.2 p.10). Alla
 *    okända funktioner/ärenden nekas — aldrig implicit allow.
 *  - INGEN SESSIONSKONTEXT: den prövade principalen är `uid`-ARGUMENTET, inte den
 *    inloggade användaren (anropet är server-till-server från brain-gw, utan
 *    NC-session). Därför kan H1-grinden i {@see ArendeService::assertEnhetAtkomst()}
 *    INTE återanvändas rakt av — den läser IUserSession (fel principal). Vi
 *    återanvänder i stället dess SEMANTIK (normaliseEnhet-matchning mot uid:ns
 *    grupper) men mot den explicita uid:n. Se {@see enhetMatch()}.
 *  - INGEN SIDOEFFEKT: ren mapper-läsning (SPEC 5.2.5). Ingen skrivning, ingen
 *    journalföring (authz-audit bor i gw.audit_log), ingen extern I/O. Aldrig PII
 *    eller ärendeinnehåll i loggar (endast koordinationsdata: uid utelämnas ur
 *    felmeddelanden — endast request-korrelation via LoggerInterface-kontexten).
 *
 * ── BESLUTSORDNING (SPEC 5.2.2, varje steg fail-closed) ────────────────────
 *   1. Funktionsvalidering (okänd ⇒ deny_okand_funktion; system_ingest via uid ⇒
 *      deny_system_ingest_uid).
 *   2. v2-aktivering: fn_draft_* / fn_avslutssyntes bakom eval-grinden
 *      (app-config `ork_fn_enabled`) ⇒ deny_fn_ej_aktiverad om ej aktiverad.
 *   3. Ärendeuppslag (saknas ⇒ deny_okant_arende).
 *   4. R0-karantän (commit_destination='karantan' ELLER retention_state='pausad')
 *      ⇒ deny_r0_karantan för ALLA funktioner, oberoende roll.
 *   5. Frysning (steg='avslutat' + klass=capture) ⇒ deny_fryst.
 *   6. Stegkontroll (fn×steg för de stegbundna draft-funktionerna) ⇒ deny_steg_sparr.
 *   7-8. Rollhärledning ur member-ledgern (GAP-057 handoff) + H1-enhetskontroll för
 *      mottagningskretsen.
 *   9. Roll×klass-matris (SPEC 5.2.2 + Node-kontraktets rollmappning).
 *
 * Beslutet {allow, roll, skal, skydd} är exakt det kontrakt brain-gw redan byggts
 * mot; formen får inte ändras.
 */
class AuthzService {
    // ── skäl-koder (sluten enum; gw mappar 1:1 till audit reason_code) ──────
    public const SKAL_MEDLEM_HANDLAGGARE = 'medlem_handlaggare';
    public const SKAL_MEDLEM_CO_HANDLAGGARE = 'medlem_co_handlaggare';
    public const SKAL_MEDLEM_OBSERVATOR = 'medlem_observator';
    public const SKAL_KRETS_OTILLDELAD = 'krets_otilldelad';
    public const SKAL_DENY_EJ_MEDLEM = 'deny_ej_medlem';
    public const SKAL_DENY_KRETS_TILLDELAD = 'deny_krets_tilldelad';
    public const SKAL_DENY_OBSERVATOR_SKRIVNING = 'deny_observator_skrivning';
    public const SKAL_DENY_KRETS_SKRIVNING = 'deny_krets_skrivning';
    public const SKAL_DENY_ENHET = 'deny_enhet';
    public const SKAL_DENY_R0_KARANTAN = 'deny_r0_karantan';
    public const SKAL_DENY_FRYST = 'deny_fryst';
    public const SKAL_DENY_OKANT_ARENDE = 'deny_okant_arende';
    public const SKAL_DENY_OKAND_FUNKTION = 'deny_okand_funktion';
    public const SKAL_DENY_SYSTEM_INGEST_UID = 'deny_system_ingest_uid';
    public const SKAL_DENY_STEG_SPARR = 'deny_steg_sparr';
    public const SKAL_DENY_FN_EJ_AKTIVERAD = 'deny_fn_ej_aktiverad';
    public const SKAL_DENY_INTERNAL_ERROR = 'deny_internal_error';

    // ── fn-klasser (gw-designens §3.2) ─────────────────────────────────────
    private const KLASS_ASK = 'ask';
    private const KLASS_READ_FULL = 'read_full';
    private const KLASS_CAPTURE = 'capture';
    private const KLASS_SYSTEM_INGEST = 'system_ingest';

    /**
     * funktion => fn-klass. SLUTEN LISTA = fail-closed mot okända funktioner
     * (SPEC 5.2.2 p.1; nya processfunktioner kräver spec-tillägg, aldrig implicit
     * allow). Superset av SPEC 5.2.3:s FUNKTIONER och Node-kontraktets funktion-enum
     * (som även bär fn_gallringsoversikt/fn_inflode_klassning/fn_draft_sammanstallning).
     * Alla fn_*-läsfunktioner bär klass read_full (SPEC 5.2.2: klassen ≥ verktygens).
     *
     * @var array<string,string>
     */
    private const FN_KLASS = [
        // Råa gw-klasser (kan komma direkt som funktion-värde).
        'ask' => self::KLASS_ASK,
        'read_full' => self::KLASS_READ_FULL,
        'capture' => self::KLASS_CAPTURE,
        'system_ingest' => self::KLASS_SYSTEM_INGEST,
        // v1-läsfunktioner (alltid aktiva).
        'fn_briefing' => self::KLASS_READ_FULL,
        'fn_lage' => self::KLASS_READ_FULL,
        'fn_gap_check' => self::KLASS_READ_FULL,
        'fn_frist_vakt' => self::KLASS_READ_FULL,
        'fn_motstridighet' => self::KLASS_READ_FULL,
        'fn_gallringsoversikt' => self::KLASS_READ_FULL,
        'fn_inflode_klassning' => self::KLASS_READ_FULL,
        // v2-utkastfunktioner (bakom eval-grind, stegbundna — se DRAFT_* nedan).
        'fn_draft_skyddsbedomning' => self::KLASS_READ_FULL,
        'fn_draft_kommunicering' => self::KLASS_READ_FULL,
        'fn_draft_journal' => self::KLASS_READ_FULL,
        'fn_draft_beslutsformulering' => self::KLASS_READ_FULL,
        'fn_avslutssyntes' => self::KLASS_READ_FULL,
        'fn_draft_sammanstallning' => self::KLASS_READ_FULL,
    ];

    /**
     * v2-utkastfunktioner: bakom eval-grinden (app-config `ork_fn_enabled`) och
     * begränsade till handläggare|co-handläggare (observatör/krets nekas skrivning).
     *
     * @var list<string>
     */
    private const DRAFT_FUNKTIONER = [
        'fn_draft_skyddsbedomning',
        'fn_draft_kommunicering',
        'fn_draft_journal',
        'fn_draft_beslutsformulering',
        'fn_avslutssyntes',
        'fn_draft_sammanstallning',
    ];

    /**
     * fn×steg-allowlist (SPEC 5.2.2 p.6 — realiserar deny_steg_sparr). En stegbunden
     * draft-funktion tillåts ENDAST i uppräknade steg (default = deny). Mappat mot de
     * KANONISKA stegvärdena i ArendeLifecycleService (inflode | forhandsbedomning |
     * utredning | beslut | uppfoljning | avslutat) — SPEC:s icke-kanoniska stegnamn
     * (kommunicering/delgivning/avslut) är moment/artefakter INOM steg, inte egna steg,
     * och har översatts till närmaste kanoniska steg (se oppnaFragor för avstämning).
     * Funktioner utan post här (fn_draft_journal, fn_draft_sammanstallning) är EJ
     * stegbundna (tillåtna i alla live-steg).
     *
     * @var array<string,list<string>>
     */
    private const STEG_ALLOWLIST = [
        'fn_draft_skyddsbedomning' => ['forhandsbedomning'],
        'fn_draft_kommunicering' => ['utredning', 'beslut'],
        'fn_draft_beslutsformulering' => ['forhandsbedomning', 'beslut'],
        'fn_avslutssyntes' => ['beslut', 'uppfoljning', 'avslutat'],
    ];

    /** commit_destination-värdet som betyder R0-karantän (SakerhetsskyddGrind auktoritativt). */
    private const DEST_KARANTAN = 'karantan';
    /** retention_state-värdet som speglar en pausad (karantänsatt) livscykel. */
    private const RETENTION_PAUSAD = 'pausad';
    /** Terminalt steg — capture nekas här (frysning, SPEC 5.2.2 p.5). */
    private const STEG_AVSLUTAT = 'avslutat';

    public function __construct(
        private readonly ArendeMapper $arendeMapper,
        private readonly MemberMapper $memberMapper,
        private readonly PartMapper $partMapper,
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
        private readonly LoggerInterface $logger,
        // TRAILING OPTIONAL (autowirad): eval-grinden för v2-funktioner. Null ⇒ endast
        // v1-funktioner är aktiva (koddefault, SPEC 5.2.2 p.2).
        private readonly ?IAppConfig $appConfig = null,
    ) {
    }

    /**
     * Fatta ett authz-beslut. KASTAR ALDRIG — varje fel blir en fail-closed deny.
     *
     * @param string $uid        Principalen som prövas (INTE sessionsanvändaren).
     * @param string $hubsCaseId Ärendets kanoniska nyckel.
     * @param string $funktion   En av FN_KLASS-nycklarna (annars deny_okand_funktion).
     *
     * @return array{allow:bool, roll:?string, skal:string, skydd:bool}
     */
    public function check(string $uid, string $hubsCaseId, string $funktion): array {
        try {
            // 1. Funktionsvalidering (sluten lista).
            $klass = self::FN_KLASS[$funktion] ?? null;
            if ($klass === null) {
                return $this->deny(null, self::SKAL_DENY_OKAND_FUNKTION, false);
            }
            // system_ingest auktoriseras som TJÄNST (cert-scope i gw), aldrig via uid.
            if ($klass === self::KLASS_SYSTEM_INGEST) {
                return $this->deny(null, self::SKAL_DENY_SYSTEM_INGEST_UID, false);
            }

            // 2. v2-aktivering: utkastfunktioner bakom eval-grinden.
            if ($this->arDraft($funktion) && !$this->funktionAktiverad($funktion)) {
                return $this->deny(null, self::SKAL_DENY_FN_EJ_AKTIVERAD, false);
            }

            // 3. Ärendeuppslag (existens läcker endast till gw = betrodd infra).
            try {
                $arende = $this->arendeMapper->findByCaseId($hubsCaseId);
            } catch (DoesNotExistException) {
                return $this->deny(null, self::SKAL_DENY_OKANT_ARENDE, false);
            }

            // skydd-flaggan (best-effort, ingen PII i svaret — endast bool).
            $skydd = $this->harSkyddadPart($hubsCaseId);

            // 4. R0-karantän ⇒ ovillkorlig deny för ALLA funktioner (oberoende roll).
            if ($arende->getCommitDestination() === self::DEST_KARANTAN
                || $arende->getRetentionState() === self::RETENTION_PAUSAD) {
                return $this->deny(null, self::SKAL_DENY_R0_KARANTAN, $skydd);
            }

            // 5. Frysning: avslutat ärende ⇒ läs tillåten, capture nekas.
            if ($arende->getSteg() === self::STEG_AVSLUTAT && $klass === self::KLASS_CAPTURE) {
                return $this->deny(null, self::SKAL_DENY_FRYST, $skydd);
            }

            // 6. Stegkontroll för stegbundna draft-funktioner (default = deny).
            if ($this->arDraft($funktion) && isset(self::STEG_ALLOWLIST[$funktion])
                && !in_array($arende->getSteg(), self::STEG_ALLOWLIST[$funktion], true)) {
                return $this->deny(null, self::SKAL_DENY_STEG_SPARR, $skydd);
            }

            // 7-8. Rollhärledning (GAP-057 handoff) + H1-enhetskontroll för kretsen.
            [$roll, $denySkal] = $this->harledRoll($uid, $hubsCaseId, $arende);
            if ($denySkal !== null) {
                return $this->deny($roll, $denySkal, $skydd);
            }

            // 9. Roll×klass-matris.
            return $this->rollKlassMatris($roll, $klass, $funktion, $skydd);
        } catch (\Throwable $e) {
            // Fail-closed: ohanterat fel ⇒ deny. Logga UTAN uid/case i meddelandet.
            $this->logger->error('hubs_arende authz/check internt fel (fail-closed deny)', [
                'app' => 'hubs_arende',
                'exception' => $e,
            ]);
            return $this->deny(null, self::SKAL_DENY_INTERNAL_ERROR, false);
        }
    }

    // ================================================================== //
    //  Rollhärledning
    // ================================================================== //

    /**
     * Härled uid:ns roll ur member-ledgern med GAP-057-handoff-semantiken (samma
     * modell som {@see ArendeService::atkomstUids()}): så snart en operatörsroll
     * (handläggare/co/observatör) finns på ärendet ÄR de åtkomstlistan och
     * mottagningskretsen är revokerad. Kretsallow kräver DESSUTOM H1-enhetsmatch.
     *
     * @return array{0:?string, 1:?string} [roll, denySkal] — denySkal null ⇒ tillåten
     *   (fortsätt till rollmatrisen); annars neka med den koden.
     */
    private function harledRoll(string $uid, string $hubsCaseId, Arende $arende): array {
        $caseHarOperator = false;
        $uidRoller = [];
        foreach ($this->memberMapper->findByCaseId($hubsCaseId) as $m) {
            $roll = (string)$m->getRoll();
            if (in_array($roll, [
                Member::ROLL_HANDLAGGARE,
                Member::ROLL_CO_HANDLAGGARE,
                Member::ROLL_OBSERVATOR,
            ], true)) {
                $caseHarOperator = true;
            }
            if ((string)$m->getUid() === $uid) {
                $uidRoller[$roll] = true;
            }
        }

        // Operatörsroller litar på member-raden (tilldela har redan asserterat
        // kretstillhörighet vid handoff) — ingen ytterligare enhetskontroll.
        if (isset($uidRoller[Member::ROLL_HANDLAGGARE])) {
            return [Member::ROLL_HANDLAGGARE, null];
        }
        if (isset($uidRoller[Member::ROLL_CO_HANDLAGGARE])) {
            return [Member::ROLL_CO_HANDLAGGARE, null];
        }
        if (isset($uidRoller[Member::ROLL_OBSERVATOR])) {
            return [Member::ROLL_OBSERVATOR, null];
        }

        // Endast kretsmedlem: kretsen ser ärendet ENDAST så länge det är otilldelat.
        if (isset($uidRoller[Member::ROLL_MOTTAGNINGSKRETS])) {
            if ($caseHarOperator) {
                // Handoff har skett ⇒ kretsen är revokerad (OSL 26 kap inre sekretess).
                return [Member::ROLL_MOTTAGNINGSKRETS, self::SKAL_DENY_KRETS_TILLDELAD];
            }
            // Kretsallow kräver H1-enhetsmatch (skydd mot enhetsbytesdrift).
            if (!$this->enhetMatch($uid, $arende->getEnhet())) {
                return [Member::ROLL_MOTTAGNINGSKRETS, self::SKAL_DENY_ENHET];
            }
            return [Member::ROLL_MOTTAGNINGSKRETS, null];
        }

        // Ingen member-rad alls ⇒ ej behörig.
        return [null, self::SKAL_DENY_EJ_MEDLEM];
    }

    /**
     * Roll×klass-matris (SPEC 5.2.2 + Node-kontraktets rollmappning):
     *  - LÄS (ask/read_full inkl. fn_*-läsfunktioner): alla medlemsroller tillåts
     *    (kretsen redan enhets-/otilldelat-kontrollerad i {@see harledRoll()}).
     *  - DRAFT (fn_draft_* / fn_avslutssyntes): endast handläggare|co (observatör/krets
     *    nekas skrivning) — utöver v2- och steg-grindarna ovan.
     *  - CAPTURE (mänsklig ingest): endast handläggare|co.
     *
     * $roll är här garanterat en icke-null medlemsroll (deny_ej_medlem hanterat).
     *
     * @return array{allow:bool, roll:?string, skal:string, skydd:bool}
     */
    private function rollKlassMatris(?string $roll, string $klass, string $funktion, bool $skydd): array {
        // Utkast + capture är skriv-lika: endast operatörer med skrivrätt.
        if ($this->arDraft($funktion) || $klass === self::KLASS_CAPTURE) {
            if ($roll === Member::ROLL_HANDLAGGARE || $roll === Member::ROLL_CO_HANDLAGGARE) {
                return $this->allow($roll, $skydd);
            }
            if ($roll === Member::ROLL_OBSERVATOR) {
                return $this->deny($roll, self::SKAL_DENY_OBSERVATOR_SKRIVNING, $skydd);
            }
            // mottagningskrets
            return $this->deny($roll, self::SKAL_DENY_KRETS_SKRIVNING, $skydd);
        }

        // Rena läsfunktioner: alla medlemsroller tillåts.
        return $this->allow($roll, $skydd);
    }

    // ================================================================== //
    //  Grind-hjälpare
    // ================================================================== //

    /** True när funktionen är en v2-utkastfunktion. */
    private function arDraft(string $funktion): bool {
        return in_array($funktion, self::DRAFT_FUNKTIONER, true);
    }

    /**
     * Är en v2-funktion aktiverad för kommunen? Läser app-config `ork_fn_enabled`
     * (komma-/mellanslagsseparerad lista). Koddefault (tom lista / ingen appConfig):
     * endast v1-funktioner ⇒ draft-funktioner nekas (deny_fn_ej_aktiverad).
     */
    private function funktionAktiverad(string $funktion): bool {
        if ($this->appConfig === null) {
            return false;
        }
        $rad = trim($this->appConfig->getAppValueString('ork_fn_enabled', ''));
        if ($rad === '') {
            return false;
        }
        $aktiva = preg_split('/[\s,]+/', $rad, -1, PREG_SPLIT_NO_EMPTY);
        return is_array($aktiva) && in_array($funktion, $aktiva, true);
    }

    /**
     * H1-enhetsmatch för en EXPLICIT uid (inte sessionsanvändaren). Återanvänder
     * {@see ArendeService::normaliseEnhet()}-semantiken (strippa '@', trim, lower) men
     * mot uid:ns egna grupper. FAIL-CLOSED: okänd uid, tom enhet eller ingen matchande
     * grupp ⇒ false.
     */
    private function enhetMatch(string $uid, ?string $enhet): bool {
        $normEnhet = $this->normaliseEnhet($enhet);
        if ($normEnhet === '') {
            return false;
        }
        $user = $this->userManager->get($uid);
        if ($user === null) {
            return false;
        }
        foreach ($this->groupManager->getUserGroupIds($user) as $gid) {
            if ($this->normaliseEnhet($gid) === $normEnhet) {
                return true;
            }
        }
        return false;
    }

    /**
     * Normalisera enhet/grupp-identifierare för jämförelse: strippa avslutande '@'
     * (funktionsadress-suffix), trim och gemener. Speglar
     * {@see ArendeService::normaliseEnhet()}.
     */
    private function normaliseEnhet(?string $value): string {
        $v = trim((string)$value);
        $v = rtrim($v, '@');
        return mb_strtolower($v);
    }

    /**
     * skydd-flaggan (SPEC 5.2.1): true när ≥1 part har skydd != 'ingen'. Best-effort —
     * ett läsfel får aldrig fälla beslutet (fail-safe till false; ingen PII i svaret,
     * endast en bool). PartMapper är motorns enda PII-tabell; endast en räkning görs.
     */
    private function harSkyddadPart(string $hubsCaseId): bool {
        try {
            foreach ($this->partMapper->findByCaseId($hubsCaseId) as $part) {
                $s = (string)$part->getSkydd();
                if ($s !== '' && $s !== Part::SKYDD_INGEN) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('hubs_arende authz/check: skydd-uppslag misslyckades (fail-safe false)', [
                'app' => 'hubs_arende',
                'exception' => $e,
            ]);
        }
        return false;
    }

    // ================================================================== //
    //  Beslutsformare
    // ================================================================== //

    /**
     * @return array{allow:bool, roll:?string, skal:string, skydd:bool}
     */
    private function allow(?string $roll, bool $skydd): array {
        return [
            'allow' => true,
            'roll' => $roll,
            'skal' => $this->allowSkal($roll),
            'skydd' => $skydd,
        ];
    }

    /**
     * @return array{allow:bool, roll:?string, skal:string, skydd:bool}
     */
    private function deny(?string $roll, string $skal, bool $skydd): array {
        return [
            'allow' => false,
            'roll' => $roll,
            'skal' => $skal,
            'skydd' => $skydd,
        ];
    }

    /** allow-skäl per roll (SPEC 5.2.1 allow-koder). */
    private function allowSkal(?string $roll): string {
        return match ($roll) {
            Member::ROLL_HANDLAGGARE => self::SKAL_MEDLEM_HANDLAGGARE,
            Member::ROLL_CO_HANDLAGGARE => self::SKAL_MEDLEM_CO_HANDLAGGARE,
            Member::ROLL_OBSERVATOR => self::SKAL_MEDLEM_OBSERVATOR,
            Member::ROLL_MOTTAGNINGSKRETS => self::SKAL_KRETS_OTILLDELAD,
            default => self::SKAL_MEDLEM_HANDLAGGARE,
        };
    }
}
