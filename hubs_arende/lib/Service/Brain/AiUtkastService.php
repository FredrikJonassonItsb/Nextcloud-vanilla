<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Service\Brain;

use OCA\HubsArende\Db\AiUtkast;
use OCA\HubsArende\Db\AiUtkastMapper;
use OCA\HubsArende\Db\HandelseMapper;
use OCA\HubsArende\Service\ArendeService;
use OCA\HubsArende\Service\HandlingService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * HITL-BACKEND för brain-per-ärende (SPEC-BRAIN-PER-ARENDE kap 8, MÄNNISKA-I-LOOPEN).
 *
 * Äger AI-utkastregistrets livscykel: {@see skapa()} (orkestreraren skriver ett
 * utkast) → {@see hamta()}/{@see lista()} (granskningsvyn) → {@see godkann()} /
 * {@see avvisa()} (människans beslut). Ett utkast blir en HANDLING i akten ENDAST
 * vid ett mänskligt godkännande (facit: aldrig maskinell commit) — då skapas
 * handlingen via {@see HandlingService::generera()} med provenansmetadata, och
 * utkastets råa innehåll NOLLAS i samma svep (raderingsfönstret, 8.0.4).
 *
 * FACIT som denna service upprätthåller:
 *  - AI-utkast blir handling FÖRST vid HITL-godkännande (aldrig maskinell commit).
 *  - fn_draft_beslutsformulering får aldrig bära beslutsUTFALL: vid godkännande
 *    DUBBELKOLLAS serverside att utkastets utfall_eko == människans ställnings-
 *    tagande OCH att beslutstexten inte träffar utfalls-/rekommendationslexikonet
 *    ({@see verifieraBeslutssparr()}, 8.0.5/8.8) — inte enbart i orkestreraren.
 *  - `innehall` (rått AI-innehåll) nollas vid BÅDE godkännande och avvisning.
 *  - Audit (TYP_AI) utan ärendeinnehåll: bara koordinationsvärden.
 *
 * H1 (existens läcker aldrig): all authz går via {@see ArendeService::show()}
 * (enhet-/medlemsgrinden); okänt/ej-behörigt ärende ELLER okänt utkast ⇒
 * DoesNotExistException (OCS-lagret → 404). Konflikter (redan avgjort /
 * utfallsspärr) ⇒ {@see AiUtkastKonfliktException} (OCS → 409).
 */
class AiUtkastService {
    /**
     * Spärrlexikon (SPEC 8.0.5 pkt 3) — utfalls-/rekommendationsmönster OCH
     * profileringsmönster. Träff i en beslutstext vid godkännande ⇒ utfallsspärr.
     * Case-insensitivt, unicode. Djupförsvar: den BINDANDE spärren är HITL +
     * frånvaron av maskinell commit-väg — lexikonet är kringgåeligt men dubbleras
     * ändå serverside här (granskningsfynd säkerhet 8.0.5/8.8).
     */
    private const SPARRLEXIKON = [
        '/\b(rekommenderar|föreslår att|bör (beviljas|avslås|inledas?|inte inledas?|placeras|omhändertas)|talar för (bifall|avslag)|lämplig(aste)? insats)\b/iu',
        '/\b(riskpoäng|riskprofil|risknivå|sannolikhet(en)? att (barnet|klienten|föräldern))\b/iu',
    ];

    public function __construct(
        private ArendeService $arendeService,
        private AiUtkastMapper $mapper,
        private HandlingService $handlingService,
        private LoggerInterface $logger,
        // TRAILING OPTIONAL (autowired): journal + session speglar HandlingService.
        // Best-effort — får ALDRIG fälla HITL-beslutet de beskriver.
        private ?HandelseMapper $handelseMapper = null,
        private ?IUserSession $userSession = null,
    ) {
    }

