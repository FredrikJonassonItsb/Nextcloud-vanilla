import VueTelInput from 'vue-tel-input'
import 'vue-tel-input/dist/vue-tel-input.css'
import { initInterceptors, initStore } from '../../itsl/interceptors/axios-setup.js'
import { MESSAGE_TYPES, MY_MESSAGES_MAILBOX_ID, UNASSIGNED_MAILBOX_ID } from '../store/constants.js'
import { messageTypeToFolderName } from './messageTypeUtils.js'
import { formatPhoneNumber } from './phoneUtils.js'
import { addTagToQuery } from './tagQueryUtils.js'
import { registerEditorPlugin } from '../../ckeditor/pluginRegistry.js'
import PastePreserveNewlinesPlugin from '../ckeditor/paste/PastePreserveNewlinesPlugin.js'
import { initScrollMarginHandler } from './scrollMarginHandler.js'
import { initComposerDeepLink } from './initComposerDeepLink.js'
import { loadState } from '@nextcloud/initial-state'
import useItslStore from '../store/itslStore.js'
import dragEventBus from '../../directives/drag-and-drop/util/dragEventBus.js'
import useMainStore from '../../store/mainStore.js'
import useOutboxStore from '../../store/outboxStore.js'
import router from '../../router.js'
import { UNIFIED_ACCOUNT_ID } from '../../store/constants.js'
import { normalizedEnvelopeListId } from '../../util/normalization.js'
import Vue, { set as vueSet } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import '../../itsl/styling/variables.scss'
import '../../itsl/styling/layout.scss'
import '../../itsl/styling/_envelope-overrides.scss'
import '../../itsl/styling/_upstream-overrides.scss'
import Thread from '../../components/Thread.vue'

export function initITSL() {
	Vue.prototype.$MESSAGE_TYPES = MESSAGE_TYPES
	registerEditorPlugin(PastePreserveNewlinesPlugin)
	Vue.use(VueTelInput, {
		defaultCountry: 'SE',
		preferredCountries: ['SE'],
	})
	initInterceptors()
	initStore()
	initComposerDeepLink() // Hubs Start: open composer from ?type=…&case=… deep links + post-send ärende-koppling
	initTags()
	initTagPermissions()
	initTagNullGuard()
	initNextcloudFolders()
	initScrollMarginHandler()
	initDragDropSync()
	initMailboxLoadFix()
	initSplitpaneDefaults()
	initMoveSync()
	initTagPrioritySync()
	initVirtualMailboxTagSync()
	initFavoriteListSync()
	initOutboxThreadRefresh()
	initVirtualMailboxes()
	initThreadSortOrder()
	initThreadScrollOverride()
	initThreadExpandOverride()
	initAccountDisplayNames()
}

function initTags() {
	// PHP returns tags as {accountId: [tags]} - store in itslStore
	const tagsByAccount = loadState('mail', 'tags', {})
	useItslStore().initTagsFromPhpState(tagsByAccount)

	// Flatten to array and overwrite state so upstream init.js sees flat format
	// Use both mechanisms: window._nc_initial_state Map (NC 28+) and DOM element (legacy)
	const flatTags = Object.values(tagsByAccount).flat()
	if (window._nc_initial_state instanceof Map) {
		window._nc_initial_state.set('#initial-state-mail-tags', flatTags)
	}
	const elem = document.querySelector('#initial-state-mail-tags')
	if (elem) {
		elem.value = btoa(JSON.stringify(flatTags))
	}
}

function initTagPermissions() {
	const canManageTags = loadState('sdkmc', 'canManageTags', false)
	useItslStore().canManageTags = canManageTags
}

/**
 * Upstream getEnvelopeTags() maps tagIds via this.tags[tagId] which can return
 * undefined when a user-created tag (via sdkmc) isn't in the global tag store yet.
 * Upstream code then accesses .imapLabel on the undefined entry and crashes.
 * Wrap the method to filter out undefined entries.
 */
function initTagNullGuard() {
	const mainStore = useMainStore()
	const original = mainStore.getEnvelopeTags.bind(mainStore)
	mainStore.getEnvelopeTags = function(id) {
		return original(id).filter(Boolean)
	}
}

function initNextcloudFolders() {

	ensureFoldersExist([
		messageTypeToFolderName(MESSAGE_TYPES.SDK.id),
		messageTypeToFolderName(MESSAGE_TYPES.SECURE.id),
		messageTypeToFolderName(MESSAGE_TYPES.INTERNAL.id),
		messageTypeToFolderName(MESSAGE_TYPES.SMS.id),
		messageTypeToFolderName(MESSAGE_TYPES.FAX.id),
	])
}

async function ensureFoldersExist(folderNames = []) {
	const basePath = `/remote.php/dav/files/${OC.currentUser}/` // TODO: don't use OC.currentUser since it's deprecated, but init it in App.php

	for (const name of folderNames) {
		const fullPath = `${basePath}${encodeURIComponent(name)}/`

		const exists = await fetch(fullPath, {
			method: 'PROPFIND',
			headers: { Depth: '0', 'OCS-APIREQUEST': 'true' },
			credentials: 'include',
		})

		if (exists.status === 404) {
			const created = await fetch(fullPath, {
				method: 'MKCOL',
				headers: { 'OCS-APIREQUEST': 'true' },
				credentials: 'include',
			})

			if (!created.ok) {
				const errorText = await created.text()
				console.error(`Error creating folder "${name}": ${created.status}`, errorText)
			}
		} else if (!exists.ok) {
			const errorText = await exists.text()
			console.error(`Error checking folder "${name}": ${exists.status}`, errorText)
		}
	}
}

