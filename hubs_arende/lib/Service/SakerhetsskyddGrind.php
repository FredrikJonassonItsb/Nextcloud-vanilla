<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Service;

use OCA\HubsArende\Db\ArendeMapper;
use OCA\HubsArende\Db\PekareMapper;
use OCA\HubsArende\Integration\Client\GroupfolderClient;
use OCA\HubsArende\Integration\Client\SdkmcClient;
use OCA\HubsArende\Integration\Client\SpreedClient;
use OCA\HubsArende\Service\Brain\BrainProvisionService;
use Psr\Log\LoggerInterface;

/**
 * Säkerhetsskydds- och visselblåsnings-grind (FAIL-CLOSED).
 *
 * This is the very first gate in the case engine: {@see ArendeService::createCase()}
 * runs {@see evaluate()} as step R0, BEFORE any side effect (no register row, no
 * tag, no room, no folder). If the inflow shows a säkerhetsskydds- or
 * visselblåsnings-indicator the case is rejected/isolated and an avvisningskvitto
 * is returned — nothing is created.
 *
 * Why fail-closed: säkerhetsskyddsklassificerad information (säkerhetsskyddslagen
 * 2018:585) and protected whistleblower reports (lag 2021:890) must NEVER flow into
 * the ordinary ärende-pipeline (index / Spreed / Groupfolder / case:-tag). A false
 * negative here is a security incident, so the DEFAULT on ANY indicator — or on an
 * inconclusive/erroring detector — is to REJECT (avvisad=true). Only an explicitly
 * clean signal passes.
 *
 * The actual detection (keyword / sender / content signals) is a TODO-hook:
 * {@see detectIndicator()} currently uses conservative deterministic heuristics over
 * the inflow fields and is the single place to wire a real classifier (local-only,
 * never an external LLM on sekretess-belagt innehåll — same red-zone rule as KB-Whisper).
 *
 * Visselblåsning is treated as the SAME hard boundary: a whistleblower indicator
 * isolates the inflow exactly like säkerhetsskydd (reason carries the distinction).
 */
class SakerhetsskyddGrind {
    /** Reason codes (stable, machine-readable). */
    public const REASON_OK = 'ok';
    public const REASON_SAKERHETSSKYDD = 'sakerhetsskydd_indikator';
    public const REASON_VISSELBLASNING = 'visselblasning_indikator';
    public const REASON_DETECTOR_ERROR = 'detektor_fel_failclosed';
    public const REASON_INPUT_SAKNAS = 'indata_saknas_failclosed';

    /**
     * Indicator class returned by the detector.
     */
    public const IND_NONE = 'none';
    public const IND_SAKERHETSSKYDD = 'sakerhetsskydd';
    public const IND_VISSELBLASNING = 'visselblasning';

    /**
     * Conservative keyword signals. TODO[detection]: replace/augment with a real
     * detector (structured SDK-fält, sender-org register, local content model).
     * Kept deliberately broad — a false positive only sends the inflow to a human;
     * a false negative would leak säkerhetsskyddsklassad info into the pipeline.
     *
     * @var list<string>
     */
    private const SAKERHETSSKYDD_KEYWORDS = [
        'säkerhetsskydd', 'sakerhetsskydd', 'säkerhetsklassificerad', 'hemlig',
        'kvalificerat hemlig', 'rikets säkerhet', 'totalförsvar', 'skyddsobjekt',
        'säkerhetsprövning', 'säkerhetsskyddsavtal', 'nato restricted', 'classified',
        'top secret', 'nato secret', 'nato confidential', 'secret defense',
    ];

    /**
     * @var list<string>
     */
    private const VISSELBLASNING_KEYWORDS = [
        'visselblås', 'visselbla', 'whistleblow', 'visselblåsarlag',
        'rapportering av missförhållanden', 'skyddad rapportering',
    ];

