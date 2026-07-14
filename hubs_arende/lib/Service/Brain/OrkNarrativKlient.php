<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Service\Brain;

use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * INLINE-klient mot orkestrerarens funktions-API (`POST {ork_fn_url}/fn/{fnId}`) —
 * den SYNKRONA vägen som HANDLING-FRÅN-MALL använder för att låta en generativ
 * AI-funktion producera ett KÄLLFÖRANKRAT NARRATIV i det ögonblick handläggaren
 * öppnar utkast-dialogen. Speglar {@see BrainProvisionService} som mönster för en
 * utgående HTTP-klient (IClientService, extern bas-URL + secret ur app-config,
 * kort connect-/längre total-timeout, INGEN PII i loggar).
 *
 * AVGRÄNSNING: klienten hämtar bara NARRATIVET. BEDÖMNING/BESLUT ägs av människan
 * (och av AI-funktionerna själva) — motorn skriver aldrig ett beslut åt någon.
 *
 * SKILLNADER mot BrainProvisionService (orkestreraren är en ANNAN tjänst än
 * provisionern):
 *   - Bas-URL ur app-config `hubs_arende.ork_fn_url` (utan avslutande '/'),
 *     secret ur `hubs_arende.ork_fn_secret`.
 *   - Autentisering = HMAC-SHA256 över den RÅA request-kroppen i headern
 *     `x-ork-signature` (inte Bearer) — samma kontrakt som resten av ork-flödet.
 *   - `allow_local_address` = true: ork-funktionsporten ligger på kommunens
 *     interna nät (som Spreed/Deck/Folkbokföring-klienterna), inte på en publik
 *     host.
 *   - Längre total-timeout (35 s): en LLM-körning tar tid (jfr FolkbokforingClient).
 *
 * DEGRADERBAR: saknas url ELLER secret ⇒ {@see genereraNarrativ()} är en tyst
 * no-op (returnerar null, INGET anrop). Varje fel — onåbar orkestrerare, timeout,
 * nekat/utan svar — fångas och returneras som null; metoden KASTAR ALDRIG. Utan
 * narrativ fungerar utkast-dialogen exakt som förut.
 *
 * PII-DOKTRIN: varken ärendeinnehåll, uid eller svar-markdown får nå
 * LoggerInterface — endast fn-id och feltyp loggas (debug-nivå).
 */
class OrkNarrativKlient {
    /** App-config-nycklar (hubs_arende-namespace). */
    public const CONFIG_KEY_URL = 'ork_fn_url';
    public const CONFIG_KEY_SECRET = 'ork_fn_secret';

    /** Kort connect-gräns; längre total då en LLM-körning tar tid. */
    private const CONNECT_TIMEOUT = 2;
    private const TOTAL_TIMEOUT = 35;

    /**
     * Mall→ork-funktion. BOR HÄR (inte i ArendedataService): mappningen är en REN
     * ORK-angelägenhet — vilken generativ funktion en mall svarar mot — medan
     * ArendedataService är PII-datakällelagret och ska inte känna till orkestreraren.
     * Speglar hubs_start HandlingModal:s KLASS_NYCKELORD (substring-matchning på ett
     * foldat mall-id).
     *
     * @param string $mallId Mallens id (t.ex. "beslut-om-bistand-eller-insats").
     * @return string|null fn-id (en av AuthzService::DRAFT_FUNKTIONER) eller null när
     *   ingen generativ funktion motsvarar mallen.
     */
    public static function fnForMall(string $mallId): ?string {
        // Folda: gemener, å/ä→a, ö→o, allt icke-alfanumeriskt → '-' (slug).
        $n = mb_strtolower($mallId, 'UTF-8');
        $n = strtr($n, ['å' => 'a', 'ä' => 'a', 'ö' => 'o']);
        $n = preg_replace('/[^a-z0-9]+/', '-', $n) ?? '';
        $n = trim($n, '-');

        // Ordningen speglar KLASS_NYCKELORD. VIKTIGT: matcha 'beslut-om-bistand'
        // (inte bara 'beslut'), så "Förhandsbedömning och beslut att inleda…"
        // INTE fångas som beslutsformulering.
        if (str_contains($n, 'skyddsbedom')) {
            return 'fn_draft_skyddsbedomning';
        }
        if (str_contains($n, 'journal')) {
            return 'fn_draft_journal';
        }
        if (str_contains($n, 'kommunicer')) {
            return 'fn_draft_kommunicering';
        }
        if (str_contains($n, 'beslut-om-bistand') || str_contains($n, 'bistand-eller-insats')) {
            return 'fn_draft_beslutsformulering';
        }
        if (str_contains($n, 'bbic') || str_contains($n, 'barnavardsutredning')) {
            return 'fn_draft_sammanstallning';
        }
        if (str_contains($n, 'avslut')) {
            return 'fn_avslutssyntes';
        }
        return null;
    }