    /**
     * Skriv ett nytt AI-utkast (status=utkast). Anropas av orkestreraren SEDAN
     * dess egen authz (brain-gw) släppt igenom körningen — därför ingen show()-
     * grind här; ärendetillhörigheten bärs av $hubsCaseId. Journalför utkast_skapat
     * (lokal livscykelmeta — ingesteras aldrig externt).
     *
     * @param array<string,mixed> $innehall  Utkast-JSON (rått AI-innehåll).
     * @param array<int|string,mixed> $kallrefs JSON-lista handelse-/tanke-id:n.
     * @param array<string,mixed> $provenans {runId, mallId?, modell?, modellversion?,
     *                                        promptVersion?, utfallEko?}.
     * @throws \OCP\DB\Exception
     */
    public function skapa(string $hubsCaseId, string $funktion, array $innehall, array $kallrefs, array $provenans): AiUtkast {
        $utkast = new AiUtkast();
        $utkast->setHubsCaseId($hubsCaseId);
        $utkast->setRunId((string)($provenans['runId'] ?? ''));
        $utkast->setFunktion($funktion);
        $utkast->setMallId(isset($provenans['mallId']) ? (string)$provenans['mallId'] : null);
        $utkast->setInnehall(json_encode($innehall));
        $utkast->setKallrefs($kallrefs === [] ? null : json_encode($kallrefs));
        $utkast->setStatus(AiUtkast::STATUS_UTKAST);
        $utkast->setUtfallEko(isset($provenans['utfallEko']) ? (string)$provenans['utfallEko'] : null);
        $utkast->setModell(isset($provenans['modell']) ? (string)$provenans['modell'] : null);
        $utkast->setModellversion(isset($provenans['modellversion']) ? (string)$provenans['modellversion'] : null);
        $utkast->setPromptVersion(isset($provenans['promptVersion']) ? (string)$provenans['promptVersion'] : null);
        $utkast->setSkapad(new \DateTime());

        $skapat = $this->mapper->insert($utkast);

        $this->journalfor($hubsCaseId, HandelseTypAi::UTKAST_SKAPAT, [
            'funktion' => $funktion,
            'run_id' => $skapat->getRunId(),
            'modellversion' => $skapat->getModellversion(),
        ], (string)($provenans['aktorUid'] ?? ''));

        return $skapat;
    }

    /**
     * HITL-listan för ett ärende (metadata, ALDRIG innehall). Medlems-authz via
     * show() (H1).
     *
     * @return array<int,array<string,mixed>>
     * @throws DoesNotExistException Okänt ärende/ej behörig (H1: 404).
     */
    public function lista(string $ref): array {
        $arende = $this->arendeService->show($ref);
        $rader = $this->mapper->findByCaseId($arende->getHubsCaseId());
        return array_map(static fn (AiUtkast $u): array => $u->toListItem(), $rader);
    }

    /**
     * Ett enskilt utkast (full vy). Medlems-authz via show() (H1); okänt utkast
     * ELLER utkast i ANNAT ärende ⇒ DoesNotExistException (existens läcker aldrig).
     *
     * @throws DoesNotExistException
     */
    public function hamta(string $ref, int $utkastId): AiUtkast {
        $arende = $this->arendeService->show($ref);
        return $this->laddaForArende($utkastId, $arende->getHubsCaseId());
    }

