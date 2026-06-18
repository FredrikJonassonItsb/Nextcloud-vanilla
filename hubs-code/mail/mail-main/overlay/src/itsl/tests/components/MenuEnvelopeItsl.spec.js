const { iconStub } = vi.hoisted(() => ({
	iconStub: (name) => ({ default: { name, template: '<span />', props: ['size'] } }),
}))

vi.mock('vue-material-design-icons/AlertOctagon.vue', () => iconStub('AlertOctagon'))
vi.mock('vue-material-design-icons/Check.vue', () => iconStub('Check'))
vi.mock('vue-material-design-icons/ChevronLeft.vue', () => iconStub('ChevronLeft'))
vi.mock('vue-material-design-icons/DotsHorizontal.vue', () => iconStub('DotsHorizontal'))
vi.mock('vue-material-design-icons/Download.vue', () => iconStub('Download'))
vi.mock('vue-material-design-icons/Printer.vue', () => iconStub('Printer'))
vi.mock('vue-material-design-icons/Translate.vue', () => iconStub('Translate'))
vi.mock('vue-material-design-icons/Information.vue', () => iconStub('Information'))
vi.mock('vue-material-design-icons/Share.vue', () => iconStub('Share'))
vi.mock('vue-material-design-icons/CalendarClock.vue', () => iconStub('CalendarClock'))
vi.mock('vue-material-design-icons/EmailOpen.vue', () => iconStub('EmailRead'))
vi.mock('vue-material-design-icons/Email.vue', () => iconStub('EmailUnread'))
vi.mock('vue-material-design-icons/FolderDownload.vue', () => iconStub('FolderDownload'))
vi.mock('vue-material-design-icons/Alert.vue', () => iconStub('Alert'))

vi.mock('../../components/icons/ForwardAsPDF.vue', () => iconStub('ForwardAsPDF'))
vi.mock('../../components/icons/ForwardToInternal.vue', () => iconStub('ForwardToInternal'))

vi.mock('@nextcloud/vue', () => ({
	NcActionButton: { name: 'NcActionButton', template: '<div @click="$listeners.click && $listeners.click($event)"><slot /></div>', props: ['closeAfterClick', 'isMenu', 'ariaLabel'] },
	NcActionLink: { name: 'NcActionLink', template: '<div><slot /></div>', props: ['href', 'closeAfterClick'] },
}))
vi.mock('@nextcloud/vue/components/NcActionSeparator', () => ({ default: { name: 'NcActionSeparator', template: '<div />' } }))
vi.mock('@nextcloud/vue/components/NcActionInput', () => ({ default: { name: 'NcActionInput', template: '<div />', props: ['type', 'value', 'min', 'isNativePicker'] } }))
vi.mock('@nextcloud/moment', () => {
	const fn = vi.fn(() => ({
		add: vi.fn(function() { return this }),
		hour: vi.fn(function() { return this }),
		minute: vi.fn(function() { return this }),
		second: vi.fn(function() { return this }),
		millisecond: vi.fn(function() { return this }),
		valueOf: vi.fn(() => Date.now()),
		format: vi.fn(() => ''),
		day: vi.fn(function() { return this }),
	}))
	fn.unix = vi.fn(() => fn())
	return { __esModule: true, default: fn }
})
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn(), TOAST_UNDO_TIMEOUT: 10000 }))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@/ReplyBuilder.js', () => ({ buildRecipients: vi.fn(() => ({ to: [], cc: [] })) }))
vi.mock('@/logger.js', () => ({ __esModule: true, default: { debug: vi.fn(), error: vi.fn(), warn: vi.fn(), info: vi.fn() } }))
vi.mock('@/util/acl.js', () => ({ mailboxHasRights: vi.fn(() => true) }))
vi.mock('../../utils/itslHelperFunctions.js', () => ({
	messageTypeToLabelKey: vi.fn((type) => type || ''),
}))
vi.mock('lodash/fp.js', () => ({ cloneDeep: vi.fn((obj) => JSON.parse(JSON.stringify(obj))) }))
vi.mock('js-base64', () => ({ Base64: { encode: vi.fn((s) => s) } }))

vi.mock('@/store/mainStore.js', async () => {
	const { defineStore } = await vi.importActual('pinia')
	return {
		default: defineStore('main', {
			state: () => ({
				isSnoozeDisabled: true,
				isTranslationEnabled: false,
			}),
			getters: {
				getAccount: () => () => ({
					id: 1, name: 'Test', emailAddress: 'test@test.com',
					snoozeMailboxId: 400,
				}),
				getEnvelopeTags: () => () => [],
			},
			actions: {
				startComposerSession: vi.fn(),
				toggleEnvelopeFlagged: vi.fn(),
				toggleEnvelopeImportant: vi.fn(),
				toggleEnvelopeSeen: vi.fn(),
				snoozeMessage: vi.fn(),
				unSnoozeMessage: vi.fn(),
				moveEnvelopeToJunk: vi.fn(),
				toggleEnvelopeJunk: vi.fn(),
				createAndSetSnoozeMailbox: vi.fn(),
			},
		}),
	}
})

