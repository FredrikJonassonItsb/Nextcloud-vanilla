// Provide global t() used as bare function in component script
global.t = (app, str) => str

// --- Icon stub factory ---
const { iconStub } = vi.hoisted(() => ({
	iconStub: (name) => ({ default: { name, template: '<span />', props: ['size'] } }),
}))

// --- vi.mock calls BEFORE component import ---

// vue-material-design-icons
vi.mock('vue-material-design-icons/Star.vue', () => iconStub('IconFavorite'))
vi.mock('vue-material-design-icons/Delete.vue', () => iconStub('DeleteIcon'))
vi.mock('vue-material-design-icons/PackageDown.vue', () => iconStub('ArchiveIcon'))
vi.mock('vue-material-design-icons/Email.vue', () => iconStub('EmailUnread'))
vi.mock('vue-material-design-icons/EmailOpen.vue', () => iconStub('EmailRead'))
vi.mock('vue-material-design-icons/Lock.vue', () => iconStub('LockIcon'))
vi.mock('vue-material-design-icons/LockPlus.vue', () => iconStub('LockPlusIcon'))
vi.mock('vue-material-design-icons/LockOff.vue', () => iconStub('LockOffIcon'))
vi.mock('vue-material-design-icons/Reply.vue', () => iconStub('ReplyIcon'))
vi.mock('vue-material-design-icons/ReplyAll.vue', () => iconStub('ReplyAllIcon'))

// Child Vue components
vi.mock('../../components/message/MessageHeaderItsl.vue', () => ({ default: { name: 'MessageHeaderItsl', template: '<div />', props: ['message'] } }))
vi.mock('../../components/message/MenuEnvelopeItsl.vue', () => ({ default: { name: 'MenuEnvelopeItsl', template: '<div />', props: ['envelope', 'mailbox', 'withSelect', 'withShowSource', 'moreActionsOpen', 'hasInternalMailbox', 'hasArchiveAcl', 'disableArchiveButton', 'showArchiveButton', 'messageType'] } }))
vi.mock('../../components/message/MetadataAttachmentsItsl.vue', () => ({ default: { name: 'MetadataAttachmentsItsl', template: '<div />', props: ['senderPersonIDs', 'senderReferenceIDs', 'recipientPersonIDs', 'recipientReferenceIDs', 'deletable'] } }))
vi.mock('../../components/message/AvatarMessageDirectionItsl.vue', () => ({ default: { name: 'AvatarMessageDirectionItsl', template: '<div />', props: ['messageDirection', 'size'] } }))
vi.mock('../../components/partials/ReceiptStatusItsl.vue', () => ({ default: { name: 'ReceiptStatusItsl', template: '<div />', props: ['status', 'sentAt', 'hasFailure', 'size'] } }))
vi.mock('@/components/Message.vue', () => ({ default: { name: 'Message', template: '<div />', props: ['envelope', 'message', 'fullHeight', 'smartReplies', 'replyButtonLabel'] } }))
vi.mock('@/components/Moment.vue', () => ({ default: { name: 'Moment', template: '<div />', props: ['timestamp'] } }))
vi.mock('@/components/Error.vue', () => ({ default: { name: 'Error', template: '<div />', props: ['error', 'message', 'data', 'autoMargin'] } }))
vi.mock('@/components/MessageLoadingSkeleton.vue', () => ({ default: { name: 'MessageLoadingSkeleton', template: '<div />' } }))
vi.mock('@/components/ConfirmationModal.vue', () => ({ default: { name: 'ConfirmModal', template: '<div />' } }))
vi.mock('@/components/icons/JunkIcon.vue', () => ({ default: { name: 'JunkIcon', template: '<span />', props: ['size'] } }))
vi.mock('@/components/TagModal.vue', () => ({ default: { name: 'TagModal', template: '<div />' } }))
vi.mock('@/components/MoveModal.vue', () => ({ default: { name: 'MoveModal', template: '<div />' } }))
vi.mock('@/components/TaskModal.vue', () => ({ default: { name: 'TaskModal', template: '<div />' } }))
vi.mock('@/components/EventModal.vue', () => ({ default: { name: 'EventModal', template: '<div />' } }))
vi.mock('@/components/TranslationModal.vue', () => ({ default: { name: 'TranslationModal', template: '<div />' } }))

