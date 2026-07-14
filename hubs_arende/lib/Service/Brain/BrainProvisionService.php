<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Service\Brain;

use OCP\AppFramework\Services\IAppConfig;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * HTTP-klient mot openbrain-svc:s PROVISIONER-API (`/provision/*`) — sydsidan av
 * brain-per-ärende (SPEC-BRAIN-PER-ÄRENDE kap 3.1/3.2). Här bor SAGA-steget R2b:s
 * faktiska nätanrop, samt frys-/thaw-/patch-/gallrings-verben (delas med
 * BrainLifecycleService/GallringService, kap 3.3/9).
 *
 * AVVIKER från Seam A ({@see \OCA\HubsArende\Integration\ServiceAccountAuth}) enligt
 * integrationskartan (4): provisioner-API:t är en SEPARAT tjänst på ett eget nät
 * (provision-net, PROVISION_PORT=7106) — INTE en granne-NC-app. Därför:
 *   - EXTERN absolut bas-URL ur app-config `hubs_arende.openbrain_provision_url`
 *     (t.ex. `http://openbrain-svc:7106`), aldrig IURLGenerator.
 *   - EGET provisioner-secret ur app-config `hubs_arende.brain_provision_secret`
 *     (Bearer = PROVISION_KEY, kap 3.1/11.1) — skilt från runtime/Seam A.
 *   - INGET `allow_local_address` (extern host, inte den lokala NC-instansen).
 *   - GRACEFUL NO-OP utan konfig: ej url ELLER ej secret ⇒ {@see provision()} svarar
 *     `['noop' => true]` och livscykelverben returnerar false, UTAN anrop. I icke-
 *     brainmiljöer används dessutom normalt stub-porten (integration_mode_brain),
 *     så live-tjänsten når hit bara i kommunstacken.
 *
 * ICKE-FÄLLANDE KONTRAKT (kap 3.3): {@see provision()} kastar ENDAST
 * {@see BrainProvisionUnavailable} (retrybart: connect/timeout/5xx). Permanent fel
 * (409/422) returneras som `['permanent_fel' => true, 'kod' => ...]` — kastar aldrig.
 * Ingen provisioner-felkod kan alltså nå createCase:s yttre `catch (\Throwable)`.
 * Livscykelverben ({@see freeze()}/{@see thaw()}/{@see patch()}/{@see setKarantan()}/
 * {@see delete()}/{@see rollback()}) är best-effort: de sväljer ALLT och returnerar
 * bool — retry/larm ägs av respektive hake-jobb (kap 3.4/9.9).
 *
 * ÅTKOMSTPROFIL (kort connect-timeout, längre total): connect 2 s / total 10 s
 * (kap 1.2 "provisionern onåbar/timeout (2 s connect / 10 s total)").
 */
class BrainProvisionService {
    /** App-config-nycklar (hubs_arende-namespace). */
    public const CONFIG_KEY_URL = 'openbrain_provision_url';
    public const CONFIG_KEY_SECRET = 'brain_provision_secret';
    public const CONFIG_KEY_KOMMUN = 'kommun_slug';

    private const CONNECT_TIMEOUT = 2;
    private const TOTAL_TIMEOUT = 10;

