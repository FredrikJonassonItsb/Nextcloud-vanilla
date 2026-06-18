<!--
  - SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
  - SPDX-FileCopyrightText: 2026 ITSL AB
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div v-if="displayInfo.visible" class="tag-search-indicator">
		<span class="tag-search-indicator__text">
			<template v-if="displayInfo.isUnassigned">
				{{ t('mail', 'Showing unassigned messages only') }}
			</template>
			<template v-else>
				{{ t('mail', 'Showing messages with tags:') }}
				<strong>{{ displayInfo.tagName }}</strong>
			</template>
		</span>
		<NcButton variant="tertiary"
			:aria-label="t('mail', 'Clear tag search')"
			@click="clearSearch">
			<template #icon>
				<Close :size="20" />
			</template>
		</NcButton>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import Close from 'vue-material-design-icons/Close.vue'
import { mapStores } from 'pinia'
import useItslStore from '../store/itslStore.js'
import { translateTagDisplayName } from '../../util/tag.js'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'TagSearchIndicator',
	components: {
		NcButton,
		Close,
	},
	props: {
		searchQuery: {
			type: String,
			default: '',
		},
	},
	emits: ['search-changed'],
	computed: {
		...mapStores(useItslStore),
		/**
		 * Derive display info from searchQuery prop.
		 * Always parses searchQuery to show ALL active tags, including in virtual mailboxes.
		 */
		displayInfo() {
			// Check if we have a tag search in the query
			if (!this.searchQuery?.includes('tags:')) {
				// Virtual mailbox with no tags in query yet (edge case during init)
				const virtualTag = this.itslStore.virtualMailboxTag
				if (virtualTag) {
					if (virtualTag.tagSearchValue === 'none') {
						return { visible: true, isUnassigned: true, tagName: null }
					}
					return { visible: true, isUnassigned: false, tagName: virtualTag.tagName }
				}
				return { visible: false, isUnassigned: false, tagName: null }
			}

			const tagMatch = this.searchQuery.match(/tags:([^\s]+)/)
			if (!tagMatch) {
				return { visible: false, isUnassigned: false, tagName: null }
			}

			const tagValue = decodeURIComponent(tagMatch[1])

			// Handle special "none" value for unassigned
			if (tagValue === 'none' || tagValue.split(',').includes('none')) {
				return { visible: true, isUnassigned: true, tagName: null }
			}

			// Find tag names for display - show ALL tags from searchQuery
			const tagValues = tagValue.split(',').filter(v => v !== 'none')
			const allTags = Object.values(this.itslStore.tagsByAccount).flat()
			const tagNames = tagValues.map(val => {
				const tag = allTags.find(t => t.imapLabel === val || String(t.id) === val)
				return tag ? translateTagDisplayName(tag) : val
			})

			return { visible: true, isUnassigned: false, tagName: tagNames.join(', ') }
		},
	},
	watch: {
		/**
		 * Handle search query from URL parameters.
		 * Used when clicking tags from sidebar or external links.
		 */
		'$route.query.search': {
			immediate: true,
			handler(searchQuery) {
				if (searchQuery) {
					// Emit search-changed to sync with parent's searchQuery
					this.$emit('search-changed', searchQuery)
					// Keep URL for a moment then clear to avoid cluttering browser history
					setTimeout(() => {
						if (this.$route.query.search) {
							this.$router.replace({
								...this.$route,
								query: { ...this.$route.query, search: undefined },
							})
						}
					}, 100)
				}
			},
		},
		// NOTE: Route watching for virtual mailbox exit is handled by searchMessagesMixin
		// TagSearchIndicator only handles display and the clear button click
	},
	methods: {
		t,
		/**
		 * Clear active tag search.
		 *
		 * For virtual mailboxes: clears custom search filters but STAYS in virtual mailbox.
		 * Signals mixin via store to set _virtualTagWasAdded=true.
		 * Clears droppedAssignmentTags so they won't be restored on exit.
		 *
		 * For regular tag searches: clears everything (parent handles display).
		 */
		clearSearch() {
			const virtualTag = this.itslStore.virtualMailboxTag

			if (virtualTag) {
				// Virtual mailbox: clear custom filters, stay in virtual mailbox
				// Force _virtualTagWasAdded=true so virtual tag is removed on exit
				this.itslStore.clearDroppedAssignmentTags() // For Unassigned: don't restore on exit
				this.$emit('search-changed', `tags:${virtualTag.tagSearchValue}`)
				this.itslStore.triggerVirtualSearchCleared() // Signal mixin via store
			} else {
				// Non-virtual: clear everything, no navigation (parent handles display)
				this.$emit('search-changed', '')
				this.itslStore.triggerVirtualSearchCleared() // Signal mixin to clear UI state
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.tag-search-indicator {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px;
	background-color: var(--color-primary-element-light);
	border-radius: var(--border-radius);
	margin-bottom: 8px;
}

.tag-search-indicator__text {
	flex: 1;
	font-size: var(--default-font-size);
	color: var(--color-main-text);
}
</style>
