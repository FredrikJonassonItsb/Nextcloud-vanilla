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

describe('itslStore local actions', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useItslStore()
	})

	describe('setMessageType', () => {
		it('sets selectedMessageType', () => {
			store.setMessageType('sdk_message')

			expect(store.selectedMessageType).toBe('sdk_message')
		})
	})

	describe('initTagsFromPhpState', () => {
		it('sets tagsByAccount from keyed object and marks tagsLoaded', () => {
			const tagsByAccount = {
				1: [{ id: 100, displayName: 'Tag A' }],
				2: [{ id: 200, displayName: 'Tag B' }],
			}

			store.initTagsFromPhpState(tagsByAccount)

			expect(store.tagsByAccount[1]).toEqual([{ id: 100, displayName: 'Tag A' }])
			expect(store.tagsByAccount[2]).toEqual([{ id: 200, displayName: 'Tag B' }])
			expect(store.tagsLoaded).toBe(true)
		})
	})

	describe('setTagsForAccount', () => {
		it('replaces tags for account', () => {
			store.tagsByAccount = { 1: [{ id: 1 }] }
			const newTags = [{ id: 10 }, { id: 20 }]

			store.setTagsForAccount(1, newTags)

			expect(store.tagsByAccount[1]).toEqual(newTags)
		})
	})

	describe('addTagToAccount', () => {
		it('adds tag to account', () => {
			store.tagsByAccount = { 1: [{ id: 1, displayName: 'Existing' }] }
			const newTag = { id: 2, displayName: 'New' }

			store.addTagToAccount(1, newTag)

			expect(store.tagsByAccount[1]).toHaveLength(2)
			expect(store.tagsByAccount[1][1]).toEqual(newTag)
		})

		it('skips duplicate tag with same id', () => {
			store.tagsByAccount = { 1: [{ id: 1, displayName: 'Existing' }] }

			store.addTagToAccount(1, { id: 1, displayName: 'Duplicate' })

			expect(store.tagsByAccount[1]).toHaveLength(1)
		})

		it('creates new array for unknown account', () => {
			store.tagsByAccount = {}
			const tag = { id: 1, displayName: 'First' }

			store.addTagToAccount(5, tag)

			expect(store.tagsByAccount[5]).toEqual([tag])
		})
	})

	describe('removeTagFromAccount', () => {
		it('removes tag by id', () => {
			store.tagsByAccount = { 1: [{ id: 10 }, { id: 20 }, { id: 30 }] }

			store.removeTagFromAccount(1, 20)

			expect(store.tagsByAccount[1]).toEqual([{ id: 10 }, { id: 30 }])
		})
	})

	describe('updateTagInAccount', () => {
		it('updates displayName and color on existing tag', () => {
			const tag = { id: 5, displayName: 'Old', color: '#000' }
			store.tagsByAccount = { 1: [tag] }

			store.updateTagInAccount(1, 5, 'New Name', '#fff')

			expect(tag.displayName).toBe('New Name')
			expect(tag.color).toBe('#fff')
		})
	})

	describe('addPendingTagRemoval', () => {
		it('returns timestamp and stores in pendingTagRemovals', () => {
			const ts = store.addPendingTagRemoval(100, '$tag_a')

			expect(typeof ts).toBe('number')
			expect(store.pendingTagRemovals[100]['$tag_a']).toBe(ts)
		})

		it('clears conflicting pending addition', () => {
			store.addPendingTagAddition(100, '$tag_a')
			expect(store.pendingTagAdditions[100]['$tag_a']).toBeDefined()

			store.addPendingTagRemoval(100, '$tag_a')

			expect(store.pendingTagAdditions[100]).toBeUndefined()
		})
	})

	describe('clearPendingTagRemoval', () => {
		it('clears when timestamp matches', () => {
			const ts = store.addPendingTagRemoval(100, '$tag_a')

			store.clearPendingTagRemoval(100, '$tag_a', ts)

			expect(store.pendingTagRemovals[100]).toBeUndefined()
		})

		it('no-op when timestamp does not match', () => {
			const ts = store.addPendingTagRemoval(100, '$tag_a')

			store.clearPendingTagRemoval(100, '$tag_a', ts + 999)

			expect(store.pendingTagRemovals[100]['$tag_a']).toBe(ts)
		})
	})

	describe('addPendingTagAddition', () => {
		it('returns timestamp and stores in pendingTagAdditions', () => {
			const ts = store.addPendingTagAddition(200, '$tag_b')

			expect(typeof ts).toBe('number')
			expect(store.pendingTagAdditions[200]['$tag_b']).toBe(ts)
		})

		it('clears conflicting pending removal', () => {
			store.addPendingTagRemoval(200, '$tag_b')
			expect(store.pendingTagRemovals[200]['$tag_b']).toBeDefined()

			store.addPendingTagAddition(200, '$tag_b')

			expect(store.pendingTagRemovals[200]).toBeUndefined()
		})
	})

	describe('clearPendingTagAddition', () => {
		it('clears when timestamp matches', () => {
			const ts = store.addPendingTagAddition(200, '$tag_b')

			store.clearPendingTagAddition(200, '$tag_b', ts)

			expect(store.pendingTagAdditions[200]).toBeUndefined()
		})

		it('no-op when timestamp does not match', () => {
			const ts = store.addPendingTagAddition(200, '$tag_b')

			store.clearPendingTagAddition(200, '$tag_b', ts + 999)

			expect(store.pendingTagAdditions[200]['$tag_b']).toBe(ts)
		})
	})

	describe('virtual mailbox state', () => {
		it('setVirtualMailboxTag sets state', () => {
			store.setVirtualMailboxTag('123', 'My Tag')

			expect(store.virtualMailboxTag).toEqual({ tagSearchValue: '123', tagName: 'My Tag' })
		})

		it('clearVirtualMailboxTag clears state', () => {
			store.setVirtualMailboxTag('123', 'My Tag')
			store.clearVirtualMailboxTag()

			expect(store.virtualMailboxTag).toBeNull()
		})
	})

	describe('dropped assignment tags', () => {
		it('setDroppedAssignmentTags sets state', () => {
			store.setDroppedAssignmentTags([1, 2, 3])

			expect(store.droppedAssignmentTags).toEqual([1, 2, 3])
		})

		it('clearDroppedAssignmentTags resets to empty', () => {
			store.setDroppedAssignmentTags([1, 2])
			store.clearDroppedAssignmentTags()

			expect(store.droppedAssignmentTags).toEqual([])
		})
	})

	describe('sidebar tag click', () => {
		it('triggerSidebarTagClick sets state with timestamp', () => {
			store.triggerSidebarTagClick('$tag_x', 'X Tag')

			expect(store.pendingSidebarTagClick).toMatchObject({
				imapLabel: '$tag_x',
				tagName: 'X Tag',
			})
			expect(store.pendingSidebarTagClick.timestamp).toBeDefined()
		})

		it('clearSidebarTagClick sets state to null', () => {
			store.triggerSidebarTagClick('$tag_x', 'X Tag')
			store.clearSidebarTagClick()

			expect(store.pendingSidebarTagClick).toBeNull()
		})
	})

	describe('virtual search cleared signal', () => {
		it('triggerVirtualSearchCleared sets state with timestamp', () => {
			store.triggerVirtualSearchCleared()

			expect(store.virtualSearchClearedSignal).toBeDefined()
			expect(store.virtualSearchClearedSignal.timestamp).toBeDefined()
		})

		it('clearVirtualSearchClearedSignal sets state to null', () => {
			store.triggerVirtualSearchCleared()
			store.clearVirtualSearchClearedSignal()

			expect(store.virtualSearchClearedSignal).toBeNull()
		})
	})
})
