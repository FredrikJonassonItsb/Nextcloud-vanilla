<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Integration\Client;

use OCA\HubsArende\Integration\Port\Exception\FolkbokforingException;
use OCA\HubsArende\Integration\Port\FolkbokforingPort;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * 🔌 SEAM[navet.uppslag] — SKARP konnektor mot folkbokföringen (Skatteverket
 * Navet) via kommunens INTERNA Frends-uppslags-API (K-NAV-3.1).
 *
 * Hubs anropar ALDRIG Skatteverket direkt: kommunen exponerar ett internt
 * uppslags-API (Frends) som i sin tur pratar Navet. Denna klient är den enda
 * HTTP-ytan mot det API:t; kontraktet mot motorn är
 * {@see \OCA\HubsArende\Integration\Port\FolkbokforingPort} (samma som
 * stubben — implementation väljs via IAppConfig-nyckeln
 * `hubs_arende.integration_mode_folkbokforing`).
 *
 * Transport (K-NAV-3.1):
 *   POST {folkbokforing_api_url}/folkbokforing/uppslag
 *   Body:    {"personnummer": string[], "korrelationsId": string,
 *             "andamal": string, "arendeRef": string}
 *   Headers: Authorization: Bearer {folkbokforing_api_nyckel},
 *            Accept/Content-Type: application/json
 *   Svar:    {"personposter": {pnr: post|null, ...}}
 *
 * Konfiguration (app-värden via {@see IAppConfig}, sätts vid kommun-onboarding):
 *   - `folkbokforing_api_url`     bas-URL till Frends-API:t; TOM = ej
 *                                 konfigurerad ⇒ {@see isAvailable()} = false
 *                                 (graceful degradation, K-NAV-4.1).
 *   - `folkbokforing_api_nyckel`  bearer-nyckel för Authorization-headern.
 *
 * TODO[onboarding]: auth-detaljerna (bearer-token vs mTLS/klientcertifikat,
 * ev. extra API-nycklar eller IP-allowlist) fastställs först vid respektive
 * kommuns onboarding mot deras Frends-miljö — {@see buildOptions()} är den
 * enda punkt som behöver justeras då.
 *
 * TIMEOUT: Navet kan ta uppåt 30 s vid stora batchar (K-NAV-2.4) — klientens
 * timeout är därför 35 s, INTE husets vanliga 10 s.
 *
 * PII-DOKTRIN (beslut 2026-07-06, ANALYS-HANDLING-FRAN-MALL.md §3.4): svaret
 * matar partsregistret (oc_hubs_arende_part) — motorns ENDA sanktionerade
 * PII-tabell; transient arbetsdata som gallras med ärendet, ALDRIG SoR.
 * PERSONNUMMER och NAMN får ALDRIG nå loggen eller ett exception-meddelande —
 * här loggas endast antal, korrelationsId och statuskod; felmeddelanden pekar
 * på POSITION i listan, aldrig på identitet.
 *
 * FAIL-CLOSED SKYDD (K-NAV-3.3): fältet `skydd`
 * (ingen|sekretessmarkering|skyddad_folkbokforing) är OBLIGATORISKT i varje
 * personpost. Saknas det, eller har det ett okänt värde, kastas
 * {@see FolkbokforingException} — det defaultas ALDRIG till "ingen". Vid
 * `skyddad_folkbokforing` skrubbas `kontaktadress` till null innan posten
 * lämnar klienten: den verkliga adressen får ALDRIG lagras; endast
 * `sarskildPostadress` (Skatteverkets förmedlingsadress) släpps igenom.
 *
 * AUDIT (K-NAV-4.2): Skatteverket loggar inte på användarnivå — därför är
 * `korrelationsId` obligatoriskt i varje anrop och skickas vidare i bodyn så
 * Frends kan bära det som `skv_client_correlation_id`.
 */
class FolkbokforingClient implements FolkbokforingPort {
    /** IAppConfig-nyckel: bas-URL till kommunens Frends-uppslags-API. */
    private const CONFIG_URL = 'folkbokforing_api_url';

