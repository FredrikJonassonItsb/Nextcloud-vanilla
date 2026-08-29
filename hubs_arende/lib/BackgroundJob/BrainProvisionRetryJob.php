<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\BackgroundJob;

use OCA\HubsArende\Db\ArendeMapper;
use OCA\HubsArende\Db\Handelse;
use OCA\HubsArende\Db\HandelseMapper;
use OCA\HubsArende\Service\Brain\BrainProvisionRetryService;
use OCA\HubsArende\Service\Brain\BrainProvisionService;
use OCA\HubsArende\Service\Brain\BrainProvisionUnavailable;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * DURABEL retry av brain-provisionering (SPEC-BRAIN-PER-ÄRENDE kap 3.3).
 *
 * Komplement till SAGA-steget R2b: R2b är ICKE-FÄLLANDE — provisionern onåbar/timeout/5xx
 * ⇒ ärendet skapas UTAN brain och köas i {@see BrainProvisionRetryService}. Detta jobb
 * plockar förfallna `pending`-rader var 15:e minut (exponentiell backoff) och kör om det
 * IDEMPOTENTA `POST /provision/tenants` tills brainen finns.
 *
 * FÖRÄLDRALÖS-SKYDD (kap 3.3, dubbel spärr mot läckageyta): FÖRSTA steget per rad är att
 * verifiera att ärendet FORTFARANDE existerar i registret ({@see ArendeMapper::findByCaseId}).
 * Saknas det (en sen SAGA-rollback rev register-raden R2 efter enqueue) ⇒ raden sätts
 * `permanent_fel` och HOPPAS — en brain provisioneras ALDRIG för ett ärende som inte finns.
 * (Andra spärren är R2b:s kompensationssteg `R2b:retry-neutralisera`, kap 3.3.)
 *
 * LARM: efter N={@see self::LARM_TROSKEL} misslyckade försök loggas ett driftlarm
 * (critical) — bekräftas mot kommunens driftrutin (SPEC B29). Jobbet ger dock inte upp:
 * en onåbar provisioner är ett infra-tillstånd som självläker när tjänsten är uppe igen.
 *
 * TIME_INSENSITIVE (får skjutas till lågtrafik). Ett fel i svepet får ALDRIG krascha
 * cron-runnern — allt sväljs och loggas; nästa körning tar samma (idempotenta) batch igen.
 */
class BrainProvisionRetryJob extends TimedJob {
    /** 15 min baskadens (SPEC B29). */
    private const INTERVAL = 15 * 60;
    /** Larmtröskel: antal misslyckanden innan driftlarm (SPEC B29, N=5). */
    private const LARM_TROSKEL = 5;
    /** Backoff-tak: aldrig längre än ett dygn mellan försök. */
    private const BACKOFF_CAP = 24 * 3600;
    /** Max rader per körning (kön är normalt nära tom). */
    private const BATCH = 50;

    /**
     * Journaltyp för AI-koordination. `typ` är en fri sträng (ingen migration) — den
     * kanoniska konstanten {@see Handelse}::TYP_AI ägs av kärnintegrationen (P-A). Håll
     * VÄRDET synkat: 'ai'. Se oppnaFragor.
     */
    private const TYP_AI = 'ai';

