import { MESSAGE_TYPES } from '../../store/constants.js'

// --- Icon stubs ---
const { iconStub } = vi.hoisted(() => ({
	iconStub: (name) => ({ default: { name, template: '<span />', props: ['size'] } }),
}))

vi.mock('vue-material-design-icons/Account.vue', () => iconStub('Account'))
vi.mock('vue-material-design-icons/AlertOctagon.vue', () => iconStub('AlertOctagon'))
vi.mock('vue-material-design-icons/Calendar.vue', () => iconStub('Calendar'))
vi.mock('vue-material-design-icons/Creation.vue', () => iconStub('Creation'))
vi.mock('vue-material-design-icons/ClockOutline.vue', () => iconStub('ClockOutline'))
vi.mock('vue-material-design-icons/Check.vue', () => iconStub('Check'))
vi.mock('vue-material-design-icons/ChevronLeft.vue', () => iconStub('ChevronLeft'))
vi.mock('vue-material-design-icons/Delete.vue', () => iconStub('Delete'))
vi.mock('vue-material-design-icons/PackageDown.vue', () => iconStub('PackageDown'))
vi.mock('vue-material-design-icons/CheckboxMarkedCirclePlusOutline.vue', () => iconStub('CheckboxMarkedCirclePlusOutline'))
vi.mock('vue-material-design-icons/DotsHorizontal.vue', () => iconStub('DotsHorizontal'))
vi.mock('vue-material-design-icons/OpenInNew.vue', () => iconStub('OpenInNew'))
vi.mock('vue-material-design-icons/StarOutline.vue', () => iconStub('StarOutline'))
vi.mock('vue-material-design-icons/Star.vue', () => iconStub('Star'))
vi.mock('vue-material-design-icons/Reply.vue', () => iconStub('Reply'))
vi.mock('vue-material-design-icons/EmailOpen.vue', () => iconStub('EmailOpen'))
vi.mock('vue-material-design-icons/Email.vue', () => iconStub('Email'))
vi.mock('vue-material-design-icons/Paperclip.vue', () => iconStub('Paperclip'))
vi.mock('vue-material-design-icons/Plus.vue', () => iconStub('Plus'))
vi.mock('vue-material-design-icons/Tag.vue', () => iconStub('Tag'))
vi.mock('vue-material-design-icons/Download.vue', () => iconStub('Download'))
vi.mock('vue-material-design-icons/CalendarClock.vue', () => iconStub('CalendarClock'))
vi.mock('vue-material-design-icons/Alarm.vue', () => iconStub('Alarm'))

// --- Component stubs ---
vi.mock('@/components/EnvelopeSkeleton.vue', () => ({
	default: {
		name: 'EnvelopeSkeleton',
		template: '<div class="envelope-skeleton"><slot name="icon" /><slot name="name" /><slot name="subname" /><slot name="actions" /><slot name="tags" /><slot name="extra" /></div>',
		props: ['to', 'exact', 'name', 'details', 'oneLine', 'hasAttachment', 'messageType', 'dataEnvelopeId'],
	},
}))
vi.mock('@/components/Avatar.vue', () => ({ default: { name: 'Avatar', template: '<div class="avatar" />', props: ['displayName', 'email', 'size'] } }))
vi.mock('../../components/message/AvatarMessageTypeItsl.vue', () => ({ default: { name: 'AvatarMessageTypeItsl', template: '<div class="avatar-message-type" />', props: ['messageType', 'email', 'size'] } }))
vi.mock('../../components/icons/ImportantIcon.vue', () => ({ default: { name: 'ImportantIcon', template: '<span />', props: ['size'] } }))
vi.mock('@/components/EnvelopePrimaryActions.vue', () => ({ default: { name: 'EnvelopePrimaryActions', template: '<div><slot /></div>' } }))
vi.mock('@/components/MoveModal.vue', () => ({ default: { name: 'MoveModal', template: '<div />' } }))
vi.mock('@/components/TagModal.vue', () => ({ default: { name: 'TagModal', template: '<div />' } }))
vi.mock('@/components/EventModal.vue', () => ({ default: { name: 'EventModal', template: '<div />' } }))
vi.mock('@/components/TaskModal.vue', () => ({ default: { name: 'TaskModal', template: '<div />' } }))