    /** IAppConfig-nyckel: bearer-nyckel mot Frends-API:t. */
    private const CONFIG_NYCKEL = 'folkbokforing_api_nyckel';

    /** Uppslagsendpointen, relativ bas-URL:en (K-NAV-3.1). */
    private const ENDPOINT = '/folkbokforing/uppslag';

    /** Navets hårda batchgräns: max identitetsbeteckningar per anrop. */
    private const MAX_PNR_PER_ANROP = 900;

    /** Total timeout i sekunder — Navet kan ta ~30 s (K-NAV-2.4). */
    private const TIMEOUT_SEKUNDER = 35;

    /** De enda giltiga värdena för skydd-fältet (K-NAV-3.3, fail-closed). */
    private const SKYDD_VARDEN = ['ingen', 'sekretessmarkering', 'skyddad_folkbokforing'];

    /** Giltiga avregistreringskoder (AV = avliden, UV = utvandrad). */
    private const AVREG_KODER = ['AV', 'UV'];

    /** Giltiga relationstyper (V = vårdnadshavare, VF = vårdnad för). */
    private const RELATION_TYPER = ['V', 'VF'];

    public function __construct(
        private IClientService $clientService,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Tillgänglig = bas-URL:en är konfigurerad (kommun-onboarding gjord).
     *
     * false ⇒ graceful degradation (K-NAV-4.1): motorn blockerar inte flödet,
     * handläggaren fyller partsregistret manuellt.
     */
    public function isAvailable(): bool {
        return $this->baseUrl() !== '';
    }

    /**
     * Slå upp en eller flera personer i folkbokföringen via Frends-API:t.
     *
     * Flöde: validera indata → POST {@see self::ENDPOINT} → validera +
     * normalisera varje personpost till portens shape (fail-closed på skydd).
     * Returen täcker EXAKT de begärda pnr:en — ett pnr som saknas i svaret är
     * ett kontraktsbrott och kastar exception (hellre stopp än tyst lucka).
     *
     * @param string[] $personnummer 12-siffriga identitetsbeteckningar
     *        (AAAAMMDDNNNN), max {@see self::MAX_PNR_PER_ANROP} st.
     * @param array<string,mixed> $kontext korrelationsId (OBLIGATORISK),
     *        arendeRef, andamal — se porten.
     *
     * @return array<string,array<string,mixed>|null> Map pnr => personpost|null
     *         (null = personen finns inte i folkbokföringen).
     *
     * @throws \InvalidArgumentException Programmeringsfel i ANROPET: fler än
     *         900 pnr eller pnr som inte är 12 siffror (fångas före HTTP).
     * @throws FolkbokforingException Saknad korrelationsId, ej konfigurerad
     *         integration, transportfel/timeout/HTTP-fel, ogiltig JSON, eller
     *         post som bryter fail-closed-regeln för skydd (K-NAV-3.3).
     */
    public function hamtaPerson(array $personnummer, array $kontext): array {
        // ---- Indata-validering (före all HTTP; PII-fritt i alla fel) ----- //
        if (count($personnummer) > self::MAX_PNR_PER_ANROP) {
            throw new \InvalidArgumentException(
                'Folkbokföringsuppslag: max ' . self::MAX_PNR_PER_ANROP
                . ' personnummer per anrop (Navet-gränsen), fick ' . count($personnummer) . '.'
            );
        }
        foreach (array_values($personnummer) as $i => $pnr) {
            if (!is_string($pnr) || preg_match('/^\d{12}$/', $pnr) !== 1) {
                // Positionen, aldrig värdet — PII-doktrinen gäller även fel.
                throw new \InvalidArgumentException(
                    'Folkbokföringsuppslag: personnummer på position ' . $i
                    . ' är inte en 12-siffrig identitetsbeteckning.'
                );
            }
        }

        $korrelationsId = $kontext['korrelationsId'] ?? null;
        if (!is_string($korrelationsId) || trim($korrelationsId) === '') {
            throw new FolkbokforingException(
                'Folkbokföringsuppslag: korrelationsId saknas — obligatoriskt för audit-kedjan (K-NAV-4.2).'
            );
        }

        if (!$this->isAvailable()) {
            throw new FolkbokforingException(
                'Folkbokföringsuppslag: integrationen är inte konfigurerad (folkbokforing_api_url saknas).'
            );
        }

        if ($personnummer === []) {
            // Inget att slå upp — inget anrop, ingen logg-rad behövs.
            return [];
        }

        // ---- HTTP mot Frends (K-NAV-3.1) --------------------------------- //
        $svar = $this->begarUppslag($personnummer, $kontext, $korrelationsId);

        $personposter = $svar['personposter'] ?? null;
        if (!is_array($personposter)) {
            throw new FolkbokforingException(
                'Folkbokföringsuppslag: svaret saknar "personposter" (korrelationsId ' . $korrelationsId . ').'
            );
        }

        // ---- Validera + normalisera (fail-closed) ------------------------ //
        $resultat = [];
        $antalTraffar = 0;
        $antalSkyddade = 0;
        foreach (array_values($personnummer) as $i => $pnr) {
            if (!array_key_exists($pnr, $personposter)) {
                // Kontraktsbrott: varje begärt pnr MÅSTE besvaras (post eller
                // null). En tyst lucka får inte tolkas som "finns ej".
                throw new FolkbokforingException(
                    'Folkbokföringsuppslag: svaret saknar personpost för begärt personnummer på position '
                    . $i . ' (korrelationsId ' . $korrelationsId . ').'
                );
            }

            $rad = $personposter[$pnr];
            if ($rad === null) {
                // Personen finns inte i folkbokföringen — legitimt utfall.
                $resultat[$pnr] = null;
                continue;
            }
            if (!is_array($rad)) {
                throw new FolkbokforingException(
                    'Folkbokföringsuppslag: personposten på position ' . $i
                    . ' har ogiltig typ (korrelationsId ' . $korrelationsId . ').'
                );
            }

            $post = $this->normaliseraPost($rad, $i, $korrelationsId);
            if ($post['skydd'] !== 'ingen') {
                $antalSkyddade++;
            }
            $antalTraffar++;
            $resultat[$pnr] = $post;
        }

        // PII-doktrinen: ENDAST antal + korrelationsId — aldrig pnr/namn.
        $this->logger->info('hubs_arende: FolkbokforingClient.hamtaPerson', [
            'app' => 'hubs_arende',
            'korrelationsId' => $korrelationsId,
            'antalBegarda' => count($personnummer),
            'antalTraffar' => $antalTraffar,
            'antalSkyddade' => $antalSkyddade,
        ]);

        return $resultat;
    }

    // ================================================================== //
    //  HTTP
    // ================================================================== //

    /**
     * POST:a uppslaget mot Frends-API:t och returnera dekodad JSON.
     *
     * Alla transportfel (nätfel, timeout, non-2xx, trasig JSON) översätts till
     * {@see FolkbokforingException} med SAKLIGT, PII-fritt meddelande. Till
     * skillnad från husets graceful OCS-klienter är detta ett HÅRT fel —
     * uppslaget är ett explicit användarinitierat steg och ett tyst null vore
     * vilseledande.
     *
     * @param string[] $personnummer
     * @param array<string,mixed> $kontext
     * @return array<string,mixed>
     * @throws FolkbokforingException
     */
    private function begarUppslag(array $personnummer, array $kontext, string $korrelationsId): array {
        $url = rtrim($this->baseUrl(), '/') . self::ENDPOINT;
        $body = [
            'personnummer' => array_values($personnummer),
            'korrelationsId' => $korrelationsId,
            'andamal' => (string)($kontext['andamal'] ?? ''),
            'arendeRef' => (string)($kontext['arendeRef'] ?? ''),
        ];

        try {
            $client = $this->clientService->newClient();
            $response = $client->post($url, $this->buildOptions($body));

            $status = $response->getStatusCode();
            $raw = $response->getBody();
            $text = is_string($raw) ? $raw : '';
            $decoded = $text !== '' ? json_decode($text, true) : null;

            if (!is_array($decoded)) {
                throw new FolkbokforingException(
                    'Folkbokföringsuppslag: ogiltig JSON i svaret från Frends-API:t (status '
                    . $status . ', korrelationsId ' . $korrelationsId . ').'
                );
            }

            return $decoded;
        } catch (FolkbokforingException $e) {
            $this->loggaFel($korrelationsId, count($personnummer), null, $e);
            throw $e;
        } catch (\Throwable $e) {
            // NC:s IClient kastar på non-2xx (Guzzle http_errors) och på
            // nät-/timeoutfel — plocka statuskoden om den finns, göm resten.
            $status = $this->statusFran($e);
            $this->loggaFel($korrelationsId, count($personnummer), $status, $e);
            throw new FolkbokforingException(
                'Folkbokföringsuppslag: anropet mot Frends-API:t misslyckades'
                . ($status !== null ? ' (HTTP ' . $status . ')' : ' (transportfel/timeout)')
                . ' — korrelationsId ' . $korrelationsId . '.',
                0,
                $e,
            );
        }
    }

    /**
     * Request-options mot Frends.
     *
     * TODO[onboarding]: DEN punkt som justeras per kommun — bearer-token är
     * default-antagandet; byts auth till mTLS läggs cert/ssl_key till här och
     * Authorization-headern utgår. Frends-API:t ligger på kommunens interna
     * nät, därför allow_local_address.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function buildOptions(array $body): array {
        $options = [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'json' => $body,
            // K-NAV-2.4: Navet kan ta ~30 s — 35 s total, snabb connect-gräns.
            'timeout' => self::TIMEOUT_SEKUNDER,
            'connect_timeout' => 5,
            'nextcloud' => ['allow_local_address' => true],
        ];

        $nyckel = $this->appConfig->getAppValueString(self::CONFIG_NYCKEL, '');
        if ($nyckel !== '') {
            $options['headers']['Authorization'] = 'Bearer ' . $nyckel;
        }

        return $options;
    }

    // ================================================================== //
    //  Normalisering (fail-closed)
    // ================================================================== //

    /**
     * Validera + normalisera EN rå personpost till portens shape.
     *
     * Fail-closed-ordning: skydd valideras FÖRST (K-NAV-3.3) — utan giltigt
     * skydd får inget annat i posten ens tolkas. Vid skyddad_folkbokforing
     * skrubbas kontaktadress ovillkorligen till null (verklig adress får
     * aldrig lagras); endast sarskildPostadress släpps igenom.
     *
     * Felmeddelanden bär positionen i anropslistan + korrelationsId — ALDRIG
     * personnummer eller namn.
     *
     * @param array<string,mixed> $rad Rå post från Frends-svaret.
     * @param int $i Position i anropslistan (PII-fri referens i fel/logg).
     * @return array<string,mixed> Normaliserad personpost (portens shape).
     * @throws FolkbokforingException
     */
    private function normaliseraPost(array $rad, int $i, string $korrelationsId): array {
        // ---- 1. skydd — FAIL-CLOSED, valideras före allt annat ----------- //
        $skydd = $rad['skydd'] ?? null;
        if (!is_string($skydd) || !in_array($skydd, self::SKYDD_VARDEN, true)) {
            throw new FolkbokforingException(
                'Folkbokföringsuppslag: personposten på position ' . $i
                . ' saknar giltigt skydd-fält — fail-closed, posten avvisas'
                . ' (K-NAV-3.3, korrelationsId ' . $korrelationsId . ').'
            );
        }

        // ---- 2. identitet ------------------------------------------------ //
        $pnr = $rad['personnummer'] ?? null;
        if (!is_string($pnr) || preg_match('/^\d{12}$/', $pnr) !== 1) {
            throw new FolkbokforingException(
                'Folkbokföringsuppslag: personposten på position ' . $i
                . ' har ogiltigt personnummer-format (korrelationsId ' . $korrelationsId . ').'
            );
        }

        $tidigare = [];
        foreach ((array)($rad['tidigareBeteckningar'] ?? []) as $beteckning) {
            if (is_string($beteckning) && $beteckning !== '') {
                $tidigare[] = $beteckning;
            }
        }

        // ---- 3. namn ------------------------------------------------------ //
        $namnRad = $rad['namn'] ?? null;
        $fornamn = is_array($namnRad) ? ($namnRad['fornamn'] ?? null) : null;
        $efternamn = is_array($namnRad) ? ($namnRad['efternamn'] ?? null) : null;
        if (!is_string($fornamn) || $fornamn === '' || !is_string($efternamn) || $efternamn === '') {
            throw new FolkbokforingException(
                'Folkbokföringsuppslag: personposten på position ' . $i
                . ' saknar för-/efternamn (korrelationsId ' . $korrelationsId . ').'
            );
        }
        $mellannamn = $namnRad['mellannamn'] ?? null;
        $namn = [
            'fornamn' => $fornamn,
            'mellannamn' => is_string($mellannamn) && $mellannamn !== '' ? $mellannamn : null,
            'efternamn' => $efternamn,
        ];

        // ---- 4. adresser (skrubb vid skyddad_folkbokforing) --------------- //
        $kontaktadress = $this->normaliseraAdress($rad['kontaktadress'] ?? null, $i, $korrelationsId, 'kontaktadress');
        $sarskildPostadress = $this->normaliseraAdress($rad['sarskildPostadress'] ?? null, $i, $korrelationsId, 'sarskildPostadress');
        if ($skydd === 'skyddad_folkbokforing' && $kontaktadress !== null) {
            // Verklig adress får ALDRIG lagras vid skyddad folkbokföring —
            // skrubba ovillkorligen, oavsett vad uppströmssystemet skickade.
            $kontaktadress = null;
            $this->logger->warning('hubs_arende: FolkbokforingClient — kontaktadress skrubbad (skyddad_folkbokforing)', [
                'app' => 'hubs_arende',
                'korrelationsId' => $korrelationsId,
                'position' => $i,
            ]);
        }

        // ---- 5. avregistrering / relationer / födelsetid ------------------ //
        $avreg = null;
        $avregRad = $rad['avregistrering'] ?? null;
        if (is_array($avregRad)) {
            $kod = $avregRad['kod'] ?? null;
            $datum = $avregRad['datum'] ?? null;
            if (!is_string($kod) || !in_array($kod, self::AVREG_KODER, true)
                || !is_string($datum) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum) !== 1) {
                throw new FolkbokforingException(
                    'Folkbokföringsuppslag: personposten på position ' . $i
                    . ' har ogiltig avregistrering (korrelationsId ' . $korrelationsId . ').'
                );
            }
            $avreg = ['kod' => $kod, 'datum' => $datum];
        }

        $relationer = [];
        foreach (array_values((array)($rad['relationer'] ?? [])) as $ri => $relation) {
            $relationer[] = $this->normaliseraRelation($relation, $i, $ri, $korrelationsId);
        }

        $fodelsetid = $rad['fodelsetid'] ?? null;
        if (!is_string($fodelsetid) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $fodelsetid) !== 1) {
            throw new FolkbokforingException(
                'Folkbokföringsuppslag: personposten på position ' . $i
                . ' har ogiltig fodelsetid (korrelationsId ' . $korrelationsId . ').'
            );
        }

