/**
 * Tag query manipulation utilities.
 *
 * Handles adding/removing tag filters in search query strings.
 * Used by virtual mailbox fetch interception and search mixin.
 */

/**
 * Add a tag to a search query, handling comma-separated tag lists.
 * Returns unchanged query if tag already present.
 * @param {string} query - Current search query (e.g., "from:john tags:456")
 * @param {string} tagId - Tag ID to add (e.g., "123" or "none")
 * @return {string} Updated query
 */
export function addTagToQuery(query, tagId) {
	const tagsMatch = query.match(/tags:([^\s]+)/)
	if (tagsMatch) {
		const existingTags = decodeURIComponent(tagsMatch[1]).split(',')
		if (existingTags.includes(tagId)) {
			return query // Already present
		}
		existingTags.push(tagId)
		return query.replace(/tags:[^\s]+/, `tags:${encodeURI(existingTags.join(','))}`)
	}
	// No existing tags - add new tags param
	return query ? `${query} tags:${tagId}` : `tags:${tagId}`
}

/**
 * Remove a tag from a search query, handling comma-separated tag lists.
 * @param {string} query - Current search query
 * @param {string} tagId - Tag ID to remove
 * @return {string} Updated query with tag removed
 */
export function removeTagFromQuery(query, tagId) {
	const tagsMatch = query.match(/tags:([^\s]+)/)
	if (!tagsMatch) return query

	const existingTags = decodeURIComponent(tagsMatch[1]).split(',')
	const filteredTags = existingTags.filter(t => t !== tagId)

	if (filteredTags.length === 0) {
		// No tags left - remove entire tags: param
		return query.replace(/\s*tags:[^\s]+/, '').trim()
	}
	return query.replace(/tags:[^\s]+/, `tags:${encodeURI(filteredTags.join(','))}`)
}