    /**
     * Strukturerade kuvert-nycklar vars NÄRVARO (oavsett värde) signalerar en
     * säkerhetsskydds-/handlings-klassmarkering. Behandlas fail-closed: vilket
     * icke-tomt, icke-explicit-öppet värde som helst → IND_SAKERHETSSKYDD, och ett
     * okänt/icke-tolkbart värde → ÄVEN IND_SAKERHETSSKYDD (vi får inte anta rent).
     *
     * Asymmetrin som M4 stänger: `handlingskod` (auktoritativ i
     * {@see InnehallsKlassService::lagerStrukturerat()}, samma nyckel-läsning
     * återanvänds här) plus `classification`/`x-protective-marking` var tidigare
     * osynliga för grinden. `sakerhetsklass`/`securityClass` ingår också så att en
     * enda kodväg täcker hela uppsättningen.
     *
     * @var list<string>
     */
    private const STRUKTURERADE_KLASS_NYCKLAR = [
        // säkerhetsklass-familjen (tidigare enda täckta vägen)
        'sakerhetsklass', 'säkerhetsklass', 'securityclass', 'security_class',
        // handlingskod — samma auktoritativa läsning som InnehallsKlassService
        'handlingskod', 'handlingstyp',
        // generisk klassmarkering + internationella skyddsmarkeringar
        'classification', 'classificationlevel', 'protectivemarking',
        'x-protective-marking', 'x_protective_marking', 'protective_marking',
        'sakerhetsskyddsklass', 'säkerhetsskyddsklass', 'sekretessklass',
    ];

    /**
     * Värden i ett klass-fält som uttryckligen betyder OKLASSAD/ÖPPET — den enda
     * uppsättning som får släppa igenom. ALLT annat (inklusive okänt) avvisar.
     * Jämförs mot {@see normalizeHaystack()}-normaliserat värde, så alla poster
     * är redan diakrit-fällda (ö→o) — t.ex. fångar 'oppen' även inkommande
     * "Öppen".
     *
     * @var list<string>
     */
    private const OPPNA_KLASS_VARDEN = [
        'oklassad', 'oppen', 'open', 'none', 'unclassified',
        'ej_klassad', 'ej klassad', 'ingen', 'normal', 'publik', 'public',
    ];

    /**
     * Nästlade behållare i SDK-kuvertet som kan bära strukturerade headers/fält.
     * Walkas (ett steg) så att t.ex. `sdkFields.headers.x-protective-marking`
     * fångas lika väl som ett platt fält.
     *
     * @var list<string>
     */
    private const NASTLADE_KLASS_BEHALLARE = [
        'headers', 'header', 'x-headers', 'xheaders', 'meta', 'metadata',
        'envelope', 'kuvert', 'fields', 'falt',
    ];

    /**
     * The retroaktiv-quarantine collaborators are appended as optional, autowired
     * dependencies. The NC DI container injects them at runtime; the positional
     * unit harness (logger + arendeMapper only) leaves them null and each
     * cross-app teardown degrades to a logged NO-OP — which keeps the gate
     * fail-closed (the register-row flip, the one piece of state this app owns,
     * still happens; nothing weakens the rejection).
     *
     * @param PekareMapper|null      $pekareMapper      Resolves external ids per case (talk_room/groupfolder/case_tag).
     * @param SdkmcClient|null       $sdkmcClient       untag inflow + delete case:-tag.
     * @param SpreedClient|null      $spreedClient      archive/isolate the case room.
     * @param GroupfolderClient|null $groupfolderClient lock the ärenderum ACL (never delete — evidence).
     */
    public function __construct(
        private LoggerInterface $logger,
        private ArendeMapper $arendeMapper,
        private ?PekareMapper $pekareMapper = null,
        private ?SdkmcClient $sdkmcClient = null,
        private ?SpreedClient $spreedClient = null,
        private ?GroupfolderClient $groupfolderClient = null,
        // R0-SPEGELN mot brain-gw (SPEC-BRAIN-PER-ARENDE kap 3.5/5.3): när ett
        // ärende med en provisionerad brain karantänsätts retroaktivt måste
        // provisionern PATCH:as (r0_karantan=true) så gw:ns R0-spegel underhålls
        // (ALLTID deny). TRAILING OPTIONAL — null i testharnessen/icke-brainmiljöer
        // ⇒ steget är en loggad no-op (försvagar aldrig karantänen).
        private ?BrainProvisionService $brainProvisionService = null,
    ) {
    }

