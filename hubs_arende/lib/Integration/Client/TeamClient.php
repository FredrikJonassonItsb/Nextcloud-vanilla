<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Integration\Client;

use OCA\HubsArende\Integration\ServiceAccountAuth;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Thin client onto the **circles** (Teams) app's local OCS API (SAGA T — the
 * ärenderum's PRESENTATION layer: one Team per case that ties the room's parts
 * together in Nextclouds egna UI:n).
 *
 * ÄGARMODELLEN ÄNDRAS INTE: åtkomsten till ärenderummet styrs fortsatt av
 * per-case-NC-gruppen ({@see \OCA\HubsArende\Service\ArenderumGroupService},
 * speglad ur member-ledgern med handoff-avsmalning). Teamet får GRUPPEN som sin
 * enda medlem (Member::TYPE_GROUP) och blir därmed en automatiskt korrekt spegel:
 * varje medlemsmutation som synkas in i gruppen slår igenom i teamet utan egen
 * synk-väg. Teamet grantas därutöver som extra "applicable" på ärenderummets
 * groupfolder (R4) och som deltagare i ärenderummets Talk-rum (R6) — samma
 * publik som gruppen, ingen behörighetsbreddning — så att akten och diskussionen
 * listas som team-resurser (Contacts team-vy + related_resources).
 *
 * Anropen görs som SERVICE-KONTOT (Basic-auth via ServiceAccountAuth) ⇒ kontot
 * blir teamets ägare (level 9) och en nyskapad circle är LÅST per default
 * (config CFG_CIRCLE=0 — ingen självanslutning; CFG_OPEN sätts aldrig).
 *
 *   - POST   /ocs/v2.php/apps/circles/circles                    create {name}
 *   - POST   /ocs/v2.php/apps/circles/circles/{id}/members       add {userId,type}
 *   - DELETE /ocs/v2.php/apps/circles/circles/{id}               destroy
 *
 * T returns the circle singleId, stored as a pekare (objekt_typ='team');
 * teardown (saga-kompensation / gallring / purge) destroys the circle so no
 * orphan team survives the ärenderum.
 *
 * GRACEFUL DEGRADATION: circles absent / credential saknas ⇒ NO-OP + null —
 * ärenderummet fungerar fullt ut utan sitt presentationslager.
 */
class TeamClient {
    private const APP_ID = 'circles';
    private const API_BASE = '/ocs/v2.php/apps/circles/circles';

    /** Circles member type: 2 = NC-grupp (Member::TYPE_GROUP). */
    private const MEMBER_TYPE_GROUP = 2;

    public function __construct(
        private IAppManager $appManager,
        private IClientService $clientService,
        private IURLGenerator $urlGenerator,
        private LoggerInterface $logger,
        private ServiceAccountAuth $serviceAuth,
    ) {
    }

    public function isAvailable(): bool {
        return $this->appManager->isEnabledForUser(self::APP_ID);
    }

    /**
     * T — create the case team and add the per-case access group as its ONLY
     * member. ALL-OR-NOTHING: if the group cannot be attached the fresh team is
     * destroyed again and null returned — a half-wired team (no member mirror)
     * would present an empty room and confuse more than it helps.
     *
     * @param string $name    Team display name (pseudonym — typically 'Ärende {hubsCaseId}').
     * @param string $groupId The per-case NC group id ('hubs-case-{uuid}').
     * @return string|null The circle singleId, or null (NO-OP / failure).
     */
    public function createTeam(string $name, string $groupId): ?string {
        if (!$this->isAvailable()) {
            $this->noop('createTeam', $name);
            return null;
        }
        if ($groupId === '') {
            return null;
        }

        $response = $this->ocsRequest('POST', self::API_BASE, ['name' => $name], $name);
        $singleId = $this->extractSingleId($response);
        if ($singleId === null) {
            $this->logger->warning('hubs_arende: TeamClient.createTeam — inget team-id i svaret (graceful)', [
                'app' => 'hubs_arende',
                'nameRef' => $this->safeRef($name),
            ]);
            return null;
        }

        // Attach the per-case group as the team's single member (the access mirror).
        $member = $this->ocsRequest('POST', self::API_BASE . '/' . rawurlencode($singleId) . '/members', [
            'userId' => $groupId,
            'type' => self::MEMBER_TYPE_GROUP,
        ], $name);
        if (!$this->ocsOk($member)) {
            // No half-wired team: destroy and report failure.
            $this->ocsRequest('DELETE', self::API_BASE . '/' . rawurlencode($singleId), null, $name);
            $this->logger->warning('hubs_arende: TeamClient.createTeam — kunde ej koppla gruppen, teamet revs (graceful)', [
                'app' => 'hubs_arende',
                'nameRef' => $this->safeRef($name),
                'groupId' => $groupId,
            ]);
            return null;
        }

        $this->logger->info('hubs_arende: TeamClient.createTeam', [
            'app' => 'hubs_arende',
            'nameRef' => $this->safeRef($name),
            'teamId' => $singleId,
            'groupId' => $groupId,
        ]);

        return $singleId;
    }

