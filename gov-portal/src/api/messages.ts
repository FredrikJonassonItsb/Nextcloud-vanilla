import { apiClient, ocsGet } from './client';
import type { MessageThread, SecureMessage, Conversation } from '../types';

// Fetch secure message threads (using Nextcloud Talk one-on-one conversations)
export const getSecureMessageThreads = async (limit: number = 10): Promise<MessageThread[]> => {
  try {
    // Get conversations from Talk API
    const conversations = await ocsGet<Conversation[]>('/ocs/v2.php/apps/spreed/api/v4/room', {
      includeStatus: true,
    });

    // Filter to one-on-one conversations (type 1) and map to MessageThread format
    const oneOnOneConversations = conversations
      .filter((conv) => conv.type === 1 || conv.type === ('one-on-one' as const))
      .slice(0, limit)
      .map((conv): MessageThread => ({
        id: conv.token,
        participants: [
          {
            id: conv.token,
            name: conv.displayName,
            avatar: conv.avatarUrl,
          },
        ],
        subject: conv.displayName,
        lastMessage: {
          id: conv.lastMessage?.id?.toString() || '',
          conversationId: conv.token,
          sender: {
            id: conv.lastMessage?.actorId || '',
            name: conv.lastMessage?.actorDisplayName || '',
          },
          subject: '',
          preview: conv.lastMessage?.message || '',
          timestamp: new Date((conv.lastMessage?.timestamp ?? 0) * 1000).toISOString(),
          isRead: conv.unreadMessages === 0,
          isEncrypted: true, // Assume all Talk messages are encrypted
          attachmentCount: 0,
        },
        unreadCount: conv.unreadMessages,
        isSecure: true,
      }));

    return oneOnOneConversations;
  } catch (error) {
    console.error('Failed to fetch secure messages:', error);
    throw error;
  }
};

// Fetch messages for a specific thread
export const getMessagesForThread = async (
  threadId: string,
  limit: number = 50
): Promise<SecureMessage[]> => {
  try {
    const response = await apiClient.get(
      `/ocs/v2.php/apps/spreed/api/v1/chat/${threadId}`,
      {
        params: {
          format: 'json',
          limit,
          lookIntoFuture: 0,
        },
      }
    );

    const messages = response.data.ocs.data;

    return messages.map((msg: {
      id: number;
      actorId: string;
      actorDisplayName: string;
      message: string;
      timestamp: number;
      messageParameters?: Record<string, { type?: string }>;
    }): SecureMessage => ({
      id: msg.id.toString(),
      conversationId: threadId,
      sender: {
        id: msg.actorId,
        name: msg.actorDisplayName,
      },
      subject: '',
      preview: msg.message,
      timestamp: new Date(msg.timestamp * 1000).toISOString(),
      isRead: true, // Messages fetched are considered read
      isEncrypted: true,
      attachmentCount: Object.values(msg.messageParameters || {}).filter(
        (p) => p.type === 'file'
      ).length,
    }));
  } catch (error) {
    console.error('Failed to fetch messages for thread:', error);
    throw error;
  }
};

// Send a secure message
export const sendSecureMessage = async (
  threadId: string,
  message: string
): Promise<SecureMessage> => {
  try {
    const response = await apiClient.post(
      `/ocs/v2.php/apps/spreed/api/v1/chat/${threadId}`,
      { message },
      {
        params: { format: 'json' },
      }
    );

    const msg = response.data.ocs.data;

    return {
      id: msg.id.toString(),
      conversationId: threadId,
      sender: {
        id: msg.actorId,
        name: msg.actorDisplayName,
      },
      subject: '',
      preview: msg.message,
      timestamp: new Date(msg.timestamp * 1000).toISOString(),
      isRead: true,
      isEncrypted: true,
      attachmentCount: 0,
    };
  } catch (error) {
    console.error('Failed to send message:', error);
    throw error;
  }
};

// Mark messages as read
export const markThreadAsRead = async (threadId: string): Promise<void> => {
  try {
    await apiClient.post(
      `/ocs/v2.php/apps/spreed/api/v1/chat/${threadId}/read`,
      {},
      { params: { format: 'json' } }
    );
  } catch (error) {
    console.error('Failed to mark thread as read:', error);
    throw error;
  }
};

// Get unread message count
export const getUnreadMessageCount = async (): Promise<number> => {
  try {
    const conversations = await ocsGet<Conversation[]>('/ocs/v2.php/apps/spreed/api/v4/room');

    return conversations.reduce((total, conv) => total + (conv.unreadMessages || 0), 0);
  } catch (error) {
    console.error('Failed to get unread count:', error);
    return 0;
  }
};

// Create new conversation with a user
export const createConversation = async (
  userId: string,
  _message?: string
): Promise<{ token: string }> => {
  try {
    const response = await apiClient.post(
      '/ocs/v2.php/apps/spreed/api/v4/room',
      {
        roomType: 1, // One-on-one
        invite: userId,
        source: 'users',
      },
      { params: { format: 'json' } }
    );

    return { token: response.data.ocs.data.token };
  } catch (error) {
    console.error('Failed to create conversation:', error);
    throw error;
  }
};
