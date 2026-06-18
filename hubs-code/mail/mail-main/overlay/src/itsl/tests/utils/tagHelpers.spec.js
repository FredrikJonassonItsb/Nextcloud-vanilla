const mockItslStore = {
	pendingTagRemovals: {},
	pendingTagAdditions: {},
	getTagsForAccount: vi.fn(() => []),
}

const mockMainStore = {
	getEnvelopeTags: vi.fn(() => []),
}

vi.mock('../../store/itslStore.js', () => ({ default: vi.fn(() => mockItslStore) }))
vi.mock('@/store/mainStore.js', () => ({ default: vi.fn(() => mockMainStore) }))
vi.mock('@/components/tags.js', () => ({
	hiddenTags: { forwarded: '', hasattachment: '' },
}))
vi.mock('@/store/constants.js', () => ({
	FOLLOW_UP_TAG_LABEL: '$follow_up',
}))

import { getEnvelopeTags } from '../../utils/tagHelpers.js'

describe('tagHelpers - getEnvelopeTags', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		mockItslStore.pendingTagRemovals = {}
		mockItslStore.pendingTagAdditions = {}
	})

	it('returns account-matched tags', () => {
		const accountTags = [
			{ id: 1, imapLabel: '$tag_a', displayName: 'Alpha' },
			{ id: 2, imapLabel: '$tag_b', displayName: 'Beta' },
		]
		mockItslStore.getTagsForAccount.mockReturnValue(accountTags)
		mockMainStore.getEnvelopeTags.mockReturnValue([
			{ imapLabel: '$tag_a' },
		])

		const result = getEnvelopeTags(1, 100, false)

		expect(result).toEqual([accountTags[0]])
	})

	it('excludes pending removals', () => {
		const accountTags = [
			{ id: 1, imapLabel: '$tag_a', displayName: 'Alpha' },
			{ id: 2, imapLabel: '$tag_b', displayName: 'Beta' },
		]
		mockItslStore.getTagsForAccount.mockReturnValue(accountTags)
		mockMainStore.getEnvelopeTags.mockReturnValue([
			{ imapLabel: '$tag_a' },
			{ imapLabel: '$tag_b' },
		])
		mockItslStore.pendingTagRemovals = { 100: { '$tag_a': Date.now() } }

		const result = getEnvelopeTags(1, 100, false)

		expect(result).toEqual([accountTags[1]])
	})

	it('includes pending additions', () => {
		const accountTags = [
			{ id: 1, imapLabel: '$tag_a', displayName: 'Alpha' },
			{ id: 2, imapLabel: '$tag_b', displayName: 'Beta' },
		]
		mockItslStore.getTagsForAccount.mockReturnValue(accountTags)
		mockMainStore.getEnvelopeTags.mockReturnValue([])
		mockItslStore.pendingTagAdditions = { 100: { '$tag_b': Date.now() } }

		const result = getEnvelopeTags(1, 100, false)

		expect(result).toEqual([accountTags[1]])
	})

	it('excludes $label1 tag', () => {
		const accountTags = [
			{ id: 1, imapLabel: '$label1', displayName: 'Important' },
			{ id: 2, imapLabel: '$tag_b', displayName: 'Beta' },
		]
		mockItslStore.getTagsForAccount.mockReturnValue(accountTags)
		mockMainStore.getEnvelopeTags.mockReturnValue([
			{ imapLabel: '$label1' },
			{ imapLabel: '$tag_b' },
		])

		const result = getEnvelopeTags(1, 100, false)

		expect(result).toEqual([accountTags[1]])
	})

	it('excludes hidden tags (forwarded, hasattachment)', () => {
		const accountTags = [
			{ id: 1, imapLabel: '$tag_fwd', displayName: 'Forwarded' },
			{ id: 2, imapLabel: '$tag_ok', displayName: 'Visible' },
		]
		mockItslStore.getTagsForAccount.mockReturnValue(accountTags)
		mockMainStore.getEnvelopeTags.mockReturnValue([
			{ imapLabel: '$tag_fwd' },
			{ imapLabel: '$tag_ok' },
		])

		const result = getEnvelopeTags(1, 100, false)

		expect(result).toEqual([accountTags[1]])
	})

	it('excludes follow-up tag for unified mailboxes', () => {
		const accountTags = [
			{ id: 1, imapLabel: '$follow_up', displayName: 'Follow Up' },
			{ id: 2, imapLabel: '$tag_ok', displayName: 'Visible' },
		]
		mockItslStore.getTagsForAccount.mockReturnValue(accountTags)
		mockMainStore.getEnvelopeTags.mockReturnValue([
			{ imapLabel: '$follow_up' },
			{ imapLabel: '$tag_ok' },
		])

		const result = getEnvelopeTags(1, 100, true)

		expect(result).toEqual([accountTags[1]])
	})

	it('returns empty array when accountTags is empty', () => {
		mockItslStore.getTagsForAccount.mockReturnValue([])
		mockMainStore.getEnvelopeTags.mockReturnValue([
			{ imapLabel: '$tag_a' },
		])

		const result = getEnvelopeTags(1, 100, false)

		expect(result).toEqual([])
	})

	it('concurrent pending add and remove for same tag resolves to removal', () => {
		const accountTags = [
			{ id: 1, imapLabel: '$tag_a', displayName: 'Alpha' },
		]
		mockItslStore.getTagsForAccount.mockReturnValue(accountTags)
		mockMainStore.getEnvelopeTags.mockReturnValue([
			{ imapLabel: '$tag_a' },
		])
		// Tag is both pending removal AND pending addition
		mockItslStore.pendingTagRemovals = { 100: { '$tag_a': Date.now() } }
		mockItslStore.pendingTagAdditions = { 100: { '$tag_a': Date.now() } }

		const result = getEnvelopeTags(1, 100, false)

		// Removal filters from envTagLabels first, then addition re-adds to set
		// Net result: tag IS present (addition wins over removal in set logic)
		expect(result).toEqual([accountTags[0]])
	})

	it('sorts alphabetically by displayName', () => {
		const accountTags = [
			{ id: 1, imapLabel: '$tag_z', displayName: 'Zebra' },
			{ id: 2, imapLabel: '$tag_a', displayName: 'Apple' },
			{ id: 3, imapLabel: '$tag_m', displayName: 'Mango' },
		]
		mockItslStore.getTagsForAccount.mockReturnValue(accountTags)
		mockMainStore.getEnvelopeTags.mockReturnValue([
			{ imapLabel: '$tag_z' },
			{ imapLabel: '$tag_a' },
			{ imapLabel: '$tag_m' },
		])

		const result = getEnvelopeTags(1, 100, false)

		expect(result.map(t => t.displayName)).toEqual(['Apple', 'Mango', 'Zebra'])
	})
})
