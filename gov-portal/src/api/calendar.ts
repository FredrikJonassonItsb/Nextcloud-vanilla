import { apiClient } from './client';
import type { CalendarEvent, CreateMeetingRequest } from '../types';
import { format, addDays, parseISO } from 'date-fns';

// CalDAV base path
const getCalDavBasePath = (userId: string) =>
  `/remote.php/dav/calendars/${encodeURIComponent(userId)}`;

// Fetch user's calendars
export const getCalendars = async (userId: string): Promise<Array<{ id: string; displayName: string; color: string }>> => {
  try {
    const response = await apiClient.request({
      method: 'PROPFIND',
      url: `${getCalDavBasePath(userId)}/`,
      headers: {
        'Content-Type': 'application/xml',
        Depth: '1',
      },
      data: `<?xml version="1.0"?>
        <d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:apple="http://apple.com/ns/ical/">
          <d:prop>
            <d:displayname />
            <apple:calendar-color />
            <c:supported-calendar-component-set />
          </d:prop>
        </d:propfind>`,
    });

    const parser = new DOMParser();
    const doc = parser.parseFromString(response.data, 'application/xml');
    const responses = doc.getElementsByTagNameNS('DAV:', 'response');

    const calendars: Array<{ id: string; displayName: string; color: string }> = [];

    for (const resp of Array.from(responses)) {
      const href = resp.getElementsByTagNameNS('DAV:', 'href')[0]?.textContent || '';
      const displayName = resp.getElementsByTagNameNS('DAV:', 'displayname')[0]?.textContent || '';
      const color =
        resp.getElementsByTagNameNS('http://apple.com/ns/ical/', 'calendar-color')[0]?.textContent ||
        '#0078D4';

      // Skip the root calendar path
      if (href && !href.endsWith('/calendars/' + userId + '/')) {
        const id = href.split('/').filter(Boolean).pop() || '';
        if (id && displayName) {
          calendars.push({ id, displayName, color: color.substring(0, 7) });
        }
      }
    }

    return calendars;
  } catch (error) {
    console.error('Failed to fetch calendars:', error);
    throw error;
  }
};

// Fetch calendar events for a date range
export const getCalendarEvents = async (
  userId: string,
  startDate: Date = new Date(),
  endDate: Date = addDays(new Date(), 7)
): Promise<CalendarEvent[]> => {
  try {
    const calendars = await getCalendars(userId);
    const events: CalendarEvent[] = [];

    for (const calendar of calendars) {
      try {
        const calendarEvents = await getEventsFromCalendar(
          userId,
          calendar.id,
          startDate,
          endDate,
          calendar.color
        );
        events.push(...calendarEvents);
      } catch {
        // Continue with other calendars if one fails
        console.warn(`Failed to fetch events from calendar: ${calendar.id}`);
      }
    }

    // Sort by start date
    events.sort((a, b) => new Date(a.start).getTime() - new Date(b.start).getTime());

    return events;
  } catch (error) {
    console.error('Failed to fetch calendar events:', error);
    throw error;
  }
};

// Fetch events from a specific calendar
const getEventsFromCalendar = async (
  userId: string,
  calendarId: string,
  startDate: Date,
  endDate: Date,
  calendarColor: string
): Promise<CalendarEvent[]> => {
  const startStr = format(startDate, "yyyyMMdd'T'HHmmss'Z'");
  const endStr = format(endDate, "yyyyMMdd'T'HHmmss'Z'");

  const response = await apiClient.request({
    method: 'REPORT',
    url: `${getCalDavBasePath(userId)}/${calendarId}/`,
    headers: {
      'Content-Type': 'application/xml',
      Depth: '1',
    },
    data: `<?xml version="1.0"?>
      <c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
        <d:prop>
          <d:getetag />
          <c:calendar-data />
        </d:prop>
        <c:filter>
          <c:comp-filter name="VCALENDAR">
            <c:comp-filter name="VEVENT">
              <c:time-range start="${startStr}" end="${endStr}" />
            </c:comp-filter>
          </c:comp-filter>
        </c:filter>
      </c:calendar-query>`,
  });

  const parser = new DOMParser();
  const doc = parser.parseFromString(response.data, 'application/xml');
  const responses = doc.getElementsByTagNameNS('DAV:', 'response');

  const events: CalendarEvent[] = [];

  for (const resp of Array.from(responses)) {
    const calendarData = resp.getElementsByTagNameNS(
      'urn:ietf:params:xml:ns:caldav',
      'calendar-data'
    )[0]?.textContent;

    if (calendarData) {
      const event = parseICalEvent(calendarData, calendarId, calendarColor);
      if (event) {
        events.push(event);
      }
    }
  }

  return events;
};

