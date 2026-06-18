/**
 * Barrel re-export for backward compatibility.
 *
 * Functions have been split into focused modules:
 * - messageTypeUtils.js  - type lookups, icons, address parsing
 * - phoneUtils.js        - phone formatting, extraction, validation
 * - participantUtils.js  - display name formatting
 * - pdfExportUtils.js    - HTML generation, filename for PDF export
 */

export { parseAddressInfoFromString, messageTypeToLabelKey, messageTypeToFolderName, messageTypeToIcon, hasInternalMailboxFunc } from './messageTypeUtils.js'
export { extractPhoneFromEmail, formatPhoneNumber, formatLocalPhoneNumber, getValidSSN, getValidSMSNumber } from './phoneUtils.js'
export { formatParticipantDisplayName, formatSdkFunctionName, formatSdkOrganizationName, resolveMessageDisplayName } from './participantUtils.js'
export { messageToHtml, generateHtmlForMessage, generateFilename } from './pdfExportUtils.js'