async function fetchWithRetry(fn, maxRetries = 3) {
	for (let attempt = 1; attempt <= maxRetries; attempt++) {
		try {
			return await fn()
		} catch (error) {
			const is409 = error?.response?.status === 409
			const isMailboxLocked = error?.name === 'MailboxLockedError'
			if ((is409 || isMailboxLocked) && attempt < maxRetries) {
				const delay = isMailboxLocked ? 1000 : 400 + Math.random() * 600
				await new Promise(resolve => setTimeout(resolve, delay))
			} else {
				throw error
			}
		}
	}
}

function initDragDropSync() {
	// Capture store references NOW (during Vue app init) - not inside event handler
	// The event handler runs in droppable-mailbox.js's Pinia context (separate instance)
	// so useMainStore() inside the handler would get the wrong store
	const mainStore = useMainStore()
	const itslStore = useItslStore()

	dragEventBus.on('envelopes-moved', async ({ mailboxId: destMailboxId, movedEnvelopes }) => {
		const srcMailboxIds = [...new Set(movedEnvelopes.map(e => e.mailboxId))]
		const destMailbox = mainStore.getMailbox(destMailboxId)

		// 0. Save envelope data BEFORE removing (needed for optimistic priority inbox update)
		const savedEnvelopes = movedEnvelopes.map(({ databaseId }) => {
			const envelope = mainStore.getEnvelope(databaseId)
			if (!envelope) return null
			const isImportant = mainStore.getEnvelopeTags(databaseId)
				?.some(tag => tag.imapLabel === '$label1') ?? false
			return { envelope: { ...envelope }, isImportant }
		}).filter(Boolean)

		// 1. IMMEDIATELY remove envelopes from source (optimistic update)
		// This ensures the source folder updates instantly, no race condition
		for (const { databaseId } of movedEnvelopes) {
			mainStore.removeEnvelopeMutation({ id: databaseId })
		}

		// 2. Remove from priority inbox (if it was there)
		for (const { databaseId } of movedEnvelopes) {
			itslStore.removeFromPriorityList(mainStore, databaseId)
		}

		// 3. Sync and fetch destination envelopes to add moved messages
		// HYBRID APPROACH (see /claude/mail-sync-flow.md):
		// - syncEnvelopes triggers IMAP sync, adding moved message to DB
		//   (but may not return it due to timestamp filter in findNewIds)
		// - fetchEnvelopes reads ALL messages from DB, bypassing timestamp filter
		if (destMailbox && !destMailbox.isUnified && !destMailbox.isPriorityInbox) {
			try {
				await fetchWithRetry(() =>
					mainStore.syncEnvelopes({ mailboxId: destMailboxId, query: '', init: true }),
				)
				await fetchWithRetry(() =>
					mainStore.fetchEnvelopes({ mailboxId: destMailboxId }),
				)
			} catch (err) {
				console.error('[ITSL DragDrop] Sync/fetch error:', err)
			}

			// Update priority inbox if dest is inbox
			if (destMailbox.specialRole === 'inbox') {
				const processedThreads = new Set()
				for (const { envelope: savedEnvelope } of savedEnvelopes) {
					if (processedThreads.has(savedEnvelope.threadRootId)) {
						continue
					}
					processedThreads.add(savedEnvelope.threadRootId)

					const threadEnvelopes = mainStore.getEnvelopesByThreadRootId(
						savedEnvelope.accountId,
						savedEnvelope.threadRootId,
					)
					for (const env of threadEnvelopes) {
						const isImportant = mainStore.getEnvelopeTags(env.databaseId)
							?.some(tag => tag.imapLabel === '$label1') ?? false
						itslStore.updateEnvelopePriorityList(mainStore, env, isImportant)
					}
				}
			}
		}

		// 4. Sync sources to verify/rollback if move failed
		// If move failed, sync will restore the envelope to source
		for (const srcMailboxId of srcMailboxIds) {
			const srcMailbox = mainStore.getMailbox(srcMailboxId)
			if (!srcMailbox || srcMailbox.isUnified || srcMailbox.isPriorityInbox) {
				continue
			}
			await mainStore.syncEnvelopes({ mailboxId: srcMailboxId, query: '', init: true })
		}
	})
}

/**
 * Fix for priority inbox navigation.
 *
 * Problem: hasFetchedInitialEnvelopes is a global flag. Once true, Mailbox
 * components skip loading in mounted(). But priority inbox has 3 Mailbox
 * components that each need to load their own query.
 *
 * Solution: Reset the flag when navigating to priority inbox. All Mailbox
 * components check the flag synchronously before any async loading, so by
 * the time the first one sets it true, others have already started.
 */
function initMailboxLoadFix() {
	const mainStore = useMainStore()

	router.beforeEach((to, from, next) => {
		// Reset flag when navigating TO priority inbox from somewhere else
		if (to.params.mailboxId === 'priority' && from.params.mailboxId !== 'priority') {
			mainStore.setHasFetchedInitialEnvelopesMutation(false)
		}
		next()
	})
}

