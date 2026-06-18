<?php

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Service;

use DateTime;
use Exception;
use Sabre\VObject;
use Psr\Log\LoggerInterface;

class IcsParserService {
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Extract token from ICS location field
     * This is the conversation/room token used in Talk URLs
     */
    public function extractTokenFromLocation(string $ics): string {
        try {
            $vcal = VObject\Reader::read($ics);
            if (!$vcal instanceof VObject\Component\VCalendar) {
                return '';
            }

            $vevents = $vcal->select('VEVENT');
            if ($vevents === []) {
                return '';
            }

            $vevent = reset($vevents);
            if ($vevent === false || !$vevent instanceof VObject\Component\VEvent) {
                return '';
            }

            return $this->extractConversationIdFromLocation($vevent) ?? '';
        } catch (\Throwable $e) {
            $this->logger->error('[CALENDAR INVITATION] Failed to extract token from ICS: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * @return array<int, array{uid: string, summary: string, conversation_id: string|null, attendees: array<int, array{email: string, name: string, role: string, status: string}>}>
     */
    public function extractAttendees(string $ics): array {
        try {
            $vcal = VObject\Reader::read($ics);
            if (!$vcal instanceof VObject\Component\VCalendar) {
                return [];
            }

            $vevents = $vcal->select('VEVENT');
            if ($vevents === []) {
                return [];
            }

            $all = [];
            foreach ($vevents as $vevent) {
                if (!$vevent instanceof VObject\Component\VEvent) {
                    continue;
                }

                $conversationId = $this->extractConversationIdFromLocation($vevent);
                $eventAttendees = $this->extractEventAttendees($vevent);

                if ($eventAttendees !== []) {
                    $all[] = [
                        'uid'             => $this->getVEventPropertyValue($vevent, 'UID'),
                        'summary'         => $this->getVEventPropertyValue($vevent, 'SUMMARY'),
                        'conversation_id' => $conversationId,
                        'attendees'       => $eventAttendees,
                    ];
                }
            }
            return $all;
        } catch (\Throwable $e) {
            $this->logger->error('[INTENTS] Failed to parse ICS data: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Extract event data from ICS for email templates
     *
     * @return array{title: string, description: string, location: string, organizer: string, uid: string, token: string}
     */
    public function extractEventData(string $ics): array {
        $eventData = [
            'title' => '',
            'description' => '',
            'location' => '',
            'organizer' => '',
            'uid' => '',
            'token' => '',
        ];

        try {
            $vcal = VObject\Reader::read($ics);
            if (!$vcal instanceof VObject\Component\VCalendar) {
                return $eventData;
            }

            $vevents = $vcal->select('VEVENT');
            if ($vevents === []) {
                return $eventData;
            }

            $vevent = reset($vevents);
            if ($vevent === false || !$vevent instanceof VObject\Component\VEvent) {
                return $eventData;
            }

            $eventData['title'] = $this->getVEventPropertyValue($vevent, 'SUMMARY');
            $eventData['description'] = $this->getVEventPropertyValue($vevent, 'DESCRIPTION');
            $eventData['location'] = $this->getVEventPropertyValue($vevent, 'LOCATION');
            $eventData['uid'] = $this->getVEventPropertyValue($vevent, 'UID');
            $eventData['token'] = $this->extractConversationIdFromLocation($vevent) ?? '';

            $organizer = $vevent->select('ORGANIZER');
            $firstOrganizer = reset($organizer);
            if ($firstOrganizer !== false && $firstOrganizer instanceof VObject\Property) {
                $eventData['organizer'] = $this->extractEmailFromValue($firstOrganizer->getValue());
            }
        } catch (\Throwable $e) {
            $this->logger->error('[INTENTS] Failed to extract event data from ICS: ' . $e->getMessage());
        }

        return $eventData;
    }

    /**
     * Extract formatted date and time from ICS content
     */
    public function extractDateTimeFromIcs(string $icsContent): string {
        try {
            $vcal = VObject\Reader::read($icsContent);
            if (!$vcal instanceof VObject\Component\VCalendar) {
                return '';
            }

            $vevents = $vcal->select('VEVENT');
            if ($vevents === []) {
                return '';
            }

            $vevent = reset($vevents);
            if ($vevent === false || !$vevent instanceof VObject\Component\VEvent) {
                return '';
            }

            // Get DTSTART
            $dtstart = $vevent->select('DTSTART');
            $firstDtstart = reset($dtstart);
            if ($firstDtstart === false || !$firstDtstart instanceof VObject\Property) {
                return '';
            }

            // Get DTEND if exists
            $dtend = $vevent->select('DTEND');
            $firstDtend = reset($dtend);

            // Get DateTime values using string conversion
            $startValue = (string)$firstDtstart;
            $endValue = $firstDtend !== false && $firstDtend instanceof VObject\Property
                ? (string)$firstDtend
                : null;

            // Convert to DateTime objects with error handling
            try {
                $startDateTime = new DateTime($startValue);
            } catch (Exception $e) {
                $this->logger->error('[ICS PARSER] Failed to parse start date: ' . $startValue);
                return '';
            }

            $endDateTime = null;
            if ($endValue !== null) {
                try {
                    $endDateTime = new DateTime($endValue);
                } catch (Exception $e) {
                    $this->logger->warning('[ICS PARSER] Failed to parse end date: ' . $endValue);
                    // Continue without end date
                }
            }

            // Check if it's an all-day event (has VALUE=DATE parameter)
            $isAllDay = false;
            if (isset($firstDtstart['VALUE'])) {
                $valueParam = $firstDtstart['VALUE'];
                if ($valueParam instanceof VObject\Parameter) {
                    $isAllDay = $valueParam->getValue() === 'DATE';
                }
            }

            if ($isAllDay) {
                // All-day event
                if ($endDateTime !== null && $startDateTime->format('Y-m-d') !== $endDateTime->format('Y-m-d')) {
                    // Multi-day event - FIX: Clone the endDateTime before modifying
                    $endDateTimeClone = clone $endDateTime;
                    return $startDateTime->format('l, F j, Y') . ' - ' . $endDateTimeClone->modify('-1 day')->format('l, F j, Y');
                }
                return $startDateTime->format('l, F j, Y');
            }

            // Timed event
            $formatted = $startDateTime->format('l, F j, Y \a\t g:i A');
            if ($endDateTime !== null) {
                if ($startDateTime->format('Y-m-d') === $endDateTime->format('Y-m-d')) {
                    // Same day
                    $formatted .= ' - ' . $endDateTime->format('g:i A');
                    return $formatted;
                }
                // Multi-day
                $formatted .= ' - ' . $endDateTime->format('l, F j, Y \a\t g:i A');
            }
            return $formatted;
        } catch (\Throwable $e) {
            $this->logger->error('[ICS PARSER] Failed to extract date/time from ICS', [
                'error' => $e->getMessage()
            ]);
            return '';
        }
    }

    /**
     * Modify ICS data for a specific recipient:
     * - Remove all attendees except organizer and specified recipient
     * - Remove SUMMARY (title)
     * - Remove DESCRIPTION
     * - Replace LOCATION with invitation link
     *
     * @return string Modified ICS content
     */
    public function modifyIcsForRecipient(string $ics, string $organizerEmail, string $recipientEmail, string $invitationLink): string {
        try {
            $vcal = VObject\Reader::read($ics);
            if (!$vcal instanceof VObject\Component\VCalendar) {
                return $ics;
            }

            $vevents = $vcal->select('VEVENT');
            if ($vevents === []) {
                return $ics;
            }

            foreach ($vevents as $vevent) {
                if (!$vevent instanceof VObject\Component\VEvent) {
                    continue;
                }

                // Remove SUMMARY
                $summary = $vevent->select('SUMMARY');
                foreach ($summary as $prop) {
                    if ($prop instanceof VObject\Property || $prop instanceof VObject\Component) {
                        $vevent->remove($prop);
                    }
                }

                // Remove DESCRIPTION
                $description = $vevent->select('DESCRIPTION');
                foreach ($description as $prop) {
                    if ($prop instanceof VObject\Property || $prop instanceof VObject\Component) {
                        $vevent->remove($prop);
                    }
                }

                // Replace existing LOCATION and replace with invitation link
                $location = $vevent->select('LOCATION');
                foreach ($location as $prop) {
                    if ($prop instanceof VObject\Property || $prop instanceof VObject\Component) {
                        $vevent->remove($prop);
                    }
                }
                $vevent->add('LOCATION', $invitationLink);

                // Filter attendees
                $this->filterAttendees($vevent, $organizerEmail, $recipientEmail);
            }

            return $vcal->serialize();
        } catch (\Throwable $e) {
            $this->logger->error('[CALENDAR EVENT INVITATION] Failed to modify ICS data: ' . $e->getMessage());
            return $ics;
        }
    }

    /**
     * Modify ICS data to replace the organizer:
     * @return string Modified ICS content
     */
    public function replaceOrganizer(string $ics, string $organizerName): string {
        try {
            $vcal = VObject\Reader::read($ics);
            if (!$vcal instanceof VObject\Component\VCalendar) {
                return $ics;
            }

            $vevents = $vcal->select('VEVENT');
            if ($vevents === []) {
                return $ics;
            }

            foreach ($vevents as $vevent) {
                if (!$vevent instanceof VObject\Component\VEvent) {
                    continue;
                }

                $organizer = $vevent->select('ORGANIZER');
                foreach ($organizer as $prop) {
                    if ($prop instanceof VObject\Property || $prop instanceof VObject\Component) {
                        $vevent->remove($prop);
                    }
                }

                $vevent->add('ORGANIZER', null, ['CN' => $organizerName]);
            }
            return $vcal->serialize();
        } catch (\Throwable $e) {
            $this->logger->error('[CALENDAR EVENT INVITATION] Failed to modify ICS data: ' . $e->getMessage());
            return $ics;
        }
    }

    /**
     * Extract organizer email from ICS
     */
    public function extractOrganizerEmail(string $ics): string {
        try {
            $vcal = VObject\Reader::read($ics);
            if (!$vcal instanceof VObject\Component\VCalendar) {
                return '';
            }

            $vevents = $vcal->select('VEVENT');
            if ($vevents === []) {
                return '';
            }

            $vevent = reset($vevents);
            if ($vevent === false || !$vevent instanceof VObject\Component\VEvent) {
                return '';
            }

            $organizer = $vevent->select('ORGANIZER');
            $firstOrganizer = reset($organizer);
            if ($firstOrganizer !== false && $firstOrganizer instanceof VObject\Property) {
                return $this->extractEmailFromValue($firstOrganizer->getValue());
            }
        } catch (\Throwable $e) {
            $this->logger->error('[INTENTS] Failed to extract organizer from ICS: ' . $e->getMessage());
        }

        return '';
    }

    /**
     * Extract all recipient emails from ICS attendees
     *
     * @return array<string>
     */
    public function extractRecipientEmails(string $ics): array {
        try {
            $vcal = VObject\Reader::read($ics);
            if (!$vcal instanceof VObject\Component\VCalendar) {
                return [];
            }

            $vevents = $vcal->select('VEVENT');
            if ($vevents === []) {
                return [];
            }

            $vevent = reset($vevents);
            if ($vevent === false || !$vevent instanceof VObject\Component\VEvent) {
                return [];
            }

            $emails = [];
            $attendeeComponents = $vevent->select('ATTENDEE');

            foreach ($attendeeComponents as $attendee) {
                if (!$attendee instanceof VObject\Property) {
                    continue;
                }

                $email = $this->extractEmailFromAttendee($attendee);
                if ($email !== '') {
                    $emails[] = $email;
                }
            }

            return $emails;
        } catch (\Throwable $e) {
            $this->logger->error('[INTENTS] Failed to extract recipients from ICS: ' . $e->getMessage());
            return [];
        }
    }

    private function getVEventPropertyValue(VObject\Component\VEvent $vevent, string $propertyName): string {
        $property = $vevent->select($propertyName);
        $firstProperty = reset($property);
        if ($firstProperty !== false && $firstProperty instanceof VObject\Property) {
            return $firstProperty->getValue();
        }
        return '';
    }

    private function extractConversationIdFromLocation(VObject\Component\VEvent $vevent): ?string {
        $locationProperty = $vevent->select('LOCATION');
        $firstLocation = reset($locationProperty);
        if ($firstLocation === false || !($firstLocation instanceof VObject\Property)) {
            return null;
        }

        $raw = $firstLocation->getValue();
        if ($raw === '') {
            return null;
        }

        $matches = [];
        if (preg_match('~/call/([^/?#]+)~', $raw, $matches) === 1) {
            return $matches[1];
        }
        return null;
    }

    /**
     * @return array<int, array{email: string, name: string, role: string, status: string}>
     */
    private function extractEventAttendees(VObject\Component\VEvent $vevent): array {
        $eventAttendees = [];
        $attendeeComponents = $vevent->select('ATTENDEE');

        foreach ($attendeeComponents as $attendee) {
            if (!$attendee instanceof VObject\Property) {
                continue;
            }

            $email = $this->extractEmailFromAttendee($attendee);
            if ($email === '') {
                continue;
            }

            $params = $attendee->parameters();
            /** @var array<string, VObject\Parameter> $typedParams */
            $typedParams = $params;

            $eventAttendees[] = [
                'email'  => $email,
                'name'   => $this->getParameterValue($typedParams, 'CN'),
                'role'   => $this->getParameterValue($typedParams, 'ROLE', 'REQ-PARTICIPANT'),
                'status' => $this->getParameterValue($typedParams, 'PARTSTAT', 'NEEDS-ACTION'),
            ];
        }

        return $eventAttendees;
    }

    /**
     * Filter attendees to keep only organizer and specific recipient
     */
    private function filterAttendees(VObject\Component\VEvent $vevent, string $organizerEmail, string $recipientEmail): void {
        $attendeeComponents = $vevent->select('ATTENDEE');
        if ($attendeeComponents === []) {
            return;
        }

        // Store attendees to keep
        $attendeesToKeep = [];

        foreach ($attendeeComponents as $attendee) {
            if (!$attendee instanceof VObject\Property) {
                continue;
            }

            $email = $this->extractEmailFromAttendee($attendee);
            if ($email === $organizerEmail || $email === $recipientEmail) {
                $attendeesToKeep[] = clone $attendee;
            }
        }

        // Remove all attendees
        $allAttendees = $vevent->select('ATTENDEE');
        foreach ($allAttendees as $attendee) {
            if ($attendee instanceof VObject\Property || $attendee instanceof VObject\Component) {
                $vevent->remove($attendee);
            }
        }

        // Re-add filtered attendees
        foreach ($attendeesToKeep as $attendee) {
            $vevent->add($attendee);
        }
    }

    /**
     * @param array<string, VObject\Parameter> $params
     */
    private function getParameterValue(array $params, string $paramName, string $default = ''): string {
        if (isset($params[$paramName])) {
            $value = $params[$paramName]->getValue();
            return $value !== null ? $value : $default;
        }
        return $default;
    }

    private function extractEmailFromAttendee(VObject\Property $attendee): string {
        return $this->extractEmailFromValue($attendee->getValue());
    }

    private function extractEmailFromValue(string $value): string {
        $result = preg_replace('/^mailto:/i', '', $value);
        return strtolower($result !== null ? $result : '');
    }
}
