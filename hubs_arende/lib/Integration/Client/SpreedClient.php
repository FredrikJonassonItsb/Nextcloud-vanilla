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
 * Thin client onto the **spreed** (Talk) app's room API (SAGA R6 — the case's
 * single chat space; all case chat happens in this room).
 *
 * spreed is a SEPARATE NC app, consumed over its OCS room API (v4):
 *   - POST   /ocs/v2.php/apps/spreed/api/v4/room                 createRoom(roomType,roomName)
 *   - POST   /ocs/v2.php/apps/spreed/api/v4/room/{token}/participants  addParticipant
 *   - DELETE /ocs/v2.php/apps/spreed/api/v4/room/{token}         deleteRoom
 *   - POST   /ocs/v2.php/apps/spreed/api/v4/room/{token}/archive archive
 *
 * R6 returns the talkToken, stored as a pekare (objekt_typ='talk_room',
 * objekt_id=talkToken); the compensation HARD-DELETES the room so a rolled-back
 * or purged case leaves NO orphan room row in oc_talk_rooms.
 *
 * GRACEFUL DEGRADATION: spreed absent ⇒ NO-OP + null.
 * TODO[auth]: internal call needs a credential (see {@see ocsRequest()}).
 */
class SpreedClient {
    private const APP_ID = 'spreed';
    private const API_BASE = '/ocs/v2.php/apps/spreed/api/v4/room';

    /** Talk room type: 2 = group room. */
    private const ROOM_TYPE_GROUP = 2;

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
     * R6 — create the case room and add the ACL-krets as participants.
     *
     * @param string   $name         Room name (typically the case ref).
     * @param string[] $participants uids of the ACL krets to add.
     * @return string|null The talkToken, or null (NO-OP / not wired).
     */
    public function createRoom(string $name, array $participants): ?string {
        if (!$this->isAvailable()) {
            $this->noop('createRoom', $name);
            return null;
        }

        // Step 1: create the group room.
        // TODO[spreed]+TODO[auth]: POST room {roomType:2, roomName:$name} and read
        //   token from data; then POST each participant. Deterministic stub keeps
        //   the token-shape stable until the call is wired.
        $response = $this->ocsRequest('POST', self::API_BASE, [
            'roomType' => self::ROOM_TYPE_GROUP,
            'roomName' => $name,
        ], $name);

        $talkToken = $this->extractToken($response);

        // Step 2: add participants (best-effort; absence of any one must not abort).
        if ($talkToken !== null) {
            foreach ($participants as $uid) {
                $this->ocsRequest('POST', self::API_BASE . '/' . rawurlencode($talkToken) . '/participants', [
                    'newParticipant' => $uid,
                    'source' => 'users',
                ], $name);
            }
        }

        $this->logger->info('hubs_arende: SpreedClient.createRoom', [
            'app' => 'hubs_arende',
            // OSL 26 kap: the room name may carry PII — log a digest, not the raw value.
            'nameRef' => $this->safeRef($name),
            'talkToken' => $talkToken,
            'participants' => count($participants),
        ]);

        return $talkToken;
    }

    /**
     * Add a user as a participant to an existing room (post-creation member change,
     * e.g. a co-handläggare). POST /room/{token}/participants.
     *
     * @return bool true if attempted, false on NO-OP.
     */
    public function addParticipant(string $talkToken, string $uid): bool {
        if (!$this->isAvailable()) {
            $this->noop('addParticipant', $talkToken);
            return false;
        }
        if ($uid === '') {
            return false;
        }

        $this->ocsRequest('POST', self::API_BASE . '/' . rawurlencode($talkToken) . '/participants', [
            'newParticipant' => $uid,
            'source' => 'users',
        ], $talkToken);

        $this->logger->info('hubs_arende: SpreedClient.addParticipant', [
            'app' => 'hubs_arende',
            'talkToken' => $talkToken,
        ]);

        return true;
    }

    /**
     * Add the case TEAM (circle) as a participant to the room — the presentation
     * link that lists the room as a team resource (Contacts team view) and enables
     * @team-mention. NO authorization widening: the team's only member is the
     * per-case access group whose users are already direct participants; Talk adds
     * a marker attendee (actorType='circles') + skips existing users.
     * POST /room/{token}/participants {newParticipant: <circle singleId>, source: 'circles'}.
     *
     * @return bool true if attempted, false on NO-OP.
     */
    public function addCircleParticipant(string $talkToken, string $circleSingleId): bool {
        if (!$this->isAvailable()) {
            $this->noop('addCircleParticipant', $talkToken);
            return false;
        }
        if ($circleSingleId === '') {
            return false;
        }

        $this->ocsRequest('POST', self::API_BASE . '/' . rawurlencode($talkToken) . '/participants', [
            'newParticipant' => $circleSingleId,
            'source' => 'circles',
        ], $talkToken);

        $this->logger->info('hubs_arende: SpreedClient.addCircleParticipant', [
            'app' => 'hubs_arende',
            'talkToken' => $talkToken,
            'teamId' => $circleSingleId,
        ]);

        return true;
    }