/**
 * Ensure splitpane list defaults to 30% minimum.
 *
 * Problem: NcAppContent defaults to 20%, but MailboxThread.vue sets min to 30%.
 * If localStorage has no value or a value below 30, the pane renders at 20%
 * then snaps to 30% on first interaction.
 *
 * In v4.2 the pane-config-key was static "mail", producing a single key.
 * In v5.5 it changed to "mail-" + layoutMode, producing per-layout keys.
 *
 * Solution: Force minimum 30% in localStorage for all layout keys on page load.
 */
function initSplitpaneDefaults() {
	// NcAppContent key format: nextcloud_per_{scope}_pane-list-size-{paneConfigKey}
	// scope = btoa('nextcloud') = 'bmV4dGNsb3Vk'
	// MailboxThread passes pane-config-key="mail-" + layoutMode
	const prefix = 'nextcloud_per_bmV4dGNsb3Vk_pane-list-size-mail-'
	const layouts = ['vertical-split', 'horizontal-split', 'no-split', 'list']
	for (const layout of layouts) {
		const key = prefix + layout
		const stored = localStorage.getItem(key)
		const value = stored ? parseInt(stored, 10) : null
		if (value === null || value < 30) {
			localStorage.setItem(key, '30')
		}
	}
}

/**
 * Intercept moveThread and unSnoozeThread store actions to sync priority inbox.
 *
 * Uses Pinia's $onAction to hook into actions WITHOUT modifying mail app code.
 * This replaces the direct syncAfterMove calls in MoveModal.vue and Envelope.vue.
 *
 * Also handles thread envelope removal: the original moveThread only removes one
 * envelope, but threads can have multiple. We remove the others to prevent duplicates.
 */
function initMoveSync() {
	const mainStore = useMainStore()
	const itslStore = useItslStore()
	const processedThreads = new Set() // Track threads to avoid duplicate work in bulk moves

	mainStore.$onAction(({ name, args, after, onError }) => {
		// Intercept moveThread (MoveModal, keyboard shortcuts, etc.)
		if (name === 'moveThread') {
			const { envelope, destMailboxId } = args[0]

			// Skip if already processed this thread (bulk move edge case)
			if (processedThreads.has(envelope.threadRootId)) {
				return
			}
			processedThreads.add(envelope.threadRootId)

			// Save envelope data before action removes it from store
			const savedEnvelope = { ...envelope }

			// Get all thread envelopes BEFORE action removes one
			const threadEnvelopes = mainStore.getEnvelopesByThreadRootId(
				envelope.accountId,
				envelope.threadRootId,
			)

			// Remove the OTHER envelopes (action removes the primary one)
			const otherEnvelopes = threadEnvelopes.filter(
				env => env.databaseId !== envelope.databaseId,
			)
			otherEnvelopes.forEach(env => {
				mainStore.removeEnvelopeMutation({ id: env.databaseId })
			})

			onError(() => {
				processedThreads.delete(envelope.threadRootId)
				// Restore the ones WE removed (action restores its own)
				if (otherEnvelopes.length > 0) {
					mainStore.addEnvelopesMutation({ envelopes: otherEnvelopes })
				}
			})

			after(async () => {
				processedThreads.delete(envelope.threadRootId)
				try {
					await itslStore.syncAfterMove(mainStore, savedEnvelope, destMailboxId)
				} catch (error) {
					console.error('[ITSL $onAction] syncAfterMove failed:', error)
				}
			})
		}

		// Intercept unSnoozeThread (EnvelopeItsl.vue)
		if (name === 'unSnoozeThread') {
			const { envelope } = args[0]
			const savedEnvelope = { ...envelope }

			after(async () => {
				try {
					await itslStore.syncAfterUnsnooze(mainStore, savedEnvelope)
				} catch (error) {
					console.error('[ITSL $onAction] syncAfterUnsnooze failed:', error)
				}
			})
		}

		// Intercept unSnoozeMessage (MenuEnvelopeItsl.vue - single message unsnooze)
		if (name === 'unSnoozeMessage') {
			const { id } = args[0]
			const envelope = mainStore.getEnvelope(id)
			if (envelope) {
				const savedEnvelope = { ...envelope }

				after(async () => {
					try {
						await itslStore.syncAfterUnsnooze(mainStore, savedEnvelope)
					} catch (error) {
						console.error('[ITSL $onAction] syncAfterUnsnooze failed:', error)
					}
				})
			}
		}
	})
}

/**
 * Intercept addEnvelopeTag/removeEnvelopeTag to:
 * 1. Update priority inbox lists when $label1 (important) tag changes
 * 2. Update tag search result caches for all tag changes
 *
 * This catches tag changes from all code paths including TagItem.vue and
 * DeleteTagModal.vue which call mainStore actions directly.
 */