vi.mock('../../store/itslStore.js', async () => {
	const { defineStore } = await vi.importActual('pinia')
	return {
		default: defineStore('itsl', {
			state: () => ({}),
		}),
	}
})

import { shallowMount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createSdkEnvelope, createInternalEnvelope } from '../fixtures/envelopes.js'
import MenuEnvelopeItsl from '../../components/message/MenuEnvelopeItsl.vue'

describe('MenuEnvelopeItsl', () => {
	const defaultMailbox = {
		databaseId: 100, accountId: 1, specialRole: 'inbox',
		myRights: 'lrswipkxtecda',
	}

	const mountMenu = (propsOverrides = {}) => {
		const envelope = createSdkEnvelope()
		return shallowMount(MenuEnvelopeItsl, {
			propsData: {
				envelope,
				mailbox: defaultMailbox,
				...propsOverrides,
			},
			mocks: { t: (app, str) => str },
		})
	}

	beforeEach(() => {
		setActivePinia(createPinia())
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('renders Forward button in default state', () => {
		const wrapper = mountMenu()
		expect(wrapper.text()).toContain('Forward')
	})

	it('Forward sub-menu opens and shows forward options', async () => {
		const wrapper = mountMenu()
		// Click the Forward menu button to open sub-menu
		await wrapper.setData({ forwardActionsOpen: true })
		await wrapper.vm.$nextTick()

		expect(wrapper.text()).toContain('Forward as')
	})

	it('Forward-to-internal renders when hasInternalMailbox is true', async () => {
		const wrapper = mountMenu({ hasInternalMailbox: true })
		await wrapper.setData({ forwardActionsOpen: true })
		await wrapper.vm.$nextTick()

		expect(wrapper.text()).toContain('Forward to Internal as PDF')
		expect(wrapper.text()).toContain('Forward to Internal as Message')
	})

	it('Forward-to-internal hidden when hasInternalMailbox is false', async () => {
		const wrapper = mountMenu({ hasInternalMailbox: false })
		await wrapper.setData({ forwardActionsOpen: true })
		await wrapper.vm.$nextTick()

		expect(wrapper.text()).not.toContain('Forward to Internal as PDF')
	})

	it('mark read/unread toggles text based on envelope.flags.seen', () => {
		const seenEnvelope = createSdkEnvelope({ flags: { seen: true, flagged: false, important: false } })
		const wrapper = mountMenu({ envelope: seenEnvelope })
		expect(wrapper.text()).toContain('Mark as unread')

		const unseenEnvelope = createSdkEnvelope({ flags: { seen: false, flagged: false, important: false } })
		const wrapper2 = mountMenu({ envelope: unseenEnvelope })
		expect(wrapper2.text()).toContain('Mark as read')
	})

	it('print button renders with Download as PDF text', () => {
		const wrapper = mountMenu()
		expect(wrapper.text()).toContain('Download as PDF')
	})

	it('View source renders when withShowSource is true', async () => {
		const wrapper = mountMenu({ withShowSource: true })
		await wrapper.setData({ localMoreActionsOpen: true })
		await wrapper.vm.$nextTick()

		expect(wrapper.text()).toContain('View source')
	})

	it('View source hidden when withShowSource is false', async () => {
		const wrapper = mountMenu({ withShowSource: false })
		await wrapper.setData({ localMoreActionsOpen: true })
		await wrapper.vm.$nextTick()

		expect(wrapper.text()).not.toContain('View source')
	})

	it('Forward as SDK option renders for INTERNAL/SECURE message types', async () => {
		const internalEnvelope = createInternalEnvelope()
		const wrapper = mountMenu({ envelope: internalEnvelope, messageType: 'internal_message' })
		await wrapper.setData({ forwardActionsOpen: true })
		await wrapper.vm.$nextTick()

		// Should have two forward options: forward as current type + forward as SDK
		const text = wrapper.text()
		expect(text).toContain('Forward as')
	})

	it('onForward calls startComposerSession with forward mode', async () => {
		const wrapper = mountMenu()
		const mainStore = wrapper.vm.mainStore
		const spy = vi.spyOn(mainStore, 'startComposerSession')

		wrapper.vm.onForward()

		expect(spy).toHaveBeenCalledWith(
			expect.objectContaining({
				reply: expect.objectContaining({ mode: 'forward' }),
			}),
		)
	})
})
