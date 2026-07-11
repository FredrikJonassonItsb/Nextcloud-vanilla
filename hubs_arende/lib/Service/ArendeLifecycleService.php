<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Service;

use OCA\HubsArende\Db\Arende;
use OCA\HubsArende\Db\ArendeMapper;
use OCA\HubsArende\Db\ArendeTyp;
use OCA\HubsArende\Db\Handelse;
use OCA\HubsArende\Db\HandelseMapper;
use OCA\HubsArende\Db\PekareMapper;
use OCA\HubsArende\Service\Brain\BrainProvisionService;
use OCA\HubsArende\Service\Brain\HandelseTypAi;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Lifecycle step-transitions for a case (ärende).
 *
 * The register's `steg` field is a small, bounded state machine. This service is
 * its ONLY legal mover: it loads the case through {@see ArendeService::show()}
 * (which gates object-level authz AND existence — never duplicated here), checks
 * the requested move against the canonical TRANSITION GRAPH, and persists the new
 * step via {@see ArendeMapper::update()}.
 *
 * Allowed transitions (a directed graph; anything else is a 400):
 *
 *   inflode           → forhandsbedomning
 *   forhandsbedomning → utredning | avslutat
 *   utredning         → beslut | avslutat
 *   beslut            → uppfoljning | avslutat
 *   uppfoljning       → avslutat | utredning   (utredning = återöppning)
 *   avslutat          → (terminal — no outgoing edges)
 *
 * A move to the SAME step is an idempotent no-op (the unchanged row is returned).
 *
 * Invariants (unchanged here): Hubs is never System of Record — only pseudonymt
 * koordinations-state is touched; no PII is written to logs or responses.
 */