function initTagPrioritySync() {
	const mainStore = useMainStore()
	const itslStore = useItslStore()

	mainStore.$onAction(({ name, args, after }) => {
		if (name === 'addEnvelopeTag') {
			const { envelope, imapLabel } = args[0]
			after(() => {
				if (imapLabel === '$label1') {
					itslStore.updateEnvelopePriorityList(mainStore, envelope, true)
				}
				itslStore.addToTagSearchLists(mainStore, envelope.databaseId, imapLabel)
			})
		}

		if (name === 'removeEnvelopeTag') {
			const { envelope, imapLabel } = args[0]
			after(() => {
				if (imapLabel === '$label1') {
					itslStore.updateEnvelopePriorityList(mainStore, envelope, false)
				}
				itslStore.removeFromTagSearchLists(mainStore, envelope.databaseId, imapLabel)
			})
		}
	})
}

/**
 * Update virtual mailbox lists (My Messages / Unassigned) when assignment tags change.
 * Handles single-envelope operations via $onAction interception.
 * Bulk operations (TagPopover, ThreadInfoSidebar) call updateVirtualMailboxLists directly.
 */
function initVirtualMailboxTagSync() {
	const mainStore = useMainStore()
	const itslStore = useItslStore()

	mainStore.$onAction(({ name, args, after }) => {
		if (name === 'addEnvelopeTag') {
			const { envelope, imapLabel } = args[0]
			after(() => {
				itslStore.updateVirtualMailboxLists(mainStore, envelope, imapLabel, true)
			})
		}

		if (name === 'removeEnvelopeTag') {
			const { envelope, imapLabel } = args[0]
			after(() => {
				itslStore.updateVirtualMailboxLists(mainStore, envelope, imapLabel, false)
			})
		}
	})
}

/**
 * Sync starred envelope lists when favorite status changes.
 *
 * Upstream toggles the flag but does NOT update envelopeLists keys containing
 * 'is:starred'. Unfavorited messages stay visible in Favorites, and
 * re-favorited messages don't reappear, until a full refresh.
 */
function initFavoriteListSync() {
	const mainStore = useMainStore()

	function forEachStarredList(envelope, fn) {
		const mailbox = mainStore.mailboxes[envelope.mailboxId]
		if (!mailbox) return

		const visit = (mb) => {
			if (!mb?.envelopeLists) return
			for (const listId of Object.keys(mb.envelopeLists)) {
				if (!listId.includes('is:starred')) continue
				fn(mb.envelopeLists[listId], envelope.databaseId)
			}
		}

		visit(mailbox)
		if (mainStore.accountsUnmapped[UNIFIED_ACCOUNT_ID]) {
			mainStore.accountsUnmapped[UNIFIED_ACCOUNT_ID].mailboxes
				.map((mbId) => mainStore.mailboxes[mbId])
				.filter((mb) => mb?.specialRole && mb.specialRole === mailbox.specialRole)
				.forEach(visit)
		}
	}

	function removeFromStarredLists(envelope) {
		forEachStarredList(envelope, (list, id) => {
			const idx = list.indexOf(id)
			if (idx >= 0) list.splice(idx, 1)
		})
	}

	function addToStarredLists(envelope) {
		forEachStarredList(envelope, (list, id) => {
			// Check by threadRootId, not databaseId — the list stores one
			// databaseId per thread and deduplicates by threadRootId upstream
			const threadIdx = list.findIndex(
				(existingId) => mainStore.envelopes[existingId]?.threadRootId === envelope.threadRootId,
			)
			if (threadIdx >= 0) {
				list[threadIdx] = id
			} else {
				list.unshift(id)
			}
		})
	}

	function syncStarredLists(envelope, starred) {
		if (starred) {
			addToStarredLists(envelope)
		} else {
			removeFromStarredLists(envelope)
		}
	}

	// Track when a high-level action is handling the sync so the
	// flagEnvelopeMutation interceptor doesn't double-fire
	let handledByAction = false

	mainStore.$onAction(({ name, args, after }) => {
		if (name === 'toggleEnvelopeFlagged') {
			handledByAction = true
			const envelope = args[0]
			after(() => {
				syncStarredLists(envelope, envelope.flags.flagged)
				handledByAction = false
			})
		}

		if (name === 'markEnvelopeFavoriteOrUnfavorite') {
			handledByAction = true
			const { envelope, favFlag } = args[0]
			after(() => {
				syncStarredLists(envelope, favFlag)
				handledByAction = false
			})
		}

		// ThreadInfoSidebar.toggleFavorite() calls flagEnvelopeMutation directly
		// instead of toggleEnvelopeFlagged — intercept it too.
		// Skip if already handled by a parent action to avoid double-fire.
		if (name === 'flagEnvelopeMutation' && !handledByAction) {
			const { envelope, flag, value } = args[0]
			if (flag === 'flagged') {
				after(() => syncStarredLists(envelope, value))
			}
		}
	})
}

/**
 * Intercept outboxStore.sendMessage to refresh thread after reply is sent.
 *
 * Replaces direct itslStore.onMessageSent call that was inline in outboxStore.js.
 */
function initOutboxThreadRefresh() {
	const outboxStore = useOutboxStore()
	const itslStore = useItslStore()

	outboxStore.$onAction(({ name, args, after }) => {
		if (name === 'sendMessage') {
			const { replyToDatabaseId, accountId } = args[0]
			after((wasSent) => {
				if (wasSent) {
					itslStore.onMessageSent({ replyToDatabaseId, accountId })
				}
			})
		}
	})
}