    /**
     * FAIL-CLOSED evaluation of an inflow row. Runs FIRST, before any side effect.
     *
     * @param array<string,mixed> $rad The inflow row (conversationId, subject,
     *        from, to, body/preview, sdkFields, arendeTyp, ...). All optional —
     *        missing critical input itself fails closed.
     *
     * @return array{
     *     avvisad: bool,
     *     reason: string,
     *     retroaktiv: bool,
     *     indikator: string,
     *     kvitto: array<string,mixed>
     * } When avvisad=true the caller MUST abort createCase() with NO side effect
     *   and return `kvitto` as the avvisningskvitto. `retroaktiv` signals that an
     *   already-created case for the same anchor must be retroactively quarantined
     *   via {@see evaluateRetroaktiv()}.
     */
    public function evaluate(array $rad): array {
        // --- fail-closed on missing input ---------------------------------
        // If we cannot even read the fields we need to clear an inflow, we do
        // not get to assume it is safe.
        if ($rad === []) {
            return $this->avvisa(
                self::REASON_INPUT_SAKNAS,
                self::IND_SAKERHETSSKYDD,
                $rad,
                'Tom inflödesrad — kan inte säkerhetsklassa, fail-closed.',
            );
        }

        try {
            $indikator = $this->detectIndicator($rad);
        } catch (\Throwable $e) {
            // Detector blew up → we cannot prove the inflow is clean → REJECT.
            $this->logger->error('SakerhetsskyddGrind: detektor kastade, fail-closed', [
                'app' => 'hubs_arende',
                'exception' => $e,
            ]);
            return $this->avvisa(
                self::REASON_DETECTOR_ERROR,
                self::IND_SAKERHETSSKYDD,
                $rad,
                'Säkerhetsskydds-detektorn fallerade; fail-closed avvisning.',
            );
        }

        if ($indikator === self::IND_SAKERHETSSKYDD) {
            return $this->avvisa(
                self::REASON_SAKERHETSSKYDD,
                self::IND_SAKERHETSSKYDD,
                $rad,
                'Säkerhetsskydds-indikator — inflödet isoleras, inget ärende skapas.',
            );
        }

        if ($indikator === self::IND_VISSELBLASNING) {
            // Visselblåsning = SAME hard boundary as säkerhetsskydd (isolate).
            return $this->avvisa(
                self::REASON_VISSELBLASNING,
                self::IND_VISSELBLASNING,
                $rad,
                'Visselblåsnings-indikator — isoleras i skyddad kanal, inget ordinärt ärende.',
            );
        }

        // Explicitly clean — the only path that passes the grind.
        return [
            'avvisad' => false,
            'reason' => self::REASON_OK,
            'retroaktiv' => false,
            'indikator' => self::IND_NONE,
            'kvitto' => [],
        ];
    }