    public function __construct(
        private IClientService $clientService,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * True när både bas-URL och provisioner-secret är konfigurerade (⇒ tjänsten får
     * anropa provisionern). Annars degraderar allt till graceful no-op.
     */
    public function isConfigured(): bool {
        return $this->baseUrl() !== '' && $this->secret() !== '';
    }

    // ================================================================== //
    //  R2b — provisionering (SAGA), kastar ENDAST BrainProvisionUnavailable
    // ================================================================== //

    /**
     * `POST /provision/tenants` (idempotent på hubs_case_id, kap 3.2).
     *
     * @return array{tenant_id?:string,schema?:string,status?:string,idempotent?:bool,
     *               permanent_fel?:bool,kod?:string,noop?:bool}
     *   - Lyckat (201/nyskapad · 200/idempotent): `{tenant_id, schema, status, idempotent}`.
     *   - Permanent fel (409/422/400): `{permanent_fel:true, kod}` — INGEN retry, INGET kast.
     *   - Ej konfigurerad: `{noop:true}` — ingen brain, ingen retry.
     *
     * @throws BrainProvisionUnavailable Endast vid RETRYBART fel (connect/timeout/5xx).
     */
    public function provision(string $hubsCaseId, string $arendeTypId, bool $karantan = false): array {
        if (!$this->isConfigured()) {
            $this->logger->debug('hubs_arende: brain-provision NO-OP (ej konfigurerad)', [
                'app' => 'hubs_arende',
                'hubsCaseId' => $hubsCaseId,
            ]);
            return ['noop' => true];
        }

        // send() kastar BrainProvisionUnavailable vid connect/timeout/5xx ⇒ propageras
        // ORÖRD till R2b-haken (det ENDA kastet ur denna metod).
        $res = $this->send('POST', '/provision/tenants', [
            'hubs_case_id' => $hubsCaseId,
            'arende_typ' => $arendeTypId,
            // Live-tjänsten fyller kommun ur EGEN konfig — ArendeService skickar aldrig kommun (kap 3.3).
            'kommun' => $this->kommunSlug(),
            // Normalt false: R0 fångades redan av SakerhetsskyddGrind före R2b (kap 1.2).
            'karantan' => $karantan,
        ]);

        $status = $res['status'];
        if ($status === 201 || $status === 200) {
            $body = $res['body'] ?? [];
            return [
                'tenant_id' => (string)($body['tenant_id'] ?? ''),
                'schema' => (string)($body['schema'] ?? ''),
                'status' => (string)($body['status'] ?? 'aktiv'),
                'idempotent' => $status === 200 || (($body['idempotent'] ?? false) === true),
            ];
        }

        // 409/422/400 = PERMANENT. Kontrakt: returnera, kasta ALDRIG (kap 3.3).
        $kod = (string)($res['body']['error'] ?? ('http_' . $status));
        $this->logger->warning('hubs_arende: brain-provision permanent fel', [
            'app' => 'hubs_arende',
            'hubsCaseId' => $hubsCaseId,
            'status' => $status,
            'kod' => $kod,
        ]);
        return ['permanent_fel' => true, 'kod' => $kod];
    }

    // ================================================================== //
    //  Livscykel-verb — best-effort (sväljer allt, returnerar bool)
    // ================================================================== //

    /**
     * SAGA-kompensering (kap 3.3): `DELETE /provision/tenants/{id}?reason=saga_rollback`
     * ⇒ DROP SCHEMA CASCADE + nycklar revokerade + protokollpost typ=rollback. Rivare av
     * en halvprovisionerad/rollbackad brain (läckageyta, test 12.2.5). Best-effort.
     */
    public function rollback(string $tenantId): bool {
        return $this->delete($tenantId, 'saga_rollback', 'saga_rollback');
    }

    /**
     * `DELETE /provision/tenants/{id}` (kap 9.3 gallring; `?reason=saga_rollback` = kap 3.3).
     *
     * Vid riktig gallring (reason=null) KRÄVER provisionern protokoll + händelse-ref FÖRE
     * borttag (409 protokoll_saknas annars) — de bärs av GallringService. Vid saga_rollback
     * krävs de inte. Best-effort: 200/410 = ok, annat = false + logg.
     *
     * @param array<string,mixed>|null $protokoll
     */
    public function delete(
        string $tenantId,
        string $aktor,
        ?string $reason = null,
        ?string $handelseRef = null,
        ?array $protokoll = null,
    ): bool {
        if (!$this->isConfigured()) {
            return $this->noop('delete', $tenantId);
        }
        $path = '/provision/tenants/' . rawurlencode($tenantId);
        if ($reason !== null && $reason !== '') {
            $path .= '?reason=' . rawurlencode($reason);
        }
        $body = ['aktor' => $aktor];
        if ($handelseRef !== null) {
            $body['handelse_ref'] = $handelseRef;
        }
        if ($protokoll !== null) {
            $body['protokoll'] = $protokoll;
        }
        try {
            $res = $this->send('DELETE', $path, $body);
            // 200 gallrad · 410 redan_gallrad — bägge OK för anroparen (kap 9.3 5a).
            $ok = $res['status'] === 200 || $res['status'] === 410;
            $this->logger->info('hubs_arende: brain-provision delete', [
                'app' => 'hubs_arende',
                'tenantId' => $tenantId,
                'reason' => $reason,
                'status' => $res['status'],
            ]);
            return $ok;
        } catch (\Throwable $e) {
            return $this->bestEffortFel('delete', $tenantId, $e);
        }
    }

    /**
     * `POST /provision/tenants/{id}/freeze` (kap 3.4/9.2) — dödar skrivnyckeln + REVOKE
     * på PG-nivå. hubs_case_id verifieras mot tenant av provisionern. Best-effort.
     */
    public function freeze(string $tenantId, string $hubsCaseId, string $orsak = 'avslut', string $aktor = 'saga'): bool {
        if (!$this->isConfigured()) {
            return $this->noop('freeze', $tenantId);
        }
        try {
            $res = $this->send('POST', '/provision/tenants/' . rawurlencode($tenantId) . '/freeze', [
                'orsak' => $orsak,
                'aktor' => $aktor,
                'hubs_case_id' => $hubsCaseId,
            ]);
            return $res['status'] === 200;
        } catch (\Throwable $e) {
            return $this->bestEffortFel('freeze', $tenantId, $e);
        }
    }

    /**
     * `POST /provision/tenants/{id}/thaw` (kap 3.4/9.2) — ny skrivnyckel, brainen
     * skrivbar igen. Best-effort.
     */
    public function thaw(string $tenantId, string $orsak = 'ateroppning', string $aktor = 'saga'): bool {
        if (!$this->isConfigured()) {
            return $this->noop('thaw', $tenantId);
        }
        try {
            $res = $this->send('POST', '/provision/tenants/' . rawurlencode($tenantId) . '/thaw', [
                'orsak' => $orsak,
                'aktor' => $aktor,
            ]);
            return $res['status'] === 200;
        } catch (\Throwable $e) {
            return $this->bestEffortFel('thaw', $tenantId, $e);
        }
    }

    /**
     * `PATCH /provision/tenants/{id}` (kap 3.1/3.5) — metadata-uppdatering. Endast
     * medskickade fält uppdateras. Tillåtna nycklar: `talk_room_token`, `gallras_datum`,
     * `r0_karantan`. Best-effort.
     *
     * @param array<string,mixed> $falt
     */
    public function patch(string $tenantId, array $falt): bool {
        if (!$this->isConfigured()) {
            return $this->noop('patch', $tenantId);
        }
        // Filtrera till provisioner-kontraktets fält (undvik att skicka skräp).
        $tillatna = ['talk_room_token', 'gallras_datum', 'r0_karantan'];
        $body = array_intersect_key($falt, array_flip($tillatna));
        if ($body === []) {
            return false;
        }
        try {
            $res = $this->send('PATCH', '/provision/tenants/' . rawurlencode($tenantId), $body);
            return $res['status'] === 200;
        } catch (\Throwable $e) {
            return $this->bestEffortFel('patch', $tenantId, $e);
        }
    }

    /**
     * R0-spegelns skrivväg (kap 3.5/5.3): `PATCH …{r0_karantan}`. Sätts när ett ärende
     * karantänmarkeras (commit_destination='karantan' / retention_state='pausad') eller
     * avkarantäneras. provisioner-API är enda skrivaren av `gw.tenants` (charter). Best-effort.
     */
    public function setKarantan(string $tenantId, bool $karantan): bool {
        return $this->patch($tenantId, ['r0_karantan' => $karantan]);
    }

    /**
     * R6-substeget (kap 6.3): koppla talk-room-token till tenant via
     * `PATCH …{talk_room_token}`. Best-effort.
     */
    public function setTalkRoomToken(string $tenantId, string $talkRoomToken): bool {
        return $this->patch($tenantId, ['talk_room_token' => $talkRoomToken]);
    }

    // ================================================================== //
    //  Intern HTTP + konfig
    // ================================================================== //

    /**
     * Ett provisioner-anrop. Returnerar `{status:int, body:?array}` för 2xx/4xx;
     * KASTAR {@see BrainProvisionUnavailable} för RETRYBART fel (connect/timeout/5xx).
     *
     * Klassificeringen (retrybart vs permanent) görs UTAN Guzzle-specifika typer:
     * IClient kastar på icke-2xx, och {@see \OCP\Http\Client\IClient::getResponseFromThrowable()}
     * (OCP-API) plockar ut ett ev. HTTP-svar ur throwablen. Finns ett svar = HTTP-statusfel
     * (4xx permanent / 5xx retrybart); finns inget svar = transportfel (connect/timeout) = retrybart.
     *
     * @param array<string,mixed>|null $body
     * @return array{status:int,body:array<string,mixed>|null}
     * @throws BrainProvisionUnavailable
     */
    private function send(string $method, string $path, ?array $body): array {
        $url = rtrim($this->baseUrl(), '/') . $path;
        $options = [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $this->secret(),
            ],
            'connect_timeout' => self::CONNECT_TIMEOUT,
            'timeout' => self::TOTAL_TIMEOUT,
        ];
        if ($body !== null) {
            $options['json'] = $body;
        }

        $client = $this->clientService->newClient();
        try {
            $response = $client->request($method, $url, $options);
        } catch (\Throwable $e) {
            // IClient kastade — försök läsa ett HTTP-svar ur throwablen.
            try {
                $response = $client->getResponseFromThrowable($e);
            } catch (\Throwable) {
                // Inget HTTP-svar ⇒ transportfel (connect/timeout) ⇒ RETRYBART.
                throw new BrainProvisionUnavailable(
                    'provisioner onåbar (' . $method . ' ' . $path . '): ' . $e->getMessage(),
                    0,
                    $e,
                );
            }
        }

        $status = $response->getStatusCode();
        // 5xx = infra nere/överlast ⇒ RETRYBART (kap 1.2/3.3).
        if ($status >= 500) {
            throw new BrainProvisionUnavailable('provisioner ' . $status . ' (' . $method . ' ' . $path . ')');
        }

        $raw = $response->getBody();
        $text = is_string($raw) ? $raw : '';
        $decoded = $text !== '' ? json_decode($text, true) : null;

        return ['status' => $status, 'body' => is_array($decoded) ? $decoded : null];
    }

    private function baseUrl(): string {
        return trim($this->appConfig->getAppValueString(self::CONFIG_KEY_URL, ''));
    }

    private function secret(): string {
        return trim($this->appConfig->getAppValueString(self::CONFIG_KEY_SECRET, ''));
    }

    private function kommunSlug(): string {
        return trim($this->appConfig->getAppValueString(self::CONFIG_KEY_KOMMUN, ''));
    }

    private function noop(string $verb, string $ref): bool {
        $this->logger->debug('hubs_arende: brain-provision.' . $verb . ' NO-OP (ej konfigurerad)', [
            'app' => 'hubs_arende',
            'ref' => $ref,
        ]);
        return false;
    }

    private function bestEffortFel(string $verb, string $ref, \Throwable $e): bool {
        // Livscykelverben är best-effort: retry/larm ägs av hake-jobben (kap 3.4/9.9),
        // så här sväljs ALLT (även BrainProvisionUnavailable) och false returneras.
        $this->logger->warning('hubs_arende: brain-provision.' . $verb . ' misslyckades (best-effort)', [
            'app' => 'hubs_arende',
            'ref' => $ref,
            'exception' => $e->getMessage(),
        ]);
        return false;
    }
}