/**
 * Monkey-patch mainStore.getEnvelopesByThreadRootId to support
 * reversed thread order (newest first) based on admin setting.
 *
 * Uses wrapper pattern to avoid duplicating original sort logic.
 */
function initThreadSortOrder() {
	const mainStore = useMainStore()
	const itslStore = useItslStore()

	// Save reference to original method (bound to store context)
	const originalMethod = mainStore.getEnvelopesByThreadRootId.bind(mainStore)

	// Replace with wrapper that respects admin setting
	mainStore.getEnvelopesByThreadRootId = function(accountId, threadRootId) {
		const result = originalMethod(accountId, threadRootId)
		if (itslStore.threadSortNewestFirst) {
			return [...result].reverse()
		}
		return result
	}
}

/**
 * Override "Go to newest message" scroll behavior when thread order is reversed.
 * Uses event delegation to intercept button clicks without modifying ThreadSummary.vue.
 */
function initThreadScrollOverride() {
	const itslStore = useItslStore()
	const targetAriaLabel = t('mail', 'Go to latest message')

	document.addEventListener('click', (e) => {
		const button = e.target.closest(`[aria-label="${targetAriaLabel}"]`)
		if (button && itslStore.threadSortNewestFirst) {
			e.preventDefault()
			e.stopPropagation()
			const container = document.querySelector('.splitpanes__pane-details')
				|| document.querySelector('.app-content-wrapper--mobile')
			if (container) {
				container.scrollTo({ top: 0, left: 0, behavior: 'smooth' })
			}
		}
	}, true) // Capture phase to intercept before component handler
}

/**
 * Monkey-patch Thread component's resetThread method to support
 * auto-expanding the newest message based on admin setting.
 *
 * Uses component options patching pattern - safe in Vue 2 where
 * component definitions are mutable plain objects.
 */
function initThreadExpandOverride() {
	const itslStore = useItslStore()
	const originalResetThread = Thread.methods.resetThread

	Thread.methods.resetThread = async function() {
		await originalResetThread.call(this)
		this.expandedThreads = itslStore.getExpandedThreadOverride(this.thread) ?? this.expandedThreads
	}
}

// SVG icon data URIs for sidebar account icons (mask-image pattern)
const SIDEBAR_ICON_SVGS = {
	sdk: "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M 4,2 C 2.8954305,2 2,2.8954305 2,4 v 18 l 4,-4 h 14 c 1.104569,0 2,-0.895431 2,-2 V 11 H 17 C 15.89,11 15,10.11 15,9 V 2 H 4 m 2,4 h 7 V 8 H 6 V 6 m 0,3 h 7 v 2 H 6 V 9 m 0,3 h 8 v 2 H 6 Z'/%3E%3Cpath d='M 19.627518,6.5359802 18.030066,4.9385283 18.593168,4.3754265 19.627518,5.405783 22.25932,2.773981 22.822422,3.3410764 M 20.426244,0.1461726 16.831977,1.7436245 v 2.3961778 c 0,2.2164646 1.533554,4.2891587 3.594267,4.7923567 2.060713,-0.503198 3.594267,-2.5758921 3.594267,-4.7923567 V 1.7436245 Z'/%3E%3C/svg%3E",
	internal: "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M17,12V3A1,1 0 0,0 16,2H3A1,1 0 0,0 2,3V17L6,13H16A1,1 0 0,0 17,12M21,6H19V15H6V17A1,1 0 0,0 7,18H18L22,22V7A1,1 0 0,0 21,6Z'/%3E%3C/svg%3E",
	fax: "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M19 9H18V4H8V20H22V12C22 10.34 20.66 9 19 9M10 6H16V9H10V6M14 17H10V12H14V17M16 17C15.45 17 15 16.55 15 16C15 15.45 15.45 15 16 15C16.55 15 17 15.45 17 16C17 16.55 16.55 17 16 17M16 14C15.45 14 15 13.55 15 13S15.45 12 16 12C16.55 12 17 12.45 17 13S16.55 14 16 14M19 17C18.45 17 18 16.55 18 16C18 15.45 18.45 15 19 15S20 15.45 20 16C20 16.55 19.55 17 19 17M19 14C18.45 14 18 13.55 18 13S18.45 12 19 12 20 12.45 20 13 19.55 14 19 14M4.5 8C3.12 8 2 9.12 2 10.5V18.5C2 19.88 3.12 21 4.5 21S7 19.88 7 18.5V10.5C7 9.12 5.88 8 4.5 8Z'/%3E%3C/svg%3E",
	sms: "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M11,17V7H4V17H11M11,3A2,2 0 0,1 13,5V19A2,2 0 0,1 11,21H4C2.89,21 2,20.1 2,19V5A2,2 0 0,1 4,3H11M16.5,3H21.5A1.5,1.5 0 0,1 23,4.5V7.5A1.5,1.5 0 0,1 21.5,9H18L15,12V9L15,4.5A1.5,1.5 0 0,1 16.5,3Z'/%3E%3C/svg%3E",
}

/**
 * Get the icon type for an email suffix.
 * @param {string} email
 * @return {string|null} Icon key or null for non-ITSL accounts
 */
