<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Service;

use OCA\HubsArende\Db\Arende;
use OCA\HubsArende\Db\ArendeMapper;
use OCA\HubsArende\Db\HandelseMapper;
use OCA\HubsArende\Db\MemberMapper;
use OCA\HubsArende\Db\PartMapper;
use OCA\HubsArende\Db\PekareMapper;
use OCA\HubsArende\Db\SakuppgiftMapper;
use OCA\HubsArende\Integration\Client\TeamClient;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * GDPR-gallring (art. 5.1.e — lagringsminimering) of the engine's OWN
 * coordination-/routing-row after the facksystem has taken over.
 *
 * NEVER-SoR PRINCIP: Hubs är ALDRIG System of Record. Verksamhetsdatat bor i
 * facksystemet (Treserva/Lifecare/Viva) och gallras av DET. Denna sweep purgar
 * enbart motorns egen pseudonyma pekar-/routing-rad (registret + dess pekare),
 * och först EFTER en verifierad commit som satt en verkställbar gallrings-deadline
 * från kvittot.
 *
 * Gallringsbarhet bestäms av {@see ArendeMapper::findGallringsbara()}:
 *   provenanceState='registrerad' AND retentionState='gallras_efter_commit'
 *   AND gallrasDatum IS NOT NULL AND gallrasDatum <= now.
 *
 * Idempotent: en re-körning hittar färre rader (de purgade är borta). Pekarna
 * raderas FÖRST, sedan register-raden, så ingen orphan-pekare överlever om raden
 * går bort.
 *
 * SÄKERHETSVAKT (fail-safe, defense in depth): {@see gallra()} kör en dubbelkoll i
 * loopen och raderar ALDRIG en rad som saknar gallrasDatum eller som inte är
 * provenanceState='registrerad' — även om en framtida query-regression skulle
 * släppa igenom en sådan rad.
 *
 * Loggar ENBART antal + hubsCaseId (pseudonym). ALDRIG objektRef/triageRef/dnr
 * eller annat innehåll (invariant: ingen PII i loggar/svar).
 */
class GallringService {
    public function __construct(
        private ArendeMapper $arendeMapper,
        private PekareMapper $pekareMapper,
        private LoggerInterface $logger,
        private ITimeFactory $timeFactory,
        // TRAILING OPTIONAL (autowired): the first-class member ledger. Gallring
        // MUST purge member rows (uid+roll = personal-PII) so no PII-rest survives
        // the coordination row (GDPR art. 5.1.e). Null only in a positional test
        // harness ⇒ member purge is a graceful skip.
        private ?MemberMapper $memberMapper = null,
        // TRAILING OPTIONAL (autowired): per-case access-group lifecycle. Gallring
        // raderar ärenderummets per-case-grupp så ingen tom grupp blir kvar.
        private ?ArenderumGroupService $arenderumGroupService = null,
        // TRAILING OPTIONAL (autowired): referens-filer i ärendemappen måste gallras
        // (annars kvarlämnad pekar-fil när akten stängs). Pekar-fil, ej PII.
        private ?ReferensFilService $referensFilService = null,
        // TRAILING OPTIONAL (autowired): ärenderummets team (presentationslagret)
        // måste rivas med rummet — gruppen (teamets enda medlem) raderas nedan,
        // så ett kvarlämnat team vore en tom föräldralös circle per gallrat ärende.
        private ?TeamClient $teamClient = null,
        // TRAILING OPTIONAL (autowired): händelsejournalen gallras MED ärendet —
        // aktor_uid är personuppgift (GDPR art. 5.1.e); facksystemet äger akten.
        private ?HandelseMapper $handelseMapper = null,
        // TRAILING OPTIONAL (autowired): PARTSREGISTRET — motorns enda sanktionerade
        // PII-tabell (namn/pnr/adress; ANALYS-HANDLING-FRAN-MALL §3.4). Gallras
        // OVILLKORLIGEN med ärendet: PII-raderna får aldrig överleva
        // koordinationsraden (GDPR art. 5.1.e + K-NAV-4.6).
        private ?PartMapper $partMapper = null,
        // TRAILING OPTIONAL (autowired): genererade handlingar (utkast ur mall)
        // i ärenderummets groupfolder städas MED ärendet enligt policy-beslutet
        // 2026-07-06 (utkast får leva kort i Hubs; slutresultatet bor i SoR).
        private ?HandlingService $handlingService = null,
        // TRAILING OPTIONAL (autowired): SAKUPPGIFTSLAGRET (dokumentkedjans
        // minne — bekräftade sakuppgifter, kan bära PII) gallras OVILLKORLIGEN
        // med ärendet (GDPR art. 5.1.e; samma regler som partsregistret).
        private ?SakuppgiftMapper $sakuppgiftMapper = null,
    ) {
    }

