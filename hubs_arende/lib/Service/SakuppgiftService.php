<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Service;

use OCA\HubsArende\Db\Sakuppgift;
use OCA\HubsArende\Db\SakuppgiftMapper;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * SAKUPPGIFTSLAGRET — dokumentkedjans minne
 * (ANALYS-FORIFYLLNAD-FALTKARTLAGGNING.md §4).
 *
 * När handläggaren skapar en handling ur mall och aktivt bekräftar de
 * förifyllda fälten sparas varje icke-tomt fält här. Nästa mall i kedjan
 * förifylls ur ärendets egna bekräftade uppgifter — handläggaren skriver
 * aldrig av samma sakuppgift två gånger.
 *
 * ANSVARSGRÄNSEN: lagret bär endast det en MÄNNISKA bekräftat (bekraftadAv +
 * tidpunkt + ursprungsdokument). Systemet återger bekräftade fakta bakåt —
 * det fattar aldrig bedömningar framåt.
 *
 * PII-doktrin som partsregistret: värden kan bära personuppgifter — de når
 * ALDRIG loggar eller Handelse.detalj (logga endast antal/nycklar-antal);
 * gallras OVILLKORLIGEN med ärendet (GallringService); NEVER-SoR består.
 */
class SakuppgiftService {
    public function __construct(
        private SakuppgiftMapper $mapper,
        private LoggerInterface $logger,
        private ?IUserSession $userSession = null,
    ) {
    }

    /**
     * Spara handläggarens bekräftade fält efter att en handling skapats.
     * Best-effort: ett fel här får ALDRIG fälla handlingsskapandet
     * (dokumentet är redan skrivet) — fel loggas (utan värden) och sväljs.
     *
     * @param string $hubsCaseId Kanonisk ärende-token.
     * @param array<string,string> $falt nyckel => bekräftat värde (tomma hoppas).
     * @param array<string,string> $kallor nyckel => ursprungskälla (default 'handlaggare').
     * @param string $ursprung Mall-slug för handlingen där bekräftelsen gjordes.
     * @return int Antal sparade sakuppgifter.
     */
    public function sparaBekraftade(string $hubsCaseId, array $falt, array $kallor, string $ursprung): int {
        $aktor = $this->userSession?->getUser()?->getUID() ?? '';
        $nar = new \DateTime();
        $antal = 0;

        foreach ($falt as $nyckel => $varde) {
            $varde = trim((string)$varde);
            if ($nyckel === '' || $varde === '') {
                continue;
            }
            try {
                $this->mapper->upsert(
                    $hubsCaseId,
                    (string)$nyckel,
                    $varde,
                    $kallor[$nyckel] ?? 'handlaggare',
                    $ursprung,
                    $aktor,
                    $nar,
                );
                $antal++;
            } catch (\Throwable $e) {
                // Best-effort — aldrig värdet i loggen (PII-doktrinen).
                $this->logger->warning('hubs_arende: sakuppgift kunde inte sparas (best-effort)', [
                    'app' => 'hubs_arende',
                    'hubsCaseId' => $hubsCaseId,
                    'nyckel' => (string)$nyckel,
                ]);
            }
        }

        if ($antal > 0) {
            $this->logger->info('hubs_arende: sakuppgifter bekräftade', [
                'app' => 'hubs_arende',
                'hubsCaseId' => $hubsCaseId,
                'antal' => $antal,
                'ursprung' => $ursprung,
            ]);
        }

        return $antal;
    }

    /**
     * Ärendets bekräftade sakuppgifter, som karta nyckel => rad.
     * Graceful: DB-fel ⇒ tom karta (förifyllnaden degraderar, aldrig kast).
     *
     * @return array<string, Sakuppgift>
     */
    public function hamta(string $hubsCaseId): array {
        try {
            $karta = [];
            foreach ($this->mapper->findByCaseId($hubsCaseId) as $rad) {
                $karta[$rad->getNyckel()] = $rad;
            }
            return $karta;
        } catch (\Throwable $e) {
            $this->logger->debug('hubs_arende: sakuppgifter kunde inte läsas (graceful)', [
                'app' => 'hubs_arende',
                'hubsCaseId' => $hubsCaseId,
            ]);
            return [];
        }
    }
}
