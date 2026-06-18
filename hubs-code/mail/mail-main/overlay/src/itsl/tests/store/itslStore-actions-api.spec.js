import { createPinia, setActivePinia } from 'pinia'
import axios from '@nextcloud/axios'
import useItslStore from '../../store/itslStore.js'
import * as mainStoreModule from '@/store/mainStore.js'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
		post: vi.fn(),
		put: vi.fn(),
		delete: vi.fn(),
	},
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

const mockMainStore = {
	addTagMutation: vi.fn(),
	updateTagMutation: vi.fn(),
	deleteTag: vi.fn(),
	syncEnvelopes: vi.fn(),
	fetchThread: vi.fn(),
	getAccounts: [],
	getMailbox: vi.fn(),
	getEnvelopeTags: vi.fn(() => []),
	getEnvelopesByThreadRootId: vi.fn(() => []),
}
vi.mock('@/store/mainStore.js', () => ({ default: vi.fn(() => mockMainStore) }))

describe('itslStore API actions', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useItslStore()
		vi.clearAllMocks()
	})

	describe('initStore', () => {
		const addressMappingData = { key1: 'val1', key2: 'val2' }
		const settingsData = {
			organizationExtension: 'SE999',
			threadSortNewestFirst: false,
			selectNewestInThread: true,
			enforcePersonalSecuremail: true,
		}
		const orgsData = {
			data: [
				{
					id: 'org1',
					attributes: { name: 'Org One', participantIdentifier: 'SE1111' },
				},
			],
		}
		const addressesData = {
			data: [
				{
					id: 'addr1',
					attributes: { name: 'Func One', identifier: 'SE1111:1001' },
					relationships: { parent: { data: { id: 'org1' } } },
				},
			],
		}
		const internalMailboxesData = [
			{ email: 'dept@gruppbox', name: 'Department' },
		]

		function setupAxiosMocks() {
			axios.get.mockImplementation((url) => {
				if (url.includes('existingAddresses')) return Promise.resolve({ data: addressMappingData })
				if (url.includes('getSettings')) return Promise.resolve({ data: settingsData })
				if (url.includes('organizations')) return Promise.resolve({ data: orgsData })
				if (url.includes('addresses')) return Promise.resolve({ data: addressesData })
				if (url.includes('internalMailboxesAB')) return Promise.resolve({ data: internalMailboxesData })
				return Promise.resolve({ data: null })
			})
		}

		it('loads address mapping into validFromData', async () => {
			setupAxiosMocks()

			await store.initStore()

			expect(store.validFromData.get('key1')).toBe('val1')
			expect(store.validFromData.get('key2')).toBe('val2')
		})

		it('loads settings from getSettings response', async () => {
			setupAxiosMocks()

			await store.initStore()

			expect(store.sender.organizationExtension).toBe('SE999')
			expect(store.threadSortNewestFirst).toBe(false)
			expect(store.selectNewestInThread).toBe(true)
			expect(store.userObligatedToProvideSsn).toBe(true)
		})

		it('loads addressBookOrgs and function addresses', async () => {
			setupAxiosMocks()

			await store.initStore()

			expect(store.addressBookOrgs).toHaveLength(1)
			expect(store.addressBookOrgs[0].name).toBe('Org One')
			expect(store.addressBookOrgs[0].functionAddresses).toHaveLength(1)
			expect(store.addressBookOrgs[0].functionAddresses[0].name).toBe('Func One')
			expect(store.addressBookLoaded).toBe(true)
		})

		it('loads internal mailboxes', async () => {
			setupAxiosMocks()

			await store.initStore()

			expect(store.internalMailboxes).toEqual(internalMailboxesData)
			expect(store.internalMailboxesLoaded).toBe(true)
		})

		it('skips function address loading when organizations response is empty', async () => {
			axios.get.mockImplementation((url) => {
				if (url.includes('existingAddresses')) return Promise.resolve({ data: addressMappingData })
				if (url.includes('getSettings')) return Promise.resolve({ data: settingsData })
				if (url.includes('organizations')) return Promise.resolve({ data: { data: [] } })
				if (url.includes('addresses')) return Promise.resolve({ data: addressesData })
				if (url.includes('internalMailboxesAB')) return Promise.resolve({ data: [] })
				return Promise.resolve({ data: null })
			})

			await store.initStore()

			// addressBookOrgs should be empty since no orgs returned
			expect(store.addressBookOrgs).toHaveLength(0)
			// Function addresses endpoint should not be called when tempMap is empty
			const addressCalls = axios.get.mock.calls.filter(c => c[0].includes('addresses') && !c[0].includes('existing'))
			// Only the organizations call, not the function addresses call
			expect(addressCalls.some(c => c[0].includes('addressbook/api/addresses'))).toBe(false)
		})

		it('handles internal mailbox API failure gracefully', async () => {
			axios.get.mockImplementation((url) => {
				if (url.includes('existingAddresses')) return Promise.resolve({ data: addressMappingData })
				if (url.includes('getSettings')) return Promise.resolve({ data: settingsData })
				if (url.includes('organizations')) return Promise.resolve({ data: orgsData })
				if (url.includes('addresses')) return Promise.resolve({ data: addressesData })
				if (url.includes('internalMailboxesAB')) return Promise.reject(new Error('Network error'))
				return Promise.resolve({ data: null })
			})

			await expect(store.initStore()).resolves.not.toThrow()
			expect(store.internalMailboxesLoaded).toBe(false)
		})
	})

	describe('createTag', () => {
		it('POSTs and calls addTagMutation and addTagToAccount', async () => {
			const tag = { id: 99, displayName: 'New', color: '#abc', imapLabel: '$tag_99' }
			axios.post.mockResolvedValue({ data: tag })

			const result = await store.createTag(mockMainStore, {
				displayName: 'New',
				color: '#abc',
				accountId: 1,
			})

			expect(axios.post).toHaveBeenCalledWith(
				'/apps/sdkmc/api/tags/1',
				{ displayName: 'New', color: '#abc' },
			)
			expect(mockMainStore.addTagMutation).toHaveBeenCalledWith({ tag })
			expect(result).toEqual(tag)
		})
	})

	describe('createTag - error handling', () => {
		it('propagates axios error when POST fails', async () => {
			axios.post.mockRejectedValue(new Error('Server error'))

			await expect(
				store.createTag(mockMainStore, { displayName: 'Fail', color: '#000', accountId: 1 }),
			).rejects.toThrow('Server error')

			expect(mockMainStore.addTagMutation).not.toHaveBeenCalled()
		})
	})

	describe('updateTag', () => {
		it('PUTs and calls updateTagMutation and updateTagInAccount', async () => {
			axios.put.mockResolvedValue({})
			const tag = { id: 50, displayName: 'Old', color: '#000' }
			store.tagsByAccount = { 1: [tag] }

			await store.updateTag(mockMainStore, {
				tag,
				displayName: 'Updated',
				color: '#fff',
				accountId: 1,
			})

			expect(axios.put).toHaveBeenCalledWith(
				'/apps/sdkmc/api/tags/1/50',
				{ displayName: 'Updated', color: '#fff' },
			)
			expect(mockMainStore.updateTagMutation).toHaveBeenCalledWith({
				tag,
				displayName: 'Updated',
				color: '#fff',
			})
		})
	})

	describe('deleteTag', () => {
		it('calls mainStore.deleteTag then removeTagFromAccount', async () => {
			mockMainStore.deleteTag.mockResolvedValue()
			const tag = { id: 60, displayName: 'Doomed' }
			store.tagsByAccount = { 1: [tag] }

			await store.deleteTag(mockMainStore, { tag, accountId: 1 })

			expect(mockMainStore.deleteTag).toHaveBeenCalledWith({ tag, accountId: 1 })
			expect(store.tagsByAccount[1]).toEqual([])
		})
	})

	describe('onMessageSent', () => {
		it('returns early when replyToDatabaseId is null', async () => {
			await store.onMessageSent({ replyToDatabaseId: null, accountId: 1 })

			expect(mockMainStore.fetchThread).not.toHaveBeenCalled()
		})

		it('syncs sent mailbox then fetches thread', async () => {
			const useMainStoreFn = mainStoreModule.default
			useMainStoreFn.mockReturnValue({
				...mockMainStore,
				getAccounts: [{ id: 1, sentMailboxId: 200 }],
				syncEnvelopes: vi.fn().mockResolvedValue(),
				fetchThread: vi.fn().mockResolvedValue(),
			})

			const localStore = useItslStore()
			await localStore.onMessageSent({ replyToDatabaseId: 42, accountId: 1 })

			const ms = useMainStoreFn()
			expect(ms.syncEnvelopes).toHaveBeenCalledWith({ mailboxId: 200 })
			expect(ms.fetchThread).toHaveBeenCalledWith(42)
		})

		it('retries up to 3 times on failure', async () => {
			const useMainStoreFn = mainStoreModule.default
			const mockFetchThread = vi.fn().mockRejectedValue(new Error('fail'))
			useMainStoreFn.mockReturnValue({
				...mockMainStore,
				getAccounts: [],
				syncEnvelopes: vi.fn(),
				fetchThread: mockFetchThread,
			})

			const localStore = useItslStore()
			await localStore.onMessageSent({ replyToDatabaseId: 42, accountId: 1 })

			expect(mockFetchThread).toHaveBeenCalledTimes(3)
		})

		it('does not throw after exhausting retries', async () => {
			const useMainStoreFn = mainStoreModule.default
			useMainStoreFn.mockReturnValue({
				...mockMainStore,
				getAccounts: [],
				syncEnvelopes: vi.fn(),
				fetchThread: vi.fn().mockRejectedValue(new Error('fail')),
			})

			const localStore = useItslStore()
			await expect(localStore.onMessageSent({ replyToDatabaseId: 42, accountId: 1 })).resolves.not.toThrow()
		})
	})

	describe('syncAfterMove', () => {
		it('syncs destination mailbox', async () => {
			const useMainStoreFn = mainStoreModule.default
			useMainStoreFn.mockReturnValue({
				...mockMainStore,
				syncEnvelopes: vi.fn().mockResolvedValue(),
				getMailbox: vi.fn(() => ({ specialRole: 'sent' })),
				getEnvelopesByThreadRootId: vi.fn(() => []),
			})

			const localStore = useItslStore()
			const envelope = { accountId: 1, threadRootId: 'thread-1' }
			await localStore.syncAfterMove(useMainStoreFn(), envelope, 300)

			expect(useMainStoreFn().syncEnvelopes).toHaveBeenCalledWith({
				mailboxId: 300,
				query: '',
				init: true,
			})
		})

		it('updates priority for inbox destinations', async () => {
			const useMainStoreFn = mainStoreModule.default
			const envInThread = { databaseId: 10, mailboxId: 100, threadRootId: 'thread-1', dateInt: 5000 }
			useMainStoreFn.mockReturnValue({
				...mockMainStore,
				mailboxes: {
					unified: {
						envelopeLists: {
							'is:pi-important': [],
							'is:pi-other': [],
						},
					},
					100: { specialRole: 'inbox' },
				},
				envelopes: { 10: envInThread },
				preferences: { 'sort-order': 'newest' },
				syncEnvelopes: vi.fn().mockResolvedValue(),
				getMailbox: vi.fn(function(id) { return this.mailboxes[id] }),
				getEnvelopeTags: vi.fn(() => [{ imapLabel: '$label1' }]),
				getEnvelopesByThreadRootId: vi.fn(() => [envInThread]),
			})

			const ms = useMainStoreFn()
			const localStore = useItslStore()
			const envelope = { accountId: 1, threadRootId: 'thread-1' }
			await localStore.syncAfterMove(ms, envelope, 100)

			// Should have called getEnvelopesByThreadRootId
			expect(ms.getEnvelopesByThreadRootId).toHaveBeenCalledWith(1, 'thread-1')
		})

		it('no-op when no mailboxId', async () => {
			const useMainStoreFn = mainStoreModule.default
			useMainStoreFn.mockReturnValue({
				...mockMainStore,
				syncEnvelopes: vi.fn(),
			})

			const localStore = useItslStore()
			const envelope = { accountId: 1, threadRootId: 'thread-1' }
			await localStore.syncAfterMove(useMainStoreFn(), envelope, null)

			expect(useMainStoreFn().syncEnvelopes).not.toHaveBeenCalled()
		})
	})
})
