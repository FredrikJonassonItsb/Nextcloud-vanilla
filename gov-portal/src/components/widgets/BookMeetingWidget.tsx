import { useQuery } from '@tanstack/react-query';
import { Calendar, Video, Clock, Users, Plus, RefreshCw, AlertCircle, MapPin } from 'lucide-react';
import { getUpcomingMeetings } from '../../api/calendar';
import { useAuthStore } from '../../stores/authStore';
import { format, isToday, isTomorrow, parseISO } from 'date-fns';
import { sv } from 'date-fns/locale';

export default function BookMeetingWidget() {
  const { user } = useAuthStore();

  const {
    data: meetings,
    isLoading,
    error,
    refetch,
  } = useQuery({
    queryKey: ['upcomingMeetings', user?.id],
    queryFn: () => getUpcomingMeetings(user?.id || '', 5),
    enabled: !!user?.id,
    refetchInterval: 60000, // Refetch every minute
  });

  // Format date header
  const formatDateHeader = (dateStr: string): string => {
    const date = parseISO(dateStr);
    if (isToday(date)) return 'Idag';
    if (isTomorrow(date)) return 'Imorgon';
    return format(date, 'EEEE d MMMM', { locale: sv });
  };

  // Format time
  const formatTime = (dateStr: string): string => {
    return format(parseISO(dateStr), 'HH:mm', { locale: sv });
  };

  // Group meetings by date
  const groupedMeetings = meetings?.reduce<Record<string, typeof meetings>>((groups, meeting) => {
    const dateKey = format(parseISO(meeting.start), 'yyyy-MM-dd');
    if (!groups[dateKey]) {
      groups[dateKey] = [];
    }
    groups[dateKey].push(meeting);
    return groups;
  }, {});

  return (
    <section className="widget-card" aria-labelledby="meetings-title">
      {/* Header */}
      <div className="widget-header">
        <div className="w-10 h-10 rounded-lg bg-green-50 flex items-center justify-center">
          <Video className="w-5 h-5 text-green-600" />
        </div>
        <div className="flex-1 min-w-0">
          <h2 id="meetings-title" className="widget-title">
            Boka videomöte
          </h2>
          <p className="text-xs text-gov-gray-500">
            Kommande möten och bokning
          </p>
        </div>
        <button
          onClick={() => refetch()}
          className="p-2 rounded-md hover:bg-gov-gray-100 transition-colors"
          aria-label="Uppdatera möten"
          title="Uppdatera"
        >
          <RefreshCw className={`w-4 h-4 text-gov-gray-400 ${isLoading ? 'animate-spin' : ''}`} />
        </button>
      </div>

      {/* Content */}
      <div className="widget-body">
        {isLoading && !meetings ? (
          <LoadingState />
        ) : error ? (
          <ErrorState onRetry={() => refetch()} />
        ) : !meetings || meetings.length === 0 ? (
          <EmptyState />
        ) : (
          <div className="space-y-4">
            {Object.entries(groupedMeetings || {}).map(([dateKey, dateMeetings]) => (
              <div key={dateKey}>
                {/* Date header */}
                <h3 className="text-xs font-medium text-gov-gray-500 uppercase tracking-wide mb-2">
                  {formatDateHeader(dateMeetings[0].start)}
                </h3>

                {/* Meetings for this date */}
                <ul className="space-y-2" role="list">
                  {dateMeetings.map((meeting) => (
                    <li key={meeting.id}>
                      <a
                        href={meeting.meetingUrl || `/apps/calendar/r/event/${meeting.id}`}
                        className="block p-3 rounded-lg border border-gov-gray-200 hover:border-gov-blue-300 hover:bg-gov-blue-50/50 transition-colors group"
                        style={{
                          borderLeftWidth: '3px',
                          borderLeftColor: meeting.calendarColor || '#0078D4',
                        }}
                      >
                        <div className="flex items-start justify-between gap-2">
                          <div className="flex-1 min-w-0">
                            <h4 className="text-sm font-medium text-gov-gray-800 truncate">
                              {meeting.title}
                            </h4>
                            <div className="flex items-center gap-3 mt-1 text-xs text-gov-gray-500">
                              <span className="flex items-center gap-1">
                                <Clock className="w-3 h-3" />
                                {formatTime(meeting.start)} - {formatTime(meeting.end)}
                              </span>
                              {meeting.attendees.length > 0 && (
                                <span className="flex items-center gap-1">
                                  <Users className="w-3 h-3" />
                                  {meeting.attendees.length}
                                </span>
                              )}
                            </div>
                            {meeting.location && !meeting.isVideoMeeting && (
                              <div className="flex items-center gap-1 mt-1 text-xs text-gov-gray-500">
                                <MapPin className="w-3 h-3" />
                                <span className="truncate">{meeting.location}</span>
                              </div>
                            )}
                          </div>

                          {/* Video meeting indicator */}
                          {meeting.isVideoMeeting && (
                            <div className="flex-shrink-0">
                              <span className="inline-flex items-center gap-1 px-2 py-1 bg-green-100 text-green-700 text-xs font-medium rounded-full">
                                <Video className="w-3 h-3" />
                                Video
                              </span>
                            </div>
                          )}
                        </div>

                        {/* Join button for active meetings */}
                        {meeting.isVideoMeeting && meeting.meetingUrl && (
                          <div className="mt-2 pt-2 border-t border-gov-gray-100">
                            <span className="text-xs text-gov-blue-500 font-medium group-hover:underline">
                              Anslut till mötet →
                            </span>
                          </div>
                        )}
                      </a>
                    </li>
                  ))}
                </ul>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Footer */}
      <div className="widget-footer flex items-center justify-between gap-3">
        <a href="/apps/calendar" className="btn btn-ghost text-sm">
          <Calendar className="w-4 h-4" />
          Öppna kalender
        </a>
        <a href="/apps/calendar/new" className="btn btn-primary text-sm">
          <Plus className="w-4 h-4" />
          Boka möte
        </a>
      </div>
    </section>
  );
}

function LoadingState() {
  return (
    <div className="space-y-4">
      {[1, 2].map((i) => (
        <div key={i} className="space-y-2">
          <div className="h-3 bg-gov-gray-200 rounded w-20 animate-pulse" />
          <div className="p-3 border border-gov-gray-200 rounded-lg animate-pulse">
            <div className="h-4 bg-gov-gray-200 rounded w-2/3 mb-2" />
            <div className="h-3 bg-gov-gray-100 rounded w-1/3" />
          </div>
        </div>
      ))}
    </div>
  );
}

function EmptyState() {
  return (
    <div className="empty-state py-6">
      <Calendar className="empty-state-icon" />
      <p className="empty-state-text">Inga kommande möten</p>
      <p className="text-xs text-gov-gray-400 mt-1">
        Boka ett nytt videomöte
      </p>
      <a href="/apps/calendar/new" className="btn btn-primary mt-4 text-sm">
        <Plus className="w-4 h-4" />
        Boka möte
      </a>
    </div>
  );
}

function ErrorState({ onRetry }: { onRetry: () => void }) {
  return (
    <div className="error-state py-6">
      <AlertCircle className="error-state-icon" />
      <p className="error-state-text">Kunde inte ladda möten</p>
      <button onClick={onRetry} className="btn btn-secondary mt-3 text-sm">
        Försök igen
      </button>
    </div>
  );
}