    /**
     * Retroactive quarantine of an ALREADY-created case (and its side effects)
     * when a säkerhetsskydds-/visselblåsnings-indicator is detected after the fact
     * (e.g. a later message on the same conversation, or a re-classification).
     *
     * Unlike {@see evaluate()} (which prevents creation), this UNDOES: it must
     * scrub the case out of every surface it ever reached. It is intentionally a
     * hard, isolate-everything operation — there is no partial quarantine.
     *
     * STUB: the cross-app side-effect removal is left as explicit TODOs because it
     * crosses into sdkmc / Spreed / Groupfolders / Deck, which are consumed over
     * OCS/events (this app never writes verksamhetsdata directly).
     *
     * @param string $hubsCaseId Canonical case token to quarantine.
     * @param string $reason     One of the REASON_* säkerhets-codes.
     *
     * @return array{
     *     karantanerad: bool,
     *     hubsCaseId: string,
     *     reason: string,
     *     atgarder: array<string,bool>,
     *     kvitto: array<string,mixed>
     * }
     */
    public function evaluateRetroaktiv(string $hubsCaseId, string $reason): array {
        $this->logger->warning('SakerhetsskyddGrind: RETROAKTIV karantän initierad', [
            'app' => 'hubs_arende',
            'hubsCaseId' => $hubsCaseId,
            'reason' => $reason,
        ]);

        // Track which removals succeeded so the kvitto is auditable.
        $atgarder = [
            'index_borttagen' => false,
            'spreed_isolerat' => false,
            'groupfolder_last' => false,
            'case_tag_borttagen' => false,
            'register_karantan' => false,
            'brain_karantan' => false,
        ];

        // R-retro-1: Remove from the inflow/triage index so it stops surfacing.
        //   Untag the case:{hubsCaseId} message(s) via sdkmc. Graceful: a missing
        //   neighbour leaves the åtgärd false (kvitto.fullstandig=false) but never
        //   throws — the gate must not be weakened by an unreachable app.
        $atgarder['index_borttagen'] = $this->retroSafe('index_borttagen', $hubsCaseId, function () use ($hubsCaseId): bool {
            if ($this->sdkmcClient === null) {
                return false;
            }
            return $this->sdkmcClient->untagMessage($hubsCaseId, []);
        });

        // R-retro-2: Isolate / tear down the Spreed room so no further chat lands.
        //   Archive every talk_room pekare for this case (spreed-itsl room-API).
        $atgarder['spreed_isolerat'] = $this->retroSafe('spreed_isolerat', $hubsCaseId, function () use ($hubsCaseId): bool {
            if ($this->spreedClient === null || $this->pekareMapper === null) {
                return false;
            }
            $done = false;
            foreach ($this->pekareMapper->findByCaseAndTyp($hubsCaseId, 'talk_room') as $p) {
                $done = $this->spreedClient->archiveRoom($p->getObjektId()) || $done;
            }
            return $done;
        });

        // R-retro-3: Lock the Groupfolder (revoke all ACL → security custodian only).
        //   Re-apply a deny-all ACL profile; do NOT delete (evidence/chain-of-custody).
        $atgarder['groupfolder_last'] = $this->retroSafe('groupfolder_last', $hubsCaseId, function () use ($hubsCaseId): bool {
            if ($this->groupfolderClient === null || $this->pekareMapper === null) {
                return false;
            }
            $done = false;
            foreach ($this->pekareMapper->findByCaseAndTyp($hubsCaseId, 'groupfolder') as $p) {
                $done = $this->groupfolderClient->applyAcl((int)$p->getObjektId(), 'karantan_deny_all') || $done;
            }
            return $done;
        });

        // R-retro-4: Remove the case:-tag carrier so the token stops propagating.
        //   Delete the case:{hubsCaseId} systemtag/imap_label via sdkmc + pekare.
        $atgarder['case_tag_borttagen'] = $this->retroSafe('case_tag_borttagen', $hubsCaseId, function () use ($hubsCaseId): bool {
            if ($this->sdkmcClient === null) {
                return false;
            }
            $done = false;
            // Pekar-vägen (om en R3-pekare finns).
            if ($this->pekareMapper !== null) {
                foreach ($this->pekareMapper->findByCaseAndTyp($hubsCaseId, 'case_tag') as $p) {
                    $done = $this->sdkmcClient->deleteCaseTag($hubsCaseId, '', $p->getObjektId()) || $done;
                }
                $this->pekareMapper->deleteByCaseAndTyp($hubsCaseId, 'case_tag');
            }
            // LABEL-VÄGEN (2026-07-12) — täcker case-taggar som skapats via
            // koppla/tagMessage UTAN pekare (samma buggklass som gallringens). I ett
            // säkerhetsskyddsflöde är det extra viktigt att bäraren FAKTISKT rivs.
            $done = $this->sdkmcClient->deleteCaseTagByLabel($hubsCaseId) || $done;
            return $done;
        });

        // R-retro-5: Flip the register row into karantän (commit_destination=karantan),
        //   the one piece of state this app DOES own. This always runs (no external
        //   dependency), so a quarantine is never left half-applied even when the
        //   cross-app teardowns above are unavailable.
        $atgarder['register_karantan'] = $this->retroSafe('register_karantan', $hubsCaseId, function () use ($hubsCaseId): bool {
            $arende = $this->arendeMapper->findByCaseId($hubsCaseId);
            $arende->setCommitDestination('karantan');
            $arende->setStatus('otilldelat');
            $arende->setRetentionState('pausad');
            $this->arendeMapper->update($arende);
            return true;
        });

        // R-retro-6: SPEGLA karantänen till brain-gw (SPEC kap 3.5/5.3). Om ärendet
        //   har en provisionerad brain-tenant sätts dess R0-flagga (PATCH r0_karantan=
        //   true) så att gw:ns authz-grind ALLTID nekar (deny_r0_karantan). Best-effort
        //   via retroSafe: en onåbar provisioner får ALDRIG försvaga register-karantänen
        //   ovan (den lokala, auktoritativa staten). Saknas brain/pekare ⇒ false (no-op).
        $atgarder['brain_karantan'] = $this->retroSafe('brain_karantan', $hubsCaseId, function () use ($hubsCaseId): bool {
            if ($this->brainProvisionService === null || $this->pekareMapper === null) {
                return false;
            }
            $done = false;
            foreach ($this->pekareMapper->findByCaseAndTyp($hubsCaseId, 'brain_tenant') as $p) {
                $done = $this->brainProvisionService->setKarantan($p->getObjektId(), true) || $done;
            }
            return $done;
        });

        $kvitto = [
            'typ' => 'sakerhetsskydd_retroaktiv_karantan',
            'hubsCaseId' => $hubsCaseId,
            'reason' => $reason,
            'tidpunkt' => $this->isoNow(),
            'fullstandig' => !in_array(false, $atgarder, true),
        ];

        return [
            'karantanerad' => true,
            'hubsCaseId' => $hubsCaseId,
            'reason' => $reason,
            'atgarder' => $atgarder,
            'kvitto' => $kvitto,
        ];
    }

