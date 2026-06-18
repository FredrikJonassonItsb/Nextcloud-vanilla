/**
 * SPDX-FileCopyrightText: 2026 ITSL AB
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mapStores } from 'pinia'
import { t } from '@nextcloud/l10n'
import { getCurrentUser } from '@nextcloud/auth'
import useItslStore from '../store/itslStore.js'
import { getDeduplicatedTags } from '../utils/tagSearchUtils.js'
import { isVirtualMailbox } from '../utils/initITSL.js'
import { removeTagFromQuery, addTagToQuery } from '../utils/tagQueryUtils.js'
import { MY_MESSAGES_MAILBOX_ID, UNASSIGNED_MAILBOX_ID } from '../store/constants.js'

/**
 * Mixin for SearchMessages.vue that provides:
 * 1. Deduplicated tags (overrides component's tags computed via beforeCreate)
 * 2. Sync tag selection from sidebar clicks (watches itslStore.pendingSidebarTagClick)
 * 3. Handle virtual mailbox entry/exit (drop/restore assignment tags for Unassigned)
 * 4. Track whether virtual tag was added vs user already had it (_virtualTagWasAdded flag)
 */
export const searchMessagesMixin = {
	data() {
		return {
			// Flag: true if we added the virtual tag, false if user already had it
			_virtualTagWasAdded: false,
			// Flag: skip reset in virtualMailboxTag watcher during exit cleanup
			_skipResetOnTagClear: false,
			// Snapshot of virtualMailboxTag at entry time (critical for virtual-to-virtual navigation)
			_entryVirtualMailboxTag: null,
		}
	},
	mounted() {
		// Handle page reload when already in a virtual mailbox
		// router.beforeEach doesn't fire on initial page load, so we must
		// detect virtual mailbox from route and set virtualMailboxTag ourselves
		const mailboxId = this.$route?.params?.mailboxId
		let justSetVirtualMailboxTag = false

		// If we're on a virtual mailbox but virtualMailboxTag isn't set,
		// the router hook didn't fire (page reload/direct navigation)
		if (isVirtualMailbox(mailboxId) && !this.itslStore.virtualMailboxTag) {
			// Set virtualMailboxTag - same logic as router hook
			// This triggers the watcher which handles entry setup
			if (mailboxId === MY_MESSAGES_MAILBOX_ID) {
				const tag = this.itslStore.currentUserAssignmentTag
				if (tag) {
					const tagId = String(tag.id)
					const tagName = tag.displayName || tag.username || tagId
					this.itslStore.setVirtualMailboxTag(tagId, tagName)
					justSetVirtualMailboxTag = true
				}
			} else if (mailboxId === UNASSIGNED_MAILBOX_ID) {
				this.itslStore.setVirtualMailboxTag('none', t('mail', 'Unassigned'))
				justSetVirtualMailboxTag = true
			}
		}

		// Handle entry setup for router navigation case (virtualMailboxTag was already set)
		// Skip if we just set it above - the watcher already handled entry
		const virtualTag = this.itslStore.virtualMailboxTag
		if (virtualTag && !justSetVirtualMailboxTag) {
			const currentQuery = this.searchQuery || ''
			const existingTags = this.parseTagsFromQuery(currentQuery)
			this._virtualTagWasAdded = !existingTags.includes(virtualTag.tagSearchValue)

			// Capture snapshot for exit logic
			this._entryVirtualMailboxTag = virtualTag

			// Add virtual tag to selectedTags so UI reflects the active search
			this.addVirtualTagToSelectedTags()
		}

		// For page reload case, just capture snapshot and emit search-changed
		// (watcher already set _virtualTagWasAdded and added tag to selectedTags)
		if (virtualTag && justSetVirtualMailboxTag) {
			this._entryVirtualMailboxTag = virtualTag

			// Emit search-changed to sync MailboxThread's searchQuery
			this.$nextTick(() => {
				this.$emit('search-changed', this.searchQuery)
			})
		}
	},
	computed: {
		...mapStores(useItslStore),
	},
	beforeCreate() {
		// Override the component's tags computed with ITSL deduplicated version
		// This runs before Vue processes computed properties
		if (this.$options.computed?.tags) {
			this.$options.computed.tags = function() {
				return getDeduplicatedTags()
			}
		}
	},
	watch: {
		/**
		 * Watch virtualMailboxTag for entry detection and safety net exit.
		 */
		'itslStore.virtualMailboxTag': {
			handler(newTag, oldTag) {
				// ENTRY: tag was just set
				if (newTag && !oldTag) {
					const currentQuery = this.searchQuery || ''
					const existingTags = this.parseTagsFromQuery(currentQuery)
					this._virtualTagWasAdded = !existingTags.includes(newTag.tagSearchValue)

					// Add virtual tag to selectedTags (for My Messages)
					// For Unassigned, $onAction interceptor handles the query override
					this.addVirtualTagToSelectedTags()

					// Emit updated searchQuery so TagSearchIndicator shows all tags
					// For router navigation, this is the only emit; for page reload, mounted() also emits
					// (but with same value, so idempotent)
					this.$nextTick(() => {
						this.$emit('search-changed', this.searchQuery)
					})
				}

				// EXIT: tag was just cleared (safety net)
				if (!newTag && oldTag && !this._skipResetOnTagClear) {
					this.resetFiltersWithoutEmit()
				}
			},
		},
		/**
		 * Watch pendingSidebarTagClick for sidebar tag clicks.
		 * Sidebar clicks do NOT navigate - they just signal via store.
		 */
		'itslStore.pendingSidebarTagClick': {
			handler(tagClick) {
				if (!tagClick) return

				const { imapLabel } = tagClick
				this.itslStore.clearSidebarTagClick()

				const virtualTag = this.itslStore.virtualMailboxTag

				// Sync form state (clears filters, sets single tag)
				this.syncTagFromExternal(imapLabel)

				if (virtualTag) {
					// In virtual mailbox: re-add virtual tag
					const virtualTagValue = virtualTag.tagSearchValue

					// Check if clicked tag matches virtual mailbox tag (for My Messages)
					const clickedTag = this.tags.find(t => t.imapLabel === imapLabel)
					let clickedTagMatchesVirtual = false
					if (virtualTagValue !== 'none' && clickedTag?.isAssignmentTag) {
						// My Messages uses current user's assignment tag
						// Match if clicked tag is also current user's assignment tag (from any account)
						const currentUser = getCurrentUser()?.uid
						if (currentUser && clickedTag.username === currentUser) {
							clickedTagMatchesVirtual = true
						}
					}

					if (virtualTagValue === 'none' && this.isAssignmentTag(imapLabel)) {
						// Clicking assignment tag in Unassigned: conflict
						// selectedTags has the clicked tag, but query override keeps showing 'none'
						this._virtualTagWasAdded = false
					} else if (clickedTagMatchesVirtual) {
						// Clicking the same tag as virtual mailbox (current user's assignment tag)
						this._virtualTagWasAdded = false
					} else {
						// Add virtual tag back
						const tag = this.tags.find(t =>
							t.imapLabel === virtualTagValue || String(t.id) === virtualTagValue,
						)
						if (tag && !this.selectedTags.some(t => t.imapLabel === tag.imapLabel)) {
							this.selectedTags.push(tag)
						}
						this._virtualTagWasAdded = true
					}
				}

				// Use $nextTick to ensure Vue reactivity has updated searchQuery computed
				// before emitting (selectedTags mutations need to propagate first)
				this.$nextTick(() => {
					this.$emit('search-changed', this.searchQuery)
				})
			},
		},
		/**
		 * Watch virtualSearchClearedSignal for X button clicks in virtual mailbox.
		 * Sets _virtualTagWasAdded=true so virtual tag is removed on exit.
		 * Also syncs UI state to show only the virtual tag filter.
		 * Uses store signaling because TagSearchIndicator cannot communicate
		 * directly with the mixin (MailboxThread.vue is outside itsl/).
		 */
		'itslStore.virtualSearchClearedSignal': {
			handler(signal) {
				if (!signal) return

				// Clear the signal immediately
				this.itslStore.clearVirtualSearchClearedSignal()

				// Only process if we're in a virtual mailbox
				if (this.itslStore.virtualMailboxTag) {
					this._virtualTagWasAdded = true

					// Sync UI state to match the cleared search
					const virtualTag = this.itslStore.virtualMailboxTag
					if (virtualTag.tagSearchValue !== 'none') {
						// For My Messages: set selectedTags to only the virtual tag
						const tag = this.tags.find(t =>
							t.imapLabel === virtualTag.tagSearchValue || String(t.id) === virtualTag.tagSearchValue,
						)
						this.selectedTags = tag ? [tag] : []
					} else {
						// For Unassigned: selectedTags should be empty (none handled by interceptor)
						this.selectedTags = []
					}

					// Clear other filter state per Flow 9/27 in docs
					this.query = ''
					this.searchInFrom = []
					this.searchInTo = []
					this.searchInCc = []
					this.searchInBcc = []
					this.searchInSubject = null
					this.searchInMessageBody = null
					this.searchFlags = []
					this.startDate = null
					this.endDate = null
					this.mentionsMe = false
				} else {
					// Non-virtual: reset ALL filters
					this.resetFiltersWithoutEmit()
				}
			},
		},
		/**
		 * Handle virtual mailbox entry/exit via route changes.
		 *
		 * Uses snapshot pattern (_entryVirtualMailboxTag) to handle virtual-to-virtual navigation,
		 * where the router beforeEach hook updates virtualMailboxTag before this watcher runs.
		 *
		 * ENTERING Unassigned: drop assignment tags from search (they conflict with 'none').
		 * EXITING any virtual mailbox: restore dropped tags, remove virtual tag if we added it.
		 */
		'$route.params.mailboxId': {
			handler(newMailboxId, oldMailboxId) {
				// Use SNAPSHOT for exit (not current store value - critical for virtual-to-virtual)
				const virtualTag = this._entryVirtualMailboxTag
				const wasInVirtual = virtualTag && isVirtualMailbox(oldMailboxId)
				const goingToVirtual = isVirtualMailbox(newMailboxId)

				// EXIT: leaving any virtual mailbox
				if (wasInVirtual) {
					this._handleVirtualMailboxExit(oldMailboxId, newMailboxId, virtualTag)
				}

				// ENTERING Unassigned: drop assignment tags from search
				if (newMailboxId === UNASSIGNED_MAILBOX_ID && oldMailboxId !== UNASSIGNED_MAILBOX_ID) {
					const currentQuery = this.searchQuery || ''
					const { cleanedQuery, droppedTags } = this.removeAssignmentTagsFromQuery(currentQuery)

					if (droppedTags.length > 0) {
						this.itslStore.setDroppedAssignmentTags(droppedTags)
						// Sync selectedTags - remove assignment tags to match cleaned query
						this.selectedTags = this.selectedTags.filter(tag =>
							!droppedTags.includes(String(tag.id)),
						)
						this.$emit('search-changed', cleanedQuery)
					}
				}

				// ENTRY: capture snapshot and run entry logic
				if (goingToVirtual) {
					// For virtual-to-virtual, the virtualMailboxTag watcher doesn't fire entry case
					// (Vue coalesces oldTag→newTag, so neither entry nor exit condition is true)
					// Run entry logic explicitly after exit cleanup
					if (wasInVirtual) {
						this._handleVirtualMailboxEntry()
					}

					// Capture snapshot for later exit
					this.$nextTick(() => {
						this._entryVirtualMailboxTag = this.itslStore.virtualMailboxTag
					})
				} else {
					this._entryVirtualMailboxTag = null
				}
			},
		},
		/**
		 * Watch selectedTags to restore virtual tag when cleared via upstream search bar X.
		 * This ensures both X buttons (tag bar and search bar) behave identically in virtual mailboxes.
		 */
		selectedTags: {
			handler(newTags) {
				// If selectedTags is empty while in virtual mailbox, restore virtual tag
				if (newTags?.length === 0 && this.itslStore.virtualMailboxTag) {
					this.$nextTick(() => {
						// Only restore if still empty (avoid race conditions)
						if (this.selectedTags?.length === 0) {
							this.addVirtualTagToSelectedTags()
							this._virtualTagWasAdded = true
						}
					})
				}
			},
		},
	},
	methods: {
		/**
		 * Parse tag IDs from a search query.
		 * @param {string} query - Search query
		 * @return {string[]} Array of tag IDs/values
		 */
		parseTagsFromQuery(query) {
			if (!query?.includes('tags:')) return []
			const match = query.match(/tags:([^\s]+)/)
			if (!match) return []
			return decodeURIComponent(match[1]).split(',')
		},
		/**
		 * Check if a tag is an assignment tag.
		 * @param {string} imapLabel - Tag imapLabel or ID
		 * @return {boolean}
		 */
		isAssignmentTag(imapLabel) {
			const allTags = Object.values(this.itslStore.tagsByAccount).flat()
			const tag = allTags.find(t => t.imapLabel === imapLabel || String(t.id) === imapLabel)
			return tag?.isAssignmentTag === true
		},
		/**
		 * Add virtual tag to selectedTags (for My Messages only).
		 * For Unassigned, we don't add 'none' to selectedTags - the $onAction interceptor handles it.
		 */
		addVirtualTagToSelectedTags() {
			const virtualTag = this.itslStore.virtualMailboxTag
			if (!virtualTag) return

			const tagValue = virtualTag.tagSearchValue
			if (tagValue === 'none') {
				// For Unassigned, we don't add 'none' to selectedTags
				// The $onAction interceptor handles adding tags:none to queries
				return
			}

			// For My Messages, add the virtual tag to selectedTags
			const tag = this.tags.find(t =>
				t.imapLabel === tagValue || String(t.id) === tagValue,
			)
			if (tag && !this.selectedTags.some(t => t.imapLabel === tag.imapLabel)) {
				this.selectedTags.push(tag)
			}
		},
		/**
		 * Set _virtualTagWasAdded flag.
		 * Called by virtualSearchClearedSignal watcher when X button is clicked.
		 * Also exposed as public API for potential future use.
		 * @param {boolean} value - The new value for the flag
		 */
		setVirtualTagWasAdded(value) {
			this._virtualTagWasAdded = value
		},
		/**
		 * Handle exit from a virtual mailbox.
		 * @param {string} oldMailboxId - The mailbox being exited
		 * @param {string} newMailboxId - The mailbox being entered
		 * @param {object} virtualTag - The virtual mailbox tag (from snapshot)
		 */
		_handleVirtualMailboxExit(oldMailboxId, newMailboxId, virtualTag) {
			this._skipResetOnTagClear = true

			let newQuery = this.searchQuery || ''

			// Restore dropped assignment tags for Unassigned
			if (oldMailboxId === UNASSIGNED_MAILBOX_ID) {
				const droppedTags = this.itslStore.droppedAssignmentTags
				if (droppedTags.length > 0) {
					newQuery = this.restoreAssignmentTags(newQuery, droppedTags)
					// Sync selectedTags - restore assignment tags to match query
					const allTags = Object.values(this.itslStore.tagsByAccount).flat()
					for (const tagId of droppedTags) {
						const tag = allTags.find(t => String(t.id) === tagId)
						if (tag && !this.selectedTags.some(t => String(t.id) === tagId)) {
							this.selectedTags.push(tag)
						}
					}
				}
			}

			// Remove virtual tag from query and selectedTags
			// For Unassigned: ALWAYS remove 'none' (it's never a user tag)
			// For My Messages: only remove if we added it (_virtualTagWasAdded=true)
			if (oldMailboxId === UNASSIGNED_MAILBOX_ID) {
				// Step 6: Always remove 'none' from query when exiting Unassigned
				newQuery = removeTagFromQuery(newQuery, 'none')
			} else if (this._virtualTagWasAdded) {
				// For My Messages: remove virtual tag if we added it
				newQuery = removeTagFromQuery(newQuery, virtualTag.tagSearchValue)
				// Also sync selectedTags
				this._removeVirtualTagFromSelectedTags(virtualTag.tagSearchValue)
			}

			// Clear state ONLY if NOT going to another virtual mailbox
			// For virtual-to-virtual, the router already set the new virtualMailboxTag
			if (!isVirtualMailbox(newMailboxId)) {
				this.itslStore.clearVirtualMailboxTag()
				this._entryVirtualMailboxTag = null // Clear snapshot
				this._virtualTagWasAdded = false // Reset flag for clean state
			}
			this.itslStore.clearDroppedAssignmentTags()

			this.$emit('search-changed', newQuery)

			this.$nextTick(() => {
				this._skipResetOnTagClear = false
			})
		},
		/**
		 * Handle entry to a virtual mailbox (for virtual-to-virtual navigation).
		 * Sets _virtualTagWasAdded flag and adds virtual tag to selectedTags.
		 * Called explicitly because Vue coalesces virtualMailboxTag watcher for virtual-to-virtual.
		 */
		_handleVirtualMailboxEntry() {
			const virtualTag = this.itslStore.virtualMailboxTag
			if (!virtualTag) return

			const currentQuery = this.searchQuery || ''
			const existingTags = this.parseTagsFromQuery(currentQuery)
			this._virtualTagWasAdded = !existingTags.includes(virtualTag.tagSearchValue)
			this.addVirtualTagToSelectedTags()
		},
		/**
		 * Remove virtual tag from selectedTags array.
		 * Called during exit to sync selectedTags with the updated query.
		 * @param {string} tagValue - The tag value to remove (imapLabel or ID)
		 */
		_removeVirtualTagFromSelectedTags(tagValue) {
			const tagIndex = this.selectedTags.findIndex(t =>
				t.imapLabel === tagValue || String(t.id) === tagValue,
			)
			if (tagIndex !== -1) {
				this.selectedTags.splice(tagIndex, 1)
			}
		},
		/**
		 * Sync tag selection from external source (sidebar click).
		 * Clears other filters and sets the specified tag as selected.
		 * @param {string} imapLabel - The IMAP label of the tag to select
		 */
		syncTagFromExternal(imapLabel) {
			this.query = ''
			this.searchInFrom = []
			this.searchInTo = []
			this.searchInCc = []
			this.searchInBcc = []
			this.searchInSubject = null
			this.searchInMessageBody = null
			this.searchFlags = []
			this.startDate = null
			this.endDate = null
			this.mentionsMe = false
			const tag = this.tags.find(t => t.imapLabel === imapLabel)
			if (tag) {
				this.selectedTags = [tag]
			}
		},
		/**
		 * Reset all filters without emitting search-changed.
		 * Used as safety net when virtualMailboxTag is cleared.
		 */
		resetFiltersWithoutEmit() {
			this.match = 'allof'
			this.query = ''
			this.selectedTags = []
			this.searchInFrom = []
			this.searchInTo = []
			this.searchInCc = []
			this.searchInBcc = []
			this.searchInSubject = null
			this.searchInMessageBody = null
			this.searchFlags = []
			this.startDate = null
			this.endDate = null
			this.mentionsMe = false
		},
		/**
		 * Remove assignment tags from a search query.
		 * Used when entering Unassigned mailbox - assignment tags conflict with 'none'.
		 * @param {string} query - Current search query
		 * @return {object} { cleanedQuery, droppedTags }
		 */
		removeAssignmentTagsFromQuery(query) {
			const allTags = Object.values(this.itslStore.tagsByAccount).flat()
			const assignmentTagIds = allTags.filter(t => t.isAssignmentTag).map(t => String(t.id))

			const tagsMatch = query.match(/tags:([^\s]+)/)
			if (!tagsMatch) return { cleanedQuery: query, droppedTags: [] }

			const existingTags = decodeURIComponent(tagsMatch[1]).split(',')
			const droppedTags = existingTags.filter(id => assignmentTagIds.includes(id))
			const remainingTags = existingTags.filter(id => !assignmentTagIds.includes(id))

			let cleanedQuery = query
			if (remainingTags.length === 0) {
				cleanedQuery = query.replace(/\s*tags:[^\s]+/, '').trim()
			} else {
				cleanedQuery = query.replace(/tags:[^\s]+/, `tags:${remainingTags.join(',')}`)
			}

			return { cleanedQuery, droppedTags }
		},
		/**
		 * Restore previously dropped assignment tags to a query.
		 * Used when exiting Unassigned mailbox.
		 * @param {string} query - Current search query
		 * @param {string[]} tagIds - Tag IDs to restore
		 * @return {string} Updated query
		 */
		restoreAssignmentTags(query, tagIds) {
			if (!tagIds.length) return query
			// Add each tag back using the helper
			let result = query
			for (const tagId of tagIds) {
				result = addTagToQuery(result, tagId)
			}
			return result
		},
	},
}
