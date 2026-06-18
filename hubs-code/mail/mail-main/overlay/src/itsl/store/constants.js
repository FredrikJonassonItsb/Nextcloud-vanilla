export const SDK_ORGANIZATION_ROOT = 'urn:riv:infrastructure:messaging:functionalAddress'
export const SDK_ACTORID_UPIS_ROOT = 'iso6523-actorid-upis'

export const MESSAGE_TYPES = {
	SDK: {
		id: 'sdk_message',
		labelKey: 'SDK Message',
	},
	INTERNAL: {
		id: 'internal_message',
		labelKey: 'Internal Message',
	},
	SECURE: {
		id: 'secure_email',
		labelKey: 'Secure E-mail',
	},
	FAX: {
		id: 'fax_message',
		labelKey: 'Digital Fax',
	},
	SMS: {
		id: 'sms_message',
		labelKey: 'SMS Message',
	},
}

export const SDKMC_API_ROUTES = {
	GET_ADDRESS_MAPPING: '/apps/sdkmc/api/v2/frontend/sdk/existingAddresses',
	GET_INFO: '/apps/sdkmc/api/v2/frontend/getSettings',
	GET_ADDRESS_BOOK_ORGANIZATIONS: '/apps/sdkmc/api/v2/frontend/sdk/addressbook/api/organizations',
	GET_ADDRESS_BOOK_FUNCTION_ADDRESSES: '/apps/sdkmc/api/v2/frontend/sdk/addressbook/api/addresses',
	GET_INTERNAL_EMAILS: '/apps/sdkmc/api/v2/securemail/internalMailboxesAB',
	// ITSL Tag API routes
	CREATE_TAG: (accountId) => `/apps/sdkmc/api/tags/${accountId}`,
	UPDATE_TAG: (accountId, tagId) => `/apps/sdkmc/api/tags/${accountId}/${tagId}`,
	DELETE_TAG: (accountId, tagId) => `/apps/sdkmc/api/tags/${accountId}/delete/${tagId}`,
	SET_MESSAGE_TAG: (id, imapLabel) => `/apps/sdkmc/api/messages/${id}/tags/${encodeURIComponent(imapLabel)}`,
	REMOVE_MESSAGE_TAG: (id, imapLabel) => `/apps/sdkmc/api/messages/${id}/tags/${encodeURIComponent(imapLabel)}`,
	// Bulk thread operations
	SET_THREAD_TAG: (imapLabel) => `/apps/sdkmc/api/thread/tags/${encodeURIComponent(imapLabel)}`,
	REMOVE_THREAD_TAG: (imapLabel) => `/apps/sdkmc/api/thread/tags/${encodeURIComponent(imapLabel)}`,
	SET_THREAD_FLAGS: '/apps/sdkmc/api/thread/flags',
}

export const MESSAGE_DIRECTION = {
	INCOMING: 'incoming',
	OUTGOING: 'outgoing',
}

// Virtual mailbox IDs for ITSL virtual mailboxes
export const MY_MESSAGES_MAILBOX_ID = 'my-messages'
export const UNASSIGNED_MAILBOX_ID = 'unassigned'