    /**
     * Detect a säkerhetsskydds-/visselblåsnings-indicator in the inflow row.
     *
     * TODO[detection]: this is the single real-detection hook. Replace the
     * keyword heuristics with (in falling determinism):
     *   (a) structured SDK-fält / X-headers (handlingskod, säkerhetsklass-fält),
     *   (b) sender organisation against a configured register (LOA3-stärkt),
     *   (c) a LOCAL content model (never an external LLM on sekretess-belagt innehåll).
     * Keep it fail-closed: when in doubt, return an indicator (the caller rejects).
     *
     * @param array<string,mixed> $rad
     * @return self::IND_* One of the indicator constants.
     */
    private function detectIndicator(array $rad): string {
        // ---- Structured signal first (strongest, primary grind) ----------
        // The PRESENCE of any class-/handlingskod-bearing key is itself the
        // indicator, regardless of value. We flatten the SDK envelope (incl. one
        // level of nested headers/meta) and then:
        //   - any class key whose value is not an explicit oklassad/öppet token
        //     → IND_SAKERHETSSKYDD,
        //   - a class key whose value is empty OR an unknown/uninterpretable
        //     token → ALSO IND_SAKERHETSSKYDD (fail-closed; we must not assume
        //     a marking present-but-unreadable means "clean").
        // This closes the M4 asymmetry: handlingskod was authoritative in
        // InnehallsKlassService but invisible to the gate.
        $sdk = $rad['sdkFields'] ?? $rad['itsl'] ?? [];
        if (is_array($sdk)) {
            $flat = $this->flattenSdk($sdk);

            foreach (self::STRUKTURERADE_KLASS_NYCKLAR as $nyckel) {
                if (!array_key_exists($nyckel, $flat)) {
                    continue;
                }
                $varde = $this->normalizeValue($flat[$nyckel]);
                // Explicitly oklassad/öppet is the ONLY value that may pass; a
                // bare-present, empty, or unknown value all fail closed.
                if (!in_array($varde, self::OPPNA_KLASS_VARDEN, true)) {
                    return self::IND_SAKERHETSSKYDD;
                }
            }

            // Visselblåsning: an explicit truthy flag in the envelope.
            if ($this->sdkFlaggaSann($flat, ['visselblasning', 'whistleblower', 'visselblasare', 'whistleblowing'])) {
                return self::IND_VISSELBLASNING;
            }
        }

        // ---- Weak deterministic keyword fallback (NEVER the sole grind) ----
        // Keyword hits are secondary: structured presence above is primary. The
        // haystack is diacritic-folded and whitespace-collapsed so that e.g.
        // "TOP   SECRET" / "Rikets  säkerhet" still match.
        $haystack = $this->normalizeHaystack($this->concatTextFields($rad));
        if ($haystack !== '') {
            foreach (self::SAKERHETSSKYDD_KEYWORDS as $kw) {
                if (str_contains($haystack, $this->normalizeHaystack($kw))) {
                    return self::IND_SAKERHETSSKYDD;
                }
            }
            foreach (self::VISSELBLASNING_KEYWORDS as $kw) {
                if (str_contains($haystack, $this->normalizeHaystack($kw))) {
                    return self::IND_VISSELBLASNING;
                }
            }
        }

        return self::IND_NONE;
    }