    /**
     * Remove a user from an existing room (revoke a co-handläggare). Talk removes
     * by attendeeId, so we look the user's attendeeId up first; an absent user is a
     * graceful no-op. DELETE /room/{token}/attendees.
     *
     * @return bool true if a removal was attempted, false on NO-OP / not found.
     */
    public function removeParticipant(string $talkToken, string $uid): bool {
        if (!$this->isAvailable()) {
            $this->noop('removeParticipant', $talkToken);
            return false;
        }

        $attendeeId = $this->resolveAttendeeId($talkToken, $uid);
        if ($attendeeId === null) {
            $this->logger->info('hubs_arende: SpreedClient.removeParticipant — deltagare ej funnen (graceful skip)', [
                'app' => 'hubs_arende',
                'talkToken' => $talkToken,
            ]);
            return false;
        }

        $this->ocsRequest('DELETE', self::API_BASE . '/' . rawurlencode($talkToken) . '/attendees', [
            'attendeeId' => $attendeeId,
        ], $talkToken);

        $this->logger->info('hubs_arende: SpreedClient.removeParticipant', [
            'app' => 'hubs_arende',
            'talkToken' => $talkToken,
            'attendeeId' => $attendeeId,
        ]);

        return true;
    }

    /**
     * Resolve the attendeeId of a 'users'-type participant by uid, or null when not
     * a member. GET /room/{token}/participants.
     */
    private function resolveAttendeeId(string $talkToken, string $uid): ?int {
        $response = $this->ocsRequest('GET', self::API_BASE . '/' . rawurlencode($talkToken) . '/participants', null, $talkToken);
        $data = $response['ocs']['data'] ?? $response['data'] ?? null;
        if (!is_array($data)) {
            return null;
        }
        foreach ($data as $participant) {
            if (!is_array($participant)) {
                continue;
            }
            $actorType = (string)($participant['actorType'] ?? 'users');
            $actorId = (string)($participant['actorId'] ?? '');
            if ($actorType === 'users' && $actorId === $uid && isset($participant['attendeeId'])) {
                return (int)$participant['attendeeId'];
            }
        }
        return null;
    }

    /**
     * R6 compensation — HARD-DELETE the case room (DELETE /{token}).
     *
     * Every caller is a teardown path (saga rollback, R0 fail-closed grind, demo
     * purge) on a room that was just created in R6 and carries no chat record worth
     * keeping; deletion is the correct semantic and leaves no orphan row in
     * oc_talk_rooms. Deliberate archival of a real, in-use room (case-closure /
     * gallring of chat history) is a SEPARATE future operation, not this path.
     *
     * @return bool true if attempted, false on NO-OP.
     */
    public function deleteRoom(string $talkToken): bool {
        if (!$this->isAvailable()) {
            $this->noop('deleteRoom', $talkToken);
            return false;
        }

        $this->ocsRequest('DELETE', self::API_BASE . '/' . rawurlencode($talkToken), null, $talkToken);

        $this->logger->info('hubs_arende: SpreedClient.deleteRoom', [
            'app' => 'hubs_arende',
            'talkToken' => $talkToken,
        ]);

        return true;
    }

    /**
     * Säkerhetsskydd-retroaktiv isolation — ARCHIVE the case room (POST /{token}/archive),
     * preserving it. NOT a delete: when a case is retroactively classified
     * säkerhetsskyddsklassad the room is evidence (chain-of-custody, jfr R-retro
     * groupfolder-låsningen som uttryckligen INTE raderar), so it must survive while
     * being moved out of normal flow. Contrast {@see deleteRoom()}, the saga/purge
     * teardown which hard-deletes a freshly-created room.
     *
     * @return bool true if attempted, false on NO-OP.
     */
    public function archiveRoom(string $talkToken): bool {
        if (!$this->isAvailable()) {
            $this->noop('archiveRoom', $talkToken);
            return false;
        }

        $this->ocsRequest('POST', self::API_BASE . '/' . rawurlencode($talkToken) . '/archive', null, $talkToken);

        $this->logger->info('hubs_arende: SpreedClient.archiveRoom', [
            'app' => 'hubs_arende',
            'talkToken' => $talkToken,
        ]);

        return true;
    }

    // ================================================================== //
    //  Internal helpers
    // ================================================================== //

    /** Pull the talkToken out of an OCS v2 envelope ({ocs:{data:{token}}}) or flat data. */
    private function extractToken(?array $response): ?string {
        if ($response === null) {
            return null;
        }
        $data = $response['ocs']['data'] ?? $response['data'] ?? $response;
        if (is_array($data) && isset($data['token']) && is_string($data['token']) && $data['token'] !== '') {
            return $data['token'];
        }
        return null;
    }

    /**
     * Internal OCS request against the spreed app.
     *
     * TODO[auth]: in-process call carries no session; the Talk room API requires
     * authentication. Wire a service-account credential here. Failures are
     * swallowed + logged so the SAGA continues.
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
            $this->logger->warning('hubs_arende: SpreedClient OCS-anrop misslyckades (graceful)', [
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
        $this->logger->debug('hubs_arende: SpreedClient.' . $method . ' NO-OP (spreed ej aktiverad)', [
            'app' => 'hubs_arende',
            'ref' => $ref,
        ]);
    }

    /**
     * Build a non-reversible, PII-safe digest of a free-text value for logging.
     *
     * OSL 2009:400 26 kap / GDPR art. 5.1.c: a room name may carry PII and must
     * never reach the log verbatim. Returns the byte length plus a short SHA-256
     * prefix so log lines stay correlatable without exposing the raw value.
     * Empty input yields a stable sentinel.
     */
    private function safeRef(string $value): string {
        if ($value === '') {
            return 'len:0';
        }
        return 'len:' . strlen($value) . ':' . substr(hash('sha256', $value), 0, 12);
    }
}
