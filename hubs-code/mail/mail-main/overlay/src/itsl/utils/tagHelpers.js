/**
 * ITSL Tag Helper Utilities
 * Provides tag-related utility functions for components
 */

import useItslStore from '../store/itslStore.js'
import useMainStore from '../../store/mainStore.js'
import { hiddenTags } from '../../components/tags.js'
import { FOLLOW_UP_TAG_LABEL } from '../../store/constants.js'

/**
 * Get envelope tags with correct SDKMC colors for the account.
 * Uses itslStore.tagsByAccount to ensure account-specific tag colors.
 *
 * @param {number} accountId - The account ID
 * @param {number} databaseId - The envelope database ID
 * @param {boolean} isUnified - Whether the mailbox is unified
 * @return {Array} Array of tag objects with correct colors
 */
export function getEnvelopeTags(accountId, databaseId, isUnified) {
	const itslStore = useItslStore()
	const mainStore = useMainStore()

	// Access pending state directly for Vue reactivity tracking
	const pendingRemovals = itslStore.pendingTagRemovals
	const pendingAdditions = itslStore.pendingTagAdditions
	const envPendingRemovals = pendingRemovals[databaseId] || {}
	const envPendingAdditions = pendingAdditions[databaseId] || {}

	const accountTags = itslStore.getTagsForAccount(accountId)

	// Get imapLabels from envelope via mainStore, filtering out pending removals
	const envTagLabels = mainStore.getEnvelopeTags(databaseId)
		.map(t => t?.imapLabel)
		.filter(label => label && !(label in envPendingRemovals))

	// Build tag set including pending additions
	const tagLabelSet = new Set(envTagLabels)
	for (const label of Object.keys(envPendingAdditions)) {
		tagLabelSet.add(label)
	}

	// Return matching sdkmc tags (already correct colors)
	let tags = Array.from(tagLabelSet)
		.map(label => accountTags.find(t => t.imapLabel === label))
		.filter(tag => tag && tag.imapLabel !== '$label1'
			&& !(tag.displayName.toLowerCase() in hiddenTags))
		.sort((a, b) => a.displayName.localeCompare(b.displayName))

	if (isUnified) {
		tags = tags.filter((tag) => tag.imapLabel !== FOLLOW_UP_TAG_LABEL)
	}

	return tags
}