    /**
     * Flatten the SDK envelope to a single string-keyed map for class-key
     * lookup. Keys are lowercased; one level of known nested containers
     * (headers/meta/envelope/…) is merged in so a marking carried as
     * `sdkFields.headers.x-protective-marking` is found like a flat field. Flat
     * keys win over nested on collision (the envelope's own field is the more
     * authoritative placement).
     *
     * @param array<mixed,mixed> $sdk
     * @return array<string,mixed>
     */
    private function flattenSdk(array $sdk): array {
        $flat = [];
        foreach ($sdk as $k => $v) {
            $lk = strtolower(trim((string)$k));
            if (in_array($lk, self::NASTLADE_KLASS_BEHALLARE, true) && is_array($v)) {
                foreach ($v as $nk => $nv) {
                    $lnk = strtolower(trim((string)$nk));
                    if (!array_key_exists($lnk, $flat)) {
                        $flat[$lnk] = $nv;
                    }
                }
                continue;
            }
            // Flat key takes precedence over a previously-merged nested one.
            $flat[$lk] = $v;
        }
        return $flat;
    }

    /**
     * Normalise a single structured value for the oklassad/öppet comparison:
     * scalars → diacritic-folded, lowercased, whitespace-collapsed string;
     * anything non-scalar (array/object) is treated as a present-but-
     * uninterpretable marking → empty string, which fails closed upstream.
     *
     * @param mixed $value
     */
    private function normalizeValue(mixed $value): string {
        if (is_bool($value)) {
            // A bare boolean class flag: true = marked, false = explicitly open.
            return $value ? 'true' : 'open';
        }
        if (!is_scalar($value)) {
            return '';
        }
        return $this->normalizeHaystack((string)$value);
    }

