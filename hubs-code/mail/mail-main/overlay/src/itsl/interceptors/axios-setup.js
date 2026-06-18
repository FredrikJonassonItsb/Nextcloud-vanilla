import axios from '@nextcloud/axios'
import useItslStore from '../store/itslStore.js'
import useMainStore from '../../store/mainStore.js'
import { MESSAGE_TYPES, SDKMC_API_ROUTES } from '../store/constants.js'
import { translateTagQuery } from '../utils/tagQueryTranslator.js'

let itslStore = null

// Normalize tag IDs in envelope data to use canonical IDs from itslStore (per-account)
function normalizeEnvelopeTags(envelope, mainStore) {
	if (!envelope?.tags || Array.isArray(envelope.tags)) return

	// Get accountId from envelope or derive from mailboxId (backend doesn't include accountId in list responses)
	const mailbox = envelope.mailboxId ? mainStore.getMailbox(envelope.mailboxId) : null
	const accountId = envelope.accountId || mailbox?.accountId

	if (!accountId) {
		// Mailbox not loaded yet - skip silently, will normalize on next fetch
		return
	}

	// Get tags for this envelope's account only
	const itslStore = useItslStore()
	const accountTags = itslStore.tagsByAccount[accountId] || []

	for (const tag of Object.values(envelope.tags)) {
		// Find existing tag with same imapLabel in THIS account only
		const existingTag = accountTags.find(t => t.imapLabel === tag.imapLabel)
		if (existingTag && existingTag.id !== tag.id) {
			tag.id = existingTag.id
		}
	}
}

function normalizeResponseEnvelopes(data, mainStore) {
	if (!data) return
	// Single envelope
	if (data.databaseId) {
		normalizeEnvelopeTags(data, mainStore)
	}
	// Array of envelopes
	if (Array.isArray(data)) {
		data.forEach(env => normalizeEnvelopeTags(env, mainStore))
	}
	// Sync response: { newMessages, changedMessages, ... }
	if (data.newMessages) {
		data.newMessages.forEach(env => normalizeEnvelopeTags(env, mainStore))
	}
	if (data.changedMessages) {
		data.changedMessages.forEach(env => normalizeEnvelopeTags(env, mainStore))
	}
}

export function initInterceptors() {
	itslStore = useItslStore() // late call for init of itslStore after Vue and pinia is setup

	// Response Interceptor - normalize tag IDs before data reaches mainStore
	axios.interceptors.response.use(
		(response) => {
			const url = response.config.url
			if (url.includes('/api/messages') || url.includes('/api/mailboxes')) {
				const mainStore = useMainStore()
				normalizeResponseEnvelopes(response.data, mainStore)
			}
			return response
		},
		(error) => Promise.reject(error),
	)

	// Request Interceptor
	axios.interceptors.request.use(
		(config) => {
			if ((config.method === 'post' && config.url.endsWith('/api/drafts'))
				|| (config.method === 'post' && config.url.includes('/api/outbox/from-draft'))
				|| (config.method === 'put' && config.url.includes('/api/drafts'))) {

				let recipient = ''

				if (config.data.itsl.messageType === MESSAGE_TYPES.SDK.id) {
					recipient = generateValidSDKAddress(config.data.itsl.organizationAddress, config.data.itsl.functionAddress)
					config.data.itsl.sdk = generateSDKItslAttachment(config.data.itsl)
				} else if (config.data.itsl.messageType === MESSAGE_TYPES.INTERNAL.id) {
					recipient = config.data.itsl.email
				} else if (config.data.itsl.messageType === MESSAGE_TYPES.SECURE.id) {
					recipient = config.data.itsl.ssn ? `${config.data.itsl.notification}.${config.data.itsl.ssn}.securemail` : `${config.data.itsl.notification}.org.securemail`
				} else if (config.data.itsl.messageType === MESSAGE_TYPES.FAX.id) {
					recipient = `${config.data.itsl.faxAddress}@fax`
				} else if (config.data.itsl.messageType === MESSAGE_TYPES.SMS.id) {
					recipient = `${config.data.itsl.smsAddress}@sms`
				}

				config.data.to.splice(0, config.data.to.length) // clear existing
				config.data.to.push({
					email: recipient,
					label: recipient,
				})
				// itsl object cleanup
				cleanupItslDataObject(config)

			}

			// ITSL: Intercept tag API calls and redirect to sdkmc
			// DELETE /apps/mail/api/tags/{accountId}/delete/{tagId} → DELETE /apps/sdkmc/api/tags/{accountId}/delete/{tagId}
			const deleteTagMatch = config.method === 'delete' && config.url.match(/\/apps\/mail\/api\/tags\/(\d+)\/delete\/(\d+)$/)
			if (deleteTagMatch) {
				const accountId = deleteTagMatch[1]
				const tagId = deleteTagMatch[2]
				config.url = SDKMC_API_ROUTES.DELETE_TAG(accountId, tagId)
			}

			// PUT /apps/mail/api/messages/{id}/tags/{imapLabel} → PUT /apps/sdkmc/api/messages/{id}/tags/{imapLabel}
			// Enrich with accountId/messageId from mainStore
			const setMessageTagMatch = config.method === 'put' && config.url.match(/\/apps\/mail\/api\/messages\/(\d+)\/tags\/([^/]+)$/)
			if (setMessageTagMatch) {
				const databaseId = parseInt(setMessageTagMatch[1])
				const imapLabel = decodeURIComponent(setMessageTagMatch[2])
				const mainStore = useMainStore()
				const envelope = mainStore.envelopes[databaseId]
				config.url = SDKMC_API_ROUTES.SET_MESSAGE_TAG(databaseId, imapLabel)
				config.data = { accountId: envelope?.accountId, messageId: envelope?.messageId }
			}

			// DELETE /apps/mail/api/messages/{id}/tags/{imapLabel} → DELETE /apps/sdkmc/api/messages/{id}/tags/{imapLabel}
			// Enrich with accountId/messageId from mainStore
			const removeMessageTagMatch = config.method === 'delete' && config.url.match(/\/apps\/mail\/api\/messages\/(\d+)\/tags\/([^/]+)$/)
			if (removeMessageTagMatch) {
				const databaseId = parseInt(removeMessageTagMatch[1])
				const imapLabel = decodeURIComponent(removeMessageTagMatch[2])
				const mainStore = useMainStore()
				const envelope = mainStore.envelopes[databaseId]
				config.url = SDKMC_API_ROUTES.REMOVE_MESSAGE_TAG(databaseId, imapLabel)
				config.data = { accountId: envelope?.accountId, messageId: envelope?.messageId }
			}

			// ITSL: Translate tag IDs to imapLabels in search queries
			if (config.method === 'get' && config.url.includes('/api/messages') && config.params?.filter) {
				translateTagQuery(config)
			}

			return config
		},
		(error) => Promise.reject(error),
	)
}

