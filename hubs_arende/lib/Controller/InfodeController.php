<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Controller;

use OCA\HubsArende\AppInfo\Application;
use OCA\HubsArende\Service\ArendeMatchService;
use OCA\HubsArende\Service\ArendeService;
use OCA\HubsArende\Service\InnehallsKlassService;
use OCA\HubsArende\Service\SakerhetsskyddGrind;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * OCS surface for the inflöde-bands (the three bands the hubs_start dashboard
 * already calls: "Att ta emot" 1a / "Att hantera" 1b / "Ej ärendekopplat" 1c).
 *
 * This is the server-side aggregat that {@see fetchInflodeSummary()} and
 * {@see inflodeAction()} bind to. Klassning + ärende-match are computed HERE
 * (server-side, no client fan-out, K-4.30) by {@see InnehallsKlassService} and
 * {@see ArendeMatchService}; the dashboard receives finished fields.
 *
 * Thin transport: it validates/normalises input and delegates writes to
 * {@see ArendeService} (the saga single-writer). No coordination state is
 * computed here beyond shaping the read aggregate.
 *
 * Säkerhetsskydd (M3): the fail-closed R0-grind no longer lives only on the
 * write/födelse-path. {@see avvisadAvGrind()} centralises it on the READ/triage
 * path too — it runs FIRST on every inflöde-rad (in {@see inflodeSummary()} and
 * {@see doKoppla()}), before any klassning/matchning, so klassat material is
 * isolated with a neutral karantän-markör and never reaches
 * {@see InnehallsKlassService} or {@see ArendeMatchService}.
 *
 * In demo / utan riktigt inflöde the inflow feed is not wired, so the summary
 * returns an empty (but well-formed) structure and the action verbs return a
 * deterministic synthetic ack — never any PII (OSL 26 kap.).
 *
 * Effective prefix: /ocs/v2.php/apps/hubs_arende/api/v1/...
 */
class InfodeController extends OCSController {
    /** Allowed inflöde-verbs (POST /inflode/{action}). */
    private const ACTIONS = [
        'koppla',
        'skapa',
        'besvara',
        'vidarebefordra',
        'gallra',
        'registrera',
    ];

