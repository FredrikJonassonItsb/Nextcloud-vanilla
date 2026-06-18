/**
 * API Module Exports
 *
 * Central export point for all API functions
 */

// Client
export { apiClient, ocsGet, ocsPost, ocsDelete, webdavClient } from './client';

// Messages
export {
  getSecureMessageThreads,
  getMessagesForThread,
  sendSecureMessage,
  markThreadAsRead,
  getUnreadMessageCount,
  createConversation,
} from './messages';

// Calendar
export {
  getCalendars,
  getCalendarEvents,
  createMeeting,
  getUpcomingMeetings,
} from './calendar';

// Chat
export {
  getConversations,
  getChatMessages,
  sendChatMessage,
  markConversationAsRead,
  getTotalUnreadCount,
  getConversation,
  createGroupConversation,
  startCall,
  leaveCall,
  toggleFavorite,
  searchUsers,
} from './chat';

// Documents
export {
  getRecentFiles,
  getFileInfo,
  getFileDownloadUrl,
  getFilePreviewUrl,
  openFileInNextcloud,
  toggleFileFavorite,
  searchFiles,
} from './documents';