// Parse iCal event data
const parseICalEvent = (
  icalData: string,
  calendarId: string,
  calendarColor: string
): CalendarEvent | null => {
  try {
    const lines = icalData.split(/\r?\n/);
    const event: Partial<CalendarEvent> = {
      calendarId,
      calendarColor,
      attendees: [],
    };

    let inEvent = false;

    for (let i = 0; i < lines.length; i++) {
      let line = lines[i];

      // Handle line folding
      while (i + 1 < lines.length && (lines[i + 1].startsWith(' ') || lines[i + 1].startsWith('\t'))) {
        line += lines[i + 1].substring(1);
        i++;
      }

      if (line.startsWith('BEGIN:VEVENT')) {
        inEvent = true;
        continue;
      }

      if (line.startsWith('END:VEVENT')) {
        break;
      }

      if (!inEvent) continue;

      if (line.startsWith('UID:')) {
        event.id = line.substring(4);
      } else if (line.startsWith('SUMMARY:')) {
        event.title = line.substring(8);
      } else if (line.startsWith('DESCRIPTION:')) {
        event.description = line.substring(12).replace(/\\n/g, '\n');
      } else if (line.startsWith('DTSTART')) {
        event.start = parseICalDate(line);
      } else if (line.startsWith('DTEND')) {
        event.end = parseICalDate(line);
      } else if (line.startsWith('LOCATION:')) {
        event.location = line.substring(9);
        // Check if location is a video meeting URL
        if (event.location.includes('http') || event.location.includes('meet.')) {
          event.isVideoMeeting = true;
          event.meetingUrl = event.location;
        }
      } else if (line.startsWith('ORGANIZER')) {
        const orgMatch = line.match(/CN=([^;:]+)/);
        const emailMatch = line.match(/mailto:([^;]+)/i);
        event.organizer = {
          id: '',
          name: orgMatch?.[1] || '',
          email: emailMatch?.[1] || '',
        };
      } else if (line.startsWith('ATTENDEE')) {
        const nameMatch = line.match(/CN=([^;:]+)/);
        const emailMatch = line.match(/mailto:([^;]+)/i);
        const statusMatch = line.match(/PARTSTAT=([^;:]+)/);

        if (emailMatch) {
          event.attendees?.push({
            id: '',
            name: nameMatch?.[1] || emailMatch[1],
            email: emailMatch[1],
            status: mapAttendeeStatus(statusMatch?.[1]),
          });
        }
      }
    }

    // Check for Talk meeting link in description
    if (event.description?.includes('/call/') || event.description?.includes('talk')) {
      event.isVideoMeeting = true;
      const urlMatch = event.description.match(/https?:\/\/[^\s]+\/call\/[^\s]+/);
      if (urlMatch) {
        event.meetingUrl = urlMatch[0];
      }
    }

    if (event.id && event.title && event.start && event.end) {
      return event as CalendarEvent;
    }

    return null;
  } catch (error) {
    console.error('Failed to parse iCal event:', error);
    return null;
  }
};

// Parse iCal date
const parseICalDate = (line: string): string => {
  const dateMatch = line.match(/(\d{8}T?\d{0,6}Z?)/);
  if (!dateMatch) return new Date().toISOString();

  const dateStr = dateMatch[1];

  if (dateStr.length === 8) {
    // Date only: YYYYMMDD
    return parseISO(
      `${dateStr.substring(0, 4)}-${dateStr.substring(4, 6)}-${dateStr.substring(6, 8)}`
    ).toISOString();
  } else if (dateStr.includes('T')) {
    // DateTime: YYYYMMDDTHHmmss or YYYYMMDDTHHmmssZ
    const formatted = `${dateStr.substring(0, 4)}-${dateStr.substring(4, 6)}-${dateStr.substring(
      6,
      8
    )}T${dateStr.substring(9, 11)}:${dateStr.substring(11, 13)}:${dateStr.substring(13, 15)}`;
    return dateStr.endsWith('Z') ? new Date(formatted + 'Z').toISOString() : new Date(formatted).toISOString();
  }

  return new Date().toISOString();
};