    /**
     * GODKÄNN ett utkast (SPEC 8.0.7 steg 4). Ordning (facit: handling FÖRE nollning):
     *  (a) beslutssparr-dubbelkoll för fn_draft_beslutsformulering,
     *  (b) HandlingService::generera → .docx i akten + TYP_HANDLING,
     *  (c) `innehall` NULL + status=godkant i samma svep (raderingsfönstret),
     *  (d) journal TYP_AI utkast_godkant (+ diff_pct vid redigerat godkännande).
     *
     * @param string $ref                  hubsCaseId eller dnr (authz via show, H1).
     * @param int    $utkastId             Utkastets id.
     * @param string|null $stallningstagandeUtfall Människans utfall (fn_draft_beslutsformulering);
     *                                     måste eka utkastets utfall_eko (8.8).
     * @param array<string,mixed>|null $redigeradText Redigerat innehåll ersätter utkastets
     *                                     (redigerat godkännande → diff sparas).
     * @return array<string,mixed> {ok, utkastId, status, handling, diffPct}
     * @throws DoesNotExistException Okänt ärende/utkast eller ej behörig (404).
     * @throws AiUtkastKonfliktException Redan avgjort (409) eller utfallsspärr (409).
     * @throws \InvalidArgumentException Utkastet saknar mall_id (kan ej bli handling).
     * @throws \RuntimeException Ärenderummet ej tillgängligt (från HandlingService).
     */
    public function godkann(string $ref, int $utkastId, ?string $stallningstagandeUtfall = null, ?array $redigeradText = null): array {
        $arende = $this->arendeService->show($ref);
        $hubsCaseId = $arende->getHubsCaseId();
        $utkast = $this->laddaForArende($utkastId, $hubsCaseId);

        if (!$utkast->arOavgjort()) {
            throw AiUtkastKonfliktException::redanAvgjort($utkast->getStatus());
        }

        $mallId = $utkast->getMallId();
        if ($mallId === null || $mallId === '') {
            throw new \InvalidArgumentException('AI-utkastet saknar mall_id och kan inte bli en handling');
        }

        // Effektivt innehåll: redigerat vinner över utkastets original.
        $original = $this->avkodaInnehall($utkast->getInnehall());
        $effektivt = $redigeradText ?? $original;

        // (a) SERVERSIDE-DUBBELKOLL för beslutsformulering (8.0.5/8.8) — FÖRE handling.
        if ($utkast->getFunktion() === AiUtkast::FN_BESLUTSFORMULERING) {
            $this->verifieraBeslutssparr($utkast, $stallningstagandeUtfall, $effektivt);
        }

        // (b) Handlingen skapas i akten via mall + fält (provenans följer separat).
        $falt = $this->harledFalt($effektivt);
        $resultat = $this->handlingService->generera($ref, $mallId, $falt);

        // Redigerat godkännande: diff-indikator för provenans (gallras med ärendet).
        $redigerat = $redigeradText !== null;
        $diffPct = $redigerat ? $this->beraknaDiffPct($original, $effektivt) : null;

        // (c) RADERINGSFÖNSTER: nolla rått innehåll i samma svep som handlingen finns
        //     i akten (facksystemet är rättskällan; ingen dubbellagring i NC-backup).
        $utkast->setStatus(AiUtkast::STATUS_GODKANT);
        $utkast->setInnehall(null);
        $utkast->setAvgjordAv($this->aktorUid());
        $utkast->setAvgjord(new \DateTime());
        if ($redigerat) {
            $utkast->setDiffPct($diffPct);
            $utkast->setDiffText($this->byggDiff($original, $effektivt));
        }
        $this->mapper->update($utkast);

        // (d) TYP_AI utkast_godkant — innehållsfri provenans (HITL-utfall, ingesteras).
        $detalj = [
            'funktion' => $utkast->getFunktion(),
            'run_id' => $utkast->getRunId(),
            'modellversion' => $utkast->getModellversion(),
            'prompt_version' => $utkast->getPromptVersion(),
        ];
        if ($redigerat) {
            $detalj['diff_pct'] = $diffPct;
        }
        $this->journalfor($hubsCaseId, HandelseTypAi::UTKAST_GODKANT, $detalj, $this->aktorUid());

        return [
            'ok' => true,
            'utkastId' => $utkastId,
            'status' => AiUtkast::STATUS_GODKANT,
            'handling' => $resultat,
            'diffPct' => $diffPct,
        ];
    }

