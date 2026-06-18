const mockItslStore = {
	tagsByAccount: {},
}

vi.mock('../../store/itslStore.js', () => ({ default: vi.fn(() => mockItslStore) }))
vi.mock('@/components/tags.js', () => ({
	hiddenTags: { forwarded: '', hasattachment: '' },
}))

import { getDeduplicatedTags } from '../../utils/tagSearchUtils.js'

describe('tagSearchUtils - getDeduplicatedTags', () => {
	beforeEach(() => {
		mockItslStore.tagsByAccount = {}
	})

	it('deduplicates by imapLabel', () => {
		mockItslStore.tagsByAccount = {
			1: [{ imapLabel: '$tag_a', displayName: 'Alpha', isDefaultTag: false }],
			2: [{ imapLabel: '$tag_a', displayName: 'Alpha copy', isDefaultTag: false }],
		}

		const result = getDeduplicatedTags()

		expect(result).toHaveLength(1)
		expect(result[0].imapLabel).toBe('$tag_a')
	})

	it('excludes hidden tags', () => {
		mockItslStore.tagsByAccount = {
			1: [
				{ imapLabel: '$tag_visible', displayName: 'Visible', isDefaultTag: false },
				{ imapLabel: '$tag_fwd', displayName: 'Forwarded', isDefaultTag: false },
			],
		}

		const result = getDeduplicatedTags()

		expect(result).toHaveLength(1)
		expect(result[0].displayName).toBe('Visible')
	})

	it('sorts default tags first, then alphabetically', () => {
		mockItslStore.tagsByAccount = {
			1: [
				{ imapLabel: '$tag_z', displayName: 'Zebra', isDefaultTag: false },
				{ imapLabel: '$tag_def', displayName: 'Default', isDefaultTag: true },
				{ imapLabel: '$tag_a', displayName: 'Apple', isDefaultTag: false },
			],
		}

		const result = getDeduplicatedTags()

		expect(result[0].displayName).toBe('Default')
		expect(result[1].displayName).toBe('Apple')
		expect(result[2].displayName).toBe('Zebra')
	})

	it('returns empty result for empty tagsByAccount', () => {
		mockItslStore.tagsByAccount = {}

		const result = getDeduplicatedTags()

		expect(result).toEqual([])
	})

	it('sorts multiple default tags in reverse alphabetical order', () => {
		mockItslStore.tagsByAccount = {
			1: [
				{ imapLabel: '$def_a', displayName: 'Alpha Default', isDefaultTag: true },
				{ imapLabel: '$def_z', displayName: 'Zeta Default', isDefaultTag: true },
				{ imapLabel: '$tag_m', displayName: 'Middle', isDefaultTag: false },
			],
		}

		const result = getDeduplicatedTags()

		// Default tags first (reverse alpha per the comparator: a < b returns 1)
		expect(result[0].displayName).toBe('Zeta Default')
		expect(result[1].displayName).toBe('Alpha Default')
		expect(result[2].displayName).toBe('Middle')
	})
})
