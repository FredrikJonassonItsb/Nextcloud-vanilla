<?php

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Service;

use OCA\DAV\CalDAV\CalDavBackend;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class CalendarEventProcessorService {
    public function __construct(
        private CalDavBackend $caldav,
        private LoggerInterface $logger,
        private IRequest $request,
    ) {
    }

    /**
     * Extract ICS data from calendar event
     * Returns null if extraction fails
     */
    public function extractIcsData(object $event): ?string {
        if (!method_exists($event, 'getCalendarData')) {
            $this->logger->error('[CALENDAR] Event object does not have getCalendarData method', [
                'eventClass' => get_class($event)
            ]);
            return null;
        }

        $calendarData = $event->getCalendarData();

        if (!is_array($calendarData) || !isset($calendarData['id'])) {
            $this->logger->warning('[CALENDAR] Invalid calendar data structure', [
                'calendarData' => $calendarData
            ]);
            return null;
        }

        $calendarId = is_int($calendarData['id']) ? $calendarData['id'] : null;

        $pathInfo = (string)$this->request->getPathInfo();
        $pathParts = $this->parseCalendarPath($pathInfo);

        if ($pathParts === null) {
            $this->logger->warning('[CALENDAR] Could not parse calendar path from URL', [
                'path' => $pathInfo
            ]);
            return null;
        }

        if ($calendarId === null) {
            $this->logger->warning('[CALENDAR] Calendar ID not found', [
                'user' => $pathParts['user'],
                'calendarUri' => $pathParts['calendarUri'],
                'eventCalendarData' => $calendarData,
            ]);
            return null;
        }

        $calendarObject = $this->caldav->getCalendarObject($calendarId, $pathParts['objectUri']);

        if ($calendarObject === null || !isset($calendarObject['calendardata']) || $calendarObject['calendardata'] === '') {
            $this->logger->warning('[CALENDAR] Calendar object not found or empty', [
                'calendarId' => $calendarId,
                'objectUri' => $pathParts['objectUri'],
            ]);
            return null;
        }

        $ics = is_string($calendarObject['calendardata']) ? $calendarObject['calendardata'] : '';

        if ($ics === '') {
            $this->logger->warning('[CALENDAR] Calendar data is not a valid string', [
                'calendarId' => $calendarId,
                'objectUri' => $pathParts['objectUri'],
                'calendarDataType' => gettype($calendarObject['calendardata'])
            ]);
            return null;
        }

        return $ics;
    }

    /**
     * @return array{user: string, calendarUri: string, objectUri: string}|null
     */
    public function parseCalendarPath(string $path): ?array {
        $matches = [];
        if (preg_match('#^/dav/calendars/([^/]+)/([^/]+)/(.+\.ics)$#', $path, $matches) === 1) {
            return [
                'user' => $matches[1],
                'calendarUri' => $matches[2],
                'objectUri' => $matches[3],
            ];
        }
        return null;
    }
}