// --- Directives ---
vi.mock('@/directives/drag-and-drop/draggable-envelope/index.js', () => ({
	DraggableEnvelopeDirective: { bind() {}, unbind() {} },
}))

// --- Services / utils ---
vi.mock('@/ReplyBuilder.js', () => ({ buildRecipients: vi.fn(() => ({ to: [], cc: [] })) }))
vi.mock('@/util/shortRelativeDatetime.js', () => ({
	shortRelativeDatetime: vi.fn(() => '1m'),
	messageDateTime: vi.fn(() => 'Jan 1, 2026'),
}))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn(), TOAST_UNDO_TIMEOUT: 10000 }))
vi.mock('@/errors/NoTrashMailboxConfiguredError.js', () => ({
	default: class NoTrashMailboxConfiguredError extends Error {
		static getName() { return 'NoTrashMailboxConfiguredError' }
	},
}))
vi.mock('@/logger.js', () => ({ __esModule: true, default: { debug: vi.fn(), error: vi.fn(), warn: vi.fn(), info: vi.fn() } }))
vi.mock('@/errors/match.js', () => ({ matchError: vi.fn() }))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@/crypto/pgp.js', () => ({ isPgpText: vi.fn(() => false) }))
vi.mock('@/components/tags.js', () => ({ hiddenTags: {} }))
vi.mock('@/util/tag.js', () => ({ translateTagDisplayName: vi.fn((tag) => tag.displayName) }))
vi.mock('@nextcloud/moment', () => {
	const fn = vi.fn(() => ({ add: vi.fn(() => ({ minute: vi.fn(() => ({ second: vi.fn(() => ({ valueOf: vi.fn(() => Date.now()) })) })) })), format: vi.fn(() => '') }))
	fn.unix = vi.fn(() => fn())
	return { __esModule: true, default: fn }
})
vi.mock('@/store/constants.js', () => ({ FOLLOW_UP_TAG_LABEL: '$follow_up' }))

// --- ACL ---
vi.mock('@/util/acl.js', () => ({
	mailboxHasRights: vi.fn(() => true),
}))

// --- Tag helpers ---
vi.mock('../../utils/tagHelpers.js', () => ({
	getEnvelopeTags: vi.fn(() => []),
}))

// --- ITSL helpers (real parseAddressInfoFromString) ---
vi.mock('../../utils/itslHelperFunctions.js', async () => {
	const actual = await vi.importActual('../../utils/itslHelperFunctions.js')
	return {
		...actual,
		parseAddressInfoFromString: vi.fn(actual.parseAddressInfoFromString),
	}
})

// --- Static SVG import ---
vi.mock('@/../img/important.svg', () => ({ default: '<svg />' }))

// --- @nextcloud/vue ---
vi.mock('@nextcloud/vue', () => ({
	NcActionButton: { name: 'NcActionButton', template: '<div><slot /></div>', props: ['closeAfterClick'] },
	NcActionLink: { name: 'NcActionLink', template: '<div><slot /></div>', props: ['href', 'closeAfterClick'] },
	NcActionSeparator: { name: 'NcActionSeparator', template: '<div />' },
	NcActionInput: { name: 'NcActionInput', template: '<div />', props: ['type', 'value', 'min', 'isNativePicker'] },
	NcActionText: { name: 'NcActionText', template: '<div><slot /></div>' },
}))

