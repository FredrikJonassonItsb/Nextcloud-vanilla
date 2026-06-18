import { createPinia, setActivePinia } from 'pinia'
import useItslStore from '../../store/itslStore.js'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
		post: vi.fn(),
		put: vi.fn(),
		delete: vi.fn(),
	},
}))
vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: vi.fn(() => ({ uid: 'testuser' })),
	getRequestToken: vi.fn(() => 'mock-token'),
	onRequestTokenUpdate: vi.fn(),
}))

describe('itslStore getters', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useItslStore()
	})

	describe('getTagsForAccount', () => {
		it('returns tags for existing account', () => {
			const tags = [{ id: 1, displayName: 'Alpha' }, { id: 2, displayName: 'Beta' }]
			store.tagsByAccount = { 10: tags }

			expect(store.getTagsForAccount(10)).toEqual(tags)
		})

		it('returns empty array for unknown account', () => {
			store.tagsByAccount = {}

			expect(store.getTagsForAccount(999)).toEqual([])
		})
	})

	describe('getInternalMailboxName', () => {
		beforeEach(() => {
			store.internalMailboxes = [
				{ email: 'dept@gruppbox', name: 'Department A' },
				{ email: 'user@personlig', name: 'Personal Box' },
			]
		})

		it('returns display name for existing email', () => {
			expect(store.getInternalMailboxName('dept@gruppbox')).toBe('Department A')
		})

		it('returns null for non-existent email', () => {
			expect(store.getInternalMailboxName('unknown@gruppbox')).toBeNull()
		})

		it('returns null for null input', () => {
			expect(store.getInternalMailboxName(null)).toBeNull()
		})
	})

	describe('currentUserAssignmentTag', () => {
		it('returns matching assignment tag for current user', () => {
			const assignmentTag = { id: 1, isAssignmentTag: true, username: 'testuser', imapLabel: '$assignee_testuser' }
			store.tagsByAccount = { 1: [assignmentTag] }

			expect(store.currentUserAssignmentTag).toEqual(assignmentTag)
		})

		it('returns null when no matching tag exists', () => {
			store.tagsByAccount = {
				1: [{ id: 1, isAssignmentTag: true, username: 'otheruser', imapLabel: '$assignee_other' }],
			}

			expect(store.currentUserAssignmentTag).toBeNull()
		})

		it('returns null when getCurrentUser returns null', async () => {
			const { getCurrentUser } = await import('@nextcloud/auth')
			getCurrentUser.mockReturnValueOnce(null)

			store.tagsByAccount = {
				1: [{ id: 1, isAssignmentTag: true, username: 'testuser', imapLabel: '$assignee_testuser' }],
			}

			expect(store.currentUserAssignmentTag).toBeNull()
		})
	})

	describe('lookupAddressBookLabels', () => {
		beforeEach(() => {
			store.addressBookOrgs = [
				{
					address: 'SE1234567890',
					name: 'Org A',
					functionAddresses: [
						{ address: 'SE1234567890:1001', name: 'Function 1' },
					],
				},
			]
		})

		it('returns labels when match found', () => {
			const result = store.lookupAddressBookLabels('SE1234567890:1001', 'SE1234567890')

			expect(result).toEqual({
				functionAddressLabel: 'Function 1',
				organizationAddressLabel: 'Org A',
			})
		})

		it('returns empty strings when no match', () => {
			const result = store.lookupAddressBookLabels('nonexistent', 'SE1234567890')

			expect(result).toEqual({
				functionAddressLabel: '',
				organizationAddressLabel: '',
			})
		})

		it('returns empty strings when both null', () => {
			const result = store.lookupAddressBookLabels(null, null)

			expect(result).toEqual({
				functionAddressLabel: '',
				organizationAddressLabel: '',
			})
		})
	})

	describe('getExpandedThreadOverride', () => {
		it('returns first databaseId when threadSortNewestFirst=true and selectNewestInThread=true', () => {
			store.threadSortNewestFirst = true
			store.selectNewestInThread = true
			const thread = [{ databaseId: 10 }, { databaseId: 20 }, { databaseId: 30 }]

			expect(store.getExpandedThreadOverride(thread)).toEqual([10])
		})

		it('returns last databaseId when threadSortNewestFirst=false and selectNewestInThread=true', () => {
			store.threadSortNewestFirst = false
			store.selectNewestInThread = true
			const thread = [{ databaseId: 10 }, { databaseId: 20 }, { databaseId: 30 }]

			expect(store.getExpandedThreadOverride(thread)).toEqual([30])
		})

		it('returns null when selectNewestInThread=false', () => {
			store.selectNewestInThread = false
			store.threadSortNewestFirst = true
			const thread = [{ databaseId: 10 }]

			expect(store.getExpandedThreadOverride(thread)).toBeNull()
		})

		it('returns null for empty thread', () => {
			store.selectNewestInThread = true
			store.threadSortNewestFirst = true

			expect(store.getExpandedThreadOverride([])).toBeNull()
		})
	})
})