    public function __construct(
        private IClientService $clientService,
        private IConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Kör en ork-funktion INLINE och returnera dess källförankrade narrativ.
     *
     * `POST {ork_fn_url}/fn/{fnId}` med rå JSON-kropp
     * `{"hubs_case_id","uid","trigger":"ui","inline":true}` signerad med
     * `x-ork-signature: hmac_sha256(rawBody, ork_fn_secret)`. Vid lyckad körning
     * (HTTP 202) bär svaret `svar_md` (narrativ markdown) samt ev. `kallor`/`modell`.
     *
     * @param string $hubsCaseId Ärendets kanoniska nyckel.
     * @param string $fnId       Ork-funktionen ({@see fnForMall()}).
     * @param string $uid        Den handläggare som utlöste utkastet.
     *
     * @return array{svar_md:string, kallor:mixed, modell:mixed}|null Narrativet, eller
     *   null vid ej konfigurerad/onåbar/nekande orkestrerare (degraderbart no-op).
     */
    public function genereraNarrativ(string $hubsCaseId, string $fnId, string $uid): ?array {
        $url = $this->baseUrl();
        $secret = $this->secret();
        // GRACEFUL NO-OP: utan url eller secret finns ingen orkestrerare att nå.
        if ($url === '' || $secret === '') {
            return null;
        }

        try {
            $rawBody = json_encode([
                'hubs_case_id' => $hubsCaseId,
                'uid' => $uid,
                'trigger' => 'ui',
                'inline' => true,
            ]);
            if ($rawBody === false) {
                return null;
            }
            $sig = hash_hmac('sha256', $rawBody, $secret);
            $endpoint = rtrim($url, '/') . '/fn/' . rawurlencode($fnId);

            $client = $this->clientService->newClient();
            $response = $client->post($endpoint, [
                'body' => $rawBody,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'x-ork-signature' => $sig,
                ],
                'connect_timeout' => self::CONNECT_TIMEOUT,
                'timeout' => self::TOTAL_TIMEOUT,
                // Ork-funktionsporten bor på kommunens interna nät.
                'nextcloud' => ['allow_local_address' => true],
            ]);

            $raw = $response->getBody();
            $text = is_string($raw) ? $raw : '';
            $data = $text !== '' ? json_decode($text, true) : null;

            // Lyckad körning = ett icke-tomt svar_md. Nekad/fel ⇒ inget svar_md ⇒ null.
            if (is_array($data) && !empty($data['svar_md'])) {
                return [
                    'svar_md' => (string)$data['svar_md'],
                    'kallor' => $data['kallor'] ?? null,
                    'modell' => $data['modell'] ?? null,
                ];
            }
            return null;
        } catch (\Throwable $e) {
            // Degraderbart: en onåbar/nekande orkestrerare får ALDRIG fälla utkastet.
            // INGEN PII, INGET svar-innehåll — endast fn-id och feltyp i loggen.
            $this->logger->debug('hubs_arende: ork-narrativ inline misslyckades (graceful)', [
                'app' => 'hubs_arende',
                'fnId' => $fnId,
                'exception' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function baseUrl(): string {
        return trim($this->config->getAppValue('hubs_arende', self::CONFIG_KEY_URL, ''));
    }

    private function secret(): string {
        return trim($this->config->getAppValue('hubs_arende', self::CONFIG_KEY_SECRET, ''));
    }
}