function cleanupItslDataObject(config) {
	delete config.data.itsl.organizationAddress
	delete config.data.itsl.organizationAddressLabel
	delete config.data.itsl.functionAddress
	delete config.data.itsl.functionAddressLabel
	delete config.data.itsl.email
	delete config.data.itsl.notification
	delete config.data.itsl.ssn
	delete config.data.itsl.faxAddress
	delete config.data.itsl.smsAddress
	delete config.data.itsl.senderPersonIDs
	delete config.data.itsl.senderReferenceIDs
	delete config.data.itsl.recipientPersonIDs
	delete config.data.itsl.recipientReferenceIDs
	delete config.data.itsl.confidentiality
	delete config.data.itsl.additionalAttachment
	delete config.data.itsl.additionalAttachmentName
	delete config.data.itsl.overrideBody
}

export function initStore() {
	itslStore.initStore().then(() => {
		console.debug('[DEBUG] mail: itsl store initialized')
		// Tags now loaded sync from PHP initial state in init.js
	}).catch((e) => {
		console.debug('[DEBUG] mail: itsl store init error', e)
	})
}
function generateValidSDKAddress(organizationAddress, functionAddress) {
	if (!organizationAddress || !functionAddress) {
		return ''
	}

	const formattedSdkAddress = functionAddress
		.replace(/:0203:.*/, '')
		.replace(/:/g, '.')
		.replace(/\.\.\./g, '.')
		.replace(/\.\./g, '.')

	const formattedOrgAddress = organizationAddress
		.replace(/:/g, '.')
		.replace(/\.\.\./g, '.')
		.replace(/\.\./g, '.')

	return `${formattedSdkAddress}@${formattedOrgAddress}.sdk`
}

function generateSDKItslAttachment(itsl) {
	const senderFunctionAddress = getFunctionAddressFromEmailAddress(itsl.alias?.emailAddress)
	if (!senderFunctionAddress) {
		throw new Error(t('mail', 'Can not find the selected function address {functionAddress} (connected to alias {alias})', { functionAddress: itsl.alias.name, alias: itsl.alias.emailAddress }))
	}
	const senderLabels = itslStore.getSenderLabels(senderFunctionAddress)
	return {
		messageHeader: {
			messageId: null,
			conversationId: null,
			refToMessageId: null,
			creationDateTime: new Date().toISOString(),
			label: itsl.messageSubject,
			confidentiality: itsl.confidentiality,
			recipient: {
				recipientId: {
					extension: itsl.organizationAddress,
					root: itslStore.getSDKactoridUpisRoot,
				},
				attention: {
					subOrganization: {
						organizationId: {
							extension: itsl.functionAddress,
							root: itslStore.getSDKOrganizationRoot,
						},
						label: itsl.functionAddressLabel,
					},
					person: itsl.recipientPersonIDs,
					reference: itsl.recipientReferenceIDs,
				},
				label: itsl.organizationAddressLabel,
			},
			sender: {
				senderId: {
					extension: itslStore.getSenderORGextension,
					root: itslStore.getSDKactoridUpisRoot,
				},
				attention: {
					subOrganization: {
						organizationId: {
							extension: senderFunctionAddress,
							root: itslStore.getSDKOrganizationRoot,
						},
						label: senderLabels.functionAddressLabel,
					},
					person: itsl.senderPersonIDs,
					reference: itsl.senderReferenceIDs,
				},
				label: senderLabels.organizationAddressLabel,
			},
		},
	}
}

function getFunctionAddressFromEmailAddress(fromEmail) {
	if (!fromEmail) {
		return null
	}
	const foundEntry = [...itslStore.getValidFromData.entries()].find(([key, value]) => value === fromEmail)
	return foundEntry ? foundEntry[0] : null
}
