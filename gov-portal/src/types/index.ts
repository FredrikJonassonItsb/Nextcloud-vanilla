// User types
export interface User {
  id: string;
  displayName: string;
  email: string;
  avatar?: string;
  groups: string[];
  language: string;
}

// Authentication types
export interface AuthState {
  isAuthenticated: boolean;
  isLoading: boolean;
  user: User | null;
  accessToken: string | null;
  refreshToken: string | null;
  tokenExpiry: number | null;
  error: string | null;
}

export interface OAuthConfig {
  clientId: string;
  redirectUri: string;
  authorizationEndpoint: string;
  tokenEndpoint: string;
  nextcloudUrl: string;
}

// Message types (for Säkra meddelanden)
export interface SecureMessage {
  id: string;
  conversationId: string;
  sender: {
    id: string;
    name: string;
    avatar?: string;
  };
  subject: string;
  preview: string;
  timestamp: string;
  isRead: boolean;
  isEncrypted: boolean;
  attachmentCount: number;
}

export interface MessageThread {
  id: string;
  participants: Array<{
    id: string;
    name: string;
    avatar?: string;
  }>;
  subject: string;
  lastMessage: SecureMessage;
  unreadCount: number;
  isSecure: boolean;
}

// Calendar/Meeting types (for Boka videomöte)
export interface CalendarEvent {
  id: string;
  title: string;
  description?: string;
  start: string;
  end: string;
  location?: string;
  isVideoMeeting: boolean;
  meetingUrl?: string;
  organizer: {
    id: string;
    name: string;
    email: string;
  };
  attendees: Array<{
    id: string;
    name: string;
    email: string;
    status: 'accepted' | 'declined' | 'tentative' | 'pending';
  }>;
  calendarId: string;
  calendarColor?: string;
}

export interface CreateMeetingRequest {
  title: string;
  description?: string;
  startDateTime: string;
  endDateTime: string;
  attendeeEmails: string[];
  isVideoMeeting: boolean;
}

// Chat types (for Intern chatt - Nextcloud Talk)
// Note: Nextcloud Talk API returns type as number (1=one-on-one, 2=group, 3=public, 4=changelog)
export type ConversationType = 'one-on-one' | 'group' | 'public' | 'changelog' | 1 | 2 | 3 | 4;

export interface Conversation {
  id: string;
  token: string;
  type: ConversationType;
  name: string;
  displayName: string;
  description?: string;
  participantCount: number;
  unreadMessages: number;
  unreadMention: boolean;
  lastMessage?: {
    id: string;
    message: string;
    actorId: string;
    actorDisplayName: string;
    timestamp: number;
  };
  lastActivity: number;
  isFavorite: boolean;
  hasCall: boolean;
  canStartCall: boolean;
  avatarUrl?: string;
}

export interface ChatMessage {
  id: string;
  token: string;
  actorType: 'users' | 'guests' | 'bots';
  actorId: string;
  actorDisplayName: string;
  timestamp: number;
  message: string;
  messageParameters: Record<string, unknown>;
  systemMessage: string;
  messageType: 'comment' | 'system' | 'command';
  isReplyable: boolean;
  parent?: ChatMessage;
}

// Document/File types (for Dokument)
export interface FileInfo {
  id: number;
  name: string;
  path: string;
  type: 'file' | 'directory';
  mimeType: string;
  size: number;
  modified: string;
  etag: string;
  permissions: string;
  favorite: boolean;
  shareTypes?: number[];
  ownerDisplayName?: string;
  isShared: boolean;
  isFederated: boolean;
}

export interface RecentFile extends FileInfo {
  activity: {
    type: 'created' | 'modified' | 'shared' | 'downloaded';
    timestamp: string;
    actor?: string;
  };
}

// Activity types
export interface Activity {
  id: number;
  type: string;
  subject: string;
  message: string;
  object_type: string;
  object_id: number;
  object_name: string;
  datetime: string;
  icon: string;
  link?: string;
}

// API Response types
export interface OCSResponse<T> {
  ocs: {
    meta: {
      status: string;
      statuscode: number;
      message: string;
    };
    data: T;
  };
}

export interface PaginatedResponse<T> {
  data: T[];
  total: number;
  offset: number;
  limit: number;
  hasMore: boolean;
}

// Widget state types
export interface WidgetState {
  isLoading: boolean;
  error: string | null;
  lastUpdated: string | null;
}

// Notification types
export interface Notification {
  notification_id: number;
  app: string;
  user: string;
  datetime: string;
  object_type: string;
  object_id: string;
  subject: string;
  message: string;
  link: string;
  actions: Array<{
    label: string;
    link: string;
    type: string;
    primary: boolean;
  }>;
}

// Settings types
export interface PortalSettings {
  theme: 'light' | 'dark' | 'system';
  language: 'sv' | 'en';
  widgetOrder: string[];
  hiddenWidgets: string[];
  refreshInterval: number; // in seconds
}

// Federation types
export interface FederatedUser {
  id: string;
  displayName: string;
  cloudId: string; // user@cloud.example.com
  server: string;
  isExternal: boolean;
}

// Error types
export interface ApiError {
  code: string;
  message: string;
  details?: Record<string, unknown>;
}
