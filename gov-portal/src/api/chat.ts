import { apiClient, ocsGet } from './client';
import type { Conversation, ChatMessage } from '../types';

// Fetch all conversations (for Intern chatt widget)
export const getConversations = async (limit: number = 10): Promise<Conversation[]> => {
  try {
    const conversations = await ocsGet<Conversation[]>('/ocs/v2.php/apps/spreed/api/v4/room', {
      includeStatus: true,
    });

    // Sort by last activity and return top conversations
    return conversations
      .sort((a, b) => (b.lastActivity || 0) - (a.lastActivity || 0))
      .slice(0, limit)
      .map((conv) => ({
        ...conv,
        avatarUrl: getConversationAvatarUrl(conv),
      }));
  } catch (error) {
    console.error('Failed to fetch conversations:', error);
    throw error;
  }
};

// Get avatar URL for a conversation
const getConversationAvatarUrl = (conv: Conversation): string => {
  const baseUrl = window.location.origin;

  switch (conv.type) {
    case 'one-on-one':
    case 1:
      // For one-on-one, use the other participant's avatar
      return `${baseUrl}/ocs/v2.php/apps/spreed/api/v1/room/${conv.token}/avatar`;
    case 'group':
    case 2:
      return `${baseUrl}/ocs/v2.php/apps/spreed/api/v1/room/${conv.token}/avatar`;
    default:
      return `${baseUrl}/ocs/v2.php/apps/spreed/api/v1/room/${conv.token}/avatar`;
  }
};

// Fetch messages for a conversation
export const getChatMessages = async (
  conversationToken: string,
  limit: number = 50,
  lastKnownMessageId?: number
): Promise<ChatMessage[]> => {
  try {
    const params: Record<string, string | number> = {
      limit,
      lookIntoFuture: 0,
      setReadMarker: 1,
    };

    if (lastKnownMessageId) {
      params.lastKnownMessageId = lastKnownMessageId;
    }

    const response = await apiClient.get(
      `/ocs/v2.php/apps/spreed/api/v1/chat/${conversationToken}`,
      {
        params: { ...params, format: 'json' },
      }
    );

    return response.data.ocs.data;
  } catch (error) {
    console.error('Failed to fetch chat messages:', error);
    throw error;
  }
};

// Send a chat message
export const sendChatMessage = async (
  conversationToken: string,
  message: string,
  replyTo?: number
): Promise<ChatMessage> => {
  try {
    const response = await apiClient.post(
      `/ocs/v2.php/apps/spreed/api/v1/chat/${conversationToken}`,
      {
        message,
        replyTo: replyTo || 0,
      },
      {
        params: { format: 'json' },
      }
    );

    return response.data.ocs.data;
  } catch (error) {
    console.error('Failed to send chat message:', error);
    throw error;
  }
};

// Mark conversation as read
export const markConversationAsRead = async (conversationToken: string): Promise<void> => {
  try {
    await apiClient.post(
      `/ocs/v2.php/apps/spreed/api/v1/chat/${conversationToken}/read`,
      {},
      { params: { format: 'json' } }
    );
  } catch (error) {
    console.error('Failed to mark conversation as read:', error);
    throw error;
  }
};

// Get total unread count across all conversations
export const getTotalUnreadCount = async (): Promise<number> => {
  try {
    const conversations = await getConversations(100);
    return conversations.reduce((total, conv) => total + (conv.unreadMessages || 0), 0);
  } catch {
    return 0;
  }
};

// Get conversation details
export const getConversation = async (conversationToken: string): Promise<Conversation> => {
  try {
    const response = await ocsGet<Conversation>(
      `/ocs/v2.php/apps/spreed/api/v4/room/${conversationToken}`
    );
    return {
      ...response,
      avatarUrl: getConversationAvatarUrl(response),
    };
  } catch (error) {
    console.error('Failed to fetch conversation:', error);
    throw error;
  }
};

// Create a new group conversation
export const createGroupConversation = async (
  roomName: string,
  participants: string[]
): Promise<Conversation> => {
  try {
    // Create the room
    const response = await apiClient.post(
      '/ocs/v2.php/apps/spreed/api/v4/room',
      {
        roomType: 2, // Group
        roomName,
      },
      { params: { format: 'json' } }
    );

    const conversation = response.data.ocs.data;

    // Add participants
    for (const userId of participants) {
      await apiClient.post(
        `/ocs/v2.php/apps/spreed/api/v4/room/${conversation.token}/participants`,
        {
          newParticipant: userId,
          source: 'users',
        },
        { params: { format: 'json' } }
      );
    }

    return conversation;
  } catch (error) {
    console.error('Failed to create group conversation:', error);
    throw error;
  }
};

// Start a call in a conversation
export const startCall = async (
  conversationToken: string,
  flags: number = 3 // 1 = in call, 2 = audio, 4 = video
): Promise<void> => {
  try {
    await apiClient.post(
      `/ocs/v2.php/apps/spreed/api/v4/call/${conversationToken}`,
      { flags },
      { params: { format: 'json' } }
    );
  } catch (error) {
    console.error('Failed to start call:', error);
    throw error;
  }
};

// Leave a call
export const leaveCall = async (conversationToken: string): Promise<void> => {
  try {
    await apiClient.delete(`/ocs/v2.php/apps/spreed/api/v4/call/${conversationToken}`, {
      params: { format: 'json' },
    });
  } catch (error) {
    console.error('Failed to leave call:', error);
    throw error;
  }
};

// Toggle favorite status
export const toggleFavorite = async (
  conversationToken: string,
  isFavorite: boolean
): Promise<void> => {
  try {
    if (isFavorite) {
      await apiClient.delete(
        `/ocs/v2.php/apps/spreed/api/v4/room/${conversationToken}/favorite`,
        { params: { format: 'json' } }
      );
    } else {
      await apiClient.post(
        `/ocs/v2.php/apps/spreed/api/v4/room/${conversationToken}/favorite`,
        {},
        { params: { format: 'json' } }
      );
    }
  } catch (error) {
    console.error('Failed to toggle favorite:', error);
    throw error;
  }
};

// Search for users to add to conversation
export const searchUsers = async (
  query: string,
  limit: number = 10
): Promise<Array<{ id: string; displayName: string; avatar?: string }>> => {
  try {
    const response = await apiClient.get('/ocs/v2.php/core/autocomplete/get', {
      params: {
        format: 'json',
        search: query,
        itemType: 'call',
        itemId: 'new',
        sorter: 'status',
        limit,
      },
    });

    return response.data.ocs.data.map((user: { id: string; label: string }) => ({
      id: user.id,
      displayName: user.label,
      avatar: `${window.location.origin}/avatar/${user.id}/64`,
    }));
  } catch (error) {
    console.error('Failed to search users:', error);
    throw error;
  }
};
