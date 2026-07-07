<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * NEW FILE for the sdkmc app. Target: lib/Service/MeetingService.php
 *
 * Shared server-side aggregation of today's secure meetings + live lobby state.
 * Both the OCS MeetingController (api.fetchTodaysMeetings / fetchLobbyStatus) and
 * the standard-dashboard DagensMotenWidget delegate here, so the merge logic
 * lives in ONE place (the widget must never call a method that doesn't exist).
 *
 * Merges:
 *   - CalDAV (today's events whose LOCATION points at a call: /call/{token}),
 *   - secure-room state (lobby state + whether a call is currently running),
 *   - sdkmc's ConversationBankIDAuth records (green/purple verification badge).
 *
 * Cross-app dependencies (spreed Manager/ParticipantService, DAV calendar) may be
 * absent in a deployment, so every call into them is guarded; the contract shape
 * is always returned with safe empty defaults rather than failing.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */

namespace OCA\SdkMc\Service;

use OCA\SdkMc\Db\ConversationBankIDAuthMapper;
use OCP\Calendar\IManager as ICalendarManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class MeetingService {

    /** secure-room lobby: no lobby. Mirrors OCA\Talk\Webinary::LOBBY_NONE. */
    private const LOBBY_NONE = 0;
    /** call flag: nobody connected. Mirrors OCA\Talk\Participant::FLAG_DISCONNECTED. */
    private const CALL_FLAG_DISCONNECTED = 0;

    /**
     * @param \OCA\Talk\Manager|null $talkManager
     * @param \OCA\Talk\Service\ParticipantService|null $participantService
     */
    public function __construct(
        private ConversationBankIDAuthMapper $convBankAuthMapper,
        private IDBConnection $db,
        private LoggerInterface $logger,
        private ?ICalendarManager $calendarManager = null,
        private ?\OCA\Talk\Manager $talkManager = null,
        private ?\OCA\Talk\Service\ParticipantService $participantService = null,
    ) {
    }

    /**
     * Today's secure meetings for a user.
     *
     * `lobbyState` is returned as an OBJECT ({state, waiting}) — the shape MotesRad
     * expects (it reads `lobbyState.waiting`). `countdownMin` is whole minutes until
     * start and MAY be negative/0 once the meeting has begun. `dnr` is the linked
     * Hubs case (null for standalone meetings).
     *
     * @return list<array{token: string, title: string, start: ?string, end: ?string,
     *                     dnr: ?string, participants: int, bankIdRequired: bool,
     *                     verificationBadge: ('green'|'purple'|null),
     *                     lobbyState: array{state: int, waiting: int},
     *                     countdownMin: ?int, hasCall: bool}>
     */
    public function getTodaysMeetings(string $userId): array {
        $events = $this->findTodaysCallEvents($userId);

        $meetings = [];
        $seenTokens = [];
        foreach ($events as $event) {
            $token = $this->extractCallToken((string)($event['location'] ?? ''));
            if ($token === null || isset($seenTokens[$token])) {
                continue;
            }
            $seenTokens[$token] = true;

            $roomState = $this->resolveRoomState($token);
            $bankId = $this->resolveBankIdState($token);
            $waiting = $this->resolveLobbyWaitingCount($token);

            $meetings[] = [
                'token' => $token,
                'title' => $event['title'] !== '' ? $event['title'] : $token,
                'start' => $event['start'],
                'end' => $event['end'],
                'dnr' => $event['dnr'] ?? null,
                'participants' => $roomState['participants'],
                'bankIdRequired' => $bankId['required'],
                'verificationBadge' => $bankId['badge'],
                'lobbyState' => [
                    'state' => $roomState['lobbyState'],
                    'waiting' => $waiting,
                ],
                'countdownMin' => $this->countdownMinutes($event['start'] ?? null),
                'hasCall' => $roomState['hasCall'],
            ];
        }

        usort($meetings, static function (array $a, array $b): int {
            return ($a['start'] ?? '~') <=> ($b['start'] ?? '~');
        });

        return $meetings;
    }

    /**
     * Live lobby state for one meeting: who is verified and waiting.
     *
     * @return array{waiting: list<array{actorId: string, displayName: string, verified: bool}>, verifiedCount: int}
     */
    public function getLobby(string $token): array {
        $empty = ['waiting' => [], 'verifiedCount' => 0];

        $guests = $this->resolveLobbyGuests($token);
        if ($guests === []) {
            return $empty;
        }

        $waiting = [];
        $verifiedCount = 0;
        foreach ($guests as $guest) {
            $actorId = $guest['actorId'];
            $authRecord = $actorId !== ''
                ? $this->convBankAuthMapper->findByTokenAndActorId($token, $actorId)
                : null;
            $verified = $authRecord !== null;
            if ($verified) {
                $verifiedCount++;
            }

            $waiting[] = [
                'actorId' => $actorId,
                'displayName' => $verified && $authRecord !== null
                    ? $authRecord->getDisplayName()
                    : $guest['displayName'],
                'verified' => $verified,
            ];
        }

        return ['waiting' => $waiting, 'verifiedCount' => $verifiedCount];
    }

    // >>> HUBS-START-ADD (upstream-kandidat) ─ möten per ärende ──────────────
    /**
     * ÄRENDETS MÖTEN — kortets "Möten"-flik: alla bokade säkra möten (kommande
     * OCH genomförda) som är knutna till ärendet via bokningens dnr-märkning
     * (X-HUBS-DNR / CATEGORIES hubs-dnr-*, skrivna av SecureMeetingService).
     *
     * Söker anroparens EGNA kalendrar (CalDAV — samma synlighet som kalender-
     * appen ger användaren; ingen behörighetsbreddning). Tidsfönster: 90 dagar
     * bakåt (genomförda) till 365 framåt (kommande).
     *
     * @param string       $userId Den inloggade användaren.
     * @param list<string> $refs   Ärendets referenser (dnr + hubsCaseId).
     * @return array{kommande: list<array<string,mixed>>, genomforda: list<array<string,mixed>>}
     */
    public function getCaseMeetings(string $userId, array $refs): array {
        $empty = ['kommande' => [], 'genomforda' => []];
        if (!$this->calendarManager instanceof ICalendarManager || $refs === []) {
            return $empty;
        }
        $normRefs = [];
        foreach ($refs as $ref) {
            $r = strtolower(trim((string)$ref));
            if ($r !== '') {
                $normRefs[$r] = true;
            }
        }
        if ($normRefs === []) {
            return $empty;
        }

        try {
            $tz = new \DateTimeZone(date_default_timezone_get());
            $start = new \DateTimeImmutable('-90 days', $tz);
            $end = new \DateTimeImmutable('+365 days', $tz);
            $nu = new \DateTimeImmutable('now', $tz);

            $calendars = $this->calendarManager->getCalendarsForPrincipal('principals/users/' . $userId);
            $options = ['timerange' => ['start' => $start, 'end' => $end]];

            $kommande = [];
            $genomforda = [];
            foreach ($calendars as $calendar) {
                foreach ($calendar->search('/call/', ['LOCATION'], $options) as $row) {
                    $event = $this->normaliseCalendarRow($row);
                    if ($event === null) {
                        continue;
                    }
                    $dnr = strtolower(trim((string)($event['dnr'] ?? '')));
                    if ($dnr === '' || !isset($normRefs[$dnr])) {
                        continue;
                    }
                    $token = null;
                    if (preg_match('#/call/([A-Za-z0-9]+)#', $event['location'], $m) === 1) {
                        $token = $m[1];
                    }
                    $mote = [
                        'titel' => $event['title'],
                        'start' => $event['start'],
                        'slut' => $event['end'],
                        'callUrl' => $event['location'],
                        'token' => $token,
                        'dnr' => $event['dnr'],
                    ];
                    $ar = ($event['end'] ?? $event['start'] ?? '');
                    $arGenomford = $ar !== '' && $ar < $nu->format('c');
                    if ($arGenomford) {
                        $genomforda[] = $mote;
                    } else {
                        $kommande[] = $mote;
                    }
                }
            }

            usort($kommande, static fn (array $a, array $b): int => ($a['start'] ?? '~') <=> ($b['start'] ?? '~'));
            usort($genomforda, static fn (array $a, array $b): int => ($b['start'] ?? '') <=> ($a['start'] ?? ''));

            return ['kommande' => $kommande, 'genomforda' => $genomforda];
        } catch (\Throwable $e) {
            $this->logger->warning('hubs-start: case-meetings lookup failed', ['exception' => $e]);
            return $empty;
        }
    }
    // <<< HUBS-START-ADD ─────────────────────────────────────────────────────

    // -----------------------------------------------------------------------
    // CalDAV
    // -----------------------------------------------------------------------

    /**
     * @return list<array{location: string, title: string, start: ?string, end: ?string, dnr: ?string}>
     */
    private function findTodaysCallEvents(string $userId): array {
        if (!$this->calendarManager instanceof ICalendarManager) {
            return [];
        }

        try {
            $tz = new \DateTimeZone(date_default_timezone_get());
            $start = new \DateTimeImmutable('today 00:00:00', $tz);
            $end = new \DateTimeImmutable('tomorrow 00:00:00', $tz);

            $calendars = $this->calendarManager->getCalendarsForPrincipal('principals/users/' . $userId);
            if ($calendars === []) {
                return [];
            }

            $options = ['timerange' => ['start' => $start, 'end' => $end]];
            $results = [];
            foreach ($calendars as $calendar) {
                $found = $calendar->search('/call/', ['LOCATION'], $options);
                foreach ($found as $row) {
                    $normalised = $this->normaliseCalendarRow($row);
                    if ($normalised !== null) {
                        $results[] = $normalised;
                    }
                }
            }

            return $results;
        } catch (\Throwable $e) {
            $this->logger->warning('hubs-start: failed to read today\'s calendar events', ['exception' => $e]);
            return [];
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array{location: string, title: string, start: ?string, end: ?string, dnr: ?string}|null
     */
    private function normaliseCalendarRow(array $row): ?array {
        $objects = $row['objects'] ?? [];
        if (!is_array($objects)) {
            return null;
        }

        foreach ($objects as $object) {
            if (!is_array($object)) {
                continue;
            }
            $location = $this->firstPropertyValue($object, 'LOCATION');
            if ($location === '' || !str_contains($location, '/call/')) {
                continue;
            }

            return [
                'location' => $location,
                'title' => $this->firstPropertyValue($object, 'SUMMARY'),
                'start' => $this->propertyToIso($object['DTSTART'] ?? null),
                'end' => $this->propertyToIso($object['DTEND'] ?? null),
                'dnr' => $this->extractDnr($object),
            ];
        }

        return null;
    }

    /**
     * Extract a Hubs case dnr from the event. The booking side (SecureMeetingService
     * buildIcs) tags the case either as a CATEGORIES entry `hubs-dnr-{dnr}` or an
     * `X-HUBS-DNR` property. We accept both forms; X-HUBS-DNR wins if present.
     *
     * @param array<string, mixed> $object
     */
    private function extractDnr(array $object): ?string {
        // 1) Explicit X-HUBS-DNR property (preferred, carries the raw dnr verbatim).
        $explicit = trim($this->firstPropertyValue($object, 'X-HUBS-DNR'));
        if ($explicit !== '') {
            return $explicit;
        }

        // 2) CATEGORIES may be a comma-joined string OR a list of category values;
        //    look for the `hubs-dnr-{dnr}` marker in any of them.
        foreach ($this->categoryValues($object) as $category) {
            if (preg_match('/^hubs-dnr-(.+)$/i', trim($category), $m) === 1) {
                $dnr = trim($m[1]);
                if ($dnr !== '') {
                    return $dnr;
                }
            }
        }

        return null;
    }

    /**
     * Flatten the CATEGORIES property into individual category values, splitting
     * any comma-joined string Sabre may hand us into separate entries.
     *
     * @param array<string, mixed> $object
     * @return list<string>
     */
    private function categoryValues(array $object): array {
        $raw = $object['CATEGORIES'] ?? null;
        $values = [];

        $collect = static function ($item) use (&$values): void {
            if (is_string($item) && $item !== '') {
                foreach (explode(',', $item) as $part) {
                    $values[] = $part;
                }
            }
        };

        if (is_string($raw)) {
            $collect($raw);
        } elseif (is_array($raw)) {
            foreach ($raw as $entry) {
                if (is_array($entry)) {
                    foreach ($entry as $inner) {
                        $collect($inner);
                    }
                } else {
                    $collect($entry);
                }
            }
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $object
     */
    private function firstPropertyValue(array $object, string $property): string {
        $raw = $object[$property] ?? null;
        if (is_string($raw)) {
            return $raw;
        }
        if (is_array($raw)) {
            $first = $raw[0] ?? null;
            if (is_string($first)) {
                return $first;
            }
            if (is_array($first) && isset($first[0]) && is_string($first[0])) {
                return $first[0];
            }
        }
        return '';
    }

    private function propertyToIso(mixed $value): ?string {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        if (is_array($value)) {
            return $this->propertyToIso($value[0] ?? null);
        }
        if (is_int($value)) {
            return (new \DateTimeImmutable('@' . $value))->format(\DateTimeInterface::ATOM);
        }
        if (is_string($value) && $value !== '') {
            try {
                return (new \DateTimeImmutable($value))->format(\DateTimeInterface::ATOM);
            } catch (\Throwable) {
                return null;
            }
        }
        return null;
    }

    /**
     * Whole minutes from now until the meeting start. MAY be negative/0 once the
     * meeting has begun. Null when the start is missing/unparseable.
     */
    private function countdownMinutes(?string $startIso): ?int {
        if ($startIso === null || $startIso === '') {
            return null;
        }
        try {
            $start = new \DateTimeImmutable($startIso);
        } catch (\Throwable) {
            return null;
        }
        $diffSeconds = $start->getTimestamp() - (new \DateTimeImmutable('now'))->getTimestamp();
        return (int)floor($diffSeconds / 60);
    }

    private function extractCallToken(string $location): ?string {
        if ($location === '') {
            return null;
        }
        if (preg_match('#/call/([a-z0-9]{4,30})#', $location, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    // -----------------------------------------------------------------------
    // secure-room state
    // -----------------------------------------------------------------------

    /**
     * @return array{lobbyState: int, hasCall: bool, participants: int}
     */
    private function resolveRoomState(string $token): array {
        $default = ['lobbyState' => self::LOBBY_NONE, 'hasCall' => false, 'participants' => 0];

        if ($this->talkManager === null || !class_exists('OCA\\Talk\\Manager')) {
            return $default;
        }

        try {
            $room = $this->talkManager->getRoomByToken($token);

            $participants = 0;
            if ($this->participantService !== null) {
                try {
                    $participants = $this->participantService->getNumberOfActors($room);
                } catch (\Throwable $e) {
                    // best-effort
                }
            }

            return [
                'lobbyState' => $room->getLobbyState(false),
                'hasCall' => $room->getCallFlag() !== self::CALL_FLAG_DISCONNECTED,
                'participants' => $participants,
            ];
        } catch (\Throwable $e) {
            return $default;
        }
    }

    /**
     * Number of guests currently waiting in the lobby for this meeting. Reuses the
     * same guest-resolution path as getLobby() so the dashboard count and the live
     * lobby view never disagree. Best-effort: 0 when Talk is unavailable.
     */
    private function resolveLobbyWaitingCount(string $token): int {
        $guests = $this->resolveLobbyGuests($token);
        return count($guests);
    }

    /**
     * @return list<array{actorId: string, displayName: string}>
     */
    private function resolveLobbyGuests(string $token): array {
        if ($this->talkManager === null
            || $this->participantService === null
            || !class_exists('OCA\\Talk\\Model\\Attendee')) {
            return [];
        }

        try {
            $room = $this->talkManager->getRoomByToken($token);
            $participants = $this->participantService->getParticipantsForRoom($room);

            $guests = [];
            foreach ($participants as $participant) {
                $attendee = $participant->getAttendee();
                $actorType = $attendee->getActorType();
                if ($actorType !== \OCA\Talk\Model\Attendee::ACTOR_GUESTS
                    && $actorType !== \OCA\Talk\Model\Attendee::ACTOR_EMAILS) {
                    continue;
                }

                $guests[] = [
                    'actorId' => (string)$attendee->getActorId(),
                    'displayName' => (string)$attendee->getDisplayName(),
                ];
            }

            return $guests;
        } catch (\Throwable $e) {
            return [];
        }
    }

    // -----------------------------------------------------------------------
    // BankID / verification badge
    // -----------------------------------------------------------------------

    /**
     * // TODO(hubs-start): add ConversationBankIDAuthMapper::findByConversation()
     * // and replace this inline query so the table name lives in one place.
     *
     * @return array{required: bool, badge: ('green'|'purple'|null)}
     */
    private function resolveBankIdState(string $token): array {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('required_ssn')
                ->from('sdkmc_conv_bank_auth')
                ->where($qb->expr()->eq(
                    'conversation_id',
                    $qb->createNamedParameter($token, IQueryBuilder::PARAM_STR)
                ));

            $result = $qb->executeQuery();
            $rows = $result->fetchAll();
            $result->closeCursor();

            if ($rows === []) {
                return ['required' => false, 'badge' => null];
            }

            foreach ($rows as $row) {
                $ssn = $row['required_ssn'] ?? null;
                if (is_string($ssn) && $ssn !== '') {
                    return ['required' => true, 'badge' => 'green'];
                }
            }

            return ['required' => true, 'badge' => 'purple'];
        } catch (\Throwable $e) {
            $this->logger->warning('hubs-start: failed to resolve BankID state', ['exception' => $e]);
            return ['required' => false, 'badge' => null];
        }
    }
}