class ArendeLifecycleService {
    /**
     * Canonical allowed-transition graph: current steg → list of legal next steg.
     * `avslutat` is terminal (empty list). This is the single source of truth for
     * what a move may do; an edge not present here is rejected (controller → 400).
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_TRANSITIONS = [
        'inflode' => ['forhandsbedomning'],
        'forhandsbedomning' => ['utredning', 'avslutat'],
        'utredning' => ['beslut', 'avslutat'],
        'beslut' => ['uppfoljning', 'avslutat'],
        'uppfoljning' => ['avslutat', 'utredning'],
        'avslutat' => [],
    ];

    public function __construct(
        private ArendeService $arendeService,
        private ArendeMapper $arendeMapper,
        private ArendeTypRegistry $typRegistry,
        private LoggerInterface $logger,
        private ITimeFactory $timeFactory,
        // Händelsejournalen ("Historik & beslut"). BEST-EFFORT — journal-fel får
        // aldrig fälla övergången. TRAILING OPTIONAL (positionell testharness).
        private ?HandelseMapper $handelseMapper = null,
        private ?IUserSession $userSession = null,
        // BEVAKNINGS-KEDJAN: steg-övergången släcker det lämnade stegets mål och
        // föder nästa (14d→4mån-nollställningen); avslut avbryter allt. BEST-EFFORT,
        // TRAILING OPTIONAL — null ⇒ ingen bevakningsnollställning (testharness).
        private ?BevakningService $bevakningService = null,
        // UTREDNINGSKEDJANS GRINDAR (A7/A9). GrindConfig avgör om enforcement är PÅ
        // (config-flaggor, på i dev/av i prod); EvidensService avläser om ett
        // lagstadgat moment (skyddsbedömning/kommunicering) faktiskt producerats.
        // TRAILING OPTIONAL — null ⇒ grindarna degraderar till gammalt beteende.
        private ?GrindConfig $grindConfig = null,
        private ?EvidensService $evidensService = null,
        // A12 — provenans-kanal mot facksystemet vid avslut (best-effort, får aldrig
        // fälla övergången). TRAILING OPTIONAL — null ⇒ ingen extern provenans.
        private ?FacksystemCommitService $commitService = null,
        // BRAIN-LIVSCYKEL (SPEC-BRAIN-PER-ARENDE kap 3.4/9.2): vid avslut FRYSES
        // ärendets brain-tenant (dödad skrivnyckel + REVOKE på PG-nivå) så den blir
        // read-only. pekareMapper resolverar hubs_case_id → tenant_id (pekartyp
        // 'brain_tenant'). BÄGGE TRAILING OPTIONAL — null ⇒ frysningen är en no-op
        // (får ALDRIG fälla den redan genomförda övergången). Autowiras i drift.
        private ?PekareMapper $pekareMapper = null,
        private ?BrainProvisionService $brainProvisionService = null,
    ) {
    }

    /**
     * Transition a case to a new lifecycle step.
     *
     * @param string               $ref      hubsCaseId or dnr (resolved via show()).
     * @param string               $nyttSteg The target step (must be a graph node).
     * @param array<string, mixed> $kontext  Optional context (reserved; not PII).
     *
     * @return Arende The updated case (or the unchanged case on an idempotent no-op).
     *
     * @throws DoesNotExistException     When the case does not exist OR the caller is
     *         not authorised for its enhet (show() denies indistinguishably → 404).
     * @throws \InvalidArgumentException On an unknown target step or an illegal move
     *         (controller → 400).
     */
    public function transitionera(string $ref, string $nyttSteg, array $kontext = []): Arende {
        // Reuse the authz + existence gate. Do NOT duplicate the enhet check.
        $arende = $this->arendeService->show($ref);
        $franSteg = $arende->getSteg();

        // The target must be a known node in the graph.
        if (!array_key_exists($nyttSteg, self::ALLOWED_TRANSITIONS)) {
            throw new \InvalidArgumentException('Okänt steg: ' . $nyttSteg);
        }

        // Same step → idempotent no-op (return the unchanged row, no write/log churn).
        if ($franSteg === $nyttSteg) {
            return $arende;
        }

        // Validate the edge against the canonical transition graph.
        $tillatna = self::ALLOWED_TRANSITIONS[$franSteg] ?? [];
        if (!in_array($nyttSteg, $tillatna, true)) {
            throw new \InvalidArgumentException(
                'Otillåten övergång ' . $franSteg . ' → ' . $nyttSteg . '.'
            );
        }

        // ============================================================== //
        //  UTREDNINGSKEDJANS GRINDAR (A7/A9). Kastar FÖRE setSteg — en blockerad
        //  övergång persisteras aldrig. Enforcement är config-gatead (GrindConfig,
        //  på i dev/av i prod); när en flagga är AV degraderar grinden till sitt
        //  gamla, icke-tvingande beteende. Legitima men okontrollerbara utfall går
        //  smidigt men LÄMNAR SPÅR (journalförd TYP_GRINDVAL).
        // ============================================================== //

        // A7 — SKYDDSBEDÖMNINGS-EXISTENS-GRIND (forhandsbedomning→utredning).
        // Bryter den cirkulära härledningen (GAP-U1): grinden kräver att en verklig
        // skyddsbedömning (handling ur mall) EXISTERAR — inte en klient-boolean.
        // Hård grind med journalförd override (beslut 2026-07-08): handläggaren kan
        // medvetet passera med ett strukturerat skäl (t.ex. gjord i Treserva).
        if ($franSteg === 'forhandsbedomning' && $nyttSteg === 'utredning') {
            $typ = $this->typRegistry->get($arende->getArendeTyp());
            if ($typ !== null && $typ->getPliktGrind() === true) {
                if ($this->grindConfig !== null && $this->grindConfig->skyddsbedomningGrind()) {
                    // fail-open: null EvidensService (testharness) ⇒ anses uppfyllt.
                    $harBedomning = $this->evidensService === null
                        || $this->evidensService->harArtefakt($arende->getHubsCaseId(), 'skyddsbedomning');
                    if (!$harBedomning) {
                        $skal = (string)($kontext['override']['skal'] ?? '');
                        if ($skal === '') {
                            throw new \InvalidArgumentException(
                                'Plikt-grind: en skyddsbedömning måste finnas (eller anges som gjord utanför Hubs) innan utredning inleds.'
                            );
                        }
                        $this->journalGrindval($arende->getHubsCaseId(), 'skyddsbedomning', 'override', ['skal' => $skal]);
                    } else {
                        $this->journalGrindval($arende->getHubsCaseId(), 'skyddsbedomning', 'godkand', []);
                    }
                } elseif (($kontext['skyddsbedomningKvitterad'] ?? false) !== true) {
                    // Flagga AV → gammalt beteende (klient-boolean), bakåtkompatibelt.
                    throw new \InvalidArgumentException(
                        'Plikt-grind: skyddsbedömningen måste kvitteras innan utredning inleds.'
                    );
                }
            }
        }

        // A9a — INTE-INLEDA-MOTIV (forhandsbedomning→avslutat). "Inte inleda" är ett
        // legitimt utfall men får inte vara ett tyst förbi-klick: kräver strukturerat
        // {orsak, beslutsfattare}. Journalförs så beslut skiljs från slarv.
        if ($franSteg === 'forhandsbedomning' && $nyttSteg === 'avslutat'
            && $this->grindConfig !== null && $this->grindConfig->inteInledaMotiv()) {
            $orsak = (string)($kontext['inteInledaVal']['orsak'] ?? '');
            if ($orsak === '') {
                throw new \InvalidArgumentException(
                    'Beslut om att inte inleda utredning måste ange en orsak.'
                );
            }
            $this->journalGrindval($arende->getHubsCaseId(), 'inte_inleda', 'vald', [
                'orsak' => $orsak,
                'beslutsfattare' => (string)($kontext['inteInledaVal']['beslutsfattare'] ?? ''),
            ]);
        }

        // A9b — KOMMUNICERINGS-CHECKPOINT (utredning→beslut). Kommunicering (FL 25 §)
        // före beslut är en stark men överbryggbar kontroll — hård spärr → fejk.
        // Kräver att en kommunicering finns ELLER ett medvetet override-skäl.
        if ($franSteg === 'utredning' && $nyttSteg === 'beslut'
            && $this->grindConfig !== null && $this->grindConfig->beslutDokument()) {
            $harKomm = $this->evidensService === null
                || $this->evidensService->harArtefakt($arende->getHubsCaseId(), 'kommunicering');
            if (!$harKomm) {
                $val = $kontext['kommuniceringVal'] ?? null;
                $gjord = is_array($val) && ($val['gjord'] ?? false) === true;
                $skal = is_array($val) ? (string)($val['skal'] ?? '') : '';
                if (!$gjord && $skal === '') {
                    throw new \InvalidArgumentException(
                        'Kommunicering med parterna (FL 25 §) saknas — bekräfta att den gjorts eller ange varför den utelämnas.'
                    );
                }
                $this->journalGrindval($arende->getHubsCaseId(), 'kommunicering', $gjord ? 'godkand' : 'override', ['skal' => $skal]);
            }
        }

        // A9c — AVSLUTSMOTIV (X→avslutat, ej forhandsbedomning som har A9a). Avslut
        // kräver ett strukturerat utfall så ett ärende inte bara klickas bort.
        if ($nyttSteg === 'avslutat' && $franSteg !== 'forhandsbedomning'
            && $this->grindConfig !== null && $this->grindConfig->avslutMotiv()) {
            $utfall = (string)($kontext['avslutsmotiv']['utfall'] ?? '');
            if ($utfall === '') {
                throw new \InvalidArgumentException(
                    'Avslut kräver ett angivet utfall.'
                );
            }
            $this->journalGrindval($arende->getHubsCaseId(), 'avslut', 'vald', [
                'utfall' => $utfall,
                'kvarstaende' => (bool)($kontext['avslutsmotiv']['kvarstaende'] ?? false),
            ]);
        }

        // Apply the move.
        $arende->setSteg($nyttSteg);

        // Optionally recompute fristDue if the ärendetyp's fristPolicy declares a
        // per-step frist for the target step; otherwise leave fristDue untouched.
        $this->maybeRecomputeFristDue($arende, $nyttSteg);

        $arende = $this->arendeMapper->update($arende);

        // Journal (best-effort): steg-övergången i "Historik & beslut"-tidslinjen.
        if ($this->handelseMapper !== null) {
            try {
                $this->handelseMapper->record(
                    $arende->getHubsCaseId(),
                    Handelse::TYP_STEG,
                    [
                        'fran' => $franSteg,
                        'till' => $nyttSteg,
                        // GRINDLÄGE (T4/IVO): vilken grind gällde + var enforcement PÅ/AV
                        // vid övergången — så frånvaro av TYP_GRINDVAL inte är tvetydig.
                        'grind' => $this->grindLageForOvergang($franSteg, $nyttSteg),
                    ],
                    $this->userSession?->getUser()?->getUID() ?? '',
                );
            } catch (\Throwable $e) {
                $this->logger->warning('hubs_arende: journal-skrivning misslyckades (graceful)', [
                    'app' => 'hubs_arende',
                    'hubsCaseId' => $arende->getHubsCaseId(),
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        // BEVAKNINGS-NOLLSTÄLLNINGEN: släck det lämnade stegets mål (t.ex. 14 d
        // förhandsbedömning när utredning inleds) och föd nästa stegs mallar (4 mån
        // utredning) — själva "nollställningen". Avslut avbryter alla aktiva
        // bevakningar (ingenting kvar att bevaka). Best-effort. Re-hämta registret
        // efteråt så den projicerade fristen speglas i svaret.
        if ($this->bevakningService !== null) {
            if ($nyttSteg === 'avslutat') {
                $this->bevakningService->utvardera($arende->getHubsCaseId(), 'avslut');
            } else {
                $this->bevakningService->skapaStandardForSteg($arende->getHubsCaseId(), $nyttSteg);
            }
            try {
                $arende = $this->arendeMapper->findByCaseId($arende->getHubsCaseId());
            } catch (\Throwable) {
                // behåll den lokala kopian om re-hämtningen fallerar
            }
        }

        // A12 — BEVARANDE VID AVSLUT: spegla avslutet som provenans i facksystemet
        // (journalen gallras MED ärendet, så rättskällan måste bo externt). Best-
        // effort — får aldrig fälla den redan genomförda övergången.
        if ($nyttSteg === 'avslutat' && $this->commitService !== null) {
            try {
                $this->commitService->sparaProvenans($arende->getHubsCaseId(), [
                    'moment' => 'avslut',
                    'lagrum' => 'Arkivlagen',
                    'utfall' => (string)($kontext['avslutsmotiv']['utfall'] ?? ($kontext['inteInledaVal']['orsak'] ?? '')),
                    'harCommit' => $arende->getProvenanceState() === 'registrerad',
                    'aktorUid' => $this->userSession?->getUser()?->getUID() ?? '',
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('hubs_arende: avsluts-provenans misslyckades (graceful)', [
                    'app' => 'hubs_arende', 'hubsCaseId' => $arende->getHubsCaseId(),
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        // BRAIN-FRYSNING VID AVSLUT (SPEC-BRAIN-PER-ARENDE kap 3.4/9.2): ett avslutat
        // ärendes brain görs read-only (skrivnyckeln dödas). Best-effort — får ALDRIG
        // fälla den redan persisterade övergången. No-op utan brain/pekare/konfig.
        if ($nyttSteg === 'avslutat') {
            $this->frysBrainVidAvslut($arende->getHubsCaseId());
        }

        // Provenance log — hubsCaseId + från/till-steg only, NEVER PII.
        $this->logger->info('hubs_arende: steg-övergång', [
            'app' => 'hubs_arende',
            'hubsCaseId' => $arende->getHubsCaseId(),
            'franSteg' => $franSteg,
            'tillSteg' => $nyttSteg,
        ]);

        return $arende;
    }

    /**
     * Journalför ett medvetet grind-val (A9) — koordinationsdata utan PII: grind +
     * val + enum-koder (orsak/skäl/utfall), ALDRIG fri motiveringstext. Best-effort.
     *
     * @param array<string,mixed> $extra
     */
    private function journalGrindval(string $hubsCaseId, string $grind, string $val, array $extra): void {
        if ($this->handelseMapper === null) {
            return;
        }
        try {
            $this->handelseMapper->record(
                $hubsCaseId,
                Handelse::TYP_GRINDVAL,
                array_merge(['grind' => $grind, 'val' => $val], $extra),
                $this->userSession?->getUser()?->getUID() ?? '',
            );
        } catch (\Throwable $e) {
            $this->logger->warning('hubs_arende: grindval-journal misslyckades (graceful)', [
                'app' => 'hubs_arende', 'hubsCaseId' => $hubsCaseId, 'grind' => $grind,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * GRINDLÄGE för en steg-övergång (T4/IVO) — stämplas på TYP_STEG så en läsare i
     * efterhand kan skilja "grind passerad" från "grind avstängd". Utan detta är
     * frånvaron av en TYP_GRINDVAL-post tvetydig (enforcement av by default). Ren
     * koordinationsdata utan PII: {moment, enforcement:'pa'|'av'|'ingen', evidens:bool}.
     *
     * @return array{moment:?string, enforcement:string, evidens?:bool}
     */
    private function grindLageForOvergang(string $franSteg, string $nyttSteg): array {
        $gc = $this->grindConfig;
        $pa = static fn (bool $flagga): string => $flagga ? 'pa' : 'av';
        if ($franSteg === 'forhandsbedomning' && $nyttSteg === 'utredning') {
            return ['moment' => 'skyddsbedomning', 'enforcement' => $pa($gc?->skyddsbedomningGrind() ?? false), 'evidens' => $this->evidensService !== null];
        }
        if ($franSteg === 'forhandsbedomning' && $nyttSteg === 'avslutat') {
            return ['moment' => 'inte_inleda', 'enforcement' => $pa($gc?->inteInledaMotiv() ?? false)];
        }
        if ($franSteg === 'utredning' && $nyttSteg === 'beslut') {
            return ['moment' => 'kommunicering', 'enforcement' => $pa($gc?->beslutDokument() ?? false), 'evidens' => $this->evidensService !== null];
        }
        if ($nyttSteg === 'avslutat') {
            return ['moment' => 'avslut', 'enforcement' => $pa($gc?->avslutMotiv() ?? false)];
        }
        return ['moment' => null, 'enforcement' => 'ingen'];
    }

    /**
     * Frys ärendets brain-tenant(er) vid avslut (SPEC kap 3.4/9.2). BEST-EFFORT och
     * defensiv: hela metoden sväljer allt och får ALDRIG fälla den övergång den följer.
     * Resolverar tenant_id ur pekar-registret (pekartyp 'brain_tenant') och kallar
     * {@see BrainProvisionService::freeze()} (dödar skrivnyckeln + REVOKE). En lyckad
     * frysning journalförs som TYP_AI/fryst (koordinationsdata utan ärendeinnehåll).
     * No-op när brain/pekare saknas (testharness/icke-brainmiljö) eller inget rum finns.
     */
    private function frysBrainVidAvslut(string $hubsCaseId): void {
        if ($this->brainProvisionService === null || $this->pekareMapper === null) {
            return;
        }
        try {
            foreach ($this->pekareMapper->findByCaseAndTyp($hubsCaseId, 'brain_tenant') as $p) {
                $tenantId = $p->getObjektId();
                if ($tenantId === '') {
                    continue;
                }
                if ($this->brainProvisionService->freeze($tenantId, $hubsCaseId, 'avslut')) {
                    $this->journalfor($hubsCaseId, HandelseTypAi::FRYST);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('hubs_arende: brain-frysning vid avslut misslyckades (graceful)', [
                'app' => 'hubs_arende',
                'hubsCaseId' => $hubsCaseId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Journalför en AI-livscykelhändelse (TYP_AI) — koordinationsdata utan PII:
     * enbart {handling}. Best-effort; ett journal-fel får aldrig fälla övergången.
     */
    private function journalfor(string $hubsCaseId, string $handling): void {
        if ($this->handelseMapper === null) {
            return;
        }
        try {
            $this->handelseMapper->record(
                $hubsCaseId,
                HandelseTypAi::typVarde(),
                ['handling' => $handling],
                $this->userSession?->getUser()?->getUID() ?? '',
            );
        } catch (\Throwable $e) {
            $this->logger->warning('hubs_arende: TYP_AI-journal misslyckades (graceful)', [
                'app' => 'hubs_arende', 'hubsCaseId' => $hubsCaseId, 'handling' => $handling,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Recompute fristDue iff the case's ärendetyp fristPolicy defines a per-step
     * frist (`perStegFrist`/`stegFrister` map: steg → days) for the target step.
     * When no such per-step entry exists the frist is left exactly as-is — the
     * createCase/Treserva clock stays authoritative.
     */
    private function maybeRecomputeFristDue(Arende $arende, string $nyttSteg): void {
        $typ = $this->typRegistry->get($arende->getArendeTyp());
        if ($typ === null) {
            return;
        }
        $days = $this->perStegFristDays($typ, $nyttSteg);
        if ($days === null) {
            // No per-step frist for this step → leave fristDue unchanged.
            return;
        }
        $due = (clone $this->timeFactory->getDateTime())->add(new \DateInterval('P' . $days . 'D'));
        $arende->setFristDue($due);
    }

    /**
     * Read a per-step frist (in days) from the ärendetyp's fristPolicy JSON, if it
     * declares one under `perStegFrist` (or the alias `stegFrister`) as a
     * steg → int(days) map. Returns null when no per-step frist applies.
     */
    private function perStegFristDays(ArendeTyp $typ, string $steg): ?int {
        $policy = $this->decodeFristPolicy($typ->getFristPolicy());
        $map = $policy['perStegFrist'] ?? $policy['stegFrister'] ?? null;
        if (!is_array($map) || !isset($map[$steg])) {
            return null;
        }
        $days = $map[$steg];
        if (!is_int($days) && !(is_string($days) && ctype_digit($days))) {
            return null;
        }
        $days = (int)$days;
        return $days > 0 ? $days : null;
    }

    /**
     * Decode the ärendetyp's fristPolicy JSON text into an array (empty on null /
     * malformed JSON — mirrors ArendeService::decodeFristPolicy).
     *
     * @return array<string, mixed>
     */
    private function decodeFristPolicy(?string $json): array {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
