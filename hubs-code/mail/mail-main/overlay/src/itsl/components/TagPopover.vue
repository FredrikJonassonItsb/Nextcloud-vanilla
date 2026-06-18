<!--
  - SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="tag-popover">
		<!-- Search bar -->
		<div class="tag-popover__search">
			<input v-model="searchQuery"
				class="tag-popover__search-input"
				type="text"
				:placeholder="searchPlaceholder"
				@keyup.enter="onSearchEnter">
		</div>

		<!-- Tag list (scrollable) -->
		<div class="tag-popover__list-container">
			<!-- Assignment tags section -->
			<div v-if="showAssignmentsSection && filteredAssignmentTags.length > 0" class="tag-popover__section">
				<div class="tag-popover__section-header">
					{{ t('mail', 'Assign to') }}
				</div>
				<div class="tag-popover__list">
					<TagPopoverItem v-for="tag in filteredAssignmentTags"
						:key="tag.id"
						:tag="tag"
						:envelopes="envelopes"
						:pending-state="pendingChanges[tag.imapLabel]"
						:can-manage-tags="canManageTags"
						:is-assignment-tag="true"
						:search-query="searchQuery"
						@toggle="togglePendingChange"
						@delete="confirmDelete" />
				</div>
			</div>

			<!-- Regular tags section -->
			<div v-if="showLabelsSection && filteredRegularTags.length > 0" class="tag-popover__section">
				<div class="tag-popover__section-header">
					{{ t('mail', 'Labels') }}
				</div>
				<div class="tag-popover__list">
					<TagPopoverItem v-for="tag in filteredRegularTags"
						:key="tag.id"
						:tag="tag"
						:envelopes="envelopes"
						:pending-state="pendingChanges[tag.imapLabel]"
						:can-manage-tags="canManageTags"
						:is-assignment-tag="false"
						:search-query="searchQuery"
						@toggle="togglePendingChange"
						@delete="confirmDelete" />
				</div>
			</div>

			<!-- Empty state (nothing to show based on filter mode) -->
			<div v-else-if="visibleTagCount === 0 && !searchQuery.trim()" class="tag-popover__empty">
				{{ emptyStateMessage }}
			</div>

			<!-- No search results -->
			<div v-else-if="visibleTagCount === 0 && searchQuery.trim()" class="tag-popover__empty">
				{{ t('mail', 'No matching tags') }}
			</div>
		</div>

		<!-- Divider (only show when there's a bottom action) -->
		<div v-if="hasPendingChanges || (canCreateTags && !hasExactMatch && searchQuery.trim())"
			class="tag-popover__divider" />

		<!-- Bottom action: Apply button OR create new tag option -->
		<div v-if="hasPendingChanges" class="tag-popover__apply">
			<NcButton variant="primary"
				wide
				@click="applyChanges">
				{{ t('mail', 'Apply') }}
			</NcButton>
		</div>

		<!-- Create new tag option (shown when search doesn't match existing tag, not in assignments mode) -->
		<div v-else-if="canCreateTags && !hasExactMatch && searchQuery.trim()"
			class="tag-popover__create-option"
			@click="createTagFromSearch">
			<div class="tag-popover__create-color" :style="{ backgroundColor: newTagColor }" />
			<span class="tag-popover__create-label">
				"<strong>{{ searchQuery.trim() }}</strong>" {{ t('mail', '(create new)') }}
			</span>
			<NcLoadingIcon v-if="creating" :size="20" />
		</div>

		<!-- Delete confirmation -->
		<div v-if="tagToDelete && canManageTags" class="tag-popover__delete-confirm">
			<span>{{ t('mail', 'Delete "{tagName}"?', { tagName: tagToDelete.displayName }) }}</span>
			<NcButton type="error"
				:disabled="deleting"
				@click="doDelete">
				<template #icon>
					<NcLoadingIcon v-if="deleting" :size="20" />
				</template>
				{{ t('mail', 'Yes') }}
			</NcButton>
			<NcButton variant="tertiary"
				:disabled="deleting"
				@click="tagToDelete = null">
				{{ t('mail', 'No') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { set as vueSet } from 'vue'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { showError } from '@nextcloud/dialogs'
import { getCurrentUser } from '@nextcloud/auth'
import TagPopoverItem from './TagPopoverItem.vue'
import { hiddenTags } from '../../components/tags.js'
import { mapStores } from 'pinia'
import useMainStore from '../../store/mainStore.js'
import useItslStore from '../store/itslStore.js'
import { setThreadTag, removeThreadTag } from '../services/ThreadTagService.js'

function randomColor() {
	// Generate colors with good contrast - either dark or saturated
	const strategy = Math.floor(Math.random() * 4)
	let r, g, b

	if (strategy === 0) {
		// Dark color: all channels low (00-77)
		r = Math.floor(Math.random() * 120)
		g = Math.floor(Math.random() * 120)
		b = Math.floor(Math.random() * 120)
	} else {
		// Saturated color: one dominant channel (CC-FF), others low (00-55)
		const dominant = Math.floor(Math.random() * 3)
		const high = 0xCC + Math.floor(Math.random() * 0x34) // CC-FF
		const low1 = Math.floor(Math.random() * 0x56) // 00-55
		const low2 = Math.floor(Math.random() * 0x56) // 00-55

		if (dominant === 0) {
			r = high; g = low1; b = low2
		} else if (dominant === 1) {
			r = low1; g = high; b = low2
		} else {
			r = low1; g = low2; b = high
		}
	}

	const toHex = (n) => n.toString(16).padStart(2, '0')
	return '#' + toHex(r) + toHex(g) + toHex(b)
}

export default {
	name: 'TagPopover',
	components: {
		NcButton,
		NcLoadingIcon,
		TagPopoverItem,
	},
	props: {
		envelopes: {
			type: Array,
			required: true,
		},
		/**
		 * Filter mode for the popover:
		 * - 'all': Show both assignments and labels sections (default)
		 * - 'assignments': Show only ASSIGN TO section, no tag creation
		 * - 'labels': Show only LABELS section, with tag creation
		 */
		filterMode: {
			type: String,
			default: 'all',
			validator: (value) => ['all', 'assignments', 'labels'].includes(value),
		},
	},
	emits: ['close'],
	data() {
		return {
			searchQuery: '',
			newTagName: '',
			newTagColor: randomColor(),
			creating: false,
			tagToDelete: null,
			deleting: false,
			pendingChanges: {}, // { imapLabel: true (add) | false (remove) }
		}
	},
	computed: {
		...mapStores(useMainStore, useItslStore),
		allTags() {
			const accountId = this.envelopes[0]?.accountId
			const accountTags = accountId ? this.itslStore.getTagsForAccount(accountId) : []

			// Also include tags that are on the envelopes but might not be in itslStore
			const allTagsMap = new Map()
			accountTags.forEach(tag => allTagsMap.set(tag.imapLabel, tag))

			// Add envelope tags that might be missing from itslStore
			this.envelopes.forEach(env => {
				this.mainStore.getEnvelopeTags(env.databaseId).forEach(tag => {
					if (tag && !allTagsMap.has(tag.imapLabel)) {
						allTagsMap.set(tag.imapLabel, tag)
					}
				})
			})

			return Array.from(allTagsMap.values())
				.filter((tag) => tag.imapLabel !== '$label1' && !(tag.displayName.toLowerCase() in hiddenTags))
		},
		assignmentTags() {
			const currentUserId = getCurrentUser()?.uid
			return this.allTags
				.filter((tag) => tag.isAssignmentTag === true)
				.sort((a, b) => {
					// Current user first
					if (a.username === currentUserId && b.username !== currentUserId) return -1
					if (b.username === currentUserId && a.username !== currentUserId) return 1

					// Applied tags second
					const aApplied = this.isTagApplied(a)
					const bApplied = this.isTagApplied(b)
					if (aApplied && !bApplied) return -1
					if (!aApplied && bApplied) return 1

					// Then alphabetically
					return a.displayName.localeCompare(b.displayName)
				})
		},
		regularTags() {
			return this.allTags
				.filter((tag) => tag.isAssignmentTag !== true)
				.sort((a, b) => {
					// Applied tags first
					const aApplied = this.isTagApplied(a)
					const bApplied = this.isTagApplied(b)
					if (aApplied && !bApplied) return -1
					if (!aApplied && bApplied) return 1

					// Then default tags
					if (a.isDefaultTag && !b.isDefaultTag) return -1
					if (b.isDefaultTag && !a.isDefaultTag) return 1

					// Then alphabetically
					return a.displayName.localeCompare(b.displayName)
				})
		},
		filteredAssignmentTags() {
			if (!this.searchQuery.trim()) return this.assignmentTags
			const query = this.searchQuery.toLowerCase().trim()
			return this.assignmentTags.filter(tag =>
				tag.displayName.toLowerCase().includes(query),
			)
		},
		filteredRegularTags() {
			if (!this.searchQuery.trim()) return this.regularTags
			const query = this.searchQuery.toLowerCase().trim()
			return this.regularTags.filter(tag =>
				tag.displayName.toLowerCase().includes(query),
			)
		},
		hasPendingChanges() {
			return Object.keys(this.pendingChanges).length > 0
		},
		canManageTags() {
			return this.itslStore.canManageTags
		},
		hasExactMatch() {
			const query = this.searchQuery.toLowerCase().trim()
			if (!query) return true // No search = don't show create option
			return this.allTags.some(tag =>
				tag.displayName.toLowerCase() === query
				|| tag.imapLabel.toLowerCase() === query,
			)
		},
		/**
		 * Whether to show the Assign To section
		 */
		showAssignmentsSection() {
			return this.filterMode === 'all' || this.filterMode === 'assignments'
		},
		/**
		 * Whether to show the Labels section
		 */
		showLabelsSection() {
			return this.filterMode === 'all' || this.filterMode === 'labels'
		},
		/**
		 * Whether tag creation is allowed (only for labels mode or all mode)
		 */
		canCreateTags() {
			return this.canManageTags && this.filterMode !== 'assignments'
		},
		/**
		 * Placeholder text for search input based on filter mode
		 */
		searchPlaceholder() {
			if (this.filterMode === 'assignments') {
				return this.t('mail', 'Search users...')
			}
			return this.canManageTags
				? this.t('mail', 'Search or create tag...')
				: this.t('mail', 'Search tags...')
		},
		/**
		 * Count of visible tags based on filter mode
		 */
		visibleTagCount() {
			let count = 0
			if (this.showAssignmentsSection) {
				count += this.filteredAssignmentTags.length
			}
			if (this.showLabelsSection) {
				count += this.filteredRegularTags.length
			}
			return count
		},
		/**
		 * Empty state message based on filter mode
		 */
		emptyStateMessage() {
			if (this.filterMode === 'assignments') {
				return this.t('mail', 'No users to assign')
			}
			if (this.filterMode === 'labels') {
				return this.t('mail', 'No tags yet. Create your first tag below.')
			}
			return this.t('mail', 'No tags yet. Create your first tag below.')
		},
	},
	mounted() {
		// Listen for outside clicks to close popover
		document.addEventListener('click', this.handleOutsideClick, true)
		// Also listen for clicks inside message iframes (same-origin)
		this.setupIframeClickListeners()
	},
	beforeDestroy() {
		document.removeEventListener('click', this.handleOutsideClick, true)
		this.cleanupIframeClickListeners()
	},
	methods: {
		setupIframeClickListeners() {
			this.iframeCleanupFns = []
			const iframes = document.querySelectorAll('iframe.message-frame')
			iframes.forEach(iframe => {
				try {
					const iframeDoc = iframe.contentDocument || iframe.contentWindow?.document
					if (iframeDoc) {
						const handler = () => this.$emit('close')
						iframeDoc.addEventListener('click', handler, true)
						this.iframeCleanupFns.push(() => {
							iframeDoc.removeEventListener('click', handler, true)
						})
					}
				} catch (e) {
					// Cross-origin iframe, can't add listener
				}
			})
		},
		cleanupIframeClickListeners() {
			if (this.iframeCleanupFns) {
				this.iframeCleanupFns.forEach(fn => fn())
				this.iframeCleanupFns = []
			}
		},
		handleOutsideClick(event) {
			// NcPopover teleports content to body inside .v-popper__popper
			// The trigger button stays in .v-popper (different element!)
			// We need to check both locations to determine if click is "inside"

			// 1. Check if click is inside ANY popover content (including nested ones like color picker)
			// This is important because child popovers (e.g., NcColorPicker) are also teleported to body
			if (event.target.closest('.v-popper__popper')) {
				return // Click inside a popover - don't close
			}

			// 2. Check if click is on any shown NcPopover trigger (like the + button)
			// This lets NcPopover's own handler toggle the popover
			if (event.target.closest('.v-popper.v-popper--shown')) {
				return // Click on trigger - let NcPopover handle toggle
			}

			// Click is truly outside both popover content and trigger
			this.$emit('close')
		},
		isTagApplied(tag) {
			// Access pending state directly for Vue reactivity tracking
			const pendingRemovals = this.itslStore.pendingTagRemovals
			const pendingAdditions = this.itslStore.pendingTagAdditions

			return this.envelopes.some(envelope => {
				const envPendingAdditions = pendingAdditions[envelope.databaseId] || {}
				const envPendingRemovals = pendingRemovals[envelope.databaseId] || {}

				// Check if pending addition (optimistic UI - tag being added)
				if (tag.imapLabel in envPendingAdditions) {
					return true
				}
				// Check if pending removal (optimistic UI - tag being removed)
				if (tag.imapLabel in envPendingRemovals) {
					return false
				}
				return this.mainStore.getEnvelopeTags(envelope.databaseId)
					.some(t => t.imapLabel === tag.imapLabel)
			})
		},
		getEffectiveState(tag) {
			// Returns the effective checked state considering pending changes
			const actualState = this.isTagApplied(tag)
			if (tag.imapLabel in this.pendingChanges) {
				return this.pendingChanges[tag.imapLabel]
			}
			return actualState
		},
		togglePendingChange(tag) {
			const actualState = this.isTagApplied(tag)
			const currentPending = this.pendingChanges[tag.imapLabel]

			if (currentPending === undefined) {
				// No pending change - add one (opposite of actual state)
				this.$set(this.pendingChanges, tag.imapLabel, !actualState)
			} else {
				// Calculate the new state after toggle
				const newState = !currentPending
				if (newState === actualState) {
					// New state equals actual state - no net change, remove pending
					this.$delete(this.pendingChanges, tag.imapLabel)
				} else {
					// Toggle the pending change
					this.$set(this.pendingChanges, tag.imapLabel, newState)
				}
			}
		},
		onSearchEnter() {
			// Combine both filtered lists for search behavior
			const allFiltered = [...this.filteredAssignmentTags, ...this.filteredRegularTags]
			// If search matches exactly one tag, toggle it
			if (allFiltered.length === 1) {
				this.togglePendingChange(allFiltered[0])
			} else if (allFiltered.length === 0 && this.searchQuery.trim()) {
				// No match - create new tag
				this.newTagName = this.searchQuery.trim()
				this.createTag()
			}
		},
		async applyChanges() {
			const changesToApply = { ...this.pendingChanges }
			const envelopesCopy = [...this.envelopes] // Copy for use after popover closes

			// Close popover IMMEDIATELY - don't clear pendingChanges here as it causes
			// visual glitch (Apply button disappears before popover closes).
			// Component will be recreated with fresh state when popoverKey increments.
			this.$emit('close')

			// Defer store mutations to next tick so popover closes before tag list re-renders
			setTimeout(() => {
				this.applyChangesDeferred(changesToApply, envelopesCopy)
			}, 0)
		},
		async applyChangesDeferred(changesToApply, envelopesCopy) {
			// Track timestamps and original state for rollback
			const timestamps = new Map() // Map<`${databaseId}-${imapLabel}`, { timestamp, isAddition }>
			const originalTags = new Map() // Map<databaseId, tagIds[]> for rollback

			// Get threadId from route - this is the envelope user clicked on (the representative)
			const threadId = parseInt(this.$route.params.threadId, 10)

			// STEP 1: Per-envelope × per-tag optimistic updates
			for (const [imapLabel, shouldAdd] of Object.entries(changesToApply)) {
				for (const envelope of envelopesCopy) {
					const key = `${envelope.databaseId}-${imapLabel}`
					if (shouldAdd) {
						const timestamp = this.itslStore.addPendingTagAddition(envelope.databaseId, imapLabel)
						timestamps.set(key, { timestamp, isAddition: true })

						// OPTIMISTIC: Add tag ID to envelope.tags so downstream reads see the new tag
						const tagObj = this.allTags.find(t => t.imapLabel === imapLabel)
						if (tagObj && !envelope.tags?.includes(tagObj.id)) {
							if (!originalTags.has(envelope.databaseId)) {
								originalTags.set(envelope.databaseId, [...(envelope.tags || [])])
							}
							vueSet(envelope, 'tags', [...(envelope.tags || []), tagObj.id])
						}

						// Add to tag search caches (only for threadId envelope = representative)
						if (envelope.databaseId === threadId) {
							this.itslStore.addToTagSearchLists(this.mainStore, envelope.databaseId, imapLabel)
						}
					} else {
						const timestamp = this.itslStore.addPendingTagRemoval(envelope.databaseId, imapLabel)
						timestamps.set(key, { timestamp, isAddition: false })

						// OPTIMISTIC: Remove tag ID from envelope.tags
						// Look up tag from allTags (includes itslStore tags) - don't rely on mainStore
						const tagObj = this.allTags.find(t => t.imapLabel === imapLabel)
						if (tagObj && envelope.tags?.includes(tagObj.id)) {
							if (!originalTags.has(envelope.databaseId)) {
								originalTags.set(envelope.databaseId, [...envelope.tags])
							}
							vueSet(envelope, 'tags', envelope.tags.filter(id => id !== tagObj.id))
						}

						// Remove from tag search caches (all envelopes - we don't know which is the representative)
						this.itslStore.removeFromTagSearchLists(this.mainStore, envelope.databaseId, imapLabel)
					}
				}
			}

			// STEP 2: Bulk API calls - one per tag operation
			try {
				const ids = envelopesCopy.map(env => env.databaseId)
				const promises = []
				const tagOperations = [] // Track which operations we're running

				for (const [imapLabel, shouldAdd] of Object.entries(changesToApply)) {
					if (shouldAdd) {
						promises.push(setThreadTag(ids, imapLabel))
						tagOperations.push({ imapLabel, shouldAdd: true })
					} else {
						promises.push(removeThreadTag(ids, imapLabel))
						tagOperations.push({ imapLabel, shouldAdd: false })
					}
				}
				const results = await Promise.all(promises)

				// STEP 3: Post-API side effects
				results.forEach((returnedTag, index) => {
					const { imapLabel, shouldAdd } = tagOperations[index]
					if (shouldAdd && returnedTag) {
						// Ensure tag exists in mainStore
						if (!this.mainStore.getTag(returnedTag.id)) {
							this.mainStore.addTagMutation({ tag: returnedTag })
						}
					}
					// Priority inbox update if $label1
					if (imapLabel === '$label1') {
						for (const envelope of envelopesCopy) {
							this.itslStore.updateEnvelopePriorityList(this.mainStore, envelope, shouldAdd)
						}
					}
					// Virtual mailbox update for assignment tag changes
					for (const envelope of envelopesCopy) {
						this.itslStore.updateVirtualMailboxLists(this.mainStore, envelope, imapLabel, shouldAdd)
					}
				})
				// SUCCESS: Do NOT clear pending state (persists as user intent)
			} catch (error) {
				// STEP 4: Rollback on error
				console.error('Tag update failed:', error)
				showError(this.t('mail', 'Failed to update tags'))
				// ROLLBACK: Restore envelope.tags and clear pending state
				for (const [imapLabel] of Object.entries(changesToApply)) {
					for (const envelope of envelopesCopy) {
						const key = `${envelope.databaseId}-${imapLabel}`
						const { timestamp, isAddition } = timestamps.get(key)
						// Restore envelope.tags to pre-optimistic state (for both add and remove)
						const original = originalTags.get(envelope.databaseId)
						if (original) {
							vueSet(envelope, 'tags', original)
						}
						if (isAddition) {
							this.itslStore.clearPendingTagAddition(envelope.databaseId, imapLabel, timestamp)
						} else {
							this.itslStore.clearPendingTagRemoval(envelope.databaseId, imapLabel, timestamp)
						}
					}
				}
			}
		},
		async createTagFromSearch() {
			// Use search query as the new tag name
			this.newTagName = this.searchQuery.trim()
			await this.createTag()
		},
		async createTag() {
			const displayName = this.newTagName.trim()
			if (!displayName) return

			if (displayName.toLowerCase() in hiddenTags) {
				showError(this.t('mail', 'Tag name is a hidden system tag'))
				return
			}

			const accountId = this.envelopes[0]?.accountId
			const accountTags = accountId ? this.itslStore.getTagsForAccount(accountId) : []
			if (accountTags.some(tag => tag.displayName === displayName)) {
				showError(this.t('mail', 'Tag already exists'))
				return
			}

			this.creating = true
			try {
				const newTag = await this.itslStore.createTag(this.mainStore, {
					displayName,
					color: this.newTagColor,
					accountId,
				})
				// Auto-check the newly created tag
				if (newTag?.imapLabel) {
					this.$set(this.pendingChanges, newTag.imapLabel, true)
				}
				this.newTagName = ''
				this.searchQuery = ''
				this.newTagColor = randomColor()
			} catch (error) {
				console.error(error)
				showError(this.t('mail', 'An error occurred, unable to create the tag.'))
			} finally {
				this.creating = false
			}
		},
		confirmDelete(tag) {
			this.tagToDelete = tag
		},
		async doDelete() {
			if (!this.tagToDelete) return

			this.deleting = true
			try {
				// Remove tag from all envelopes first
				for (const envelope of this.envelopes) {
					const envTags = this.mainStore.getEnvelopeTags(envelope.databaseId)
					if (envTags.some(t => t.id === this.tagToDelete.id)) {
						await this.mainStore.removeEnvelopeTag({
							envelope,
							imapLabel: this.tagToDelete.imapLabel,
						})
					}
				}

				// Then delete the tag
				await this.itslStore.deleteTag(this.mainStore, {
					tag: this.tagToDelete,
					accountId: this.envelopes[0].accountId,
				})
				this.tagToDelete = null
			} catch (error) {
				console.error(error)
				showError(this.t('mail', 'An error occurred, unable to delete the tag.'))
			} finally {
				this.deleting = false
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.tag-popover {
	width: 280px; // Fixed width to prevent resizing
	padding: 8px 0;
}

.tag-popover__search {
	padding: 4px 8px 8px;
}

.tag-popover__search-input {
	width: 100%;
	padding: 6px 10px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	box-sizing: border-box;

	&:focus {
		border-color: var(--color-primary-element);
		outline: none;
	}
}

.tag-popover__list-container {
	max-height: 250px;
	overflow-y: auto;
}

.tag-popover__section {
	margin-bottom: 8px;
}

.tag-popover__section-header {
	padding: 4px 12px;
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
	letter-spacing: 0.5px;
}

.tag-popover__list {
	display: flex;
	flex-direction: column;
}

.tag-popover__empty {
	padding: 16px;
	text-align: center;
	color: var(--color-text-maxcontrast);
}

.tag-popover__divider {
	height: 1px;
	background-color: var(--color-border);
	margin: 8px 0;
}

.tag-popover__create-option {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px;
	cursor: pointer;

	&:hover {
		background: var(--color-background-hover);
	}
}

.tag-popover__create-color {
	width: 16px;
	height: 16px;
	border-radius: 50%;
	flex-shrink: 0;
}

.tag-popover__create-label {
	flex: 1;
	cursor: pointer;
}

.tag-popover__apply {
	padding: 8px;
	margin-top: 4px;
	border-top: 1px solid var(--color-border);
}

.tag-popover__delete-confirm {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px;
	margin-top: 8px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);

	span {
		flex: 1;
	}
}
</style>
