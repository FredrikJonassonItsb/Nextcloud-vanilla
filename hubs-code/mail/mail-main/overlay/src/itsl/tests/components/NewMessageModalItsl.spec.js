// --- Mocks (before component import) ---

const { iconStub } = vi.hoisted(() => ({
	iconStub: (name) => ({ default: { name, template: '<span />', props: ['size'] } }),
}))

vi.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton', template: '<button><slot /></button>', props: ['type', 'ariaLabel'] },
	NcEmptyContent: { name: 'EmptyContent', template: '<div class="empty-content"><slot /><slot name="description" /><slot name="action" /></div>', props: ['name'] },
	NcModal: { name: 'Modal', template: '<div class="modal"><slot /></div>', props: ['size', 'name', 'labelId', 'additionalTrapElements'] },
	NcLoadingIcon: { name: 'Loading', template: '<div class="loading" />' },
}))

vi.mock('vue-material-design-icons/Minus.vue', () => iconStub('MinimizeIcon'))
vi.mock('vue-material-design-icons/ArrowExpand.vue', () => iconStub('MaximizeIcon'))
vi.mock('vue-material-design-icons/ArrowCollapse.vue', () => iconStub('DefaultComposerIcon'))

vi.mock('../../components/message/ComposerItsl.vue', () => ({
	default: {
		name: 'ComposerItsl',
		template: '<div class="composer-stub" />',
		methods: { getMessageData() { return { body: { value: '' } } } },
	},
}))

vi.mock('@/components/Loading.vue', () => ({
	default: {
		name: 'Loading',
		template: '<div class="loading-stub" />',
		props: ['hint'],
	},
}))

vi.mock('@/components/RecipientInfo.vue', () => ({
	default: {
		name: 'RecipientInfo',
		template: '<div />',
	},
}))

vi.mock('@/logger.js', () => ({
	__esModule: true,
	default: { debug: vi.fn(), error: vi.fn(), warn: vi.fn(), info: vi.fn() },
}))

vi.mock('@/service/DraftService.js', () => ({
	saveDraft: vi.fn(() => Promise.resolve({ id: 101 })),
	updateDraft: vi.fn(() => Promise.resolve()),
	deleteDraft: vi.fn(),
}))

vi.mock('@/util/text.js', () => ({
	detect: vi.fn(() => 'html'),
	html: vi.fn((str) => ({ isHtml: true, value: str || '', format: 'html' })),
	plain: vi.fn((str) => ({ isHtml: false, value: str || '', format: 'plain' })),
	toHtml: vi.fn((body) => body),
	toPlain: vi.fn((body) => body),
}))

vi.mock('@nextcloud/dialogs', () => ({
	showError: vi.fn(),
	showSuccess: vi.fn(),
	TOAST_UNDO_TIMEOUT: 10000,
}))

vi.mock('@nextcloud/moment', () => {
	const fn = vi.fn(() => ({ format: vi.fn(() => ''), toDate: vi.fn(() => new Date()) }))
	fn.unix = vi.fn(() => fn())
	return { __esModule: true, default: fn }
})

vi.mock('@nextcloud/l10n', () => ({
	translate: vi.fn((app, str) => str),
}))

vi.mock('@/store/constants.js', () => ({
	UNDO_DELAY: 7000,
}))

vi.mock('@/errors/match.js', () => ({
	matchError: vi.fn(() => Promise.resolve(undefined)),
}))

vi.mock('@/errors/NoSentMailboxConfiguredError.js', () => ({
	default: { getName: () => 'NoSentMailboxConfiguredError' },
	getName: () => 'NoSentMailboxConfiguredError',
}))
vi.mock('@/errors/ManyRecipientsError.js', () => ({
	default: { getName: () => 'ManyRecipientsError' },
	getName: () => 'ManyRecipientsError',
}))
vi.mock('@/errors/AttachmentMissingError.js', () => ({
	default: { getName: () => 'AttachmentMissingError' },
	getName: () => 'AttachmentMissingError',
}))

const defaultComposerMessage = {
	type: 'new',
	data: { accountId: 1, to: [], cc: [], bcc: [], subject: '', body: '', attachments: [], sendAt: 0 },
	options: {},
}

vi.mock('@/store/mainStore.js', async () => {
	const { defineStore } = await vi.importActual('pinia')
	return {
		default: defineStore('main', {
			state: () => ({
				showMessageComposer: false,
				composerSessionId: undefined,
				newMessage: undefined,
				composerMessageIsSaved: false,
				_composerMessage: {
					type: 'new',
					data: { accountId: 1, to: [], cc: [], bcc: [], subject: '', body: '', attachments: [], sendAt: 0 },
					options: {},
				},
			}),
			getters: {
				composerMessage: (state) => state._composerMessage,
				getPreference: () => () => 'normal',
				getAccount: () => () => null,
			},
			actions: {
				hideMessageComposerMutation() {},
				showMessageComposerMutation() {},
				stopComposerSession() {},
				patchComposerData() {},
				setComposerIndicatorDisabledMutation() {},
				setComposerMessageSavedMutation() {},
				removeEnvelopeMutation() {},
				removeMessageMutation() {},
				savePreference() {},
				syncEnvelopes() {},
			},
		}),
	}
})

vi.mock('@/store/outboxStore.js', async () => {
	const { defineStore } = await vi.importActual('pinia')
	return {
		default: defineStore('outbox', {
			state: () => ({}),
			actions: {
				enqueueFromDraft() {},
				updateMessage() {},
				deleteMessage() {},
				sendMessageWithUndo() { return Promise.resolve() },
			},
		}),
	}
})

// --- Import component after mocks ---