function getIconTypeForEmail(email) {
	if (!email) return null
	if (email.endsWith('@sdk')) return 'sdk'
	if (email.endsWith('@personlig') || email.endsWith('@gruppbox')) return 'internal'
	if (email.endsWith('@fax')) return 'fax'
	if (email.endsWith('@sms')) return 'sms'
	return null
}

/**
 * Set account display names from itslStore and inject sidebar icons + loading CSS.
 * Uses plain reactive values instead of Object.defineProperty getters.
 */
function initAccountDisplayNames() {
	const mainStore = useMainStore()
	const itslStore = useItslStore()

	/**
	 * Set account.name to the resolved display name from itslStore.
	 * If data hasn't loaded yet, sets name to empty string (loading state).
	 */
	function setAccountDisplayName(account) {
		if (!account.emailAddress) return
		const iconType = getIconTypeForEmail(account.emailAddress)
		if (!iconType) return // Not an ITSL account — keep original name
		const resolved = itslStore.resolveAccountDisplayName(account.emailAddress)
		account.name = resolved ?? ''
	}

	/**
	 * Like setAccountDisplayName, but only updates if data is loaded (resolved !== null).
	 * Used after edit/patch mutations to avoid overwriting the name with '' while loading.
	 */
	function setAccountDisplayNameIfLoaded(account) {
		if (!account.emailAddress) return
		const iconType = getIconTypeForEmail(account.emailAddress)
		if (!iconType) return
		const resolved = itslStore.resolveAccountDisplayName(account.emailAddress)
		if (resolved !== null) account.name = resolved
	}

	/**
	 * Update all ITSL account names from itslStore and remove loading CSS.
	 */
	function updateAllAccountNames() {
		for (const accountId of mainStore.accountList) {
			const account = mainStore.accountsUnmapped[accountId]
			if (account) setAccountDisplayName(account)
		}
	}

	function regenerateSidebarIcons() {
		let css = ''
		for (const accountId of mainStore.accountList) {
			const account = mainStore.accountsUnmapped[accountId]
			if (!account?.emailAddress) continue
			const iconType = getIconTypeForEmail(account.emailAddress)
			if (!iconType) continue
			const svgUrl = SIDEBAR_ICON_SVGS[iconType]
			css += `#account-${account.id} {
	position: relative;
	padding-left: 22px;
}
#account-${account.id}::before {
	content: '';
	position: absolute;
	left: 0;
	top: 50%;
	transform: translateY(-50%);
	width: 16px;
	height: 16px;
	-webkit-mask-image: url("${svgUrl}");
	mask-image: url("${svgUrl}");
	-webkit-mask-size: contain;
	mask-size: contain;
	-webkit-mask-repeat: no-repeat;
	mask-repeat: no-repeat;
	background-color: var(--color-main-text);
}
`
		}

		let styleEl = document.getElementById('itsl-sidebar-icons')
		if (!styleEl) {
			styleEl = document.createElement('style')
			styleEl.id = 'itsl-sidebar-icons'
			document.head.appendChild(styleEl)
		}
		styleEl.textContent = css
	}

	/**
	 * Inject pulsing placeholder CSS for ITSL accounts that are still loading.
	 * Uses ::after on the #account-{id} heading element (::before is the icon).
	 */
	function injectLoadingPlaceholders() {
		let css = ''
		for (const accountId of mainStore.accountList) {
			const account = mainStore.accountsUnmapped[accountId]
			if (!account?.emailAddress) continue
			if (!getIconTypeForEmail(account.emailAddress)) continue
			css += `#account-${account.id} {
	flex: 1;
	min-height: 20px;
}
#account-${account.id}::after {
	content: '';
	position: absolute;
	top: 50%;
	transform: translateY(-50%);
	left: 22px;
	right: 0;
	height: 16px;
	border-radius: 8px;
	background: linear-gradient(-45deg, var(--color-background-hover), var(--color-background-dark), var(--color-background-darker), var(--color-placeholder-light));
	background-size: 400% 400%;
	animation: itsl-loading-gradient 3s ease-in infinite;
}
`
		}
		if (!css) return
		let styleEl = document.getElementById('itsl-sidebar-loading')
		if (!styleEl) {
			styleEl = document.createElement('style')
			styleEl.id = 'itsl-sidebar-loading'
			document.head.appendChild(styleEl)
		}
		styleEl.textContent = css
	}

	function removeLoadingPlaceholders() {
		const styleEl = document.getElementById('itsl-sidebar-loading')
		if (styleEl) styleEl.remove()
	}

	// When accounts are added/modified, set display name and regenerate icons
	mainStore.$onAction(({ name, args, after }) => {
		if (name === 'addAccountMutation') {
			after(() => {
				const account = args[0]
				const stored = mainStore.accountsUnmapped[account.id]
				if (stored) setAccountDisplayName(stored)
				regenerateSidebarIcons()
				// Inject loading placeholder if data not yet loaded
				if (!itslStore.addressBookLoaded || !itslStore.internalMailboxesLoaded) {
					injectLoadingPlaceholders()
				}
			})
		}
		if (name === 'editAccountMutation') {
			after(() => {
				const account = args[0]
				const stored = mainStore.accountsUnmapped[account.id]
				if (stored) setAccountDisplayNameIfLoaded(stored)
			})
		}
		if (name === 'patchAccountMutation') {
			after(() => {
				const { account } = args[0]
				const stored = mainStore.accountsUnmapped[account.id]
				if (stored) setAccountDisplayNameIfLoaded(stored)
			})
		}
	})

	// When itslStore data loads, update all account names and remove loading CSS.
	// Uses $subscribe instead of $onAction because initStore() may already be
	// in flight when this hook is registered (it's called before us in initITSL).
	let storeLoadHandled = false
	itslStore.$subscribe(() => {
		if (storeLoadHandled) return
		if (itslStore.addressBookLoaded && itslStore.internalMailboxesLoaded) {
			storeLoadHandled = true
			updateAllAccountNames()
			removeLoadingPlaceholders()
		}
	})
}