    /**
     * T compensation / gallring / purge — destroy the case team (DELETE /{id}).
     * Idempotent server-side: an already-absent circle is a swallowed 404.
     *
     * @return bool true if attempted, false on NO-OP.
     */
    public function destroyTeam(string $singleId): bool {
        if (!$this->isAvailable()) {
            $this->noop('destroyTeam', $singleId);
            return false;
        }
        if ($singleId === '') {
            return false;
        }

        $this->ocsRequest('DELETE', self::API_BASE . '/' . rawurlencode($singleId), null, $singleId);

        $this->logger->info('hubs_arende: TeamClient.destroyTeam', [
            'app' => 'hubs_arende',
            'teamId' => $singleId,
        ]);

        return true;
    }

    // ================================================================== //
    //  Internal helpers
    // ================================================================== //

    /** Pull the circle singleId out of an OCS v2 envelope ({ocs:{data:{id}}}). */
    private function extractSingleId(?array $response): ?string {
        if ($response === null) {
            return null;
        }
        $data = $response['ocs']['data'] ?? $response['data'] ?? $response;
        if (is_array($data) && isset($data['id']) && is_string($data['id']) && $data['id'] !== '') {
            return $data['id'];
        }
        return null;
    }

    /** True when an OCS v2 envelope reports status ok (statuscode 100/200). */
    private function ocsOk(?array $response): bool {
        if ($response === null) {
            return false;
        }
        $status = $response['ocs']['meta']['status'] ?? null;
        return $status === 'ok';
    }

    /**
     * Internal OCS request against the circles app, authenticated as the service
     * account (⇒ the account becomes the created team's owner). Failures are
     * swallowed + logged so the SAGA continues (samma mönster som SpreedClient).
     *
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>|null
     */
    private function ocsRequest(string $method, string $path, ?array $body, string $ref): ?array {
        try {
            $client = $this->clientService->newClient();
            $url = $this->urlGenerator->getAbsoluteURL($path);
            $options = [
                'headers' => [
                    'Accept' => 'application/json',
                    'OCS-APIRequest' => 'true',
                ],
                'timeout' => 10,
                'nextcloud' => ['allow_local_address' => true],
            ];
            $auth = $this->serviceAuth->authorizationHeader();
            if ($auth !== null) {
                $options['headers']['Authorization'] = $auth;
            }
            if ($body !== null) {
                $options['json'] = $body;
            }

            $response = $client->request($method, $url, $options);
            $raw = $response->getBody();
            $text = is_string($raw) ? $raw : '';
            $decoded = $text !== '' ? json_decode($text, true) : null;

            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $e) {
            $this->logger->warning('hubs_arende: TeamClient OCS-anrop misslyckades (graceful)', [
                'app' => 'hubs_arende',
                'method' => $method,
                'path' => $path,
                'ref' => $ref,
                'exception' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function noop(string $method, string $ref): void {
        $this->logger->debug('hubs_arende: TeamClient.' . $method . ' NO-OP (circles ej aktiverad)', [
            'app' => 'hubs_arende',
            'ref' => $ref,
        ]);
    }

    /**
     * Non-reversible, PII-safe digest of a free-text value for logging (the team
     * name is pseudonym by convention, but the log invariant is defense-in-depth).
     */
    private function safeRef(string $value): string {
        if ($value === '') {
            return 'len:0';
        }
        return 'len:' . strlen($value) . ':' . substr(hash('sha256', $value), 0, 12);
    }
}