import { shallowMount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import NewMessageModalItsl from '../../components/message/NewMessageModalItsl.vue'
import useMainStore from '@/store/mainStore.js'

const flushPromises = () => new Promise((resolve) => setTimeout(resolve, 0))

describe('NewMessageModalItsl', () => {
	let mainStore

	beforeEach(() => {
		setActivePinia(createPinia())
		mainStore = useMainStore()
		// Suppress Vue warn noise from shallowMount ($refs.composer.getMessageData)
		vi.spyOn(console, 'error').mockImplementation(() => {})
		vi.clearAllMocks()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	const mountModal = (overrides = {}) => {
		return shallowMount(NewMessageModalItsl, {
			propsData: {
				accounts: [{ id: 1, emailAddress: 'test@example.com', aliases: [] }],
				...overrides,
			},
			mocks: {
				t: (app, str) => str,
				$route: { params: {} },
			},
		})
	}

	// 1. Renders Modal when showMessageComposer is truthy
	it('renders Modal when showMessageComposer is truthy', () => {
		mainStore.$patch({ showMessageComposer: true })
		const wrapper = mountModal()
		expect(wrapper.findComponent({ name: 'Modal' }).exists()).toBe(true)
	})

	// 2. Does not render Modal when showMessageComposer is falsy
	it('does not render Modal when showMessageComposer is falsy', () => {
		mainStore.$patch({ showMessageComposer: false })
		const wrapper = mountModal()
		expect(wrapper.findComponent({ name: 'Modal' }).exists()).toBe(false)
	})

	// 3. Modal contains ComposerItsl child component
	it('contains ComposerItsl child inside the modal', () => {
		mainStore.$patch({ showMessageComposer: true })
		const wrapper = mountModal()
		expect(wrapper.findComponent({ name: 'ComposerItsl' }).exists()).toBe(true)
	})

	// 4. modalTitle shows "Reply" for reply messages
	it('modalTitle returns "Reply" when composerData has replyTo', () => {
		mainStore.$patch({
			showMessageComposer: true,
			_composerMessage: {
				type: 'reply',
				data: { accountId: 1, to: [], cc: [], bcc: [], subject: 'Test', body: '', attachments: [], replyTo: { messageId: 'abc' }, sendAt: 0 },
				options: {},
			},
		})
		const wrapper = mountModal()
		expect(wrapper.vm.modalTitle).toBe('Reply')
	})

	// 5. modalTitle shows null for new compose (no special type)
	it('modalTitle returns null for a new message', () => {
		mainStore.$patch({
			showMessageComposer: true,
			_composerMessage: {
				type: 'new',
				data: { accountId: 1, to: [], cc: [], bcc: [], subject: '', body: '', attachments: [], sendAt: 0 },
				options: {},
			},
		})
		const wrapper = mountModal()
		expect(wrapper.vm.modalTitle).toBeNull()
	})

	// 6. onClose calls onMinimize which triggers hideMessageComposerMutation
	it('onClose triggers hideMessageComposerMutation via onMinimize', async () => {
		mainStore.$patch({ showMessageComposer: true, composerMessageIsSaved: false })
		const spy = vi.spyOn(mainStore, 'hideMessageComposerMutation')
		const wrapper = mountModal()
		wrapper.vm.changed = true
		wrapper.vm.cookedComposerData = { body: { value: 'test' }, accountId: 1, to: [], cc: [], bcc: [], attachments: [] }

		await wrapper.vm.onClose()
		await flushPromises()

		expect(spy).toHaveBeenCalled()
	})

	// 7. Error state renders EmptyContent with error message
	it('renders error content when error is set', async () => {
		mainStore.$patch({ showMessageComposer: true })
		const wrapper = mountModal()
		await wrapper.setData({ error: 'Something went wrong' })

		const emptyContent = wrapper.findComponent({ name: 'EmptyContent' })
		expect(emptyContent.exists()).toBe(true)
		expect(wrapper.text()).toContain('Something went wrong')
	})

	// 8. Sending state shows Loading component
	it('shows Loading component when sending is true', async () => {
		mainStore.$patch({ showMessageComposer: true })
		const wrapper = mountModal()
		await wrapper.setData({ sending: true })

		const loading = wrapper.findComponent({ name: 'Loading' })
		expect(loading.exists()).toBe(true)
	})

	// 9. Warning state shows EmptyContent with Go back and Send anyway buttons
	it('renders warning content with Go back and Send anyway buttons', async () => {
		mainStore.$patch({ showMessageComposer: true })
		const wrapper = mountModal()
		await wrapper.setData({ warning: 'Missing attachment?' })

		const emptyContent = wrapper.findComponent({ name: 'EmptyContent' })
		expect(emptyContent.exists()).toBe(true)

		const buttons = wrapper.findAllComponents({ name: 'NcButton' })
		const buttonTexts = buttons.wrappers.map((b) => b.text().trim())
		expect(buttonTexts).toContain('Go back')
		expect(buttonTexts).toContain('Send anyway')
	})

	// 10. Modal size toggles with maximize/minimize (largerModal state)
	it('toggles largerModal on onMaximize call', async () => {
		mainStore.$patch({ showMessageComposer: true })
		const spy = vi.spyOn(mainStore, 'savePreference')
		const wrapper = mountModal()

		expect(wrapper.vm.largerModal).toBe(false)
		await wrapper.vm.onMaximize()
		expect(wrapper.vm.largerModal).toBe(true)
		expect(spy).toHaveBeenCalledWith({ key: 'modalSize', value: 'large' })

		await wrapper.vm.onMaximize()
		expect(wrapper.vm.largerModal).toBe(false)
		expect(spy).toHaveBeenCalledWith({ key: 'modalSize', value: 'normal' })
	})
})