        return [
            'personnummer' => $pnr,
            'tidigareBeteckningar' => $tidigare,
            'namn' => $namn,
            'kontaktadress' => $kontaktadress,
            'sarskildPostadress' => $sarskildPostadress,
            'skydd' => $skydd,
            'avregistrering' => $avreg,
            'relationer' => $relationer,
            'fodelsetid' => $fodelsetid,
        ];
    }

    /**
     * Normalisera en adress till {rader: string[], postnummer, postort} — eller
     * null. En "halv" adress (fel typ/saknade fält) avvisas hellre än gissas.
     *
     * @param mixed $rad
     * @return array{rader: string[], postnummer: string, postort: string}|null
     * @throws FolkbokforingException
     */
    private function normaliseraAdress(mixed $rad, int $i, string $korrelationsId, string $falt): ?array {
        if ($rad === null) {
            return null;
        }
        if (!is_array($rad)) {
            throw new FolkbokforingException(
                'Folkbokföringsuppslag: personposten på position ' . $i
                . ' har ogiltig ' . $falt . ' (korrelationsId ' . $korrelationsId . ').'
            );
        }

        $rader = [];
        foreach ((array)($rad['rader'] ?? []) as $adressrad) {
            if (is_string($adressrad) && $adressrad !== '') {
                $rader[] = $adressrad;
            }
        }
        $postnummer = $rad['postnummer'] ?? null;
        $postort = $rad['postort'] ?? null;
        if ($rader === [] || !is_string($postnummer) || $postnummer === ''
            || !is_string($postort) || $postort === '') {
            throw new FolkbokforingException(
                'Folkbokföringsuppslag: personposten på position ' . $i
                . ' har ofullständig ' . $falt . ' (korrelationsId ' . $korrelationsId . ').'
            );
        }

        return ['rader' => $rader, 'postnummer' => $postnummer, 'postort' => $postort];
    }

    /**
     * Normalisera EN relation ({typ: V|VF, personnummer, namn, tomDatum}).
     *
     * Strikt hellre än tyst: en tappad vårdnadshavar-relation vore ett
     * SAKFEL i barnärenden — okänd typ/trasig rad kastar exception i stället
     * för att filtreras bort.
     *
     * @param mixed $relation
     * @return array{typ: string, personnummer: string|null, namn: string, tomDatum: string|null}
     * @throws FolkbokforingException
     */
    private function normaliseraRelation(mixed $relation, int $i, int $ri, string $korrelationsId): array {
        $typ = is_array($relation) ? ($relation['typ'] ?? null) : null;
        $namn = is_array($relation) ? ($relation['namn'] ?? null) : null;
        if (!is_array($relation)
            || !is_string($typ) || !in_array($typ, self::RELATION_TYPER, true)
            || !is_string($namn) || $namn === '') {
            throw new FolkbokforingException(
                'Folkbokföringsuppslag: personposten på position ' . $i
                . ' har ogiltig relation (index ' . $ri . ', korrelationsId ' . $korrelationsId . ').'
            );
        }

        $relPnr = $relation['personnummer'] ?? null;
        $tomDatum = $relation['tomDatum'] ?? null;

        return [
            'typ' => $typ,
            'personnummer' => is_string($relPnr) && $relPnr !== '' ? $relPnr : null,
            'namn' => $namn,
            'tomDatum' => is_string($tomDatum) && $tomDatum !== '' ? $tomDatum : null,
        ];
    }

    // ================================================================== //
    //  Interna hjälpare
    // ================================================================== //

    /** Konfigurerad bas-URL till Frends-API:t (tom sträng = ej onboardad). */
    private function baseUrl(): string {
        return trim($this->appConfig->getAppValueString(self::CONFIG_URL, ''));
    }

    /**
     * Plocka HTTP-statuskoden ur ett klient-fel om det bär en (Guzzle
     * BadResponseException-form: getResponse()->getStatusCode()).
     */
    private function statusFran(\Throwable $e): ?int {
        if (method_exists($e, 'getResponse')) {
            $response = $e->getResponse();
            if (is_object($response) && method_exists($response, 'getStatusCode')) {
                return (int)$response->getStatusCode();
            }
        }
        return null;
    }

    /**
     * Fel-loggning enligt PII-doktrinen: antal + korrelationsId + statuskod +
     * exception-KLASS. Exception-meddelandet loggas medvetet INTE — ett
     * transportfel kan citera request-bodyn (personnummer!) i sitt meddelande.
     */
    private function loggaFel(string $korrelationsId, int $antal, ?int $status, \Throwable $e): void {
        $this->logger->warning('hubs_arende: FolkbokforingClient — uppslag mot Frends-API:t misslyckades', [
            'app' => 'hubs_arende',
            'korrelationsId' => $korrelationsId,
            'antalBegarda' => $antal,
            'status' => $status,
            'exceptionClass' => get_class($e),
        ]);
    }
}