// @nextcloud libraries
vi.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton', template: '<button><slot /></button>' },
	NcModal: { name: 'NcModal', template: '<div><slot /></div>' },
}))
vi.mock('@nextcloud/vue/components/NcActions', () => ({ default: { name: 'NcActions', template: '<div><slot /></div>', props: ['inline'] } }))
vi.mock('@nextcloud/vue/components/NcActionText', () => ({ default: { name: 'NcActionText', template: '<div><slot /></div>' } }))

vi.mock('@nextcloud/moment', () => {
	const fn = vi.fn(() => ({ format: vi.fn(() => '2026-01-01') }))
	fn.unix = vi.fn(() => fn())
	return { __esModule: true, default: fn }
})
vi.mock('@nextcloud/dialogs', () => ({
	showError: vi.fn(),
	showSuccess: vi.fn(),
	TOAST_UNDO_TIMEOUT: 10000,
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((url) => url),
}))
vi.mock('@nextcloud/initial-state', () => ({
	loadState: vi.fn(() => false),
}))
vi.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: vi.fn(() => Promise.resolve({ data: {} })), post: vi.fn(() => Promise.resolve({ data: {} })) },
}))

// App-level mocks
vi.mock('@/logger.js', () => {
	const logger = { debug: vi.fn(), error: vi.fn(), warn: vi.fn(), info: vi.fn() }
	return { __esModule: true, default: logger }
})
vi.mock('@/util/acl.js', () => ({
	mailboxHasRights: vi.fn(() => true),
}))
vi.mock('@/crypto/pgp.js', () => ({
	isPgpText: vi.fn(() => false),
}))
vi.mock('@/ReplyBuilder.js', () => ({
	buildRecipients: vi.fn(() => ({ to: [], cc: [] })),
}))
vi.mock('@/service/AiIntergrationsService.js', () => ({
	smartReply: vi.fn(() => Promise.resolve([])),
}))
vi.mock('@/service/ListService.js', () => ({
	unsubscribe: vi.fn(),
}))
vi.mock('@/errors/match.js', () => ({
	matchError: vi.fn(),
}))
vi.mock('@/errors/NoTrashMailboxConfiguredError.js', () => ({
	default: { getName: vi.fn(() => 'NoTrashMailboxConfiguredError') },
	getName: vi.fn(() => 'NoTrashMailboxConfiguredError'),
}))
vi.mock('@/components/tags.js', () => ({
	hiddenTags: {},
}))
vi.mock('@/util/tag.js', () => ({
	translateTagDisplayName: vi.fn((t) => t),
}))
vi.mock('@/util/text.js', () => ({
	Text: vi.fn(),
	toPlain: vi.fn(() => ({ value: '' })),
}))
vi.mock('@/store/constants.js', () => ({
	FOLLOW_UP_TAG_LABEL: '$follow_up',
}))
vi.mock('html2pdf.js', () => ({
	default: vi.fn(() => ({
		from: vi.fn().mockReturnThis(),
		set: vi.fn().mockReturnThis(),
		outputPdf: vi.fn(() => Promise.resolve(new Blob())),
		save: vi.fn(),
	})),
}))
vi.mock('../../utils/itslHelperFunctions.js', () => ({
	messageToHtml: vi.fn(() => '<html></html>'),
	generateFilename: vi.fn(() => 'file.pdf'),
	messageTypeToFolderName: vi.fn(() => 'SDK'),
	hasInternalMailboxFunc: vi.fn(() => false),
}))
vi.mock('lodash/fp.js', () => ({
	cloneDeep: vi.fn((obj) => JSON.parse(JSON.stringify(obj))),
}))

// SVG import
vi.mock('@/../img/important.svg', () => ({ default: '<svg />' }))

// Pinia stores
vi.mock('@/store/outboxStore.js', async () => {
	const { defineStore } = await vi.importActual('pinia')
	return {
		default: defineStore('outbox', {
			state: () => ({}),
			actions: {
				enqueueMessage: vi.fn(() => Promise.resolve({ id: 1 })),
				sendMessage: vi.fn(() => Promise.resolve()),
			},
		}),
	}
})

