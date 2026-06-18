import { createPinia, setActivePinia } from 'pinia'
import useItslStore from '../../store/itslStore.js'

vi.mock('@nextcloud/axios', () => ({
	get: vi.fn(),
	post: vi.fn(),
	put: vi.fn(),
	delete: vi.fn(),
}))
vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: () => ({ uid: 'testuser' }),
	getRequestToken: vi.fn(() => 'mock-token'),
	onRequestTokenUpdate: vi.fn(),
}))
vi.mock('@/util/priorityInbox.js', () => ({
	priorityImportantQuery: 'is:pi-important',
	priorityOtherQuery: 'is:pi-other',
}))
vi.mock('@/util/normalization.js', () => ({
	normalizedEnvelopeListId: vi.fn((q) => q),
}))
vi.mock('@/store/constants.js', () => ({
	UNIFIED_INBOX_ID: 'unified',
}))
vi.mock('@/store/mainStore.js', () => ({ default: vi.fn() }))

describe('itslStore tag search list actions', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useItslStore()
	})

	describe('removeFromTagSearchLists', () => {
		it('removes from unified mailbox envelope list', () => {
			const tagId = 123
			const listId = `tags:${tagId}`
			const mainStore = {
				tags: { [tagId]: { id: tagId, imapLabel: '$tag_x' } },
				mailboxes: {
					unified: { envelopeLists: { [listId]: [1, 2, 3] } },
				},
				envelopes: { 2: { mailboxId: 100 } },
			}
			mainStore.mailboxes[100] = { envelopeLists: {} }

			store.removeFromTagSearchLists(mainStore, 2, '$tag_x')

			expect(mainStore.mailboxes.unified.envelopeLists[listId]).toEqual([1, 3])
		})

		it('removes from envelope own mailbox list', () => {
			const tagId = 456
			const listId = `tags:${tagId}`
			const mainStore = {
				tags: { [tagId]: { id: tagId, imapLabel: '$tag_y' } },
				mailboxes: {
					unified: { envelopeLists: {} },
					100: { envelopeLists: { [listId]: [10, 20, 30] } },
				},
				envelopes: { 20: { mailboxId: 100 } },
			}

			store.removeFromTagSearchLists(mainStore, 20, '$tag_y')

			expect(mainStore.mailboxes[100].envelopeLists[listId]).toEqual([10, 30])
		})

		it('handles missing lists gracefully', () => {
			const mainStore = {
				tags: {},
				mailboxes: {
					unified: { envelopeLists: {} },
				},
				envelopes: { 1: { mailboxId: 100 } },
			}

			expect(() => store.removeFromTagSearchLists(mainStore, 1, '$nonexistent')).not.toThrow()
		})
	})

	describe('addToTagSearchLists', () => {
		it('adds to existing unified list', () => {
			const tagId = 789
			const listId = `tags:${tagId}`
			const mainStore = {
				tags: { [tagId]: { id: tagId, imapLabel: '$tag_a' } },
				mailboxes: {
					unified: { envelopeLists: { [listId]: [1, 2] } },
				},
				envelopes: { 3: { mailboxId: 100 } },
			}
			mainStore.mailboxes[100] = { envelopeLists: {} }

			store.addToTagSearchLists(mainStore, 3, '$tag_a')

			expect(mainStore.mailboxes.unified.envelopeLists[listId]).toContain(3)
		})

		it('adds to existing own-mailbox list', () => {
			const tagId = 321
			const listId = `tags:${tagId}`
			const mainStore = {
				tags: { [tagId]: { id: tagId, imapLabel: '$tag_b' } },
				mailboxes: {
					unified: { envelopeLists: {} },
					100: { envelopeLists: { [listId]: [10] } },
				},
				envelopes: { 20: { mailboxId: 100 } },
			}

			store.addToTagSearchLists(mainStore, 20, '$tag_b')

			expect(mainStore.mailboxes[100].envelopeLists[listId]).toContain(20)
		})

		it('skips non-existent lists (does not create new ones)', () => {
			const tagId = 555
			const listId = `tags:${tagId}`
			const mainStore = {
				tags: { [tagId]: { id: tagId, imapLabel: '$tag_new' } },
				mailboxes: {
					unified: { envelopeLists: {} },
					100: { envelopeLists: {} },
				},
				envelopes: { 5: { mailboxId: 100 } },
			}

			store.addToTagSearchLists(mainStore, 5, '$tag_new')

			expect(mainStore.mailboxes.unified.envelopeLists[listId]).toBeUndefined()
			expect(mainStore.mailboxes[100].envelopeLists[listId]).toBeUndefined()
		})

		it('does not add duplicates', () => {
			const tagId = 999
			const listId = `tags:${tagId}`
			const mainStore = {
				tags: { [tagId]: { id: tagId, imapLabel: '$tag_c' } },
				mailboxes: {
					unified: { envelopeLists: { [listId]: [1, 2] } },
				},
				envelopes: { 1: { mailboxId: 100 } },
			}
			mainStore.mailboxes[100] = { envelopeLists: {} }

			store.addToTagSearchLists(mainStore, 1, '$tag_c')

			expect(mainStore.mailboxes.unified.envelopeLists[listId]).toEqual([1, 2])
		})
	})
})
