<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Integration\Port;

/**
 * 🔌 SEAM[treserva] / SEAM[treserva.commit]
 *
 * Port mot facksystemet (Treserva/Lifecare/Viva) via Frends iPaaS.
 *
 * Detta interface är den ENDA kontaktytan mellan ärende-motorn och den
 * verkliga slutlagringen. Samma kontrakt gäller mot stubben
 * ({@see \OCA\HubsArende\Integration\Stub\FacksystemCommitStub}) och mot den
 * skarpa Frends-konnektorn — `FacksystemCommitService` väljer implementation
 * via INTEGRATION_MODE (IAppConfig: hubs_arende.integration.facksystem).
 *
 * KÄRNMÖNSTER (migrerat ur hubs_start/src/services/demo/treserva.js
 * `commitHandling`): en commit producerar ett VERIFIERAT kvitto. Retention-
 * klockan startar ALDRIG på själva anropet — den startar ENBART på den
 * verifierade callbacken (GAP-007). I den asynkrona modellen sker detta i två
 * led: {@see commit()} initierar (returnerar ett *preliminärt* kvitto med
 * verifierad=false), och först {@see verifyCallback()} levererar det verifierade
 * kvittot {verifierad:true}. Den synkrona stubben kan korta detta genom att
 * köra callbacken in-process direkt i `commit()`, men kontraktet är detsamma.
 *
 * Kvitto-shape (matchar treserva.js exakt så att frontend är oförändrad):
 *   [
 *     'ok'           => bool,
 *     'dnr'          => string|null,   // Treserva delar ut dnr vid registrering
 *     'committedAt'  => string,        // ISO-8601
 *     'gallrasDatum' => string|null,   // YYYY-MM-DD, sätts vid verifierad commit
 *     'verifierad'   => bool,          // true = verifierad callback mottagen
 *     'hubsCaseId'   => string,
 *     'modul'        => string,        // frends_modul som tog emot commiten
 *     'receipt'      => array,         // kvittenspost (drivs kvittens-/retention-ytan)
 *   ]
 */
interface FacksystemCommitPort {
    /**
     * Initiera en commit av en handling till facksystemet via Frends.
     *
     * @param string $hubsCaseId Kanonisk ärende-token (UUID v4).
     * @param string $modul      Frends-modul (ifo_barn|ifo_vuxen|ao|lss|ek_bistand|familjeratt).
     * @param array<string,mixed> $payload Handlings-payload (typ, artefakter, arendetyp, ...).
     *
     * @return array<string,mixed> Kvitto enligt shape i klassdoc. I async-modellen
     *         är detta PRELIMINÄRT (verifierad=false, gallrasDatum=null) tills
     *         {@see verifyCallback()} kört. En synkron stub får returnera ett redan
     *         verifierat kvitto.
     *
     * @throws \OCA\HubsArende\Integration\Port\Exception\CommitFailedException vid avvisad/felad commit.
     * @throws \OCA\HubsArende\Integration\Port\Exception\CommitTimeoutException om facksystemet inte svarar i tid.
     */
    public function commit(string $hubsCaseId, string $modul, array $payload): array;

    /**
     * Registrera en async-callback-förväntan (speglar Frends verifierade återkallning).
     *
     * Anropas direkt efter {@see commit()} i async-modellen för att binda en
     * korrelationsnyckel till det väntande kvittot. Frends återkallar senare med
     * {hubsCaseId, dnr} mot denna korrelationsnyckel; ärende-motorn matchar och
     * kör {@see verifyCallback()}.
     *
     * @param string $hubsCaseId   Kanonisk ärende-token.
     * @param string $correlationId Idempotensnyckel som binder commit↔callback.
     *
     * @return string Token som facksystemet/Frends förväntas eka tillbaka i callbacken.
     */
    public function registerCallback(string $hubsCaseId, string $correlationId): string;

    /**
     * Verifiera en inkommande Frends-callback och materialisera det VERIFIERADE kvittot.
     *
     * Detta är den enda punkt där retention får startas (GAP-007): först här sätts
     * gallrasDatum + verifierad=true. Implementationen MÅSTE vara idempotent på
     * $callbackToken så att en omsänd callback inte dubbel-registrerar.
     *
     * @param string $callbackToken Token ur {@see registerCallback()} som facksystemet ekade.
     * @param array<string,mixed> $callbackData Frends-payload, minst {hubsCaseId, dnr}.
     *
     * @return array<string,mixed> Verifierat kvitto (verifierad=true, gallrasDatum satt).
     *
     * @throws \OCA\HubsArende\Integration\Port\Exception\CallbackVerificationException om token är okänd/ogiltig.
     */
    public function verifyCallback(string $callbackToken, array $callbackData): array;
}
