<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Integration\Port;

/**
 * 🔌 SEAM[ediarium]
 *
 * Port mot e-diarium / e-arkiv enligt FGS (Förvaltningsgemensamma specifikationer:
 * FGS-Ärende/FGS-PSI/FGS-Diarium/FGS-Paket). Mål: registrera en allmän handling i
 * diariet och paketera/leverera till e-arkiv.
 *
 * Samma kontrakt mot stub ({@see \OCA\HubsArende\Integration\Stub\EdiariumStub})
 * och skarp integration; väljs via INTEGRATION_MODE (IAppConfig:
 * hubs_arende.integration.ediarium).
 *
 * Två operationer speglar de två FGS-livscykellägena:
 *  - {@see registrera()} — diarieför en handling (FGS-Ärende/Diarium). Används av
 *    kat 6 (rättsligt/tvång) som diarieför DIREKT (preSagaHook='diariefor_direkt').
 *  - {@see arkivera()} — paketera ett avslutat ärende till e-arkiv (FGS-Paket/SIP).
 */
interface EdiariumPort {
    /**
     * Registrera (diarieför) en handling i e-diariet enligt FGS.
     *
     * @param string $hubsCaseId Kanonisk ärende-token.
     * @param array<string,mixed> $handling Handlings-metadata enligt FGS-Ärende/Diarium:
     *        ['handlingstyp' => string (DHP), 'titel' => string, 'riktning' => 'inkommande'|'upprattad'|'utgaende',
     *         'sekretess' => string|null, 'inkomDatum' => string(ISO), 'arendetyp' => string].
     *
     * @return array<string,mixed> Diarieförings-kvitto:
     *         ['ok' => bool, 'diarienummer' => string, 'registreradAt' => string(ISO),
     *          'provenanceState' => 'registrerad', 'handlingId' => string].
     *
     * @throws \OCA\HubsArende\Integration\Port\Exception\DiariumException vid avvisad registrering.
     */
    public function registrera(string $hubsCaseId, array $handling): array;

    /**
     * Paketera och leverera ett avslutat ärende till e-arkiv enligt FGS (SIP-paket).
     *
     * @param string $hubsCaseId Kanonisk ärende-token.
     * @param array<string,mixed> $paket Arkiverings-instruktion:
     *        ['handlingar' => array<int,array<string,mixed>>, 'gallrasDatum' => string|null,
     *         'bevarasDatum' => string|null, 'arkivbildare' => string, 'klassificering' => string].
     *
     * @return array<string,mixed> Arkiverings-kvitto:
     *         ['ok' => bool, 'paketId' => string (SIP-id), 'arkiveradAt' => string(ISO),
     *          'retentionState' => string, 'verifierad' => bool].
     *
     * @throws \OCA\HubsArende\Integration\Port\Exception\ArkivException vid avvisad/ofullständig leverans.
     */
    public function arkivera(string $hubsCaseId, array $paket): array;
}
