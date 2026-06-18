/**
 * Tag query translation - converts frontend tag IDs to backend imapLabels in search queries.
 *
 * The frontend uses tag.id for search (e.g., "tags:123,456") but the backend expects
 * imapLabels (e.g., "tags:$label1,$itsl_assigned_user"). This translator handles the
 * conversion, including cross-account tag resolution and the special 'none' marker.
 */

import useMainStore from '../../store/mainStore.js'
import useItslStore from '../store/itslStore.js'

/**
 * Translate tag IDs to imapLabels in an axios request config's filter param.
 *
 * Mutates config.params.filter in place. If a tag can't be resolved in the target
 * account, sets config.adapter to return empty results (no messages can match).
 *
 * @param {object} config Axios request config
 * @return {boolean} true if translation succeeded, false if a tag wasn't found
 */
export function translateTagQuery(config) {
	if (!config.params?.filter) return true

	const mainStore = useMainStore()
	const itslStore = useItslStore()

	// Get target account from mailboxId to find correct imapLabels
	const mailbox = mainStore.getMailbox(config.params.mailboxId)
	const accountTags = itslStore.tagsByAccount[mailbox?.accountId] || []

	let tagNotInAccount = false

	config.params.filter = config.params.filter.replace(
		/tags:([^\s]+)/g,
		(match, tagValue) => {
			if (tagNotInAccount) return match // Already failed, skip further processing

			const values = tagValue.split(',')
			const hasNone = values.includes('none')
			const convertedLabels = []

			for (const tagId of values) {
				if (tagId === 'none') {
					convertedLabels.push('none')
					continue
				}

				const tag = mainStore.tags[tagId]
				if (!tag) {
					// Unknown tag ID - can't find in target account
					tagNotInAccount = true
					return match
				}

				// Skip assignment tags when 'none' is present (don't add to query, don't fail)
				if (hasNone && tag.isAssignmentTag) {
					continue
				}

				// Find tag with same displayName in TARGET account
				const targetTag = accountTags.find(t => t.displayName === tag.displayName)
				if (!targetTag) {
					// Tag doesn't exist in target account - no messages can match (AND logic)
					tagNotInAccount = true
					return match
				}

				convertedLabels.push(targetTag.imapLabel)
			}

			// Dedupe and return
			return `tags:${[...new Set(convertedLabels)].join(',')}`
		},
	)

	// If any tag wasn't found in target account, return empty results via adapter
	if (tagNotInAccount) {
		config.adapter = () => Promise.resolve({
			data: [],
			status: 200,
			statusText: 'OK',
			headers: {},
			config,
		})
		return false
	}

	return true
}
