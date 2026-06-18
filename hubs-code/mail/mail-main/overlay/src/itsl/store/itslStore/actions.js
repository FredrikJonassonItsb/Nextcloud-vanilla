import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { set } from 'vue'
import { SDKMC_API_ROUTES, MY_MESSAGES_MAILBOX_ID, UNASSIGNED_MAILBOX_ID } from '../constants.js'
import {
	priorityImportantQuery,
	priorityOtherQuery,
} from '../../../util/priorityInbox.js'
import { normalizedEnvelopeListId } from '../../../util/normalization.js'
import { UNIFIED_INBOX_ID } from '../../../store/constants.js'
import useMainStore from '../../../store/mainStore.js'

export default function itslStoreActions() {
	return {
		setMessageType(type) {
			this.selectedMessageType = type
		},
		async initStore() {
			// Section 1: validFromData + settings
			try {
				const responseSDKAddress = await axios.get(SDKMC_API_ROUTES.GET_ADDRESS_MAPPING)
				if (responseSDKAddress.data) {
					for (const key in responseSDKAddress.data) {
						if (Object.prototype.hasOwnProperty.call(responseSDKAddress.data, key)) {
							this.validFromData.set(key, responseSDKAddress.data[key])
						}
					}
				}
				const responseGetInfo = await axios.get(SDKMC_API_ROUTES.GET_INFO)
				if (responseGetInfo.data) {
					this.userObligatedToProvideSsn = responseGetInfo.data.enforcePersonalSecuremail

					if (responseGetInfo.data?.organizationExtension) {
						this.sender.organizationExtension = responseGetInfo.data.organizationExtension
					}

					// Thread sort order (admin setting) - defaults to true if not set
					if (responseGetInfo.data.threadSortNewestFirst !== undefined) {
						this.threadSortNewestFirst = responseGetInfo.data.threadSortNewestFirst
					}

					// Auto-expand newest in thread (admin setting) - defaults to false
					if (responseGetInfo.data.selectNewestInThread !== undefined) {
						this.selectNewestInThread = responseGetInfo.data.selectNewestInThread
					}
				}
			} catch (error) {
				console.warn('[itslStore] Failed to load validFromData/settings:', error)
			}

			// Section 2: Address book
			try {
				const responseAddressBookOrgs = await axios.get(SDKMC_API_ROUTES.GET_ADDRESS_BOOK_ORGANIZATIONS)
				const tempMap = new Map() // map is required for init phase, for search we will use array

				if (responseAddressBookOrgs.data?.data) {
					for (const orgInfo of responseAddressBookOrgs.data.data) {
						tempMap.set(orgInfo.id, {
							id: orgInfo.id,
							name: orgInfo.attributes.name,
							searchableName: `${orgInfo.attributes.name || ''} (${orgInfo.attributes.participantIdentifier})`.trim().toLowerCase() ?? '',
							address: orgInfo.attributes.participantIdentifier,
							functionAddresses: [],
						})
					}
				}

				if (tempMap.size > 0) { // loading organization addresses only if previous call was succesfull
					const responseAddressBookPersons = await axios.get(SDKMC_API_ROUTES.GET_ADDRESS_BOOK_FUNCTION_ADDRESSES)
					if (responseAddressBookPersons.data?.data) {
						for (const addrInfo of responseAddressBookPersons.data.data) {
							const tempItem = tempMap.get(addrInfo.relationships?.parent?.data?.id)
							if (tempItem) {
								tempItem.functionAddresses.push({
									id: addrInfo.id,
									name: addrInfo.attributes.name,
									searchableName: `${addrInfo.attributes.name || ''} (${addrInfo.attributes.identifier})`.trim().toLowerCase() ?? '',
									address: addrInfo.attributes.identifier,
								})
							} else {
								console.warn('invalid orgId:', addrInfo.relationships?.parent?.data?.id)
							}
						}
						this.addressBookOrgs.push(...Array.from(tempMap.values()))
					}
				}
			} catch (error) {
				console.warn('[itslStore] Failed to load address book:', error)
			}
			this.addressBookLoaded = true

			// Section 3: Internal mailboxes
			try {
				const responseInternalMailboxes = await axios.get(SDKMC_API_ROUTES.GET_INTERNAL_EMAILS)
				if (responseInternalMailboxes.data && Array.isArray(responseInternalMailboxes.data)) {
					this.internalMailboxes = responseInternalMailboxes.data
				}
				this.internalMailboxesLoaded = true
			} catch (error) {
				console.warn('[itslStore] Failed to load internal mailboxes:', error)
			}
		},
		getSenderLabels(senderFunctionAddress) {
			const result = {
				functionAddressLabel: '',
				organizationAddressLabel: '',
			}

			for (const org of this.addressBookOrgs) {
				if (org.address === this.getSenderORGextension) {
					const match = org.functionAddresses.find(f => f.address === senderFunctionAddress)
					if (match) {
						result.organizationAddressLabel = org.name
						result.functionAddressLabel = match.name
						break
					}
				}
			}

			return result
		},
		// Move envelope between priority inbox lists when importance changes
		updateEnvelopePriorityList(mainStore, envelope, isImportant) {
			// Check if envelope belongs to an inbox - only inbox messages should appear in priority lists
			const envelopeMailbox = mainStore.getMailbox(envelope.mailboxId)
			if (envelopeMailbox?.specialRole !== 'inbox') {
				return // Non-inbox messages (sent, drafts, etc.) shouldn't appear in priority lists
			}

			const unifiedMailbox = mainStore.mailboxes[UNIFIED_INBOX_ID]
			if (!unifiedMailbox || !unifiedMailbox.envelopeLists) {
				return
			}

			const importantListId = normalizedEnvelopeListId(priorityImportantQuery)
			const otherListId = normalizedEnvelopeListId(priorityOtherQuery)

			// Create new arrays to avoid mutation issues (set() handles reactive creation)
			let importantList = [...(unifiedMailbox.envelopeLists[importantListId] || [])]
			let otherList = [...(unifiedMailbox.envelopeLists[otherListId] || [])]

			const envelopeId = envelope.databaseId
			const threadRootId = envelope.threadRootId
			const mailboxId = envelope.mailboxId
			const dateInt = envelope.dateInt ?? 0

			// Check if a better representative (higher dateInt) already exists in either list
			// This ensures we keep only one envelope per thread+mailbox (the latest one)
			const hasBetterRepresentative = (list) => list.some(id => {
				const env = mainStore.envelopes[id]
				return env?.threadRootId === threadRootId
					&& env?.mailboxId === mailboxId
					&& env.dateInt > dateInt
			})

			if (hasBetterRepresentative(importantList) || hasBetterRepresentative(otherList)) {
				return // Don't replace a better representative
			}

			// Remove all envelopes from same thread+mailbox (we'll add the current one as representative)
			const isSameThreadMailbox = (id) => {
				const env = mainStore.envelopes[id]
				return env?.threadRootId === threadRootId && env?.mailboxId === mailboxId
			}

			importantList = importantList.filter(id => !isSameThreadMailbox(id))
			otherList = otherList.filter(id => !isSameThreadMailbox(id))

			// Add to the appropriate list
			if (isImportant) {
				importantList.push(envelopeId)
			} else {
				otherList.push(envelopeId)
			}

			// Sort lists by dateInt to maintain correct order
			const getDateInt = (id) => mainStore.envelopes[id]?.dateInt ?? 0
			const isNewest = mainStore.preferences?.['sort-order'] === 'newest'

			importantList.sort((a, b) => isNewest ? getDateInt(b) - getDateInt(a) : getDateInt(a) - getDateInt(b))
			otherList.sort((a, b) => isNewest ? getDateInt(b) - getDateInt(a) : getDateInt(a) - getDateInt(b))

			// Update both lists
			set(unifiedMailbox.envelopeLists, importantListId, importantList)
			set(unifiedMailbox.envelopeLists, otherListId, otherList)
		},
		// Quick removal from priority lists (used for optimistic UI on drag & drop)
		removeFromPriorityList(mainStore, databaseId) {
			const unifiedMailbox = mainStore.mailboxes[UNIFIED_INBOX_ID]
			if (!unifiedMailbox || !unifiedMailbox.envelopeLists) {
				return
			}

			const importantListId = normalizedEnvelopeListId(priorityImportantQuery)
			const otherListId = normalizedEnvelopeListId(priorityOtherQuery)

			const importantList = unifiedMailbox.envelopeLists[importantListId]
			const otherList = unifiedMailbox.envelopeLists[otherListId]

			if (importantList) {
				const filtered = importantList.filter(id => id !== databaseId)
				if (filtered.length !== importantList.length) {
					set(unifiedMailbox.envelopeLists, importantListId, filtered)
				}
			}

			if (otherList) {
				const filtered = otherList.filter(id => id !== databaseId)
				if (filtered.length !== otherList.length) {
					set(unifiedMailbox.envelopeLists, otherListId, filtered)
				}
			}
		},
		/**
		 * Update virtual mailbox lists (My Messages / Unassigned) after assignment tag change.
		 * Called after envelope.tags is already updated (optimistic or post-API).
		 */
		updateVirtualMailboxLists(mainStore, envelope, imapLabel, isAddition) {
			if (!this._isAssignmentImapLabel(imapLabel)) return

			const myMb = mainStore.mailboxes[MY_MESSAGES_MAILBOX_ID]
			const unMb = mainStore.mailboxes[UNASSIGNED_MAILBOX_ID]
			if (!myMb && !unMb) return

			const envelopeId = envelope.databaseId
			const currentUserTag = this.currentUserAssignmentTag

			// Does envelope have ANY assignment tag after this change?
			// Use itslStore.tagsByAccount (SDKMC source) since mainStore.tags may not carry isAssignmentTag
			const hasAnyAssignment = (envelope.tags || []).some(tagId => {
				for (const tags of Object.values(this.tagsByAccount)) {
					if (tags.some(t => t.id === tagId && t.isAssignmentTag === true)) {
						return true
					}
				}
				return false
			})

			const isCurrentUserTag = currentUserTag?.imapLabel === imapLabel

			// My Messages: add/remove based on current user's tag
			if (myMb) {
				if (isAddition && isCurrentUserTag) {
					this._addToAllEnvelopeLists(myMb, envelopeId)
				} else if (!isAddition && isCurrentUserTag) {
					this._removeFromAllEnvelopeLists(myMb, envelopeId)
				}
			}

			// Unassigned: add when no assignments remain, remove when assigned
			if (unMb) {
				if (isAddition && hasAnyAssignment) {
					this._removeFromAllEnvelopeLists(unMb, envelopeId)
				} else if (!isAddition && !hasAnyAssignment) {
					this._addToAllEnvelopeLists(unMb, envelopeId)
				}
			}
		},
		_isAssignmentImapLabel(imapLabel) {
			for (const tags of Object.values(this.tagsByAccount)) {
				if (tags.some(t => t.imapLabel === imapLabel && t.isAssignmentTag === true)) {
					return true
				}
			}
			return false
		},
		_addToAllEnvelopeLists(mailbox, envelopeId) {
			if (!mailbox?.envelopeLists) return
			for (const listId of Object.keys(mailbox.envelopeLists)) {
				const list = mailbox.envelopeLists[listId]
				if (!list.includes(envelopeId)) {
					set(mailbox.envelopeLists, listId, [...list, envelopeId])
				}
			}
		},
		_removeFromAllEnvelopeLists(mailbox, envelopeId) {
			if (!mailbox?.envelopeLists) return
			for (const listId of Object.keys(mailbox.envelopeLists)) {
				const list = mailbox.envelopeLists[listId]
				const filtered = list.filter(id => id !== envelopeId)
				if (filtered.length !== list.length) {
					set(mailbox.envelopeLists, listId, filtered)
				}
			}
		},
		// ITSL: Sync sent folder after any send, refresh thread if this was a reply
		async onMessageSent({ replyToDatabaseId, accountId }) {
			const mainStore = useMainStore()

			// Sync the Sent mailbox so the sent message is indexed in the DB
			if (accountId) {
				const account = mainStore.getAccounts.find(a => a.id === accountId)
				if (account?.sentMailboxId) {
					try {
						await mainStore.syncEnvelopes({ mailboxId: account.sentMailboxId })
					} catch (error) {
						console.debug('[ITSL] Failed to sync sent mailbox', { error })
					}
				}
			}

			if (!replyToDatabaseId) {
				return
			}

			const maxRetries = 3
			const retryDelay = 1000 // 1 second between retries

			for (let attempt = 1; attempt <= maxRetries; attempt++) {
				try {
					await mainStore.fetchThread(replyToDatabaseId)
					return // Success, exit
				} catch (error) {
					if (attempt < maxRetries) {
						console.debug(`[ITSL] Thread refresh attempt ${attempt} failed, retrying...`, { replyToDatabaseId })
						await new Promise(resolve => setTimeout(resolve, retryDelay))
					} else {
						console.warn('[ITSL] Failed to refresh thread after send (all retries exhausted)', { replyToDatabaseId, error })
					}
				}
			}
		},
		/**
		 * Initialize tags from PHP initial state (keyed by accountId).
		 * Called during app init - populates tagsByAccount only.
		 * mainStore is populated by init.js using the flattened array.
		 * @param {object} tagsByAccount - {accountId: [tag, ...], ...}
		 */
		initTagsFromPhpState(tagsByAccount) {
			for (const [accountId, tags] of Object.entries(tagsByAccount)) {
				set(this.tagsByAccount, parseInt(accountId, 10), tags)
			}
			this.tagsLoaded = true
		},
		setTagsForAccount(accountId, tags) {
			set(this.tagsByAccount, accountId, tags)
		},
		addTagToAccount(accountId, tag) {
			const currentTags = this.tagsByAccount[accountId] || []
			// Check if tag already exists to avoid duplicates
			if (!currentTags.some(t => t.id === tag.id)) {
				set(this.tagsByAccount, accountId, [...currentTags, tag])
			}
		},
		// ITSL: Create tag via sdkmc API
		async createTag(mainStore, { displayName, color, accountId }) {
			const response = await axios.post(SDKMC_API_ROUTES.CREATE_TAG(accountId), {
				displayName,
				color,
			})
			const tag = response.data
			mainStore.addTagMutation({ tag })
			this.addTagToAccount(accountId, tag)
			return tag
		},
		// ITSL: Update tag via sdkmc API
		async updateTag(mainStore, { tag, displayName, color, accountId }) {
			await axios.put(SDKMC_API_ROUTES.UPDATE_TAG(accountId, tag.id), {
				displayName,
				color,
			})
			mainStore.updateTagMutation({ tag, displayName, color })
			// Also update in tagsByAccount for reactivity
			this.updateTagInAccount(accountId, tag.id, displayName, color)
		},
		// ITSL: Update tag in tagsByAccount array (preserves object reference for mainStore sync)
		updateTagInAccount(accountId, tagId, displayName, color) {
			const currentTags = this.tagsByAccount[accountId] || []
			const tag = currentTags.find(t => t.id === tagId)
			if (tag) {
				// Mutate the existing object to preserve reference shared with mainStore
				set(tag, 'displayName', displayName)
				set(tag, 'color', color)
			}
		},
		// ITSL: Remove tag from account's tag list
		removeTagFromAccount(accountId, tagId) {
			const currentTags = this.tagsByAccount[accountId] || []
			const updatedTags = currentTags.filter(t => t.id !== tagId)
			set(this.tagsByAccount, accountId, updatedTags)
		},
		// ITSL: Delete tag via mainStore and cleanup itslStore
		async deleteTag(mainStore, { tag, accountId }) {
			await mainStore.deleteTag({ tag, accountId })
			this.removeTagFromAccount(accountId, tag.id)
		},
		// ITSL: Sidebar tag click signaling (for sidebar -> search flow without navigation)
		triggerSidebarTagClick(imapLabel, tagName) {
			this.pendingSidebarTagClick = { imapLabel, tagName, timestamp: Date.now() }
		},
		clearSidebarTagClick() {
			this.pendingSidebarTagClick = null
		},
		// ITSL: Virtual mailbox tag injection state (for My Messages/Unassigned)
		setVirtualMailboxTag(tagSearchValue, tagName) {
			this.virtualMailboxTag = { tagSearchValue, tagName }
		},
		clearVirtualMailboxTag() {
			this.virtualMailboxTag = null
		},
		// ITSL: Assignment tags dropped when entering Unassigned (to restore on exit)
		setDroppedAssignmentTags(tagIds) {
			this.droppedAssignmentTags = tagIds
		},
		clearDroppedAssignmentTags() {
			this.droppedAssignmentTags = []
		},
		// ITSL: Virtual search cleared signal (X button clicked in virtual mailbox)
		triggerVirtualSearchCleared() {
			this.virtualSearchClearedSignal = { timestamp: Date.now() }
		},
		clearVirtualSearchClearedSignal() {
			this.virtualSearchClearedSignal = null
		},
		// ITSL: Pending tag changes for optimistic UI (with timestamps for race condition safety)
		addPendingTagRemoval(databaseId, imapLabel) {
			// Clear any conflicting pending addition (user is toggling back)
			const pendingAddition = this.pendingTagAdditions[databaseId]
			if (pendingAddition && imapLabel in pendingAddition) {
				delete pendingAddition[imapLabel]
				if (Object.keys(pendingAddition).length === 0) {
					delete this.pendingTagAdditions[databaseId]
				}
				this.pendingTagAdditions = { ...this.pendingTagAdditions }
			}

			const timestamp = Date.now()
			if (!this.pendingTagRemovals[databaseId]) {
				set(this.pendingTagRemovals, databaseId, {})
			}
			set(this.pendingTagRemovals[databaseId], imapLabel, timestamp)
			return timestamp
		},
		clearPendingTagRemoval(databaseId, imapLabel, timestamp) {
			// Only called on error (rollback) - simple clear without mainStore check
			const pending = this.pendingTagRemovals[databaseId]
			if (pending && pending[imapLabel] === timestamp) {
				delete pending[imapLabel]
				if (Object.keys(pending).length === 0) {
					delete this.pendingTagRemovals[databaseId]
				}
				this.pendingTagRemovals = { ...this.pendingTagRemovals }
			}
		},
		addPendingTagAddition(databaseId, imapLabel) {
			// Clear any conflicting pending removal (user is toggling back)
			const pendingRemoval = this.pendingTagRemovals[databaseId]
			if (pendingRemoval && imapLabel in pendingRemoval) {
				delete pendingRemoval[imapLabel]
				if (Object.keys(pendingRemoval).length === 0) {
					delete this.pendingTagRemovals[databaseId]
				}
				this.pendingTagRemovals = { ...this.pendingTagRemovals }
			}

			const timestamp = Date.now()
			if (!this.pendingTagAdditions[databaseId]) {
				set(this.pendingTagAdditions, databaseId, {})
			}
			set(this.pendingTagAdditions[databaseId], imapLabel, timestamp)
			return timestamp
		},
		clearPendingTagAddition(databaseId, imapLabel, timestamp) {
			// Only called on error (rollback) - simple clear without mainStore check
			const pending = this.pendingTagAdditions[databaseId]
			if (pending && pending[imapLabel] === timestamp) {
				delete pending[imapLabel]
				if (Object.keys(pending).length === 0) {
					delete this.pendingTagAdditions[databaseId]
				}
				this.pendingTagAdditions = { ...this.pendingTagAdditions }
			}
		},
		// ITSL: Remove envelope from tag-based search result caches (optimistic UI)
		removeFromTagSearchLists(mainStore, databaseId, imapLabel) {
			const tagIds = this._getTagIdsForImapLabel(mainStore, imapLabel)
			if (tagIds.length === 0) return

			const removeFromMailbox = (mailbox) => {
				if (!mailbox?.envelopeLists) return
				for (const listId of Object.keys(mailbox.envelopeLists)) {
					if (this._listKeyMatchesTag(listId, tagIds)) {
						const list = mailbox.envelopeLists[listId]
						const filtered = list.filter(id => id !== databaseId)
						if (filtered.length !== list.length) {
							set(mailbox.envelopeLists, listId, filtered)
						}
					}
				}
			}

			removeFromMailbox(mainStore.mailboxes[UNIFIED_INBOX_ID])
			const envelope = mainStore.envelopes[databaseId]
			if (envelope?.mailboxId) {
				removeFromMailbox(mainStore.mailboxes[envelope.mailboxId])
			}
		},
		/**
		 * Sync destination mailbox and update priority inbox lists if destination is inbox.
		 * Call this after operations that move envelopes (move, drag & drop).
		 * For unsnooze, use syncAfterUnsnooze instead.
		 *
		 * @param {object} mainStore - The main store instance
		 * @param {object} envelope - The envelope that was moved
		 * @param {number|string} destMailboxId - Destination mailbox ID (required)
		 */
		async syncAfterMove(mainStore, envelope, destMailboxId = null) {
			const mailboxId = destMailboxId || envelope.itsl?.snoozeSrcMailboxId
			const accountId = envelope.accountId
			const threadRootId = envelope.threadRootId

			if (!mailboxId) {
				return // No destination mailbox info
			}

			// Sync destination mailbox to make envelope appear
			await mainStore.syncEnvelopes({
				mailboxId,
				query: '',
				init: true,
			})

			// Check if destination is an inbox - if so, update priority lists
			const destMailbox = mainStore.getMailbox(mailboxId)
			if (destMailbox?.specialRole !== 'inbox') {
				return // Not an inbox, no priority update needed
			}

			// Update priority inbox lists for thread envelopes
			const envelopes = mainStore.getEnvelopesByThreadRootId(accountId, threadRootId)
			for (const env of envelopes) {
				const isImportant = mainStore.getEnvelopeTags(env.databaseId)
					?.some(tag => tag.imapLabel === '$label1') ?? false
				this.updateEnvelopePriorityList(mainStore, env, isImportant)
			}
		},
		/**
		 * Sync inbox (and sent) mailboxes after unsnooze, then update priority lists.
		 *
		 * Unlike syncAfterMove (which needs a specific destMailboxId), unsnooze always
		 * moves messages back to their original source mailboxes. For threads with
		 * messages from multiple mailboxes (e.g. inbox + sent), we sync both.
		 *
		 * This avoids the unreliable envelope.itsl.snoozeSrcMailboxId which is only
		 * populated when the thread detail view has been opened (FetchThreadEvent).
		 *
		 * @param {object} mainStore - The main store instance
		 * @param {object} envelope - The envelope that was unsnoozed (saved before removal)
		 */
		async syncAfterUnsnooze(mainStore, envelope) {
			const accountId = envelope.accountId
			const threadRootId = envelope.threadRootId
			const account = mainStore.getAccounts.find(a => a.id === accountId)
			if (!account) {
				return
			}

			// Find inbox mailbox(es) for this account
			const inboxMailboxes = mainStore.getMailboxes(accountId)
				.filter(mb => mb.specialRole === 'inbox')

			// Sync inbox + sent in parallel — these are the mailboxes unsnooze restores to
			// Retry on 409 Conflict (IMAP lock still held by unsnooze backend operation)
			const syncWithRetry = async (mailboxId, retries = 3) => {
				for (let i = 0; i < retries; i++) {
					try {
						return await mainStore.syncEnvelopes({ mailboxId, query: '', init: true })
					} catch (err) {
						if (err?.response?.status === 409 && i < retries - 1) {
							await new Promise(r => setTimeout(r, 1000 * (i + 1)))
						} else {
							throw err
						}
					}
				}
			}

			const syncPromises = inboxMailboxes.map(mb => syncWithRetry(mb.databaseId))
			if (account.sentMailboxId) {
				syncPromises.push(syncWithRetry(account.sentMailboxId))
			}
			await Promise.all(syncPromises)

			// Update priority inbox lists for thread envelopes (now re-fetched from IMAP with correct flags)
			const envelopes = mainStore.getEnvelopesByThreadRootId(accountId, threadRootId)
			for (const env of envelopes) {
				const isImportant = mainStore.getEnvelopeTags(env.databaseId)
					?.some(tag => tag.imapLabel === '$label1') ?? false
				this.updateEnvelopePriorityList(mainStore, env, isImportant)
			}
		},
		// ITSL: Add envelope to tag-based search result caches (optimistic UI)
		addToTagSearchLists(mainStore, databaseId, imapLabel) {
			const tagIds = this._getTagIdsForImapLabel(mainStore, imapLabel)
			if (tagIds.length === 0) return

			const addToMailbox = (mailbox) => {
				if (!mailbox?.envelopeLists) return
				for (const listId of Object.keys(mailbox.envelopeLists)) {
					if (this._listKeyMatchesTag(listId, tagIds)) {
						const list = mailbox.envelopeLists[listId]
						if (!list.includes(databaseId)) {
							set(mailbox.envelopeLists, listId, [...list, databaseId])
						}
					}
				}
			}

			addToMailbox(mainStore.mailboxes[UNIFIED_INBOX_ID])
			const envelope = mainStore.envelopes[databaseId]
			if (envelope?.mailboxId) {
				addToMailbox(mainStore.mailboxes[envelope.mailboxId])
			}
		},
		// Resolve imapLabel to all matching numeric tag IDs across accounts
		// Falls back to itslStore.tagsByAccount if mainStore.tags hasn't been populated yet
		_getTagIdsForImapLabel(mainStore, imapLabel) {
			const ids = []
			for (const tag of Object.values(mainStore.tags || {})) {
				if (tag.imapLabel === imapLabel) {
					ids.push(String(tag.id))
				}
			}
			if (ids.length === 0) {
				for (const tags of Object.values(this.tagsByAccount)) {
					for (const tag of tags) {
						if (tag.imapLabel === imapLabel) {
							ids.push(String(tag.id))
						}
					}
				}
			}
			return ids
		},
		// Check if an envelopeLists key contains a tag query matching any given tag IDs
		// Keys look like "tags:123 match:allof" or "from:bob tags:123,456 match:allof"
		_listKeyMatchesTag(listId, tagIds) {
			const match = listId.match(/tags:([^\s]+)/)
			if (!match) return false
			const tagsInKey = decodeURIComponent(match[1]).split(',')
			return tagIds.some(id => tagsInKey.includes(id))
		},
	}
}