    public function __construct(
        ITimeFactory $time,
        private ArendeMapper $arendeMapper,
        private BrainProvisionRetryService $retry,
        private BrainProvisionService $provision,
        private HandelseMapper $handelseMapper,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(self::INTERVAL);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    /**
     * @param mixed $argument Oanvänt — svepet är parameterlöst.
     *
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    protected function run($argument): void {
        try {
            $koade = $this->retry->claimDue(self::BATCH);
            $klara = 0;
            foreach ($koade as $rad) {
                if ($this->hanteraRad($rad['hubs_case_id'], $rad['arende_typ'], $rad['forsok'])) {
                    $klara++;
                }
            }
            $this->logger->info('hubs_arende: BrainProvisionRetryJob klar', [
                'app' => 'hubs_arende',
                'behandlade' => count($koade),
                'klara' => $klara,
            ]);
        } catch (\Throwable $e) {
            // Ett fel i svepet får aldrig krascha cron-runnern.
            $this->logger->error('hubs_arende: BrainProvisionRetryJob fel', [
                'app' => 'hubs_arende',
                'exception' => $e,
            ]);
        }
    }

    /**
     * Behandla EN köad rad. Returnerar true om brainen provisionerades (status=klar).
     */
    private function hanteraRad(string $hubsCaseId, string $arendeTyp, int $forsok): bool {
        // FÖRÄLDRALÖS-SKYDD: provisionera ALDRIG en brain för ett ärende som inte längre
        // finns i registret (kap 3.3, spärr 2). Detta FÖRE varje POST.
        try {
            $this->arendeMapper->findByCaseId($hubsCaseId);
        } catch (DoesNotExistException) {
            $this->retry->markPermanent($hubsCaseId);
            $this->logger->warning('hubs_arende: brain-retry hoppar över föräldralöst ärende', [
                'app' => 'hubs_arende',
                'hubsCaseId' => $hubsCaseId,
            ]);
            return false;
        } catch (\Throwable $e) {
            // Registret oläsbart just nu ⇒ rör inte raden, försök igen nästa svep.
            $this->logger->warning('hubs_arende: brain-retry kunde ej verifiera ärende (skjuter upp)', [
                'app' => 'hubs_arende',
                'hubsCaseId' => $hubsCaseId,
                'exception' => $e->getMessage(),
            ]);
            return false;
        }

        try {
            $res = $this->provision->provision($hubsCaseId, $arendeTyp);
        } catch (BrainProvisionUnavailable $e) {
            // Fortsatt retrybart ⇒ backoff + ev. larm.
            $this->schemalaggMedBackoff($hubsCaseId, $forsok, $e->getMessage());
            return false;
        }

        if (($res['permanent_fel'] ?? false) === true) {
            // 409/422 ⇒ terminalt, ingen mer retry (kap 3.3).
            $this->retry->markPermanent($hubsCaseId);
            $this->logger->critical('hubs_arende: brain-retry permanent fel (driftlarm)', [
                'app' => 'hubs_arende',
                'hubsCaseId' => $hubsCaseId,
                'kod' => (string)($res['kod'] ?? ''),
            ]);
            return false;
        }

        if (($res['noop'] ?? false) === true) {
            // Provisionern ej konfigurerad i denna miljö ⇒ lämna pending, försök när konfig finns.
            $this->logger->debug('hubs_arende: brain-retry NO-OP (provisioner ej konfigurerad)', [
                'app' => 'hubs_arende',
                'hubsCaseId' => $hubsCaseId,
            ]);
            return false;
        }

        // Lyckat (201/200 idempotent): markera klar + journalför (best-effort, kap 3.3 —
        // status-fältet utelämnas vid lyckad efterprovisionering).
        $this->retry->markKlar($hubsCaseId);
        $this->journalfor($hubsCaseId, ['handling' => 'provisionerad']);
        $this->logger->info('hubs_arende: brain-retry lyckad efterprovisionering', [
            'app' => 'hubs_arende',
            'hubsCaseId' => $hubsCaseId,
            'idempotent' => ($res['idempotent'] ?? false) === true,
        ]);
        return true;
    }

    private function schemalaggMedBackoff(string $hubsCaseId, int $forsok, string $orsak): void {
        $nyForsok = $forsok + 1;
        $nasta = $this->time->getTime() + $this->backoffSekunder($nyForsok);
        $this->retry->schemalaggAterforsok($hubsCaseId, $nyForsok, $nasta);

        if ($nyForsok >= self::LARM_TROSKEL) {
            $this->logger->critical('hubs_arende: brain-retry upprepade misslyckanden (driftlarm)', [
                'app' => 'hubs_arende',
                'hubsCaseId' => $hubsCaseId,
                'forsok' => $nyForsok,
                'orsak' => $orsak,
            ]);
        } else {
            $this->logger->warning('hubs_arende: brain-retry misslyckades, schemalagt om', [
                'app' => 'hubs_arende',
                'hubsCaseId' => $hubsCaseId,
                'forsok' => $nyForsok,
            ]);
        }
    }

    /** Exponentiell backoff: 15 min · 2^(forsok-1), tak {@see self::BACKOFF_CAP}. */
    private function backoffSekunder(int $forsok): int {
        $exp = max(0, $forsok - 1);
        // Undvik overflow vid extrema räknare — tak innan pow behövs inte men var defensiv.
        if ($exp > 20) {
            return self::BACKOFF_CAP;
        }
        $sek = self::INTERVAL * (2 ** $exp);
        return (int)min($sek, self::BACKOFF_CAP);
    }

    /**
     * Best-effort journalföring — får ALDRIG fälla svepet (kap 3.3).
     *
     * @param array<string,mixed> $detalj
     */
    private function journalfor(string $hubsCaseId, array $detalj): void {
        try {
            $this->handelseMapper->record($hubsCaseId, self::TYP_AI, $detalj);
        } catch (\Throwable $e) {
            $this->logger->warning('hubs_arende: brain-retry journalföring misslyckades (graceful)', [
                'app' => 'hubs_arende',
                'hubsCaseId' => $hubsCaseId,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