    /**
     * True if any of the given keys holds an explicitly truthy flag.
     *
     * @param array<string,mixed> $flat
     * @param list<string>        $keys
     */
    private function sdkFlaggaSann(array $flat, array $keys): bool {
        foreach ($keys as $k) {
            $v = $flat[$k] ?? null;
            if ($v === true || $v === 1 || $v === '1' || (is_string($v) && $this->normalizeHaystack($v) === 'true')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Normalise a haystack/needle for substring matching: lowercase, fold the
     * Swedish diacritics (å/ä/ö/é → a/a/o/e) so an ASCII-stripped producer field
     * still matches, and collapse all whitespace runs to a single space. Keyword
     * matching is SECONDARY to the structured presence check — never the only
     * grind — so this only widens the weak fallback, it does not gate alone.
     */
    private function normalizeHaystack(string $s): string {
        // Fold diacritics for BOTH cases first (strtolower() is ASCII-only and
        // never lowers a multibyte Ä/Ö), then ASCII-lowercase, then collapse
        // whitespace. Folding before lowercasing means an uppercase Å in the
        // producer field still maps to a plain 'a'.
        $s = strtr($s, [
            'å' => 'a', 'ä' => 'a', 'ö' => 'o', 'é' => 'e', 'è' => 'e',
            'ü' => 'u', 'á' => 'a', 'à' => 'a', 'ø' => 'o', 'æ' => 'a',
            'Å' => 'a', 'Ä' => 'a', 'Ö' => 'o', 'É' => 'e', 'È' => 'e',
            'Ü' => 'u', 'Á' => 'a', 'À' => 'a', 'Ø' => 'o', 'Æ' => 'a',
        ]);
        $s = strtolower($s);
        $collapsed = preg_replace('/\s+/u', ' ', $s);
        return trim($collapsed ?? $s);
    }

    /**
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

    /**
     * Build a rejection result + avvisningskvitto. A säkerhetsskydds-indicator on
     * an inflow whose conversation already produced a case also flags retroaktiv.
     *
     * @param array<string,mixed> $rad
     * @return array{avvisad: bool, reason: string, retroaktiv: bool, indikator: string, kvitto: array<string,mixed>}
     */
    private function avvisa(string $reason, string $indikator, array $rad, string $message): array {
        $conversationId = isset($rad['conversationId']) ? (string)$rad['conversationId'] : null;

        $retroaktiv = false;
        if ($conversationId !== null && $conversationId !== '') {
            try {
                // If a case already exists for this anchor, the indicator arrived
                // after creation → caller must run evaluateRetroaktiv() on it.
                $retroaktiv = $this->arendeMapper->findByConversationId($conversationId) !== null;
            } catch (\Throwable $e) {
                // Even the existence check failing must not weaken the gate.
                $this->logger->warning('SakerhetsskyddGrind: retroaktiv-koll fallerade, antar retroaktiv', [
                    'app' => 'hubs_arende',
                    'exception' => $e,
                ]);
                $retroaktiv = true;
            }
        }

        $this->logger->warning('SakerhetsskyddGrind AVVISAR inflöde (fail-closed)', [
            'app' => 'hubs_arende',
            'reason' => $reason,
            'indikator' => $indikator,
            'retroaktiv' => $retroaktiv,
            'conversationId' => $conversationId,
        ]);

        return [
            'avvisad' => true,
            'reason' => $reason,
            'retroaktiv' => $retroaktiv,
            'indikator' => $indikator,
            'kvitto' => [
                'typ' => 'sakerhetsskydd_avvisning',
                'ok' => false,
                'avvisad' => true,
                'reason' => $reason,
                'indikator' => $indikator,
                'meddelande' => $message,
                'commitDestination' => 'karantan',
                'tidpunkt' => $this->isoNow(),
                'conversationId' => $conversationId,
            ],
        ];
    }

    /**
     * Run one retroaktiv-teardown step, swallowing any failure to its boolean
     * outcome. A teardown that throws (app unreachable, OCS 401, DB hiccup) must
     * NOT abort the quarantine — the remaining steps still run and the kvitto
     * records the partial outcome (fullstandig=false). This keeps the gate
     * fail-closed: the rejection already happened in evaluate(); nothing here can
     * weaken it.
     *
     * @param callable():bool $step
     */
    private function retroSafe(string $name, string $hubsCaseId, callable $step): bool {
        try {
            $ok = $step();
            if (!$ok) {
                $this->logger->info('SakerhetsskyddGrind: retro-steg ' . $name . ' hoppat (granne ej tillgänglig)', [
                    'app' => 'hubs_arende',
                    'hubsCaseId' => $hubsCaseId,
                ]);
            }
            return $ok;
        } catch (\Throwable $e) {
            $this->logger->error('SakerhetsskyddGrind: retro-steg ' . $name . ' fallerade (graceful, karantän fortsätter)', [
                'app' => 'hubs_arende',
                'hubsCaseId' => $hubsCaseId,
                'exception' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function isoNow(): string {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
