/**
 * Participant display name utilities - formatting for SDK, internal, secure, fax/sms participants.
 */

import { MESSAGE_TYPES } from '../store/constants.js'
import { formatPhoneNumber } from './phoneUtils.js'
import { parseAddressInfoFromString } from './messageTypeUtils.js'

/**
 * Format sender/participant display name - used by Thread Participants and Forward headers
 *
 * @param {string} messageType MESSAGE_TYPES.*.id
 * @param {object} options Display name options
 * @param {string} options.email Email address
 * @param {string} options.label Display label/name
 * @param {string|null} options.internalMailboxName Pre-resolved from itslStore.getInternalMailboxName()
 * @param {object|null} options.sdkParty SDK sender/recipient party object (messageHeader.sender or .recipient)
 * @return {string} Display name
 */
export function formatParticipantDisplayName(messageType, { email = '', label = '', internalMailboxName = null, sdkParty = null }) {
	if (messageType === MESSAGE_TYPES.SDK.id) {
		// SDK: function name (specific department), fall back to label if no party data
		return formatSdkFunctionName(sdkParty)
			|| label
			|| email

	} else if (messageType === MESSAGE_TYPES.INTERNAL.id || messageType === MESSAGE_TYPES.SECURE.id) {
		// INTERNAL & SECURE: internal mailbox name (SECURE sender is always internal)
		return internalMailboxName || label || email

	} else if (messageType === MESSAGE_TYPES.FAX.id || messageType === MESSAGE_TYPES.SMS.id) {
		// FAX/SMS: formatted phone number (strips @fax/@sms suffix internally)
		return formatPhoneNumber(email)

	} else {
		// Default fallback
		return label || email
	}
}

/**
 * Format SDK function name from party object (the specific department/unit you communicate with)
 *
 * @param {object|null} sdkParty SDK sender/recipient party object
 * @return {string} Function name or empty string
 */
export function formatSdkFunctionName(sdkParty) {
	return sdkParty?.attention?.subOrganization?.label
		|| sdkParty?.attention?.subOrganization?.organizationId?.extension
		|| ''
}

/**
 * Format SDK organization name from party object (the top-level organization)
 *
 * @param {object|null} sdkParty SDK sender/recipient party object
 * @return {string} Function name or empty string
 */
export function formatSdkOrganizationName(sdkParty) {
	return sdkParty?.label
		|| sdkParty?.senderId?.extension
		|| sdkParty?.recipientId?.extension
		|| ''
}

/**
 * Resolve a message display name for a single party (sender or recipient).
 * Pure function — caller picks the right party based on direction.
 *
 * @param {string} messageType - MESSAGE_TYPES.*.id
 * @param {Object} options
 * @param {string} options.email - Email address
 * @param {string} options.label - Display label/name
 * @param {string|null} options.internalMailboxName - Pre-resolved via itslStore.getInternalMailboxName()
 * @param {Object|null} options.sdkParty - SDK sender/recipient party object
 * @returns {{ name: string, description: string, raw: string }}
 */
export function resolveMessageDisplayName(messageType, { email = '', label = '', internalMailboxName = null, sdkParty = null } = {}) {
	let name = ''
	let description = ''

	if (messageType === MESSAGE_TYPES.SECURE.id) {
		// SECURE: custom logic via parseAddressInfoFromString
		const info = parseAddressInfoFromString(messageType, email)
		name = info.ssn ? `${info.ssn} (${info.notification})` : (info.notification || label || email)
	} else if (messageType === MESSAGE_TYPES.SDK.id) {
		name = formatParticipantDisplayName(messageType, { email, label, internalMailboxName, sdkParty })
		description = formatSdkOrganizationName(sdkParty)
	} else {
		// INTERNAL, FAX, SMS, fallback
		name = formatParticipantDisplayName(messageType, { email, label, internalMailboxName, sdkParty })
	}

	const raw = description ? `${name} (${description})` : name
	return { name, description, raw }
}
