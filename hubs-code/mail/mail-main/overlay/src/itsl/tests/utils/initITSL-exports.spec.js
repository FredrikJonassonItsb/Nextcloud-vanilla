// Mock all heavy imports to prevent side effects from initITSL.js
// All vi.mock paths resolve relative to THIS test file (src/itsl/tests/utils/)
// to the same absolute path the source file's imports resolve to.

// itsl-internal imports (target: src/itsl/*)
vi.mock('../../interceptors/axios-setup.js', () => ({ initInterceptors: vi.fn(), initStore: vi.fn() }))
vi.mock('../../store/constants.js', () => ({
	MESSAGE_TYPES: { SDK: { id: 'sdk_message' } },
	MY_MESSAGES_MAILBOX_ID: 'my-messages',
	UNASSIGNED_MAILBOX_ID: 'unassigned',
}))
vi.mock('../../utils/itslHelperFunctions.js', () => ({ messageTypeToFolderName: vi.fn() }))
vi.mock('../../utils/scrollMarginHandler.js', () => ({ initScrollMarginHandler: vi.fn() }))
vi.mock('../../store/itslStore.js', () => ({ default: vi.fn() }))
vi.mock('../../styling/variables.scss', () => ({}))
vi.mock('../../styling/layout.scss', () => ({}))
vi.mock('../../styling/_envelope-overrides.scss', () => ({}))
vi.mock('../../styling/_upstream-overrides.scss', () => ({}))

// mail-app imports (target: src/*)
vi.mock('@/store/outboxStore.js', () => ({ default: vi.fn() }))
vi.mock('@/directives/drag-and-drop/util/dragEventBus.js', () => ({ default: { on: vi.fn() } }))
vi.mock('@/store/mainStore.js', () => ({ default: vi.fn() }))
vi.mock('@/router.js', () => ({ default: { beforeEach: vi.fn() } }))
vi.mock('@/store/constants.js', () => ({ UNIFIED_ACCOUNT_ID: 0 }))
vi.mock('@/util/normalization.js', () => ({ normalizedEnvelopeListId: vi.fn() }))
vi.mock('@/components/Thread.vue', () => ({ methods: { resetThread: vi.fn() } }))

// npm packages
vi.mock('@nextcloud/initial-state', () => ({ loadState: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, str) => str }))
vi.mock('vue', () => ({ set: vi.fn() }))

import { isVirtualMailbox } from '../../utils/initITSL.js'
import { addTagToQuery, removeTagFromQuery } from '../../utils/tagQueryUtils.js'

afterEach(() => {
	vi.restoreAllMocks()
})

describe('isVirtualMailbox', () => {
	it('returns true for my-messages', () => {
		expect(isVirtualMailbox('my-messages')).toBe(true)
	})

	it('returns true for unassigned', () => {
		expect(isVirtualMailbox('unassigned')).toBe(true)
	})

	it('returns false for regular mailbox', () => {
		expect(isVirtualMailbox('inbox-123')).toBe(false)
	})

	it('returns false for undefined', () => {
		expect(isVirtualMailbox(undefined)).toBe(false)
	})

	it('returns false for null', () => {
		expect(isVirtualMailbox(null)).toBe(false)
	})
})

describe('addTagToQuery', () => {
	it('adds tag to empty query', () => {
		expect(addTagToQuery('', '123')).toBe('tags:123')
	})

	it('appends tag param to existing query', () => {
		expect(addTagToQuery('from:john', '456')).toBe('from:john tags:456')
	})

	it('adds comma-separated tag when tags already exist', () => {
		const result = addTagToQuery('from:john tags:456', '789')
		expect(result).toContain('tags:')
		const tagsMatch = result.match(/tags:([^\s]+)/)
		const tags = decodeURIComponent(tagsMatch[1]).split(',')
		expect(tags).toContain('456')
		expect(tags).toContain('789')
	})

	it('returns unchanged query if tag already present', () => {
		const query = 'from:john tags:456'
		expect(addTagToQuery(query, '456')).toBe(query)
	})

	it('handles tag IDs containing special characters', () => {
		const result = addTagToQuery('', '$label_1')
		expect(result).toBe('tags:$label_1')
	})

	it('appends URL-encoded comma-separated tag to existing tags with special chars', () => {
		const query = 'tags:$follow_up'
		const result = addTagToQuery(query, '$label_1')
		const tagsMatch = result.match(/tags:([^\s]+)/)
		const tags = decodeURIComponent(tagsMatch[1]).split(',')
		expect(tags).toContain('$follow_up')
		expect(tags).toContain('$label_1')
	})
})

describe('removeTagFromQuery', () => {
	it('removes one tag from comma-separated list', () => {
		const result = removeTagFromQuery('tags:123,456', '123')
		const tagsMatch = result.match(/tags:([^\s]+)/)
		const tags = decodeURIComponent(tagsMatch[1]).split(',')
		expect(tags).toContain('456')
		expect(tags).not.toContain('123')
	})

	it('removes entire tags: param when last tag removed', () => {
		expect(removeTagFromQuery('tags:123', '123')).toBe('')
	})

	it('removes tags: param but keeps other query parts', () => {
		const result = removeTagFromQuery('from:john tags:123', '123')
		expect(result).toBe('from:john')
	})

	it('returns unchanged query when no tags: param exists', () => {
		expect(removeTagFromQuery('from:john', '123')).toBe('from:john')
	})

	it('returns unchanged query when tag not present', () => {
		const query = 'tags:456'
		expect(removeTagFromQuery(query, '999')).toBe(query)
	})
})