const { mockGetEnvelopeTagsRef, mockFetchMessageRef, mockToggleEnvelopeImportantRef, mockToggleEnvelopeSeenRef } = vi.hoisted(() => ({
	mockGetEnvelopeTagsRef: vi.fn(() => []),
	mockFetchMessageRef: vi.fn(() => Promise.resolve(undefined)),
	mockToggleEnvelopeImportantRef: vi.fn(),
	mockToggleEnvelopeSeenRef: vi.fn(() => Promise.resolve()),
}))

vi.mock('@/store/mainStore.js', async () => {
	const { defineStore } = await vi.importActual('pinia')
	return {
		default: defineStore('main', {
			state: () => ({}),
			getters: {
				getAccount: () => () => ({
					id: 1,
					name: 'Test',
					emailAddress: 'test@example.com',
					archiveMailboxId: null,
				}),
				getAccounts: () => [],
				getMailbox: () => () => ({
					databaseId: 100,
					specialRole: null,
				}),
				getPreference: () => () => 'false',
				getEnvelopeTags: () => mockGetEnvelopeTagsRef,
				isInternalAddress: () => () => false,
				getInbox: () => () => ({ databaseId: 100 }),
			},
			actions: {
				fetchMessage: mockFetchMessageRef,
				toggleEnvelopeImportant: mockToggleEnvelopeImportantRef,
				toggleEnvelopeSeen: mockToggleEnvelopeSeenRef,
				toggleEnvelopeFlagged: vi.fn(),
				toggleEnvelopeJunk: vi.fn(),
				deleteMessage: vi.fn(),
				moveMessage: vi.fn(),
				startComposerSession: vi.fn(),
				clearFollowUpReminder: vi.fn(),
				fetchItineraries: vi.fn(() => Promise.resolve([])),
				fetchDkim: vi.fn(() => Promise.resolve()),
			},
		}),
	}
})

// --- Import component and fixtures AFTER all mocks ---

