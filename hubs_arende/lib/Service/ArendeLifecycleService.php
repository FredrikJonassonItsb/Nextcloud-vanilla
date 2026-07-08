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

        // PLIKT-GRIND (fas-spärr, ORO-1 / spec §3.2 kat1 + §2.3 pliktGrind): en typ
        // som deklarerar pliktGrind=true (orosanmälan) får INTE inleda utredning
        // (forhandsbedomning→utredning) förrän skyddsbedömningen är KVITTERAD. "Inte
        // inleda" (forhandsbedomning→avslutat) förblir ogated. Config-driven — ingen
        // if(kategori===1)-gren. Kvittensen är ett EXPLICIT signal i $kontext (frontend
        // skickar den när handläggaren kvitterat skyddsbedömningen); den får INTE
        // härledas ur 'steg' (cirkulärt — det är just steg-flytten som gateas).
        if (
            $franSteg === 'forhandsbedomning'
            && $nyttSteg === 'utredning'
            && ($kontext['skyddsbedomningKvitterad'] ?? false) !== true
        ) {
            $typ = $this->typRegistry->get($arende->getArendeTyp());
            if ($typ !== null && $typ->getPliktGrind() === true) {
                throw new \InvalidArgumentException(
                    'Plikt-grind: skyddsbedömningen måste kvitteras innan utredning inleds.'
                );
            }
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
                    ['fran' => $franSteg, 'till' => $nyttSteg],
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