/**
 * Intercept store actions for virtual mailbox fetch operations.
 *
 * Uses Pinia's $onAction to redirect fetchEnvelopes/syncEnvelopes calls for
 * virtual mailboxes to the unified inbox with appropriate tag filters.
 *
 * The unified inbox has isUnified: true, which triggers fan-out to all
 * individual inbox mailboxes. We add the tag filter to the query, so all
 * individual fetches include it. Results are then copied back to the
 * virtual mailbox's envelopeLists.
 */
function initVirtualMailboxFetch() {
	const mainStore = useMainStore()
	const itslStore = useItslStore()

	/**
	 * Shared preamble for virtual mailbox action interception.
	 * Checks if the action targets a virtual mailbox and redirects to unified inbox
	 * with the appropriate tag filter.
	 * @param {Array} args $onAction args array
	 * @return {{ mailbox: object, originalQuery: string } | null} Context or null if not virtual
	 */
	function getVirtualMailboxContext(args) {
		const originalMailboxId = args[0].mailboxId
		const mailbox = mainStore.getMailbox(originalMailboxId)
		if (!mailbox?.isItslVirtual) return null

		const tagFilter = getVirtualMailboxTagFilter(originalMailboxId, itslStore)
		if (!tagFilter) return null

		const originalQuery = args[0].query || ''
		args[0].mailboxId = 'unified'
		args[0].query = addTagToQuery(originalQuery, tagFilter)
		return { mailbox, originalQuery }
	}

	mainStore.$onAction(({ name, args, after }) => {
		if (name === 'fetchEnvelopes') {
			const ctx = getVirtualMailboxContext(args)
			if (!ctx) return

			after((envelopes) => {
				if (!envelopes?.length) return
				const listId = normalizedEnvelopeListId(ctx.originalQuery)
				vueSet(ctx.mailbox.envelopeLists, listId,
					envelopes.map(e => e.databaseId))
			})
		}

		if (name === 'syncEnvelopes') {
			const ctx = getVirtualMailboxContext(args)
			if (!ctx) return

			after((result) => {
				const envelopes = [result].flat(Infinity).filter(Boolean)
				if (!envelopes.length) return
				const listId = normalizedEnvelopeListId(ctx.originalQuery)
				const existing = ctx.mailbox.envelopeLists[listId] || []
				vueSet(ctx.mailbox.envelopeLists, listId,
					[...envelopes.map(e => e.databaseId), ...existing])
			})
		}

		if (name === 'fetchNextEnvelopes') {
			const ctx = getVirtualMailboxContext(args)
			if (!ctx) return

			after((envelopes) => {
				if (!envelopes?.length) return
				const listId = normalizedEnvelopeListId(ctx.originalQuery)
				const existing = ctx.mailbox.envelopeLists[listId] || []
				vueSet(ctx.mailbox.envelopeLists, listId,
					[...existing, ...envelopes.map(e => e.databaseId)])
			})
		}
	})
}

/**
 * Get tag filter for a virtual mailbox.
 * @param {string} mailboxId - Virtual mailbox ID
 * @param {object} itslStore - ITSL store instance
 * @return {string} Tag filter value (tag ID or 'none'), empty string if not applicable
 */
function getVirtualMailboxTagFilter(mailboxId, itslStore) {
	if (mailboxId === MY_MESSAGES_MAILBOX_ID) {
		const tag = itslStore.currentUserAssignmentTag
		return tag ? String(tag.id) : ''
	} else if (mailboxId === UNASSIGNED_MAILBOX_ID) {
		return 'none'
	}
	return ''
}

/**
 * Initialize ITSL virtual mailboxes (My Messages, Unassigned).
 *
 * Virtual mailboxes are sidebar navigation items that display filtered views of
 * the unified inbox. They stay on their own route (my-messages, unassigned) and
 * the filtering is handled by store action interception.
 *
 * Architecture:
 * - Router beforeEach hook sets virtualMailboxTag on entry
 * - $onAction intercepts fetchEnvelopes/syncEnvelopes → redirects to unified + tag filter
 * - searchMessagesMixin handles entry (drop assignment tags) and exit (restore tags)
 * - TagSearchIndicator derives display from virtualMailboxTag + searchQuery
 */

function initVirtualMailboxes() {
	const mainStore = useMainStore()

	// Inject virtual mailbox objects into sidebar (for navigation)
	injectVirtualMailboxes(mainStore)

	// Store action interceptor for virtual mailbox fetch operations
	initVirtualMailboxFetch()

	// Router hook tracks virtual mailbox entry (sets store state)
	initVirtualMailboxRouter()
}