    /**
     * AVVISA ett utkast (SPEC 8.0.7 steg 5). `innehall` RADERAS omedelbart
     * (mellanprodukt, TF 2:12), status=avvisat, TYP_AI utkast_avvisat — INGEN
     * handling skapas.
     *
     * @param string $ref
     * @param int    $utkastId
     * @param string|null $orsakKategori fel_i_sak|ton|tackning|annat (journalförs, aldrig fritext).
     * @return array<string,mixed> {ok, utkastId, status}
     * @throws DoesNotExistException Okänt ärende/utkast eller ej behörig (404).
     * @throws AiUtkastKonfliktException Redan avgjort (409).
     */
    public function avvisa(string $ref, int $utkastId, ?string $orsakKategori = null): array {
        $arende = $this->arendeService->show($ref);
        $hubsCaseId = $arende->getHubsCaseId();
        $utkast = $this->laddaForArende($utkastId, $hubsCaseId);

        if (!$utkast->arOavgjort()) {
            throw AiUtkastKonfliktException::redanAvgjort($utkast->getStatus());
        }

        $utkast->setStatus(AiUtkast::STATUS_AVVISAT);
        $utkast->setInnehall(null);
        $utkast->setAvgjordAv($this->aktorUid());
        $utkast->setAvgjord(new \DateTime());
        $this->mapper->update($utkast);

        $detalj = ['funktion' => $utkast->getFunktion(), 'run_id' => $utkast->getRunId()];
        if ($orsakKategori !== null && $orsakKategori !== '') {
            $detalj['orsak_kategori'] = $orsakKategori;
        }
        $this->journalfor($hubsCaseId, HandelseTypAi::UTKAST_AVVISAT, $detalj, $this->aktorUid());

        return ['ok' => true, 'utkastId' => $utkastId, 'status' => AiUtkast::STATUS_AVVISAT];
    }

    // ------------------------------------------------------------------ //

    /**
     * SERVERSIDE-BESLUTSSPÄRR (8.0.5/8.8): (1) människans utfall MÅSTE eka
     * utkastets utfall_eko, (2) beslutstexten får INTE träffa utfalls-/
     * rekommendationslexikonet. Avvikelse ⇒ utfallsspärr (409), ingen handling.
     * fn_draft_beslutsformulering får aldrig bära beslutsUTFALL — gränsen är
     * maskinellt verifierad HÄR i hubs_arende, inte enbart i orkestreraren.
     *
     * @param array<string,mixed> $effektivt
     * @throws AiUtkastKonfliktException
     */
    private function verifieraBeslutssparr(AiUtkast $utkast, ?string $stallningstagandeUtfall, array $effektivt): void {
        $eko = $utkast->getUtfallEko();
        if ($eko === null || $eko === '' || $stallningstagandeUtfall === null || $stallningstagandeUtfall === '') {
            throw AiUtkastKonfliktException::utfallssparr('Beslutsutkastet saknar utfall_eko eller ställningstagande');
        }
        if (!hash_equals($eko, $stallningstagandeUtfall)) {
            throw AiUtkastKonfliktException::utfallssparr('Utkastets utfall_eko matchar inte människans ställningstagande');
        }
        if ($this->traffarSparrlexikon($this->plattText($effektivt))) {
            throw AiUtkastKonfliktException::utfallssparr('Beslutstexten innehåller ett utfalls-/rekommendationsförslag (Annex III 5(a)-gränsen)');
        }
    }

