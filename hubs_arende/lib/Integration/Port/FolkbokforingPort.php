<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Integration\Port;

/**
 * 🔌 SEAM[navet] / SEAM[navet.uppslag]
 *
 * Port mot folkbokföringen (Skatteverket Navet) via kommunens interna Frends-API.
 *
 * Detta interface är den ENDA kontaktytan mellan ärende-motorn och
 * folkbokföringen. Hubs anropar ALDRIG Skatteverket direkt — alla uppslag går
 * via kommunens interna Frends-API (KRAVSTALLNING-NAVET-FOLKBOKFORING.md,
 * K-NAV-3.1). Samma kontrakt gäller mot stubben
 * ({@see \OCA\HubsArende\Integration\Stub\FolkbokforingStub}, mock, default)
 * och mot den skarpa konnektorn
 * ({@see \OCA\HubsArende\Integration\Client\FolkbokforingClient}, mode "live")
 * — implementation väljs via IAppConfig-nyckeln
 * `hubs_arende.integration_mode_folkbokforing`.
 *
 * PII-DOKTRIN: uppslagen matar partsregistret (oc_hubs_arende_part) — motorns
 * ENDA sanktionerade PII-tabell (beslut 2026-07-06, se
 * hubs_start/docs/ANALYS-HANDLING-FRAN-MALL.md §3.4). Datat är transient
 * arbetsdata som gallras med ärendet och är ALDRIG system-of-record.
 * PERSONNUMMER och NAMN får ALDRIG skrivas till loggar (LoggerInterface)
 * eller till Handelse.detalj — logga antal/korrelationsId/roll/skydd,
 * aldrig identitet.
 *
 * FAIL-CLOSED SKYDD: fältet `skydd`
 * (ingen|sekretessmarkering|skyddad_folkbokforing) är OBLIGATORISKT i varje
 * personpost som porten returnerar. Saknas fältet, eller har det ett okänt
 * värde, MÅSTE implementationen kasta exception — ALDRIG defaulta till
 * "ingen". Vid `skyddad_folkbokforing` får den verkliga adressen ALDRIG
 * lagras: `kontaktadress` sätts till null och endast `sarskildPostadress`
 * (Skatteverkets förmedlingsadress) får förekomma i posten.
 *
 * AUDIT (K-NAV-4.2): Skatteverket loggar inte på användarnivå — hela
 * audit-ansvaret ligger hos oss. Därför är `korrelationsId` OBLIGATORISKT i
 * varje anrop; det bärs som `skv_client_correlation_id` och binder uppslaget
 * till vår egen audit-kedja (vem/vilket ärende/vilket ändamål).
 *
 * Normaliserad personpost-shape (map pnr => post|null; null = personen finns
 * inte i folkbokföringen):
 *   [
 *     'personnummer'          => string,    // 12 siffror AAAAMMDDNNNN
 *     'tidigareBeteckningar'  => string[],  // tidigare pnr/samordningsnr
 *     'namn'                  => [
 *       'fornamn'    => string,
 *       'mellannamn' => string|null,
 *       'efternamn'  => string,
 *     ],
 *     'kontaktadress'         => array|null, // {rader: string[], postnummer, postort}
 *                                            // ALLTID null vid skyddad_folkbokforing
 *     'sarskildPostadress'    => array|null, // samma shape som kontaktadress
 *     'skydd'                 => string,     // 'ingen'|'sekretessmarkering'|'skyddad_folkbokforing'
 *                                            // OBLIGATORISK — se fail-closed ovan
 *     'avregistrering'        => array|null, // {kod: 'AV'|'UV', datum: 'YYYY-MM-DD'}
 *     'relationer'            => array,      // [{typ: 'V'|'VF', personnummer: string|null,
 *                                            //   namn: string, tomDatum: string|null}, ...]
 *     'fodelsetid'            => string,     // 'YYYY-MM-DD'
 *   ]
 */
interface FolkbokforingPort {
    /**
     * Är folkbokföringsuppslag tillgängligt just nu?
     *
     * `false` betyder graceful degradation — motorn får INTE blockera flödet,
     * utan handläggaren fyller i personuppgifterna manuellt i partsregistret.
     * Stubben svarar alltid true; den skarpa klienten svarar false vid t.ex.
     * saknad konfiguration eller känt driftstopp i Frends-API:t.
     *
     * @return bool true om porten kan ta emot {@see hamtaPerson()}-anrop.
     */
    public function isAvailable(): bool;

    /**
     * Slå upp en eller flera personer i folkbokföringen.
     *
     * Batchat uppslag: Navet tillåter max 900 identitetsbeteckningar per
     * anrop — implementationen MÅSTE avvisa större listor. Returen är en map
     * pnr => normaliserad personpost (shape i klassdoc) eller null om personen
     * inte finns i folkbokföringen. Varje returnerad post MÅSTE uppfylla
     * fail-closed-regeln för `skydd` (se klassdoc) — en post utan giltigt
     * skydd-värde får aldrig lämna porten.
     *
     * @param string[] $personnummer Lista av 12-siffriga identitetsbeteckningar
     *        (AAAAMMDDNNNN), max 900 st (Navet-gränsen).
     * @param array<string,mixed> $kontext Anropskontext:
     *        - korrelationsId (string, OBLIGATORISK): bärs som
     *          skv_client_correlation_id; Skatteverket loggar ej användarnivå,
     *          så hela audit-spårningen hänger på denna nyckel (K-NAV-4.2).
     *        - arendeRef (string): kanonisk ärende-token uppslaget görs för.
     *        - andamal (string): ändamål med uppslaget (audit/laglig grund).
     *
     * @return array<string,array<string,mixed>|null> Map pnr => personpost|null.
     *
     * @throws \OCA\HubsArende\Integration\Port\Exception\FolkbokforingException
     *         vid saknad/ogiltig korrelationsId, transportfel mot Frends-API:t,
     *         eller post som bryter fail-closed-regeln för skydd.
     * @throws \InvalidArgumentException vid >900 pnr (programmeringsfel hos
     *         anroparen — fångas före HTTP; Navet-gränsen K-NAV-2.4).
     */
    public function hamtaPerson(array $personnummer, array $kontext): array;
}
