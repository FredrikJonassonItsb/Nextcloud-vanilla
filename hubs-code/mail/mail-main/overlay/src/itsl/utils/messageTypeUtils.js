/**
 * Message type utilities - type lookups, icons, address parsing, mailbox helpers.
 */

import { MESSAGE_TYPES } from '../store/constants.js'
import { translate as t } from '@nextcloud/l10n'
import IconSDK from '../components/assets/SDKIcon.vue'
import IconForum from 'vue-material-design-icons/Forum.vue'
import IconMessageTextLock from 'vue-material-design-icons/MessageTextLock.vue'
import IconFax from 'vue-material-design-icons/Fax.vue'
import IconCellphoneMessage from 'vue-material-design-icons/CellphoneMessage.vue'

export function parseAddressInfoFromString(messageType, addressValue) {
	if (!addressValue) {
		return { email: '', notification: '', ssn: '', isSendingToPerson: false, faxAddress: '', smsAddress: '' }
	}

	const result = {}

	if (messageType === MESSAGE_TYPES.INTERNAL.id) {
		result.email = addressValue
	} else if (messageType === MESSAGE_TYPES.SECURE.id) {
		const orgSuffixToTrim = '.org.securemail'
		const securemailSuffix = '.securemail'
		if (addressValue.endsWith(securemailSuffix)) {
			if (addressValue.endsWith(orgSuffixToTrim)) {
				const addressValueTrimmed = addressValue.slice(0, -orgSuffixToTrim.length)
				result.notification = addressValueTrimmed
				result.isSendingToPerson = false
				result.ssn = ''
			} else {
				const addressValueTrimmed = addressValue.slice(0, -securemailSuffix.length)
				const lastDotIndex = addressValueTrimmed.lastIndexOf('.')
				result.notification = addressValueTrimmed.substring(0, lastDotIndex)
				result.ssn = addressValueTrimmed.substring(lastDotIndex + 1)
				result.isSendingToPerson = true
			}
		} else { // case when addressValue doesnt end with .securemail
			result.notification = addressValue ?? ''
			result.ssn = ''
			result.isSendingToPerson = false
		}

	} else if (messageType === MESSAGE_TYPES.FAX.id) {
		result.faxAddress = addressValue.split('@')[0]
	} else if (messageType === MESSAGE_TYPES.SMS.id) {
		result.smsAddress = addressValue.split('@')[0]
	}

	return result
}

export function messageTypeToLabelKey(messageType) {
	for (const key in MESSAGE_TYPES) {
		if (MESSAGE_TYPES[key].id === messageType) {
			return t('mail', MESSAGE_TYPES[key].labelKey)
		}
	}
	return t('mail', 'Unknown')
}

export function messageTypeToFolderName(messageType) {
	switch (messageType) {
	case MESSAGE_TYPES.SDK.id:
		return t('mail', 'Saved SDK messages')
	case MESSAGE_TYPES.INTERNAL.id:
		return t('mail', 'Saved internal messages')
	case MESSAGE_TYPES.SECURE.id:
		return t('mail', 'Saved secure messages')
	case MESSAGE_TYPES.SMS.id:
		return t('mail', 'Saved SMS messages')
	case MESSAGE_TYPES.FAX.id:
		return t('mail', 'Saved FAX messages')
	}
}

export function messageTypeToIcon(messageType) {
	switch (messageType) {
	case MESSAGE_TYPES.SDK.id:
		return IconSDK
	case MESSAGE_TYPES.INTERNAL.id:
		return IconForum
	case MESSAGE_TYPES.SECURE.id:
		return IconMessageTextLock
	case MESSAGE_TYPES.SMS.id:
		return IconCellphoneMessage
	case MESSAGE_TYPES.FAX.id:
		return IconFax
	}
}

export function hasInternalMailboxFunc(aliases) {
	return aliases.some(a =>
		a.emailAddress.endsWith('@gruppbox')
		|| a.emailAddress.endsWith('@personlig'),
	)
}
