/**
 * SPDX-FileCopyrightText: 2026 ITSL AB
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import useItslStore from '../store/itslStore.js'
import { hiddenTags } from '../../components/tags.js'

/**
 * Get deduplicated tags from all accounts.
 * Each account has its own copy of tags, so we need to deduplicate by imapLabel.
 *
 * @return {Array} Deduplicated and sorted array of tags
 */
export function getDeduplicatedTags() {
	const itslStore = useItslStore()
	const allTags = Object.values(itslStore.tagsByAccount).flat()
	const seenLabels = new Set()
	return allTags
		.filter(tag => {
			if (seenLabels.has(tag.imapLabel)) return false
			seenLabels.add(tag.imapLabel)
			return true
		})
		.filter(tag => !(tag.displayName.toLowerCase() in hiddenTags))
		.sort((a, b) => {
			if (a.isDefaultTag && !b.isDefaultTag) return -1
			if (b.isDefaultTag && !a.isDefaultTag) return 1
			if (a.isDefaultTag && b.isDefaultTag) {
				return a.displayName < b.displayName ? 1 : -1
			}
			return a.displayName.localeCompare(b.displayName)
		})
}
