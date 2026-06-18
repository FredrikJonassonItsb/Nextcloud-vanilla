<?php

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IURLGenerator;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use OCA\SdkMc\Service\CalendarEventProcessorService;
use OCA\SdkMc\Service\IntentProcessorService;
use OCP\AppFramework\Services\IAppConfig;
use OCA\SdkMc\Service\CustomInvitationMailerService;
use OCA\SdkMc\Service\IcsParserService;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Manager;
use OCA\Talk\Service\ParticipantService;

/**
 * TODO: RENAME!! this is not just an sms notify listener
 *
 * @param IDBConnection $db
 * @implements IEventListener<Event>
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class SmsNotifyListener implements IEventListener {
    /**
     * @SuppressWarnings("PHPMD.ExcessiveParameterList")
     */
    public function __construct(
        private LoggerInterface $logger,
        private IAppConfig $appConfig,
        private CalendarEventProcessorService $calendarProcessor,
        private IntentProcessorService $intentProcessor,
        private CustomInvitationMailerService $customMailer,
        private IcsParserService $icsParser,
        private IURLGenerator $urlGenerator,
        private Manager $talkManager,
        private ParticipantService $participantService,
        private IDBConnection $db,
    ) {
    }

    public function handle(Event $event): void {
        if (!$this->isCalendarEvent($event)) {
            return;
        }

        if (!$this->appConfig->getAppValueBool('secureMeetingsEnabled', true)) {
            return;
        }

        try {
            // Extract ICS data once
            $icsData = $this->calendarProcessor->extractIcsData($event);

            if ($icsData === null) {
                return;
            }

            // Don't send invitations for attendee responses
            if ($this->isReplyMessage($icsData)) {
                $this->logger->debug('[INTENTS] Skipping REPLY message - attendee responses don\'t trigger new invitations');
                return;
            }

            // Process intents using the ICS data
            $events = $this->icsParser->extractAttendees($icsData);
            if ($events !== []) {
                $this->intentProcessor->processEvents($events);
            }

            // Send custom invitations using the same ICS data
            $this->sendCustomInvitations($icsData);
        } catch (\Throwable $e) {
            $this->logger->error('[INTENTS] Failed to process calendar event: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }

    private function sendCustomInvitations(string $icsData): void {
        try {
            // Extract event data
            $eventData = $this->icsParser->extractEventData($icsData);
            $organizer = $eventData['organizer'];
            $token = $eventData['token'];

            if ($organizer === '' || $token === '') {
                $this->logger->error('[CALENDAR INVITATION] Missing organizer or token', [
                    'organizer' => $organizer,
                    'token' => $token
                ]);
                return;
            }

            // Get all recipient emails
            $recipients = $this->icsParser->extractRecipientEmails($icsData);

            if ($recipients === []) {
                $this->logger->warning('[CALENDAR INVITATION] No recipients found');
                return;
            }

            // Send custom invitation to each recipient
            foreach ($recipients as $recipient) {
                try {
                    // Get access token for this recipient
                    $access = $this->tryGetAccessForEmailAndToken($recipient, $token);

                    // Generate invitation link for this recipient
                    $invitationLink = $this->urlGenerator->linkToRouteAbsolute(
                        'spreed.Page.showCall',
                        [
                            'token' => $token,
                            'email' => $recipient,
                            'access' => $access,
                        ]
                    );

                    // Generate accept/decline links using the access token
                    $invitationToken = $this->getInvitationToken($eventData['uid'], $recipient);

                    $acceptLink = null;
                    $declineLink = null;

                    if ($invitationToken !== null) {
                        $acceptLink = $this->urlGenerator->linkToRouteAbsolute(
                            'dav.invitation_response.accept',
                            ['token' => $invitationToken]
                        );
                        $declineLink = $this->urlGenerator->linkToRouteAbsolute(
                            'dav.invitation_response.decline',
                            ['token' => $invitationToken]
                        );
                    }

                    $this->customMailer->sendCustomInvitation(
                        $recipient,
                        $organizer,
                        $icsData,
                        $invitationLink,
                        $acceptLink,
                        $declineLink
                    );

                    $this->logger->info('[CALENDAR INVITATION] Sent invitation', [
                        'recipient' => $recipient,
                        'hasAcceptDeclineLinks' => ($acceptLink !== null)
                    ]);
                } catch (\Exception $e) {
                    $this->logger->error('[CALENDAR INVITATION] Failed to send invitation', [
                        'recipient' => $recipient,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('[CALENDAR INVITATION] Failed to send custom invitations: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }

    private function isCalendarEvent(Event $event): bool {
        $eventClass = get_class($event);

        return $eventClass === 'OCA\\DAV\\Events\\CalendarObjectCreatedEvent'
               || $eventClass === 'OCA\\DAV\\Events\\CalendarObjectUpdatedEvent';
    }

    private function tryGetAccessForEmailAndToken(string $email, string $token): ?string {
        try {
            $room = $this->tryGetRoomByToken($token);
            if ($room === null) {
                $this->logger->error('[CALENDAR INVITATION] Room not found by token', [
                    'token' => $token,
                ]);
                return null;
            }

            $participants = $this->participantService->getParticipantsForRoom($room);

            foreach ($participants as $participant) {
                $attendee = $participant->getAttendee();
                if (
                    $attendee->getActorType() === Attendee::ACTOR_EMAILS
                    && strcasecmp($attendee->getInvitedCloudId(), $email) === 0
                ) {
                    $access = $attendee->getAccessToken();
                    if ($access !== '') {
                        return $access;
                    }
                    return null;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('[CALENDAR INVITATION] Failed to obtain access token: ' . $e->getMessage(), [
                'exception' => $e,
                'token' => $token,
                'email' => $email,
            ]);
        }

        return null;
    }

    /**
     * @return \OCA\Talk\Room|null
     */
    private function tryGetRoomByToken(string $token) {
        try {
            return $this->talkManager->getRoomByToken($token);
        } catch (\Throwable $e) {
            $this->logger->error('[CALENDAR INVITATION] Room not found for token', ['token' => $token]);
            return null;
        }
    }

    /**
     * Get the invitation token for a specific attendee from the calendar_invitations table
     */
    private function getInvitationToken(string $uid, string $attendeeEmail): ?string {
        try {
            // Format attendee email as mailto URI (as stored in the database)
            $attendee = 'mailto:' . $attendeeEmail;

            $query = $this->db->getQueryBuilder();
            $query->select('token')
                ->from('calendar_invitations')
                ->where($query->expr()->eq('uid', $query->createNamedParameter($uid)))
                ->andWhere($query->expr()->eq('attendee', $query->createNamedParameter($attendee)))
                ->setMaxResults(1);

            $result = $query->executeQuery();
            $row = $result->fetch(\PDO::FETCH_ASSOC);
            $result->closeCursor();

            if (is_array($row) && isset($row['token']) && is_string($row['token'])) {
                return $row['token'];
            }
            return null;
        } catch (\Throwable $e) {
            $this->logger->error('[CALENDAR INVITATION] Failed to get invitation token', [
                'uid' => $uid,
                'attendee' => $attendeeEmail,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function isReplyMessage(string $icsData): bool {
        return stripos($icsData, 'METHOD:REPLY') !== false;
    }
}
