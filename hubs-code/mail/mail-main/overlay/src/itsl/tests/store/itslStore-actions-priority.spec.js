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

function createMockMainStore() {
	return {
		mailboxes: {
			unified: {
				envelopeLists: {
					'is:pi-important': [],
					'is:pi-other': [],
				},
			},
		},
		envelopes: {},
		preferences: { 'sort-order': 'newest' },
		getMailbox: vi.fn(function(id) { return this.mailboxes[id] }),
	}
}

describe('itslStore priority actions', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useItslStore()
	})

	describe('updateEnvelopePriorityList', () => {
		it('adds envelope to important list when isImportant=true', () => {
			const mainStore = createMockMainStore()
			mainStore.getMailbox.mockImplementation(function(id) { return this.mailboxes[id] || { specialRole: 'inbox' } }.bind(mainStore))
			mainStore.mailboxes[100] = { specialRole: 'inbox' }
			const envelope = { databaseId: 1, mailboxId: 100, threadRootId: 'thread-1', dateInt: 1000 }
			mainStore.envelopes[1] = envelope

			store.updateEnvelopePriorityList(mainStore, envelope, true)

			expect(mainStore.mailboxes.unified.envelopeLists['is:pi-important']).toContain(1)
			expect(mainStore.mailboxes.unified.envelopeLists['is:pi-other']).not.toContain(1)
		})

		it('adds envelope to other list when isImportant=false', () => {
			const mainStore = createMockMainStore()
			mainStore.mailboxes[100] = { specialRole: 'inbox' }
			mainStore.getMailbox.mockImplementation(function(id) { return this.mailboxes[id] }.bind(mainStore))
			const envelope = { databaseId: 2, mailboxId: 100, threadRootId: 'thread-2', dateInt: 2000 }
			mainStore.envelopes[2] = envelope

			store.updateEnvelopePriorityList(mainStore, envelope, false)

			expect(mainStore.mailboxes.unified.envelopeLists['is:pi-other']).toContain(2)
			expect(mainStore.mailboxes.unified.envelopeLists['is:pi-important']).not.toContain(2)
		})

		it('moves envelope between lists', () => {
			const mainStore = createMockMainStore()
			mainStore.mailboxes[100] = { specialRole: 'inbox' }
			mainStore.getMailbox.mockImplementation(function(id) { return this.mailboxes[id] }.bind(mainStore))
			const envelope = { databaseId: 3, mailboxId: 100, threadRootId: 'thread-3', dateInt: 3000 }
			mainStore.envelopes[3] = envelope

			// First add to other
			store.updateEnvelopePriorityList(mainStore, envelope, false)
			expect(mainStore.mailboxes.unified.envelopeLists['is:pi-other']).toContain(3)

			// Move to important
			store.updateEnvelopePriorityList(mainStore, envelope, true)
			expect(mainStore.mailboxes.unified.envelopeLists['is:pi-important']).toContain(3)
			expect(mainStore.mailboxes.unified.envelopeLists['is:pi-other']).not.toContain(3)
		})

		it('skips non-inbox envelopes', () => {
			const mainStore = createMockMainStore()
			mainStore.mailboxes[200] = { specialRole: 'sent' }
			mainStore.getMailbox.mockImplementation(function(id) { return this.mailboxes[id] }.bind(mainStore))
			const envelope = { databaseId: 4, mailboxId: 200, threadRootId: 'thread-4', dateInt: 4000 }

			store.updateEnvelopePriorityList(mainStore, envelope, true)

			expect(mainStore.mailboxes.unified.envelopeLists['is:pi-important']).toEqual([])
		})

		it('does not replace better representative with higher dateInt', () => {
			const mainStore = createMockMainStore()
			mainStore.mailboxes[100] = { specialRole: 'inbox' }
			mainStore.getMailbox.mockImplementation(function(id) { return this.mailboxes[id] }.bind(mainStore))

			const existingEnv = { databaseId: 5, mailboxId: 100, threadRootId: 'thread-5', dateInt: 9000 }
			mainStore.envelopes[5] = existingEnv
			mainStore.mailboxes.unified.envelopeLists['is:pi-important'] = [5]

			const olderEnv = { databaseId: 6, mailboxId: 100, threadRootId: 'thread-5', dateInt: 1000 }
			mainStore.envelopes[6] = olderEnv

			store.updateEnvelopePriorityList(mainStore, olderEnv, true)

			// Should still contain the original, not the older one
			expect(mainStore.mailboxes.unified.envelopeLists['is:pi-important']).toContain(5)
			expect(mainStore.mailboxes.unified.envelopeLists['is:pi-important']).not.toContain(6)
		})

		it('sorts by dateInt (newest first)', () => {
			const mainStore = createMockMainStore()
			mainStore.mailboxes[100] = { specialRole: 'inbox' }
			mainStore.getMailbox.mockImplementation(function(id) { return this.mailboxes[id] }.bind(mainStore))

			const env1 = { databaseId: 10, mailboxId: 100, threadRootId: 'thread-10', dateInt: 1000 }
			const env2 = { databaseId: 11, mailboxId: 100, threadRootId: 'thread-11', dateInt: 3000 }
			const env3 = { databaseId: 12, mailboxId: 100, threadRootId: 'thread-12', dateInt: 2000 }
			mainStore.envelopes[10] = env1
			mainStore.envelopes[11] = env2
			mainStore.envelopes[12] = env3

			store.updateEnvelopePriorityList(mainStore, env1, true)
			store.updateEnvelopePriorityList(mainStore, env2, true)
			store.updateEnvelopePriorityList(mainStore, env3, true)

			const list = mainStore.mailboxes.unified.envelopeLists['is:pi-important']
			expect(list).toEqual([11, 12, 10])
		})
	})

	describe('removeFromPriorityList', () => {
		it('removes from important list', () => {
			const mainStore = createMockMainStore()
			mainStore.mailboxes.unified.envelopeLists['is:pi-important'] = [1, 2, 3]

			store.removeFromPriorityList(mainStore, 2)

			expect(mainStore.mailboxes.unified.envelopeLists['is:pi-important']).toEqual([1, 3])
		})

		it('removes from other list', () => {
			const mainStore = createMockMainStore()
			mainStore.mailboxes.unified.envelopeLists['is:pi-other'] = [4, 5, 6]

			store.removeFromPriorityList(mainStore, 5)

			expect(mainStore.mailboxes.unified.envelopeLists['is:pi-other']).toEqual([4, 6])
		})

		it('no-op if envelope not found in any list', () => {
			const mainStore = createMockMainStore()
			mainStore.mailboxes.unified.envelopeLists['is:pi-important'] = [1]
			mainStore.mailboxes.unified.envelopeLists['is:pi-other'] = [2]

			store.removeFromPriorityList(mainStore, 999)

			expect(mainStore.mailboxes.unified.envelopeLists['is:pi-important']).toEqual([1])
			expect(mainStore.mailboxes.unified.envelopeLists['is:pi-other']).toEqual([2])
		})

		it('handles missing unified mailbox gracefully', () => {
			const mainStore = { mailboxes: {}, getMailbox: vi.fn() }

			expect(() => store.removeFromPriorityList(mainStore, 1)).not.toThrow()
		})
	})
})