    public function __construct(
        IRequest $request,
        private readonly ArendeService $arendeService,
        private readonly ArendeMatchService $matchService,
        private readonly InnehallsKlassService $klassService,
        private readonly LoggerInterface $logger,
        // TRAILING OPTIONAL (autowired by the NC DI container): the fail-closed
        // R0 säkerhetsskydds-grind. Centralises the gate on the READ/triage path
        // (M3) so klassning/matchning never run on klassat material. Null only in
        // the positional unit harness — see {@see avvisadAvGrind()} for the
        // graceful degrade.
        private readonly ?SakerhetsskyddGrind $sakerhetsskyddGrind = null,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Multi-korg inflöde summary: behörighetsfiltrerade korgar + inflöde-rader
     * klassade (InnehallsKlassService) och ärende-matchade (ArendeMatchService),
     * server-side. Drives KorgValjare + the three bands.
     *
     * Returns finished fields only — counts, klass and koppling, never innehåll
     * (OSL 26 kap.). In demo (no live inflow feed) the structure is empty/
     * syntetisk with no PII.
     *
     * GET /api/v1/inflode-summary
     *
     * @return DataResponse<int, array{korgar:list<array<string,mixed>>, inflode:list<array<string,mixed>>}, array{}>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function inflodeSummary(): DataResponse {
        try {
            // No live inflow feed is wired into the standalone engine yet, so the
            // source rows are empty. The shape is preserved (korgar + inflode) and
            // each row would be enriched via the two services below.
            $korgar = $this->resolveKorgar();
            $rawRows = $this->resolveInflodeRows();

            $inflode = [];
            foreach ($rawRows as $rad) {
                // M3: säkerhetsskydds-grinden körs som FÖRSTA steg per rad, INNAN
                // någon klassning eller matchning. Klassat material får aldrig nå
                // klassService/matchService — vid avvisad utelämnas raden och
                // ersätts med en neutral karantän-markör (ingen PII, ingen klass,
                // ingen koppling beräknas på den avvisade raden).
                $avvisad = $this->avvisadAvGrind($rad);
                if ($avvisad !== null) {
                    $inflode[] = $avvisad;
                    continue;
                }

                $klass = $this->klassService->klassificera($rad);
                $koppling = $this->matchService->match($rad);
                $inflode[] = [
                    'id' => (string)($rad['id'] ?? ''),
                    'korg' => $rad['korg'] ?? null,
                    'messageType' => (string)($rad['messageType'] ?? ''),
                    'klassning' => $klass,
                    'koppling' => $koppling,
                    'foreslagenAtgard' => $klass['atgard'],
                    // Gap 2: top-level routing-band-fält så frontend zonOf() kan
                    // dela de tre banden (1a "nytt" / 1b "hör till" / 1c "ej
                    // kopplat") utan att inspektera nästlad koppling/klass.
                    'arendekoppling' => $this->arendekopplingOf($koppling, $klass),
                ];
            }

            return new DataResponse(
                ['korgar' => $korgar, 'inflode' => $inflode],
                Http::STATUS_OK,
            );
        } catch (\Throwable $e) {
            $this->logger->error('hubs_arende inflodeSummary failed', ['exception' => $e]);
            return new DataResponse(['error' => 'inflode_summary_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Apply an inflöde-åtgärd to one row (koppla|skapa|besvara|vidarebefordra|
     * gallra|registrera). Writes are orchestrated atomically by ArendeService;
     * read-only verbs (besvara|vidarebefordra|gallra) ack without a register
     * mutation in demo.
     *
     * POST /api/v1/inflode/{action}
     *
     * @param string $action one of self::ACTIONS
     * @param array<string,mixed> $rad the inflöde-rad (routing-fält, ingen PII)
     * @param ?string $hubsCaseId target case for 'koppla'/'registrera'
     *
     * @return DataResponse<int, array<string,mixed>, array{}>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function inflodeAction(?string $action = null, array $rad = [], ?string $hubsCaseId = null): DataResponse {
        // Plattforms-quirk (NC 31): ett AVSLUTANDE OCS-path-segment ({action}) binds
        // INTE till den typade parametern för POST (mid-path {ref} och body-params
        // binder dock). Återställ verbet ur request-URI:n när bindningen ger null, så
        // hela inflöde-action-ytan (koppla/skapa/...) fungerar utan kontraktsändring.
        if ($action === null || $action === '') {
            $action = $this->actionFromUri();
        }
        if (!in_array($action, self::ACTIONS, true)) {
            return new DataResponse(
                ['error' => 'okand_atgard', 'action' => $action],
                Http::STATUS_BAD_REQUEST,
            );
        }

        try {
            return match ($action) {
                'skapa', 'registrera' => $this->doSkapa($rad),
                'koppla' => $this->doKoppla($rad, $hubsCaseId),
                // besvara|vidarebefordra|gallra carry no register write in the
                // standalone engine yet — ack deterministically (no PII).
                default => new DataResponse(
                    ['ok' => true, 'action' => $action, 'id' => (string)($rad['id'] ?? '')],
                    Http::STATUS_OK,
                ),
            };
        } catch (\OCA\HubsArende\Exception\AvvisadException $e) {
            // Säkerhetsskydd-grind rejected the row: nothing was created. Surface
            // the avvisningskvitto verbatim so the caller can show it.
            return new DataResponse(
                [
                    'avvisad' => true,
                    'reason' => $e->getMessage(),
                    'kvitto' => $e->getKvitto(),
                    'retroaktiv' => $e->isRetroaktiv(),
                ],
                Http::STATUS_FORBIDDEN,
            );
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('hubs_arende inflodeAction failed', [
                'exception' => $e,
                'action' => $action,
            ]);
            return new DataResponse(['error' => 'inflode_action_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // ================================================================== //
    //  Internal helpers
    // ================================================================== //

    /**
     * Recover the {action} verb from the request URI's last path segment.
     *
     * Workaround for the NC OCS POST terminal-path-param binding quirk (the typed
     * $action param arrives null even though the route matched /inflode/{action}).
     * Strips any query string, takes the final non-empty segment, lower-cases it.
     */
    private function actionFromUri(): string {
        $uri = $this->request->getRequestUri();
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return '';
        }
        $path = rtrim($path, '/');
        $seg = substr($path, strrpos($path, '/') + 1);
        return strtolower($seg);
    }

    /**
     * M3 — centralised fail-closed säkerhetsskydds-gate for the READ/triage path.
     *
     * Runs {@see SakerhetsskyddGrind::evaluate()} as the FIRST step on an inflöde-
     * rad, before any klassning/matchning. The same single callsite is shared by
     * {@see inflodeSummary()} and {@see doKoppla()} so the gate lives in exactly
     * one place (no second copy of the evaluation logic).
     *
     * @param array<string,mixed> $rad The raw inflöde-rad.
     *
     * @return array<string,mixed>|null A neutral karantän-markör (no PII, no
     *   klassning, no koppling) when the row is rejected; null when the row passes
     *   the gate and may be klassad/matchad normally.
     */
    private function avvisadAvGrind(array $rad): ?array {
        // Graceful degrade: the positional unit harness / CLI smoke construct this
        // controller without the autowired grind. The live read-path (a wired
        // inflöde-feed) always has it injected; the födelse-/persistens-vägen
        // (createCase) stays independently grindad in ArendeService either way, so
        // a null grind here cannot let klassat material be persisted.
        if ($this->sakerhetsskyddGrind === null) {
            return null;
        }

        $res = $this->sakerhetsskyddGrind->evaluate($rad);
        if (($res['avvisad'] ?? false) !== true) {
            return null;
        }

        $this->logger->warning('hubs_arende inflöde-rad avvisad av säkerhetsskydds-grind (läs/triage-väg)', [
            'reason' => $res['reason'] ?? SakerhetsskyddGrind::REASON_SAKERHETSSKYDD,
            'indikator' => $res['indikator'] ?? SakerhetsskyddGrind::IND_SAKERHETSSKYDD,
        ]);

        // Neutral karantän-markör: a stable id is preserved for the dashboard to
        // address the row, but NO innehåll, klassning or koppling is emitted —
        // the row is replaced wholesale, not enriched.
        return [
            'id' => (string)($rad['id'] ?? ''),
            'karantan' => true,
            'reason' => (string)($res['reason'] ?? SakerhetsskyddGrind::REASON_SAKERHETSSKYDD),
            'indikator' => (string)($res['indikator'] ?? SakerhetsskyddGrind::IND_SAKERHETSSKYDD),
            'retroaktiv' => (bool)($res['retroaktiv'] ?? false),
            'klassning' => null,
            'koppling' => null,
            'foreslagenAtgard' => 'karantan',
        ];
    }

    /**
     * Gap 2 — härled top-level routing-bandet 'arendekoppling' ur radens
     * koppling/klass, så frontendens zonOf() kan dela de tre banden utan att
     * gräva i nästlade fält:
     *
     *  - 'hor_till'    : raden är (eller föreslås) kopplad till ett befintligt
     *                    ärende (koppling.status ∈ {kopplad, foreslagen}).
     *  - 'ej_kopplat'  : raden är triagerad/löst men utan ärende-koppling
     *                    (klassen pekar inte på ett orosanmälan-likt nytt ärende).
     *  - 'nytt'        : otriagerat orosanmälan-likt inflöde som ska bli ett nytt
     *                    ärende (default när varken kopplad/föreslagen eller löst).
     *
     * Rent härlett — muterar inte koppling/klass och ekar ingen PII.
     *
     * @param array<string,mixed>|null $koppling Resultatet av ArendeMatchService::match().
     * @param array<string,mixed>|null $klass    Resultatet av InnehallsKlassService::klassificera().
     *
     * @return string 'nytt'|'hor_till'|'ej_kopplat'
     */
    private function arendekopplingOf(?array $koppling, ?array $klass): string {
        $status = is_array($koppling) ? (string)($koppling['status'] ?? '') : '';
        if ($status === 'kopplad' || $status === 'foreslagen') {
            return 'hor_till';
        }

        // Ingen ärende-koppling: skilj otriagerat orosanmälan-likt (→ nytt ärende)
        // från löst/avfört material (→ ej kopplat). Klassens åtgärd/markörer styr.
        $atgard = is_array($klass) ? (string)($klass['atgard'] ?? '') : '';
        $arendeLikt = is_array($klass)
            ? (bool)($klass['arendeLikt'] ?? $klass['orosanmalan'] ?? false)
            : false;
        $lostUtanKoppling = in_array($atgard, ['gallra', 'besvara', 'vidarebefordra', 'arkivera'], true);

        if ($arendeLikt && !$lostUtanKoppling) {
            return 'nytt';
        }
        if ($lostUtanKoppling) {
            return 'ej_kopplat';
        }

        // Default: otriagerat inflöde behandlas som ett potentiellt nytt ärende
        // (band 1a "Att ta emot"), inte som redan avfört.
        return 'nytt';
    }

    /**
     * 'skapa'/'registrera' → run the saga (createCase). Returns the new register
     * row on 201, or the existing one on an idempotent hit.
     *
     * @param array<string,mixed> $rad
     */
    private function doSkapa(array $rad): DataResponse {
        $arende = $this->arendeService->createCase($rad);
        return new DataResponse($arende->jsonSerialize(), Http::STATUS_CREATED);
    }

    /**
     * 'koppla' → durably tie the inflöde-rad's message(s) to an existing case.
     * Delegates to {@see ArendeService::kopplaMeddelande()}, which tags the
     * message(s) case:{hubsCaseId} in sdkmc and records a 'conversation' pekare
     * ONLY for tags that actually landed (no false coupling). The response carries
     * `verifierad` so the dashboard shows "kopplat" only on a durable tag.
     *
     * @param array<string,mixed> $rad
     */
    private function doKoppla(array $rad, ?string $hubsCaseId): DataResponse {
        if ($hubsCaseId !== null && $hubsCaseId !== '') {
            // kopplaMeddelande() does the existence + H1-authz gate via show().
            $res = $this->arendeService->kopplaMeddelande($hubsCaseId, $this->inflodeMessageIds($rad));
            return new DataResponse(
                ['action' => 'koppla', 'id' => (string)($rad['id'] ?? '')] + $res,
                Http::STATUS_OK,
            );
        }

        // M3: grinden körs FÖRE match() — klassat material matchas aldrig mot
        // koordinationsregistret. Vid avvisad returneras en neutral karantän-
        // markör (ingen koppling beräknas, ingen PII ekas).
        $avvisad = $this->avvisadAvGrind($rad);
        if ($avvisad !== null) {
            return new DataResponse(
                ['ok' => false, 'action' => 'koppla'] + $avvisad,
                Http::STATUS_FORBIDDEN,
            );
        }

        $koppling = $this->matchService->match($rad);
        return new DataResponse(
            ['ok' => true, 'action' => 'koppla', 'koppling' => $koppling, 'id' => (string)($rad['id'] ?? '')],
            Http::STATUS_OK,
        );
    }

    /**
     * Extract the sdkmc/mail message id(s) to couple from an inflöde-rad. Accepts
     * `messageIds` (list), `messageId` (scalar) or falls back to the rad `id`.
     *
     * @param array<string,mixed> $rad
     * @return int[]
     */
    private function inflodeMessageIds(array $rad): array {
        $ids = $rad['messageIds'] ?? (isset($rad['messageId']) ? [$rad['messageId']] : null);
        if ($ids === null && isset($rad['id'])) {
            $ids = [$rad['id']];
        }
        return array_values(array_filter(array_map('intval', (array)($ids ?? []))));
    }

    /**
     * Behörighetsfiltrerade korgar. No live korg-model in the standalone engine
     * yet (that lives in sdkmc/mail), so this returns an empty list rather than
     * fabricating addresses.
     *
     * @return list<array<string,mixed>>
     */
    private function resolveKorgar(): array {
        return [];
    }

    /**
     * Raw inflöde-rader from the live inflow feed. Not wired into the standalone
     * engine yet (the feed lives in sdkmc/mail via MessageReceivedEvent), so this
     * returns an empty list — never synthetic PII.
     *
     * @return list<array<string,mixed>>
     */
    private function resolveInflodeRows(): array {
        return [];
    }
}