// --- Pinia store mock ---
vi.mock('@/store/mainStore.js', async () => {
	const { defineStore } = await vi.importActual('pinia')
	return {
		default: defineStore('main', {
			state: () => ({
				isSnoozeDisabled: false,
				_mockEnvelopeTags: [],
			}),
			getters: {
				getPreference: () => () => 'vertical-split',
				getAccount: () => (id) => ({
					id, name: 'Test', emailAddress: 'test@test.com',
					sentMailboxId: 200, archiveMailboxId: 300, snoozeMailboxId: 400,
				}),
				getMailbox: () => (id) => ({ databaseId: id, myRights: 'lrswipkxtecda' }),
				getEnvelopeTags(state) { return () => state._mockEnvelopeTags },
			},
			actions: {
				toggleEnvelopeImportant: vi.fn(),
				toggleEnvelopeFlagged: vi.fn(),
				toggleEnvelopeSeen: vi.fn(),
				toggleEnvelopeJunk: vi.fn(),
				deleteThread: vi.fn(),
				snoozeThread: vi.fn(),
				unSnoozeThread: vi.fn(),
				archiveThread: vi.fn(),
				startComposerSession: vi.fn(),
			},
		}),
	}
})

// --- Import component under test + fixtures ---
import { shallowMount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import EnvelopeItsl from '../../components/EnvelopeItsl.vue'
import { createEnvelope, createSdkEnvelope, createSecureEnvelope, createFaxEnvelope } from '../fixtures/envelopes.js'
import { mailboxHasRights } from '@/util/acl.js'
import { getEnvelopeTags } from '../../utils/tagHelpers.js'
import useMainStore from '@/store/mainStore.js'

const defaultMailbox = {
	databaseId: 100, accountId: 1, specialRole: 'inbox',
	isUnified: false, myRights: 'lrswipkxtecda',
}

const defaultItsl = { messageType: 'internal_message', messageDirection: 'outgoing' }

const mountEnvelope = (dataOverrides = {}, propsOverrides = {}) => {
	const envelope = createEnvelope({ itsl: defaultItsl, ...dataOverrides })
	return shallowMount(EnvelopeItsl, {
		propsData: {
			data: envelope,
			mailbox: defaultMailbox,
			...propsOverrides,
		},
		mocks: {
			t: (app, str) => str,
			$route: { params: { mailboxId: 100 }, query: {} },
		},
		stubs: {
			'router-link': { name: 'RouterLink', template: '<a><slot /></a>', props: ['to'] },
		},
	})
}

describe('EnvelopeItsl', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.clearAllMocks()
		mailboxHasRights.mockReturnValue(true)
		getEnvelopeTags.mockReturnValue([])
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('renders EnvelopeSkeleton with message-type prop for SDK envelope', () => {
		const sdk = createSdkEnvelope()
		const wrapper = shallowMount(EnvelopeItsl, {
			propsData: { data: sdk, mailbox: defaultMailbox },
			mocks: { t: (app, str) => str, $route: { params: { mailboxId: 100 }, query: {} } },
			stubs: { 'router-link': { name: 'RouterLink', template: '<a><slot /></a>', props: ['to'] } },
		})
		const skeleton = wrapper.findComponent({ name: 'EnvelopeSkeleton' })
		expect(skeleton.exists()).toBe(true)
		expect(skeleton.props('messageType')).toBe(MESSAGE_TYPES.SDK.id)
	})

	it('renders AvatarMessageTypeItsl in unified mailbox', () => {
		const sdk = createSdkEnvelope()
		const wrapper = shallowMount(EnvelopeItsl, {
			propsData: { data: sdk, mailbox: { ...defaultMailbox, isUnified: true } },
			mocks: { t: (app, str) => str, $route: { params: { mailboxId: 100 }, query: {} } },
			stubs: { 'router-link': { name: 'RouterLink', template: '<a><slot /></a>', props: ['to'] } },
		})
		expect(wrapper.findComponent({ name: 'AvatarMessageTypeItsl' }).exists()).toBe(true)
	})

	it('renders regular Avatar in non-unified mailbox', () => {
		const wrapper = mountEnvelope()
		expect(wrapper.findComponent({ name: 'Avatar' }).exists()).toBe(true)
		expect(wrapper.findComponent({ name: 'AvatarMessageTypeItsl' }).exists()).toBe(false)
	})

	it('isImportant is true when tags include $label1', () => {
		const mainStore = useMainStore()
		mainStore.$patch({ _mockEnvelopeTags: [{ imapLabel: '$label1', displayName: 'Important' }] })
		const wrapper = mountEnvelope()
		expect(wrapper.vm.isImportant).toBe(true)
	})

	it('isImportant is false when no $label1 tag', () => {
		const wrapper = mountEnvelope()
		expect(wrapper.vm.isImportant).toBe(false)
	})

	it('draft envelope renders Draft label in name slot', () => {
		const wrapper = mountEnvelope({ flags: { seen: false, flagged: false, draft: true } })
		expect(wrapper.find('.draft-label').exists()).toBe(true)
		expect(wrapper.find('.draft-label').text()).toContain('Draft')
	})

	it('FAX envelope shows empty subject', () => {
		const fax = createFaxEnvelope({ subject: 'Should be hidden' })
		const wrapper = shallowMount(EnvelopeItsl, {
			propsData: { data: fax, mailbox: defaultMailbox },
			mocks: { t: (app, str) => str, $route: { params: { mailboxId: 100 }, query: {} } },
			stubs: { 'router-link': { name: 'RouterLink', template: '<a><slot /></a>', props: ['to'] } },
		})
		expect(wrapper.vm.subjectForSubtitle).toBe('')
	})

	it('tags split: assignmentTags vs otherTags', () => {
		getEnvelopeTags.mockReturnValue([
			{ id: 1, imapLabel: 'assign-1', displayName: 'Alice', color: '#f00', isAssignmentTag: true },
			{ id: 2, imapLabel: 'cat-1', displayName: 'Urgent', color: '#0f0', isAssignmentTag: false },
			{ id: 3, imapLabel: 'assign-2', displayName: 'Bob', color: '#00f', isAssignmentTag: true },
		])
		const wrapper = mountEnvelope()
		expect(wrapper.vm.assignmentTags).toHaveLength(2)
		expect(wrapper.vm.otherTags).toHaveLength(1)
		expect(wrapper.vm.assignmentTags[0].displayName).toBe('Alice')
		expect(wrapper.vm.otherTags[0].displayName).toBe('Urgent')
	})

	it('isDraggable is false for draft', () => {
		const wrapper = mountEnvelope({ flags: { seen: false, flagged: false, draft: true } })
		expect(wrapper.vm.isDraggable).toBe(false)
	})

	it('isDraggable respects mailbox write ACL', () => {
		mailboxHasRights.mockReturnValue(false)
		const wrapper = mountEnvelope()
		expect(wrapper.vm.isDraggable).toBe(false)
	})

	it('SDK addresses computed shows function name and org', () => {
		const sdk = createSdkEnvelope()
		const wrapper = shallowMount(EnvelopeItsl, {
			propsData: { data: sdk, mailbox: defaultMailbox },
			mocks: { t: (app, str) => str, $route: { params: { mailboxId: 100 }, query: {} } },
			stubs: { 'router-link': { name: 'RouterLink', template: '<a><slot /></a>', props: ['to'] } },
		})
		// Outgoing SDK: shows recipient function name (org name)
		expect(wrapper.vm.addresses).toContain('Test Department')
		expect(wrapper.vm.addresses).toContain('Test Organization')
	})

	it('SECURE addresses shows SSN and notification email', () => {
		const secure = createSecureEnvelope()
		const wrapper = shallowMount(EnvelopeItsl, {
			propsData: { data: secure, mailbox: defaultMailbox },
			mocks: { t: (app, str) => str, $route: { params: { mailboxId: 100 }, query: {} } },
			stubs: { 'router-link': { name: 'RouterLink', template: '<a><slot /></a>', props: ['to'] } },
		})
		// Outgoing SECURE: to[0].email = 'user@example.com.123456789012.securemail'
		// parseAddressInfoFromString returns ssn='123456789012', notification='user@example.com'
		expect(wrapper.vm.addresses).toContain('123456789012')
		expect(wrapper.vm.addresses).toContain('user@example.com')
	})
})