    /**
     * Run one gallrings-sweep: purge the engine's own coordination row (and its
     * pekare) for every case that is DUE.
     *
     * Ren, testbar metod — tar en valfri $now (default = ITimeFactory->getDateTime())
     * och returnerar antalet purgade rader plus deras pseudonyma hubsCaseId:n. Ingen
     * extern I/O utöver mapparna, inga sidoeffekter mot facksystemet (det äger och
     * gallrar verksamhetsdatat självt).
     *
     * @param \DateTime|null $now Sweep-instant; rader med gallrasDatum <= now är due.
     *                            Null ⇒ nuvarande tid (ITimeFactory).
     *
     * @return array{antal:int, hubsCaseIds:array<int,string>} Antal purgade + deras
     *         hubsCaseId (pseudonym).
     */
    public function gallra(?\DateTime $now = null): array {
        $now ??= $this->timeFactory->getDateTime();

        $kandidater = $this->arendeMapper->findGallringsbara($now);

        $antal = 0;
        /** @var array<int,string> $hubsCaseIds */
        $hubsCaseIds = [];

        foreach ($kandidater as $rad) {
            // SÄKERHETSVAKT (fail-safe): radera ALDRIG en rad utan verkställbar
            // gallrings-deadline eller som inte är registrerad i facksystemet.
            // Query:n gatar redan på detta — detta är en andra, oberoende kontroll
            // (defense in depth) så en framtida query-regression aldrig kan purga
            // en aktiv/ogallringsbar rad.
            if (!$this->arGallringsbar($rad, $now)) {
                $this->logger->warning('hubs_arende: gallring hoppade icke-gallringsbar rad (säkerhetsvakt)', [
                    'app' => 'hubs_arende',
                    'hubsCaseId' => $rad->getHubsCaseId(),
                ]);
                continue;
            }

            $hubsCaseId = $rad->getHubsCaseId();

            // Referens-filer i ärendemappen FÖRST (+ deras pekare) — fysiska .url-pekare
            // till meddelanden måste bort med akten (annars kvarlämnad fil).
            $this->referensFilService?->taBortReferenser($hubsCaseId);

            // Genererade handlingar (mall-utkast) städas med akten — policy-beslut:
            // utkast lever kort i Hubs, slutresultatet är committat till SoR.
            $this->handlingService?->taBortHandlingar($hubsCaseId);

            // Teamet (presentationslagret) rivs FÖRE pekarna — destroy behöver
            // pekarens singleId, och gruppen (teamets enda medlem) raderas nedan.
            if ($this->teamClient !== null) {
                foreach ($this->pekareMapper->findByCaseAndTyp($hubsCaseId, 'team') as $teamPekare) {
                    $this->teamClient->destroyTeam($teamPekare->getObjektId());
                }
            }

            // Pekarna FÖRST (routing-/koordinations-pekare till externa objekt), så
            // ingen orphan-pekare överlever register-raden. findByCaseId + delete-loop
            // över ALLA objekt_typ:er (vi äger inte PekareMapper, så vi använder dess
            // befintliga QBMapper-API). Idempotent — en re-körning hittar 0 pekare.
            foreach ($this->pekareMapper->findByCaseId($hubsCaseId) as $pekare) {
                $this->pekareMapper->delete($pekare);
            }

            // Ärenderummets förstaklassiga medlemmar (uid+roll = personal-PII) MÅSTE
            // gallras med rummet — annars kvarlämnad PII-rest (GDPR art. 5.1.e).
            $this->memberMapper?->deleteByCaseId($hubsCaseId);

            // Händelsejournalen gallras med ärendet (aktor_uid = personuppgift).
            $this->handelseMapper?->deleteByCaseId($hubsCaseId);

            // PARTSREGISTRET (namn/pnr/adress — motorns enda PII-tabell) gallras
            // ovillkorligen med ärendet (K-NAV-4.6; policy-beslut 2026-07-06).
            $this->partMapper?->deleteByCaseId($hubsCaseId);

            // SAKUPPGIFTSLAGRET (dokumentkedjans minne, kan bära PII) gallras
            // med ärendet — bekräftade uppgifter är transient arbetsminne.
            $this->sakuppgiftMapper?->deleteByCaseId($hubsCaseId);

            // Per-case-åtkomstgruppen raderas så ingen tom grupp blir kvar.
            $this->arenderumGroupService?->delete($hubsCaseId);

            // Sedan själva register-/koordinations-raden.
            $this->arendeMapper->delete($rad);

            $antal++;
            $hubsCaseIds[] = $hubsCaseId;
        }

        $this->logger->info('hubs_arende: gallring slutförd', [
            'app' => 'hubs_arende',
            'antal' => $antal,
            // ENBART pseudonyma hubsCaseId:n — aldrig objektRef/triageRef/dnr/innehåll.
            'hubsCaseIds' => $hubsCaseIds,
        ]);

        return [
            'antal' => $antal,
            'hubsCaseIds' => $hubsCaseIds,
        ];
    }

    /**
     * Fail-safe predikat: en rad får purgas ENBART när den verkligen tagits över av
     * facksystemet (provenanceState='registrerad') OCH bär en verkställbar
     * gallrings-deadline (gallrasDatum !== null) som FAKTISKT passerats
     * (gallrasDatum <= now). Speglar query:ns gatar — men körs oberoende i loopen som
     * en andra vakt, så att en framtida query-regression som släpper igenom en aktiv,
     * icke-förfallen eller datumlös rad ändå aldrig leder till en för tidig DELETE.
     */
    private function arGallringsbar(Arende $rad, \DateTime $now): bool {
        $deadline = $rad->getGallrasDatum();
        return $rad->getProvenanceState() === 'registrerad'
            && $deadline !== null
            && $deadline <= $now;
    }
}
