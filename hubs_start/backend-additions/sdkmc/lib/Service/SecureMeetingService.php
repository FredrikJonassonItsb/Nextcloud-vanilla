<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * NEW FILE for the sdkmc app. Target: lib/Service/SecureMeetingService.php
 */

namespace OCA\SdkMc\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\SdkMc\Db\ConversationBankIDAuth;
use OCA\SdkMc\Db\ConversationBankIDAuthMapper;
use OCA\SdkMc\Utils\SSNHelper;
use OCP\Calendar\ICalendar;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager as ICalendarManager;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * One server-side operation behind the "Boka säkert möte" wizard.
 *
 * Replaces the brittle gov-portal flow (calendar-sms.js DOM injection + PHP
 * session intents that broke under parallel edits). The orchestration is:
 *
 *   1. Create a Talk room (spreed OCS v4) → token.
 *   2. PUT a CalDAV event whose LOCATION carries the /call/{token} URL — exactly
 *      what SmsNotifyListener/IcsParserService later read back to fire intents.
 *   3. Register BankID / SMS / securemail intents bound to the EVENT UID + the
 *      Talk token (NOT the PHP session — that was the parallel-edit bug). The
 *      BankID requirement is persisted durably via ConversationBankIDAuthMapper
 *      keyed by the conversation (token); SMS/securemail intents are queued for
 *      the calendar-event listener which pops them when the ICS lands.
 *   4. Add the citizen e-mail participant with ?resend-invitations so spreed
 *      mints the invitation/access token.
 *
 * Returns the contract shape consumed by api.createSecureMeeting():
 *   { token, eventUid, start, end, smsStatus, protection }
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class SecureMeetingService {

    private const SMS_KEY        = 'sdkmc_sms_intents';
    private const SECUREMAIL_KEY = 'sdkmc_securemail_invite_intents';

    /** spreed Room::TYPE_PUBLIC */
    private const ROOM_TYPE_PUBLIC = 3;

    public function __construct(
        private LoggerInterface $logger,
        private IAppConfig $appConfig,
        private IClientService $clientService,
        private IURLGenerator $urlGenerator,
        private ISession $session,
        private IUserManager $userManager,
        private ConversationBankIDAuthMapper $convBankAuthMapper,
        private ICalendarManager $calendarManager,
        private IL10N $l,
    ) {
    }

    /**
     * Orchestrate the whole secure-meeting booking.
     *
     * @param string $userId The booking organizer (NC uid).
     * @param array{
     *     citizen?: array{name?: string, ssn?: string, mobile?: string, secureEmail?: string},
     *     colleagueUserId?: ?string,
     *     start?: string,
     *     end?: string,
     *     title?: string,
     *     dnr?: ?string,
     *     requireBankId?: bool,
     *     sendSms?: bool,
     *     sendSecureEmailInvite?: bool,
     *     fromMailboxId?: ?int
     * } $request
     *
     * @return array{token: string, eventUid: string, dnr: ?string, start: string, end: string, smsStatus: string, protection: array{bankId: bool, sms: bool, secureEmail: bool}}
     */
    public function create(string $userId, array $request): array {
        $citizen = is_array($request['citizen'] ?? null) ? $request['citizen'] : [];
        $citizenName  = $this->str($citizen['name'] ?? '');
        $citizenSsn   = SSNHelper::formatSsn($this->str($citizen['ssn'] ?? ''));
        $citizenMobile = $this->str($citizen['mobile'] ?? '');
        $citizenEmail = strtolower($this->str($citizen['secureEmail'] ?? ''));

        $colleagueUserId = $this->str($request['colleagueUserId'] ?? '') ?: null;
        $title = $this->str($request['title'] ?? '') ?: $this->defaultTitle($citizenName);
        $dnr   = $this->str($request['dnr'] ?? '') ?: null;

        $requireBankId = (bool)($request['requireBankId'] ?? true);
        $sendSms       = (bool)($request['sendSms'] ?? false);
        $sendSecure    = (bool)($request['sendSecureEmailInvite'] ?? false);
        $fromMailboxId = isset($request['fromMailboxId']) && is_numeric($request['fromMailboxId'])
            ? (int)$request['fromMailboxId']
            : null;

        [$start, $end] = $this->resolveTimes(
            $this->str($request['start'] ?? ''),
            $this->str($request['end'] ?? ''),
        );

        // 1. Talk room ---------------------------------------------------------
        $token = $this->createTalkRoom($userId, $title);
        $callUrl = $this->urlGenerator->getAbsoluteURL('/call/' . $token);

        // 2. CalDAV event with the call URL in LOCATION ------------------------
        $eventUid = $this->createCalendarEvent($userId, $title, $start, $end, $callUrl, $citizenEmail, $dnr);

        // 3. Intents bound to the EVENT UID (durable, not PHP session) ---------
        if ($requireBankId && $citizenEmail !== '') {
            $this->registerBankIdIntent($token, $eventUid, $citizenEmail, $citizenSsn);
        }

        $smsStatus = 'none';
        if ($sendSms && $citizenMobile !== '' && $citizenEmail !== '') {
            $smsStatus = $this->registerSmsIntent($eventUid, $citizenEmail, $citizenMobile);
        }

        if ($sendSecure && $citizenEmail !== '') {
            $this->registerSecuremailIntent($eventUid, $citizenEmail, $citizenSsn, $fromMailboxId);
        }

        // 4. Add the e-mail participant (spreed mints the invitation token). ---
        if ($citizenEmail !== '') {
            $this->addEmailParticipant($userId, $token, $citizenEmail);
        }

        if ($colleagueUserId !== null && $colleagueUserId !== '') {
            $this->addUserParticipant($userId, $token, $colleagueUserId);
        }

        $this->logger->info('[HUBS-START] Secure meeting created', [
            'organizer' => $userId,
            'token' => $token,
            'eventUid' => $eventUid,
            'protection' => ['bankId' => $requireBankId, 'sms' => $sendSms, 'secureEmail' => $sendSecure],
        ]);

        return [
            'token' => $token,
            'eventUid' => $eventUid,
            'dnr' => $dnr,
            'start' => $start->format(DateTimeInterface::ATOM),
            'end' => $end->format(DateTimeInterface::ATOM),
            'smsStatus' => $smsStatus,
            'protection' => [
                'bankId' => $requireBankId,
                'sms' => $sendSms,
                'secureEmail' => $sendSecure,
            ],
        ];
    }

    // ---------------------------------------------------------------------
    // Talk room (spreed OCS v4)
    // ---------------------------------------------------------------------

    /**
     * Create a public Talk room through spreed's OCS v4 API on the local
     * instance, acting on behalf of the organizer.
     *
     * TODO(hubs-start): when the spreed Manager/RoomService is guaranteed to be
     * loadable in this process, prefer a direct call over the loopback HTTP hop
     * (guard with class_exists(\OCA\Talk\Service\RoomService::class)). The HTTP
     * path below is the dependency-light default that always matches the OCS
     * contract the frontend expects.
     */
    private function createTalkRoom(string $userId, string $roomName): string {
        // HUBS-START-ADD fix: create the room IN-PROCESS via spreed's RoomService.
        // The loopback OCS hop below carries no PHP session server-side and returns
        // 401 "Current user is not logged in", so the meeting could never be booked.
        // RoomService::createConversation mints the room directly as the organizer —
        // no app-password/token needed.
        if (class_exists(\OCA\Talk\Service\RoomService::class)) {
            try {
                /** @var \OCA\Talk\Service\RoomService $roomService */
                $roomService = \OCP\Server::get(\OCA\Talk\Service\RoomService::class);
                $room = $roomService->createConversation(
                    self::ROOM_TYPE_PUBLIC,
                    $roomName,
                    $this->userManager->get($userId),
                );
                $token = $room->getToken();
                if ($token !== '') {
                    return $token;
                }
                $this->logger->error('[HUBS-START] in-process Talk room create returned no token');
            } catch (\Throwable $e) {
                $this->logger->error('[HUBS-START] in-process Talk room create failed, falling back to OCS: ' . $e->getMessage(), ['exception' => $e]);
            }
        }

        // Fallback: loopback OCS hop (requires auth; kept for environments where the
        // spreed service classes are not loadable in-process).
        $url = $this->urlGenerator->getAbsoluteURL('/ocs/v2.php/apps/spreed/api/v4/room');

        try {
            $client = $this->clientService->newClient();
            $response = $client->post($url, [
                'headers' => [
                    'OCS-APIRequest' => 'true',
                    'Accept' => 'application/json',
                ],
                'body' => [
                    'roomType' => self::ROOM_TYPE_PUBLIC,
                    'roomName' => $roomName,
                ],
                'timeout' => 10,
            ]);

            $token = $this->extractRoomToken((string)$response->getBody());
            if ($token !== '') {
                return $token;
            }
            $this->logger->error('[HUBS-START] spreed room create returned no token', ['body' => (string)$response->getBody()]);
        } catch (\Throwable $e) {
            $this->logger->error('[HUBS-START] Failed to create Talk room: ' . $e->getMessage(), ['exception' => $e]);
        }

        // The contract requires a token field; surface the failure to the caller.
        throw new \RuntimeException('Could not create secure meeting room');
    }

    private function extractRoomToken(string $body): string {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return '';
        }
        $data = $decoded['ocs']['data'] ?? null;
        if (is_array($data) && isset($data['token']) && is_string($data['token'])) {
            return $data['token'];
        }
        return '';
    }

    // ---------------------------------------------------------------------
    // CalDAV event (LOCATION carries the call URL)
    // ---------------------------------------------------------------------

    /**
     * PUT a VEVENT into the organizer's default personal calendar with the call
     * URL in LOCATION (the exact thing IcsParserService::extractTokenFromLocation
     * reads back). Returns the generated event UID.
     */
    private function createCalendarEvent(
        string $userId,
        string $title,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        string $callUrl,
        string $citizenEmail,
        ?string $dnr = null,
    ): string {
        $eventUid = $this->generateUid();
        $objectUri = $eventUid . '.ics';
        $ics = $this->buildIcs($eventUid, $title, $start, $end, $callUrl, $userId, $citizenEmail, $dnr);

        try {
            $principalUri = 'principals/users/' . $userId;
            $calendars = $this->calendarManager->getCalendarsForPrincipal($principalUri);

            $target = null;
            foreach ($calendars as $calendar) {
                if (!$calendar instanceof ICreateFromString) {
                    continue;
                }
                // Prefer the user's primary "personal" calendar when present.
                // getUri() is declared on ICalendar; narrow before reading it.
                $uri = $calendar instanceof ICalendar ? $calendar->getUri() : '';
                if ($uri === 'personal') {
                    $target = $calendar;
                    break;
                }
                $target ??= $calendar;
            }

            if ($target instanceof ICreateFromString) {
                $target->createFromString($objectUri, $ics);
                return $eventUid;
            }

            $this->logger->error('[HUBS-START] No writable calendar found for principal', ['userId' => $userId]);
        } catch (\Throwable $e) {
            $this->logger->error('[HUBS-START] Failed to write CalDAV event: ' . $e->getMessage(), ['exception' => $e]);
        }

        // TODO(hubs-start): if the user has no writable calendar yet, provision a
        // default one (CalDavBackend::createCalendar) before the PUT. The UID is
        // still returned so intents bind correctly and the room link works.
        return $eventUid;
    }

    /**
     * Build a minimal VCALENDAR/VEVENT with the call URL in LOCATION and the
     * citizen as ATTENDEE — the same surface SmsNotifyListener consumes.
     */
    private function buildIcs(
        string $uid,
        string $title,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        string $callUrl,
        string $organizerUserId,
        string $citizenEmail,
        ?string $dnr = null,
    ): string {
        $fmt = static fn (DateTimeImmutable $d): string => $d->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
        $esc = static fn (string $v): string => addcslashes($v, ",;\\\n");

        $organizerEmail = $this->organizerEmail($organizerUserId);

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//ITSL//Hubs Start//SV',
            'CALSCALE:GREGORIAN',
            // NB: NO 'METHOD:' here — a calendar object stored on a CalDAV server
            // MUST NOT carry a METHOD property (that is for iTIP/iMIP invitations).
            // Sabre rejects it with UnsupportedMediaType → the event never lands.
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . $fmt(new DateTimeImmutable('now')),
            'DTSTART:' . $fmt($start),
            'DTEND:' . $fmt($end),
            'SUMMARY:' . $esc($title),
            'LOCATION:' . $esc($callUrl),
            'ORGANIZER;CN=' . $esc($organizerUserId) . ':mailto:' . $organizerEmail,
        ];

        // Bind this meeting to a Hubs case so MeetingService can read the dnr
        // back out of the ICS (CATEGORIES:hubs-dnr-{dnr} + X-HUBS-DNR mirror).
        $dnr = $dnr !== null ? trim($dnr) : '';
        if ($dnr !== '') {
            $lines[] = 'CATEGORIES:hubs-dnr-' . $esc($dnr);
            $lines[] = 'X-HUBS-DNR:' . $esc($dnr);
        }

        if ($citizenEmail !== '') {
            $lines[] = 'ATTENDEE;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:' . $citizenEmail;
        }

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines) . "\r\n";
    }

    private function organizerEmail(string $userId): string {
        $user = $this->userManager->get($userId);
        $email = $user?->getEMailAddress();
        if (is_string($email) && $email !== '') {
            return strtolower($email);
        }
        return $userId . '@localhost';
    }

    // ---------------------------------------------------------------------
    // Intents — bound to the EVENT UID, durable for BankID
    // ---------------------------------------------------------------------

    /**
     * Persist the BankID requirement durably (DB), keyed by the conversation
     * token. This is the non-session path the contract demands: the requirement
     * survives parallel bookings because it is per-conversation, not per-session.
     */
    private function registerBankIdIntent(string $token, string $eventUid, string $email, string $ssn): void {
        try {
            $existing = $this->convBankAuthMapper->findByConversationAndEmail($token, $email);
            if ($existing !== null) {
                return;
            }

            $auth = new ConversationBankIDAuth();
            $auth->setConversationId($token);
            $auth->setEmail(strtolower($email));
            $auth->setCreatedAt(date('Y-m-d H:i:s'));
            if ($ssn !== '') {
                $auth->setRequiredSsn($ssn);
            }
            $auth->setShowFirstName(true);
            $auth->setShowLastName(true);
            $auth->setShowSsn(true);

            $this->convBankAuthMapper->insert($auth);

            $this->logger->info('[HUBS-START][BANKID] Intent persisted', [
                'token' => $token,
                'eventUid' => $eventUid,
                'email' => $email,
                'hasSsn' => $ssn !== '',
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[HUBS-START][BANKID] Failed to persist intent: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * Queue an SMS intent for the calendar-event listener (IntentProcessorService
     * pops it when the ICS lands). Keyed by the event UID so parallel bookings do
     * not clobber each other.
     *
     * TODO(hubs-start): IntentProcessorService currently pops SMS/securemail
     * intents from the PHP-session list keyed by email only. Extend it (and the
     * stored shape below) to also match on eventUid so the binding is fully
     * session-independent like BankID; until then the eventUid is stored
     * alongside but the listener still matches on email.
     *
     * @return string 'queued' once the intent is registered.
     */
    private function registerSmsIntent(string $eventUid, string $email, string $phone): string {
        $list = $this->getSessionIntents(self::SMS_KEY);
        $list = $this->removeByEmail($list, $email);
        $list[] = [
            'email' => strtolower($email),
            'phone' => $phone,
            'eventUid' => $eventUid,
        ];
        $this->saveSessionIntents(self::SMS_KEY, $list);

        $this->logger->info('[HUBS-START][SMS] Intent queued', ['email' => $email, 'eventUid' => $eventUid]);
        return 'queued';
    }

    /**
     * Queue a securemail-invite intent for the calendar-event listener.
     *
     * TODO(hubs-start): same session-keying caveat as registerSmsIntent — the
     * eventUid is stored but IntentProcessorService::popSecuremailIntent matches
     * on email; extend it to match eventUid for full session independence.
     */
    private function registerSecuremailIntent(string $eventUid, string $email, string $ssn, ?int $accountId): void {
        $list = $this->getSessionIntents(self::SECUREMAIL_KEY);
        $list = $this->removeByEmail($list, $email);
        $intent = [
            'email' => strtolower($email),
            'ssn' => $ssn,
            'ts' => (new DateTimeImmutable('now'))->format('c'),
            'eventUid' => $eventUid,
        ];
        if ($accountId !== null) {
            $intent['accountId'] = $accountId;
        }
        $list[] = $intent;
        $this->saveSessionIntents(self::SECUREMAIL_KEY, $list);

        $this->logger->info('[HUBS-START][SECUREMAIL] Intent queued', ['email' => $email, 'eventUid' => $eventUid]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getSessionIntents(string $key): array {
        $raw = $this->session->get($key);
        $decoded = json_decode(is_string($raw) ? $raw : '[]', true);
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    /**
     * @param array<int, array<string, mixed>> $list
     * @return array<int, array<string, mixed>>
     */
    private function removeByEmail(array $list, string $email): array {
        $email = strtolower($email);
        return array_values(array_filter(
            $list,
            static fn (array $item): bool => strtolower((string)($item['email'] ?? '')) !== $email,
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $list
     */
    private function saveSessionIntents(string $key, array $list): void {
        $this->session->set($key, json_encode($list));
    }

    // ---------------------------------------------------------------------
    // Participants (spreed OCS v4 + ?resend-invitations)
    // ---------------------------------------------------------------------

    /**
     * Add the citizen as an e-mail participant and trigger the spreed invitation
     * (?resend-invitations) so an access/invitation token is minted.
     */
    private function addEmailParticipant(string $userId, string $token, string $email): void {
        // TODO(hubs-start): same loopback-credential wiring as createTalkRoom().
        $url = $this->urlGenerator->getAbsoluteURL(
            '/ocs/v2.php/apps/spreed/api/v4/room/' . rawurlencode($token) . '/participants?resend-invitations=1'
        );

        try {
            $client = $this->clientService->newClient();
            $client->post($url, [
                'headers' => [
                    'OCS-APIRequest' => 'true',
                    'Accept' => 'application/json',
                ],
                'body' => [
                    'newParticipant' => $email,
                    'source' => 'emails',
                ],
                'timeout' => 10,
            ]);
            $this->logger->info('[HUBS-START] Email participant invited', ['token' => $token, 'email' => $email]);
        } catch (\Throwable $e) {
            $this->logger->error('[HUBS-START] Failed to add email participant: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    private function addUserParticipant(string $userId, string $token, string $colleagueUserId): void {
        // TODO(hubs-start): same loopback-credential wiring as createTalkRoom().
        $url = $this->urlGenerator->getAbsoluteURL(
            '/ocs/v2.php/apps/spreed/api/v4/room/' . rawurlencode($token) . '/participants'
        );

        try {
            $client = $this->clientService->newClient();
            $client->post($url, [
                'headers' => [
                    'OCS-APIRequest' => 'true',
                    'Accept' => 'application/json',
                ],
                'body' => [
                    'newParticipant' => $colleagueUserId,
                    'source' => 'users',
                ],
                'timeout' => 10,
            ]);
            $this->logger->info('[HUBS-START] User participant added', ['token' => $token, 'user' => $colleagueUserId]);
        } catch (\Throwable $e) {
            $this->logger->error('[HUBS-START] Failed to add user participant: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Resolve start/end. Defaults to a 30-minute slot starting in 15 minutes
     * (covers the "Starta möte nu" path where no time is given).
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    private function resolveTimes(string $startRaw, string $endRaw): array {
        try {
            $start = $startRaw !== '' ? new DateTimeImmutable($startRaw) : new DateTimeImmutable('+15 minutes');
        } catch (\Throwable $e) {
            $start = new DateTimeImmutable('+15 minutes');
        }

        try {
            $end = $endRaw !== '' ? new DateTimeImmutable($endRaw) : $start->modify('+30 minutes');
        } catch (\Throwable $e) {
            $end = $start->modify('+30 minutes');
        }

        if ($end <= $start) {
            $end = $start->modify('+30 minutes');
        }

        return [$start, $end];
    }

    private function defaultTitle(string $citizenName): string {
        $citizenName = trim($citizenName);
        // Brand rule: never expose "Talk"/"Nextcloud". Use Hubs terminology.
        return $citizenName !== ''
            ? $this->l->t('Säkert möte – %s', [$citizenName])
            : $this->l->t('Säkert möte');
    }

    private function generateUid(): string {
        return 'hubs-' . bin2hex(random_bytes(16));
    }

    private function str(mixed $value): string {
        return is_string($value) ? trim($value) : '';
    }
}