import { shallowMount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ThreadEnvelopeItsl from '../../components/message/ThreadEnvelopeItsl.vue'
import { mailboxHasRights } from '@/util/acl.js'
import { createEnvelope, createSdkEnvelope, createFaxEnvelope } from '../fixtures/envelopes.js'
import { MESSAGE_TYPES, MESSAGE_DIRECTION } from '../../store/constants.js'

// Expose hoisted mocks under the original names used in tests below
const mockGetEnvelopeTags = mockGetEnvelopeTagsRef
const mockFetchMessage = mockFetchMessageRef
const mockToggleEnvelopeImportant = mockToggleEnvelopeImportantRef
const mockToggleEnvelopeSeen = mockToggleEnvelopeSeenRef

// --- Helper ---

function mountThreadEnvelope(overrides = {}) {
	return shallowMount(ThreadEnvelopeItsl, {
		propsData: {
			envelope: createEnvelope(),
			threadSubject: 'Test Subject',
			...overrides,
		},
		stubs: {
			RouterLink: { name: 'RouterLink', template: '<a><slot /></a>', props: ['to'] },
		},
		mocks: {
			t: (app, str) => str,
			$route: { params: { mailboxId: '100' } },
			$router: { push: vi.fn() },
		},
	})
}

describe('ThreadEnvelopeItsl', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		mockGetEnvelopeTags.mockReturnValue([])
		mockFetchMessage.mockResolvedValue(undefined)
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('renders collapsed state - no message body visible', () => {
		const wrapper = mountThreadEnvelope({ expanded: false })
		expect(wrapper.findComponent({ name: 'Message' }).exists()).toBe(false)
		expect(wrapper.classes()).not.toContain('envelope--expanded')
	})

	it('expanding emits toggle-expand', async () => {
		const wrapper = mountThreadEnvelope({ expanded: false })
		await wrapper.find('a').trigger('click')
		expect(wrapper.emitted('toggle-expand')).toBeTruthy()
	})

	it('isImportant computed - true when envelope tags include $label1', () => {
		mockGetEnvelopeTags.mockReturnValue([{ imapLabel: '$label1', displayName: 'Important' }])
		const wrapper = mountThreadEnvelope()
		expect(wrapper.vm.isImportant).toBeTruthy()
	})

	it('isImportant - false when no $label1 tag', () => {
		mockGetEnvelopeTags.mockReturnValue([{ imapLabel: 'other', displayName: 'Other' }])
		const wrapper = mountThreadEnvelope()
		expect(wrapper.vm.isImportant).toBeFalsy()
	})

	it('onToggleSeen calls store action', async () => {
		const envelope = createEnvelope({ flags: { seen: true, flagged: false, important: false } })
		const wrapper = mountThreadEnvelope({ envelope })
		await wrapper.vm.onToggleSeen()
		expect(mockToggleEnvelopeSeen).toHaveBeenCalledWith({ envelope })
	})

	it('MessageHeaderItsl receives correct message prop for SDK type', () => {
		const envelope = createSdkEnvelope()
		const wrapper = mountThreadEnvelope({ envelope })
		const header = wrapper.findComponent({ name: 'MessageHeaderItsl' })
		expect(header.exists()).toBe(true)
		expect(header.props('message')).toEqual(envelope)
	})

	it('AvatarMessageDirectionItsl shows INCOMING direction correctly', () => {
		const envelope = createEnvelope({
			itsl: { messageType: MESSAGE_TYPES.INTERNAL.id, messageDirection: MESSAGE_DIRECTION.INCOMING },
		})
		const wrapper = mountThreadEnvelope({ envelope })
		const avatar = wrapper.findComponent({ name: 'AvatarMessageDirectionItsl' })
		expect(avatar.exists()).toBe(true)
		expect(avatar.props('messageDirection')).toBe(MESSAGE_DIRECTION.INCOMING)
	})

	it('AvatarMessageDirectionItsl shows OUTGOING direction correctly', () => {
		const envelope = createEnvelope({
			itsl: { messageType: MESSAGE_TYPES.INTERNAL.id, messageDirection: MESSAGE_DIRECTION.OUTGOING },
		})
		const wrapper = mountThreadEnvelope({ envelope })
		const avatar = wrapper.findComponent({ name: 'AvatarMessageDirectionItsl' })
		expect(avatar.props('messageDirection')).toBe(MESSAGE_DIRECTION.OUTGOING)
	})

	it('follow-up header renders when showFollowUpHeader is true', () => {
		mockGetEnvelopeTags.mockReturnValue([{ imapLabel: '$follow_up', displayName: 'Follow up' }])
		const wrapper = mountThreadEnvelope()
		expect(wrapper.find('.envelope__follow-up-header').exists()).toBe(true)
	})

	it('confidential envelope adds secure CSS class', () => {
		const envelope = createSdkEnvelope()
		envelope.itsl.sdk.messageHeader.confidentiality = true
		const wrapper = mountThreadEnvelope({ envelope })
		expect(wrapper.classes()).toContain('envelope--secure')
	})

	it('FAX envelope hides changed subject line', () => {
		const envelope = createFaxEnvelope({ subject: 'Different Subject' })
		const wrapper = mountThreadEnvelope({ envelope, threadSubject: 'Original Subject' })
		const sublines = wrapper.findAll('.subline')
		const subjectSublines = sublines.filter((w) => w.text().includes('Different Subject'))
		expect(subjectSublines).toHaveLength(0)
	})

	it('ACL-based button visibility - write ACL controls toggle buttons', () => {
		mailboxHasRights.mockReturnValue(false)
		const wrapper = mountThreadEnvelope()
		expect(wrapper.vm.hasWriteAcl).toBe(false)
	})

	it('SDK timestamp uses creationDateTime', () => {
		const isoDate = '2026-02-15T10:30:00.000Z'
		const envelope = createSdkEnvelope()
		envelope.itsl.sdk.messageHeader.creationDateTime = isoDate
		const wrapper = mountThreadEnvelope({ envelope })
		const moments = wrapper.findAllComponents({ name: 'Moment' })
		const sdkMoment = moments.wrappers.find((w) => w.classes().includes('timestamp-sdk'))
		expect(sdkMoment).toBeTruthy()
		const expectedTimestamp = Math.floor(new Date(isoDate).getTime() / 1000)
		expect(sdkMoment.props('timestamp')).toBe(expectedTimestamp)
	})
})
