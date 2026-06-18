import { useQuery } from '@tanstack/react-query';
import { Mail, Lock, ExternalLink, Plus, RefreshCw, AlertCircle } from 'lucide-react';
import { getSecureMessageThreads } from '../../api/messages';
import { formatDistanceToNow } from 'date-fns';
import { sv } from 'date-fns/locale';

export default function SecureMessagesWidget() {
  const {
    data: threads,
    isLoading,
    error,
    refetch,
  } = useQuery({
    queryKey: ['secureMessages'],
    queryFn: () => getSecureMessageThreads(5),
    refetchInterval: 30000, // Refetch every 30 seconds
  });

  const totalUnread = threads?.reduce((sum, t) => sum + t.unreadCount, 0) || 0;

  return (
    <section className="widget-card" aria-labelledby="secure-messages-title">
      {/* Header */}
      <div className="widget-header">
        <div className="w-10 h-10 rounded-lg bg-gov-blue-50 flex items-center justify-center">
          <Mail className="w-5 h-5 text-gov-blue-500" />
        </div>
        <div className="flex-1 min-w-0">
          <h2 id="secure-messages-title" className="widget-title">
            Säkra meddelanden
          </h2>
          <p className="text-xs text-gov-gray-500 flex items-center gap-1">
            <Lock className="w-3 h-3" />
            Krypterad kommunikation
          </p>
        </div>
        {totalUnread > 0 && (
          <span className="badge-unread" aria-label={`${totalUnread} olästa meddelanden`}>
            {totalUnread}
          </span>
        )}
        <button
          onClick={() => refetch()}
          className="p-2 rounded-md hover:bg-gov-gray-100 transition-colors"
          aria-label="Uppdatera meddelanden"
          title="Uppdatera"
        >
          <RefreshCw className={`w-4 h-4 text-gov-gray-400 ${isLoading ? 'animate-spin' : ''}`} />
        </button>
      </div>

      {/* Content */}
      <div className="widget-body">
        {isLoading && !threads ? (
          <LoadingState />
        ) : error ? (
          <ErrorState onRetry={() => refetch()} />
        ) : !threads || threads.length === 0 ? (
          <EmptyState />
        ) : (
          <ul className="space-y-1" role="list">
            {threads.map((thread) => (
              <li key={thread.id}>
                <a
                  href={`/apps/spreed/${thread.id}`}
                  className="list-item group"
                  aria-label={`Meddelande från ${thread.participants[0]?.name || 'Okänd'}, ${
                    thread.unreadCount > 0 ? `${thread.unreadCount} olästa` : 'inga olästa'
                  }`}
                >
                  {/* Avatar */}
                  <div className="relative flex-shrink-0">
                    <div className="w-10 h-10 rounded-full bg-gov-blue-100 flex items-center justify-center">
                      <span className="text-sm font-medium text-gov-blue-600">
                        {thread.participants[0]?.name?.charAt(0).toUpperCase() || '?'}
                      </span>
                    </div>
                    {thread.unreadCount > 0 && (
                      <span className="absolute -top-1 -right-1 w-3 h-3 bg-gov-blue-500 rounded-full border-2 border-white" />
                    )}
                  </div>

                  {/* Content */}
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center justify-between gap-2">
                      <span
                        className={`text-sm truncate ${
                          thread.unreadCount > 0
                            ? 'font-semibold text-gov-gray-800'
                            : 'text-gov-gray-700'
                        }`}
                      >
                        {thread.participants[0]?.name || 'Okänd avsändare'}
                      </span>
                      <span className="text-xs text-gov-gray-400 flex-shrink-0">
                        {formatDistanceToNow(new Date(thread.lastMessage.timestamp), {
                          addSuffix: true,
                          locale: sv,
                        })}
                      </span>
                    </div>
                    <p
                      className={`text-sm truncate ${
                        thread.unreadCount > 0 ? 'text-gov-gray-600' : 'text-gov-gray-500'
                      }`}
                    >
                      {thread.lastMessage.preview || 'Inget meddelande'}
                    </p>
                  </div>

                  {/* Arrow indicator */}
                  <ExternalLink className="w-4 h-4 text-gov-gray-300 opacity-0 group-hover:opacity-100 transition-opacity flex-shrink-0" />
                </a>
              </li>
            ))}
          </ul>
        )}
      </div>

      {/* Footer */}
      <div className="widget-footer flex items-center justify-between gap-3">
        <a href="/apps/spreed" className="btn btn-ghost text-sm">
          <ExternalLink className="w-4 h-4" />
          Visa alla
        </a>
        <a href="/apps/spreed/new" className="btn btn-primary text-sm">
          <Plus className="w-4 h-4" />
          Nytt meddelande
        </a>
      </div>
    </section>
  );
}

function LoadingState() {
  return (
    <div className="space-y-3">
      {[1, 2, 3].map((i) => (
        <div key={i} className="flex items-center gap-3 animate-pulse">
          <div className="w-10 h-10 rounded-full bg-gov-gray-200" />
          <div className="flex-1 space-y-2">
            <div className="h-4 bg-gov-gray-200 rounded w-1/3" />
            <div className="h-3 bg-gov-gray-100 rounded w-2/3" />
          </div>
        </div>
      ))}
    </div>
  );
}

function EmptyState() {
  return (
    <div className="empty-state py-6">
      <Mail className="empty-state-icon" />
      <p className="empty-state-text">Inga meddelanden</p>
      <p className="text-xs text-gov-gray-400 mt-1">
        Dina säkra meddelanden visas här
      </p>
    </div>
  );
}

function ErrorState({ onRetry }: { onRetry: () => void }) {
  return (
    <div className="error-state py-6">
      <AlertCircle className="error-state-icon" />
      <p className="error-state-text">Kunde inte ladda meddelanden</p>
      <button onClick={onRetry} className="btn btn-secondary mt-3 text-sm">
        Försök igen
      </button>
    </div>
  );
}
