import { useQuery } from '@tanstack/react-query';
import { MessageSquare, Users, Phone, ExternalLink, Plus, RefreshCw, AlertCircle, Star } from 'lucide-react';
import { getConversations } from '../../api/chat';
import { formatDistanceToNow } from 'date-fns';
import { sv } from 'date-fns/locale';
import type { Conversation } from '../../types';

export default function ChatWidget() {
  const {
    data: conversations,
    isLoading,
    error,
    refetch,
  } = useQuery({
    queryKey: ['conversations'],
    queryFn: () => getConversations(8),
    refetchInterval: 15000, // Refetch every 15 seconds
  });

  const totalUnread = conversations?.reduce((sum, c) => sum + c.unreadMessages, 0) || 0;

  // Get conversation type icon
  const getConversationIcon = (conv: Conversation) => {
    if (conv.hasCall) {
      return <Phone className="w-4 h-4 text-green-500" />;
    }
    switch (conv.type) {
      case 'one-on-one':
      case 1:
        return null; // Show avatar instead
      case 'group':
      case 2:
        return <Users className="w-4 h-4 text-gov-blue-500" />;
      default:
        return <MessageSquare className="w-4 h-4 text-gov-gray-500" />;
    }
  };

  // Get display name for conversation
  const getDisplayName = (conv: Conversation): string => {
    return conv.displayName || conv.name || 'Okänd konversation';
  };

  return (
    <section className="widget-card" aria-labelledby="chat-title">
      {/* Header */}
      <div className="widget-header">
        <div className="w-10 h-10 rounded-lg bg-purple-50 flex items-center justify-center">
          <MessageSquare className="w-5 h-5 text-purple-600" />
        </div>
        <div className="flex-1 min-w-0">
          <h2 id="chat-title" className="widget-title">
            Intern chatt
          </h2>
          <p className="text-xs text-gov-gray-500">
            Konversationer med kollegor
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
          aria-label="Uppdatera chattar"
          title="Uppdatera"
        >
          <RefreshCw className={`w-4 h-4 text-gov-gray-400 ${isLoading ? 'animate-spin' : ''}`} />
        </button>
      </div>

      {/* Content */}
      <div className="widget-body max-h-[320px] overflow-y-auto custom-scrollbar">
        {isLoading && !conversations ? (
          <LoadingState />
        ) : error ? (
          <ErrorState onRetry={() => refetch()} />
        ) : !conversations || conversations.length === 0 ? (
          <EmptyState />
        ) : (
          <ul className="space-y-1" role="list">
            {conversations.map((conv) => (
              <li key={conv.token}>
                <a
                  href={`/apps/spreed/${conv.token}`}
                  className="list-item group"
                  aria-label={`${getDisplayName(conv)}, ${
                    conv.unreadMessages > 0
                      ? `${conv.unreadMessages} olästa meddelanden`
                      : 'inga olästa meddelanden'
                  }`}
                >
                  {/* Avatar / Icon */}
                  <div className="relative flex-shrink-0">
                    {conv.type === 'one-on-one' || conv.type === 1 ? (
                      <div className="w-10 h-10 rounded-full bg-purple-100 flex items-center justify-center">
                        <span className="text-sm font-medium text-purple-600">
                          {getDisplayName(conv).charAt(0).toUpperCase()}
                        </span>
                      </div>
                    ) : (
                      <div className="w-10 h-10 rounded-full bg-gov-gray-100 flex items-center justify-center">
                        {getConversationIcon(conv)}
                      </div>
                    )}

                    {/* Unread indicator */}
                    {conv.unreadMessages > 0 && (
                      <span className="absolute -top-1 -right-1 w-5 h-5 bg-purple-500 rounded-full flex items-center justify-center">
                        <span className="text-[10px] font-bold text-white">
                          {conv.unreadMessages > 9 ? '9+' : conv.unreadMessages}
                        </span>
                      </span>
                    )}

                    {/* Active call indicator */}
                    {conv.hasCall && (
                      <span className="absolute -bottom-1 -right-1 w-4 h-4 bg-green-500 rounded-full flex items-center justify-center animate-pulse">
                        <Phone className="w-2.5 h-2.5 text-white" />
                      </span>
                    )}
                  </div>

                  {/* Content */}
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center justify-between gap-2">
                      <div className="flex items-center gap-1.5 min-w-0">
                        <span
                          className={`text-sm truncate ${
                            conv.unreadMessages > 0
                              ? 'font-semibold text-gov-gray-800'
                              : 'text-gov-gray-700'
                          }`}
                        >
                          {getDisplayName(conv)}
                        </span>
                        {conv.isFavorite && (
                          <Star className="w-3 h-3 text-yellow-500 fill-current flex-shrink-0" />
                        )}
                      </div>
                      <span className="text-xs text-gov-gray-400 flex-shrink-0">
                        {conv.lastActivity
                          ? formatDistanceToNow(new Date(conv.lastActivity * 1000), {
                              addSuffix: false,
                              locale: sv,
                            })
                          : ''}
                      </span>
                    </div>

                    {/* Last message preview */}
                    {conv.lastMessage && (
                      <p
                        className={`text-sm truncate ${
                          conv.unreadMessages > 0 ? 'text-gov-gray-600' : 'text-gov-gray-500'
                        }`}
                      >
                        {conv.lastMessage.actorDisplayName && (
                          <span className="font-medium">
                            {conv.lastMessage.actorDisplayName.split(' ')[0]}:{' '}
                          </span>
                        )}
                        {conv.lastMessage.message || '...'}
                      </p>
                    )}

                    {/* Participant count for groups */}
                    {(conv.type === 'group' || conv.type === 2) && conv.participantCount > 0 && (
                      <span className="text-xs text-gov-gray-400">
                        {conv.participantCount} deltagare
                      </span>
                    )}
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
        <a href="/apps/spreed" className="btn btn-primary text-sm">
          <Plus className="w-4 h-4" />
          Ny chatt
        </a>
      </div>
    </section>
  );
}

function LoadingState() {
  return (
    <div className="space-y-3">
      {[1, 2, 3, 4].map((i) => (
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
      <MessageSquare className="empty-state-icon" />
      <p className="empty-state-text">Inga konversationer</p>
      <p className="text-xs text-gov-gray-400 mt-1">
        Starta en chatt med en kollega
      </p>
      <a href="/apps/spreed" className="btn btn-primary mt-4 text-sm">
        <Plus className="w-4 h-4" />
        Ny chatt
      </a>
    </div>
  );
}

function ErrorState({ onRetry }: { onRetry: () => void }) {
  return (
    <div className="error-state py-6">
      <AlertCircle className="error-state-icon" />
      <p className="error-state-text">Kunde inte ladda chattar</p>
      <button onClick={onRetry} className="btn btn-secondary mt-3 text-sm">
        Försök igen
      </button>
    </div>
  );
}