    /** true om texten träffar något spärrmönster (utfall/rekommendation/profilering). */
    private function traffarSparrlexikon(string $text): bool {
        foreach (self::SPARRLEXIKON as $monster) {
            if (preg_match($monster, $text) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Ladda ett utkast och verifiera att det tillhör $hubsCaseId. Fel case ⇒
     * DoesNotExistException (H1: en anropare som är behörig till ärende A får
     * aldrig veta att utkast-id X finns i ärende B).
     *
     * @throws DoesNotExistException
     */
    private function laddaForArende(int $utkastId, string $hubsCaseId): AiUtkast {
        try {
            $utkast = $this->mapper->findById($utkastId);
        } catch (DoesNotExistException $e) {
            throw $e;
        }
        if ($utkast->getHubsCaseId() !== $hubsCaseId) {
            throw new DoesNotExistException('Inget AI-utkast med id ' . $utkastId . ' i ärendet');
        }
        return $utkast;
    }

    /**
     * Härled mall-fält (nyckel→värde) ur utkastets JSON: föredrar en explicit
     * `falt`-nyckel; annars används en platt karta av skalära värden. HandlingService
     * hoppar okända/tomma nycklar naturligt.
     *
     * @param array<string,mixed> $innehall
     * @return array<string,mixed>
     */
    private function harledFalt(array $innehall): array {
        if (isset($innehall['falt']) && is_array($innehall['falt'])) {
            return $innehall['falt'];
        }
        $falt = [];
        foreach ($innehall as $nyckel => $varde) {
            if (is_scalar($varde)) {
                $falt[(string)$nyckel] = $varde;
            }
        }
        return $falt;
    }

    /**
     * Platta JSON-innehåll till en söksträng för spärrlexikonet: alla skalära
     * lövvärden konkateneras (fältNAMN utelämnas — vi söker i sakinnehållet, inte
     * i nyckelnamnen).
     *
     * @param array<string,mixed> $innehall
     */
    private function plattText(array $innehall): string {
        $delar = [];
        array_walk_recursive($innehall, static function ($varde) use (&$delar): void {
            if (is_scalar($varde)) {
                $delar[] = (string)$varde;
            }
        });
        return implode(' ', $delar);
    }

    /**
     * Diff-indikator (0–100 % ändrat) mellan original och redigerat innehåll —
     * provenans vid redigerat godkännande. Char-baserad likhet (similar_text);
     * grov men innehållsfri (bara procenttalet journalförs).
     *
     * @param array<string,mixed> $original
     * @param array<string,mixed> $redigerat
     */
    private function beraknaDiffPct(array $original, array $redigerat): int {
        $a = $this->plattText($original);
        $b = $this->plattText($redigerat);
        if ($a === '' && $b === '') {
            return 0;
        }
        $procentLika = 0.0;
        similar_text($a, $b, $procentLika);
        return (int)round(100.0 - $procentLika);
    }

    /**
     * Kompakt unified-liknande diff-markör (provenans, gallras med ärendet). Ren
     * längd-/skillnadssammanfattning — bär aldrig sakinnehåll ut ur akten.
     *
     * @param array<string,mixed> $original
     * @param array<string,mixed> $redigerat
     */
    private function byggDiff(array $original, array $redigerat): string {
        return json_encode([
            'orginalTecken' => mb_strlen($this->plattText($original)),
            'redigeratTecken' => mb_strlen($this->plattText($redigerat)),
        ]) ?: '';
    }

    /**
     * @param string|null $json
     * @return array<string,mixed>
     */
    private function avkodaInnehall(?string $json): array {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** Aktörens uid, '' = system/CLI (ingen session). */
    private function aktorUid(): string {
        return $this->userSession?->getUser()?->getUID() ?? '';
    }

    /**
     * Journalför en TYP_AI-händelse. BEST-EFFORT: journalfelet får ALDRIG fälla
     * HITL-beslutet det beskriver (mönstret speglar ArendeService::loggaHandelse /
     * HandlingService::loggaHandelse). detalj = litet JSON UTAN fritext/PII.
     *
     * @param array<string,mixed> $detalj
     */
    private function journalfor(string $hubsCaseId, string $underkategori, array $detalj, string $aktorUid): void {
        if ($this->handelseMapper === null) {
            return;
        }
        // Innehållsfri: nulla bort tomma provenansfält så detaljen förblir liten.
        $detalj = array_filter(['handling' => $underkategori] + $detalj, static fn ($v): bool => $v !== null && $v !== '');
        try {
            $this->handelseMapper->record($hubsCaseId, HandelseTypAi::typVarde(), $detalj, $aktorUid);
        } catch (\Throwable $e) {
            $this->logger->warning('hubs_arende: AiUtkastService — TYP_AI-journal misslyckades (best-effort)', [
                'app' => 'hubs_arende',
                'hubsCaseId' => $hubsCaseId,
                'underkategori' => $underkategori,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