// Map iCal attendee status
const mapAttendeeStatus = (
  status?: string
): 'accepted' | 'declined' | 'tentative' | 'pending' => {
  switch (status?.toUpperCase()) {
    case 'ACCEPTED':
      return 'accepted';
    case 'DECLINED':
      return 'declined';
    case 'TENTATIVE':
      return 'tentative';
    default:
      return 'pending';
  }
};

// Create a new meeting
export const createMeeting = async (
  userId: string,
  calendarId: string,
  meeting: CreateMeetingRequest
): Promise<CalendarEvent> => {
  const uid = `meeting-${Date.now()}-${Math.random().toString(36).substring(2)}`;
  const now = new Date();

  const startDate = new Date(meeting.startDateTime);
  const endDate = new Date(meeting.endDateTime);

  // Create Talk room if video meeting
  let meetingUrl = '';
  if (meeting.isVideoMeeting) {
    try {
      const talkResponse = await apiClient.post(
        '/ocs/v2.php/apps/spreed/api/v4/room',
        {
          roomType: 3, // Public room
          roomName: meeting.title,
        },
        { params: { format: 'json' } }
      );
      const roomToken = talkResponse.data.ocs.data.token;
      meetingUrl = `${window.location.origin}/call/${roomToken}`;
    } catch (error) {
      console.warn('Failed to create Talk room:', error);
    }
  }

  // Build iCal event
  const icalEvent = buildICalEvent({
    uid,
    summary: meeting.title,
    description: meeting.description || '',
    start: startDate,
    end: endDate,
    location: meetingUrl,
    attendees: meeting.attendeeEmails,
    created: now,
  });

  // Upload event to calendar
  await apiClient.put(
    `${getCalDavBasePath(userId)}/${calendarId}/${uid}.ics`,
    icalEvent,
    {
      headers: {
        'Content-Type': 'text/calendar; charset=utf-8',
      },
    }
  );

  return {
    id: uid,
    title: meeting.title,
    description: meeting.description,
    start: startDate.toISOString(),
    end: endDate.toISOString(),
    location: meetingUrl || undefined,
    isVideoMeeting: meeting.isVideoMeeting,
    meetingUrl: meetingUrl || undefined,
    organizer: { id: userId, name: '', email: '' },
    attendees: meeting.attendeeEmails.map((email) => ({
      id: '',
      name: email,
      email,
      status: 'pending',
    })),
    calendarId,
  };
};

// Build iCal event string
const buildICalEvent = (params: {
  uid: string;
  summary: string;
  description: string;
  start: Date;
  end: Date;
  location?: string;
  attendees: string[];
  created: Date;
}): string => {
  const formatICalDate = (date: Date) =>
    date
      .toISOString()
      .replace(/[-:]/g, '')
      .replace(/\.\d{3}/, '');

  let ical = `BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Gov Portal//Nextcloud//EN
BEGIN:VEVENT
UID:${params.uid}
DTSTAMP:${formatICalDate(params.created)}
DTSTART:${formatICalDate(params.start)}
DTEND:${formatICalDate(params.end)}
SUMMARY:${params.summary}`;

  if (params.description) {
    ical += `\nDESCRIPTION:${params.description.replace(/\n/g, '\\n')}`;
  }

  if (params.location) {
    ical += `\nLOCATION:${params.location}`;
  }

  for (const email of params.attendees) {
    ical += `\nATTENDEE;PARTSTAT=NEEDS-ACTION;ROLE=REQ-PARTICIPANT:mailto:${email}`;
  }

  ical += `
END:VEVENT
END:VCALENDAR`;

  return ical;
};

// Get upcoming meetings (convenience function)
export const getUpcomingMeetings = async (
  userId: string,
  limit: number = 5
): Promise<CalendarEvent[]> => {
  const events = await getCalendarEvents(userId, new Date(), addDays(new Date(), 30));
  return events.slice(0, limit);
};