/**
 * Router hook to track virtual mailbox entry/exit.
 *
 * Sets virtualMailboxTag when entering a virtual mailbox.
 * Does NOT redirect - the actual filtering is handled by $onAction interceptor.
 *
 * Note: Does NOT clear virtualMailboxTag when leaving - the mixin needs it for
 * cleanup (restoring dropped assignment tags, emitting search-changed).
 * The mixin uses a snapshot (_entryVirtualMailboxTag) to handle virtual-to-virtual
 * navigation where the store value is updated before the route watcher runs.
 */
function initVirtualMailboxRouter() {
	const itslStore = useItslStore()

	router.beforeEach((to, from, next) => {
		// Only handle mailbox routes
		if (to.name !== 'mailbox' && to.name !== 'message') {
			next()
			return
		}

		const toMailboxId = to.params.mailboxId
		const fromMailboxId = from.params?.mailboxId

		// Virtual-to-virtual navigation: clear old state before setting new
		// This happens BEFORE the mixin's route watcher runs, so the mixin
		// uses a snapshot pattern (_entryVirtualMailboxTag) for exit logic
		if (isVirtualMailbox(fromMailboxId) && isVirtualMailbox(toMailboxId)
			&& fromMailboxId !== toMailboxId) {
			itslStore.clearVirtualMailboxTag()
			itslStore.clearDroppedAssignmentTags()
		}

		// Reset hasFetchedInitialEnvelopes for virtual mailboxes
		// This ensures Mailbox.mounted() calls loadEnvelopes() on entry
		// (Similar to priority inbox fix in initMailboxLoadFix)
		if (isVirtualMailbox(toMailboxId) && !isVirtualMailbox(fromMailboxId)) {
			const mainStore = useMainStore()
			mainStore.setHasFetchedInitialEnvelopesMutation(false)
		}

		// Entering "My Messages" virtual mailbox
		if (toMailboxId === MY_MESSAGES_MAILBOX_ID) {
			const tag = itslStore.currentUserAssignmentTag
			if (tag) {
				const tagId = String(tag.id)
				const tagName = tag.displayName || tag.username || tagId
				itslStore.setVirtualMailboxTag(tagId, tagName)
			}
		} else if (toMailboxId === UNASSIGNED_MAILBOX_ID) {
			// Entering "Unassigned" virtual mailbox
			itslStore.setVirtualMailboxTag('none', t('mail', 'Unassigned'))
		}
		// NOTE: Do NOT clear virtualMailboxTag here when leaving - the mixin needs it for cleanup
		// The mixin's route watcher handles clearing after search restoration

		next() // Always proceed - no redirect
	})
}

/**
 * Check if a mailbox ID is a virtual mailbox.
 * @param {string} mailboxId - Mailbox ID to check
 * @return {boolean} True if virtual mailbox
 */
export function isVirtualMailbox(mailboxId) {
	return mailboxId === MY_MESSAGES_MAILBOX_ID || mailboxId === UNASSIGNED_MAILBOX_ID
}

/**
 * Inject virtual mailbox objects into mainStore.
 *
 * Critical settings for visibility and custom names:
 * - isUnified: false - Makes isUnifiedButOnlyInbox return true immediately
 * - specialUse: [] - Prevents MailboxTranslator from returning "All inboxes"
 * - displayName - Falls through as the display name
 * @param {object} mainStore - The main Pinia store instance
 */
function injectVirtualMailboxes(mainStore) {
	const myMessagesMailbox = {
		id: MY_MESSAGES_MAILBOX_ID,
		databaseId: MY_MESSAGES_MAILBOX_ID,
		accountId: UNIFIED_ACCOUNT_ID,
		isUnified: false, // MUST be false for visibility
		isItslVirtual: true, // ITSL marker
		specialUse: [], // MUST be empty for custom name
		specialRole: 'inbox',
		displayName: t('mail', 'My messages'),
		name: 'My messages',
		unread: 0,
		mailboxes: [],
		envelopeLists: {},
		attributes: ['\\subscribed'],
		path: '',
	}

	const unassignedMailbox = {
		id: UNASSIGNED_MAILBOX_ID,
		databaseId: UNASSIGNED_MAILBOX_ID,
		accountId: UNIFIED_ACCOUNT_ID,
		isUnified: false,
		isItslVirtual: true,
		specialUse: [],
		specialRole: 'inbox',
		displayName: t('mail', 'Unassigned'),
		name: 'Unassigned',
		unread: 0,
		mailboxes: [],
		envelopeLists: {},
		attributes: ['\\subscribed'],
		path: '',
	}

	// Add to mainStore.mailboxes
	vueSet(mainStore.mailboxes, MY_MESSAGES_MAILBOX_ID, myMessagesMailbox)
	vueSet(mainStore.mailboxes, UNASSIGNED_MAILBOX_ID, unassignedMailbox)

	// Prepend to unified account's mailbox list (after priority inbox)
	const unifiedAccount = mainStore.accountsUnmapped[UNIFIED_ACCOUNT_ID]
	if (unifiedAccount) {
		// Insert after priority inbox (index 1) but before unified inbox
		unifiedAccount.mailboxes.splice(1, 0, MY_MESSAGES_MAILBOX_ID, UNASSIGNED_MAILBOX_ID)
	}
}
