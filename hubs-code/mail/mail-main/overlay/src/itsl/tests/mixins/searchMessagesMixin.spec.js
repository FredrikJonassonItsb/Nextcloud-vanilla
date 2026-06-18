vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: vi.fn(() => ({ uid: 'testuser' })), getRequestToken: vi.fn(), onRequestTokenUpdate: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ t: (app, str) => str, translate: (app, str) => str }))
vi.mock('../../utils/tagSearchUtils.js', () => ({ getDeduplicatedTags: vi.fn(() => []) }))
vi.mock('../../utils/tagQueryUtils.js', () => ({
	addTagToQuery: vi.fn((q, id) => (q ? `${q} tags:${id}` : `tags:${id}`)),
	removeTagFromQuery: vi.fn((q, id) => q.replace(new RegExp(`\\s*tags:${id}`), '').trim()),
}))
vi.mock('../../utils/initITSL.js', () => ({
	isVirtualMailbox: vi.fn(() => false),
}))

import { shallowMount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import useItslStore from '../../store/itslStore.js'
import { searchMessagesMixin } from '../../mixins/searchMessagesMixin.js'
import * as tagQueryUtils from '../../utils/tagQueryUtils.js'
import * as tagSearchUtils from '../../utils/tagSearchUtils.js'
import { createTag, createAssignmentTag, createTagsByAccount } from '../fixtures/tags.js'

// Minimal host component that exposes the mixin's data + methods
const HostComponent = {
	mixins: [searchMessagesMixin],
	template: '<div />',
	data() {
		return {
			match: 'allof',
			query: '',
			selectedTags: [],
			searchInFrom: [],
			searchInTo: [],
			searchInCc: [],
			searchInBcc: [],
			searchInSubject: null,
			searchInMessageBody: null,
			searchFlags: [],
			startDate: null,
			endDate: null,
			mentionsMe: false,
			searchQuery: '',
		}
	},
	computed: {
		tags() { return [] },
	},
}

function mountHost(opts = {}) {
	return shallowMount(HostComponent, {
		mocks: {
			$route: { params: { mailboxId: 'inbox-1' } },
			t: (app, str) => str,
			...opts.mocks,
		},
		...opts,
	})
}

afterEach(() => {
	vi.restoreAllMocks()
})

describe('searchMessagesMixin', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useItslStore()
		store.tagsByAccount = createTagsByAccount([1, 2])
	})

	// -- parseTagsFromQuery --------------------------------------------------

	describe('parseTagsFromQuery', () => {
		it('extracts tag IDs from query string', () => {
			const wrapper = mountHost()
			expect(wrapper.vm.parseTagsFromQuery('from:john tags:10,20')).toEqual(['10', '20'])
		})

		it('returns empty array for missing tags param', () => {
			const wrapper = mountHost()
			expect(wrapper.vm.parseTagsFromQuery('')).toEqual([])
			expect(wrapper.vm.parseTagsFromQuery('from:john')).toEqual([])
			expect(wrapper.vm.parseTagsFromQuery(null)).toEqual([])
		})
	})

	// -- isAssignmentTag -----------------------------------------------------

	describe('isAssignmentTag', () => {
		it('returns true for assignment tag imapLabel', () => {
			const wrapper = mountHost()
			expect(wrapper.vm.isAssignmentTag('$assignee_testuser')).toBe(true)
		})

		it('returns false for regular tag imapLabel', () => {
			const wrapper = mountHost()
			expect(wrapper.vm.isAssignmentTag('$tag_shared')).toBe(false)
		})

		it('returns false for unknown imapLabel', () => {
			const wrapper = mountHost()
			expect(wrapper.vm.isAssignmentTag('$nonexistent')).toBe(false)
		})
	})

	// -- removeAssignmentTagsFromQuery ---------------------------------------

	describe('removeAssignmentTagsFromQuery', () => {
		it('removes assignment tag IDs and returns them as droppedTags', () => {
			const wrapper = mountHost()
			// Use actual assignment tag IDs from the fixture (102 and 202)
			const query = 'from:john tags:100,102'
			const { cleanedQuery, droppedTags } = wrapper.vm.removeAssignmentTagsFromQuery(query)
			expect(droppedTags).toEqual(['102'])
			expect(cleanedQuery).toContain('100')
			expect(cleanedQuery).not.toContain('102')
		})

		it('preserves non-assignment tags unchanged', () => {
			const wrapper = mountHost()
			const query = 'tags:100,101'
			const { cleanedQuery, droppedTags } = wrapper.vm.removeAssignmentTagsFromQuery(query)
			expect(droppedTags).toEqual([])
			expect(cleanedQuery).toBe(query)
		})

		it('returns original query when no tags param exists', () => {
			const wrapper = mountHost()
			const { cleanedQuery, droppedTags } = wrapper.vm.removeAssignmentTagsFromQuery('from:john')
			expect(cleanedQuery).toBe('from:john')
			expect(droppedTags).toEqual([])
		})
	})

	// -- restoreAssignmentTags -----------------------------------------------

	describe('restoreAssignmentTags', () => {
		it('adds dropped tag IDs back to query', () => {
			const { addTagToQuery } = tagQueryUtils
			const wrapper = mountHost()
			const result = wrapper.vm.restoreAssignmentTags('tags:100', ['102', '202'])
			expect(addTagToQuery).toHaveBeenCalledWith('tags:100', '102')
			expect(result).toContain('tags:')
		})

		it('returns unchanged query when tagIds is empty', () => {
			const wrapper = mountHost()
			expect(wrapper.vm.restoreAssignmentTags('tags:100', [])).toBe('tags:100')
		})
	})

	// -- resetFiltersWithoutEmit ---------------------------------------------

	describe('resetFiltersWithoutEmit', () => {
		it('zeroes all filter state', () => {
			const wrapper = mountHost()
			// Dirty the state
			wrapper.setData({
				match: 'anyof',
				query: 'hello',
				selectedTags: [{ id: 1 }],
				searchInFrom: ['a@b.com'],
				startDate: new Date(),
				mentionsMe: true,
			})

			wrapper.vm.resetFiltersWithoutEmit()

			expect(wrapper.vm.match).toBe('allof')
			expect(wrapper.vm.query).toBe('')
			expect(wrapper.vm.selectedTags).toEqual([])
			expect(wrapper.vm.searchInFrom).toEqual([])
			expect(wrapper.vm.startDate).toBeNull()
			expect(wrapper.vm.mentionsMe).toBe(false)
		})
	})

	// -- syncTagFromExternal -------------------------------------------------

	describe('syncTagFromExternal', () => {
		it('clears filters and sets single tag from imapLabel', () => {
			const tag = createTag({ id: 50, imapLabel: '$label_50' })
			const { getDeduplicatedTags } = tagSearchUtils
			getDeduplicatedTags.mockReturnValue([tag])

			const wrapper = mountHost()
			wrapper.setData({ query: 'old query', mentionsMe: true })

			wrapper.vm.syncTagFromExternal('$label_50')

			expect(wrapper.vm.query).toBe('')
			expect(wrapper.vm.mentionsMe).toBe(false)
			expect(wrapper.vm.selectedTags).toEqual([tag])
		})
	})

	// -- Virtual mailbox entry/exit ------------------------------------------

	describe('virtual mailbox state', () => {
		it('_handleVirtualMailboxEntry sets _virtualTagWasAdded when tag absent from query', () => {
			const tag = createAssignmentTag({ id: 99, imapLabel: '$assignee_me' })
			const { getDeduplicatedTags } = tagSearchUtils
			getDeduplicatedTags.mockReturnValue([tag])

			const wrapper = mountHost()
			store.setVirtualMailboxTag('99', 'Me')
			wrapper.setData({ searchQuery: 'from:john' })

			wrapper.vm._handleVirtualMailboxEntry()

			// Vue 2 doesn't proxy _ prefixed data; method writes to instance directly
			expect(wrapper.vm._virtualTagWasAdded).toBe(true)
		})

		it('_handleVirtualMailboxEntry keeps _virtualTagWasAdded false when tag already in query', () => {
			const tag = createAssignmentTag({ id: 99, imapLabel: '$assignee_me' })
			const { getDeduplicatedTags } = tagSearchUtils
			getDeduplicatedTags.mockReturnValue([tag])

			const wrapper = mountHost()
			store.setVirtualMailboxTag('99', 'Me')
			wrapper.setData({ searchQuery: 'tags:99' })

			wrapper.vm._handleVirtualMailboxEntry()

			expect(wrapper.vm._virtualTagWasAdded).toBe(false)
		})

		it('data properties have correct initial values', () => {
			const wrapper = mountHost()
			// Vue 2 doesn't proxy _ prefixed data to vm, access via $data
			expect(wrapper.vm.$data._virtualTagWasAdded).toBe(false)
			expect(wrapper.vm.$data._skipResetOnTagClear).toBe(false)
			expect(wrapper.vm.$data._entryVirtualMailboxTag).toBeNull()
		})
	})
})
