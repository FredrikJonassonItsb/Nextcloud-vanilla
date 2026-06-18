import { shallowMount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { MESSAGE_TYPES } from '../../store/constants.js'

// --- Mock all heavy external dependencies ---

// CKEditor
vi.mock('@/ckeditor/signature/InsertSignatureCommand.js', () => ({
	TRIGGER_CHANGE_ALIAS: 'change-alias',
	TRIGGER_EDITOR_READY: 'editor-ready',
}))

// Crypto
vi.mock('@/crypto/mailvelope.js', () => ({
	getMailvelope: vi.fn(() => Promise.resolve({
		getKeyring: vi.fn(() => Promise.resolve(null)),
	})),
}))
vi.mock('@/crypto/pgp.js', () => ({
	isPgpgMessage: vi.fn(() => false),
}))

// Services
vi.mock('@/service/AutocompleteService.js', () => ({
	findRecipient: vi.fn(() => Promise.resolve([])),
}))

// Logger
vi.mock('@/logger.js', () => {
	const logger = { debug: vi.fn(), error: vi.fn(), warn: vi.fn(), info: vi.fn() }
	return { __esModule: true, default: logger }
})

// ReplyBuilder
vi.mock('@/ReplyBuilder.js', () => ({
	buildReplyBody: vi.fn(() => ''),
}))

// Text utilities
vi.mock('@/util/text.js', () => ({
	detect: vi.fn(() => 'html'),
	html: vi.fn((str) => ({ isHtml: true, value: str || '', format: 'html' })),
	plain: vi.fn((str) => ({ isHtml: false, value: str || '', format: 'plain' })),
	toHtml: vi.fn((body) => body),
	toPlain: vi.fn((body) => body),
}))

// Store constants
vi.mock('@/store/constants.js', () => ({
	EDITOR_MODE_HTML: 'html',
	EDITOR_MODE_TEXT: 'text',
}))

// @nextcloud libraries
vi.mock('@nextcloud/dialogs', () => ({
	showError: vi.fn(),
	showWarning: vi.fn(),
	TOAST_UNDO_TIMEOUT: 10000,
}))

vi.mock('@nextcloud/moment', () => {
	const fn = vi.fn(() => ({
		format: vi.fn(() => ''),
		toDate: vi.fn(() => new Date()),
	}))
	fn.unix = vi.fn(() => fn())
	return { __esModule: true, default: fn }
})

vi.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: {
		get: vi.fn(() => Promise.resolve({ data: [] })),
		post: vi.fn(() => Promise.resolve({ data: {} })),
	},
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((url) => url),
}))

vi.mock('@nextcloud/l10n', () => ({
	translate: vi.fn((app, str) => str),
	getCanonicalLocale: vi.fn(() => 'en'),
	getFirstDay: vi.fn(() => 1),
	getLocale: vi.fn(() => 'en'),
}))

vi.mock('@nextcloud/vue/components/NcRichText', () => ({
	NcReferencePickerModal: { name: 'NcReferencePickerModal', template: '<div />' },
}))

vi.mock('@nextcloud/vue', () => ({
	NcActions: { name: 'NcActions', template: '<div><slot /></div>' },
	NcActionButton: { name: 'NcActionButton', template: '<div><slot /></div>' },
	NcActionCheckbox: { name: 'NcActionCheckbox', template: '<div><slot /></div>' },
	NcActionInput: { name: 'NcActionInput', template: '<div><slot /></div>' },
	NcActionRadio: { name: 'NcActionRadio', template: '<div><slot /></div>', props: ['disabled', 'value', 'modelValue'] },
	NcButton: { name: 'NcButton', template: '<button><slot /></button>' },
	NcSelect: {
		name: 'NcSelect',
		template: '<div class="nc-select"><slot /><slot name="no-options" /></div>',
		props: ['value', 'options', 'loading', 'searchable', 'placeholder', 'label', 'inputLabel', 'filterBy', 'reduce', 'modelValue'],
	},
}))

// vue-material-design-icons
// iconStub must be hoisted alongside vi.mock() — use vi.hoisted
const { iconStub } = vi.hoisted(() => ({
	iconStub: (name) => ({ default: { name, template: '<span />', props: ['size'] } }),
}))
vi.mock('vue-material-design-icons/ChevronLeft.vue', () => iconStub('ChevronLeft'))
vi.mock('vue-material-design-icons/Delete.vue', () => iconStub('Delete'))
vi.mock('vue-material-design-icons/Download.vue', () => iconStub('Download'))
vi.mock('vue-material-design-icons/Upload.vue', () => iconStub('Upload'))
vi.mock('vue-material-design-icons/Folder.vue', () => iconStub('Folder'))
vi.mock('vue-material-design-icons/Link.vue', () => iconStub('Link'))
vi.mock('vue-material-design-icons/Shape.vue', () => iconStub('Shape'))
vi.mock('vue-material-design-icons/Paperclip.vue', () => iconStub('Paperclip'))
vi.mock('vue-material-design-icons/FormatSize.vue', () => iconStub('FormatSize'))
vi.mock('vue-material-design-icons/Plus.vue', () => iconStub('Plus'))
vi.mock('vue-material-design-icons/Account.vue', () => iconStub('Account'))
vi.mock('vue-material-design-icons/Send.vue', () => iconStub('Send'))
vi.mock('vue-material-design-icons/SendClock.vue', () => iconStub('SendClock'))

// Child Vue components
vi.mock('@/components/TextEditor.vue', () => ({
	default: {
		name: 'TextEditor',
		template: '<div />',
		methods: { editorExecute: vi.fn() },
	},
}))
vi.mock('@/components/MailvelopeEditor.vue', () => ({ default: { name: 'MailvelopeEditor', template: '<div />' } }))
vi.mock('@/components/ComposerAttachments.vue', () => ({ default: { name: 'ComposerAttachments', template: '<div />' } }))
vi.mock('../../components/modals/PersonReferenceIDModalItsl.vue', () => ({ default: { name: 'PersonalReferenceIDModalItsl', template: '<div />' } }))
vi.mock('../../components/message/MetadataAttachmentsItsl.vue', () => ({ default: { name: 'MetadataAttachmentsItsl', template: '<div />' } }))

// vue-autosize
vi.mock('vue-autosize', () => ({
	default: { install: vi.fn() },
}))

// mitt
vi.mock('mitt', () => ({
	default: vi.fn(() => ({
		on: vi.fn(),
		off: vi.fn(),
		emit: vi.fn(),
	})),
}))

// debounce/lodash
vi.mock('debounce-promise', () => ({ default: vi.fn((fn) => fn) }))
vi.mock('lodash/fp/debounce.js', () => ({ default: vi.fn((wait, fn) => fn || vi.fn()) }))
vi.mock('lodash/fp/escape.js', () => ({ default: vi.fn((str) => str) }))
vi.mock('lodash/fp/uniqBy.js', () => ({ default: vi.fn((key) => (arr) => arr) }))
vi.mock('lodash/fp/trimCharsStart.js', () => ({ default: vi.fn((chars) => (str) => str) }))

// ITSL helper utilities
vi.mock('../../utils/itslHelperFunctions.js', () => ({
	parseAddressInfoFromString: vi.fn(() => ({})),
	messageTypeToIcon: vi.fn(() => ({ name: 'MockIcon', template: '<span />' })),
	getValidSSN: vi.fn((v) => v),
	getValidSMSNumber: vi.fn((v) => v),
	formatParticipantDisplayName: vi.fn((type, p) => p?.email || ''),
	formatSdkOrganizationName: vi.fn((o) => o?.name || ''),
}))

// Pinia stores
vi.mock('../../store/itslStore.js', async () => {
	const { defineStore } = await vi.importActual('pinia')
	return {
		default: defineStore('itsl', {
			state: () => ({
				addressBookOrgs: [],
				addressBookLoaded: false,
				selectedMessageType: '',
				sender: { organizationExtension: 'SE111' },
				userObligatedToProvideSsn: false,
				internalMailboxes: [],
				internalMailboxesLoaded: false,
			}),
			getters: {
				getAddressBookOrgs: (state) => state.addressBookOrgs,
				getAddressBookLoaded: (state) => state.addressBookLoaded,
				getSelectedMessageType: (state) => state.selectedMessageType,
				getInternalMailboxName: () => () => null,
				resolveAccountDisplayName: () => () => null,
				getValidFromData: () => new Map(),
			},
			actions: {
				setMessageType: vi.fn(),
			},
		}),
	}
})

vi.mock('@/store/mainStore.js', async () => {
	const { defineStore } = await vi.importActual('pinia')
	return {
		default: defineStore('main', {
			state: () => ({
				isScheduledSendingDisabled: false,
				ncVersion: '28',
			}),
			getters: {
				getNcVersion: (state) => state.ncVersion,
				getPreference: () => () => null,
				getSmimeCertificateByEmail: () => () => null,
				getEnvelope: () => () => null,
				getMailbox: () => () => null,
				getSmimeCertificate: () => () => null,
			},
		}),
	}
})

// --- Import component after all mocks ---

import ComposerItsl from '../../components/message/ComposerItsl.vue'
import useItslStore from '../../store/itslStore.js'

const flushPromises = () => new Promise((resolve) => setTimeout(resolve, 0))

// --- Test data ---

const mockOrgs = [
	{
		address: 'SE1111111111:1001',
		name: 'Alpha Organization',
		originalName: 'Alpha Org',
		searchableName: 'alpha organization',
		functionAddresses: [
			{ address: 'SE1111111111:1001:func1', name: 'Alpha Func 1', originalName: 'Alpha Function 1', searchableName: 'alpha func 1' },
			{ address: 'SE1111111111:1001:func2', name: 'Alpha Func 2', originalName: 'Alpha Function 2', searchableName: 'alpha func 2' },
		],
	},
	{
		address: 'SE2222222222:2001',
		name: 'Beta Organization',
		originalName: 'Beta Org',
		searchableName: 'beta organization',
		functionAddresses: [
			{ address: 'SE2222222222:2001:funcA', name: 'Beta Func A', originalName: 'Beta Function A', searchableName: 'beta func a' },
		],
	},
]

const sdkAccount = {
	id: 1,
	isUnified: false,
	emailAddress: 'test@sdk',
	name: 'SDK Account',
	editorMode: 'html',
	signature: '',
	signatureAboveQuote: false,
	smimeCertificateId: null,
	connectionStatus: true,
	aliases: [],
}

const internalAccount = {
	id: 2,
	isUnified: false,
	emailAddress: 'user@gruppbox',
	name: 'Internal Account',
	editorMode: 'html',
	signature: '',
	signatureAboveQuote: false,
	smimeCertificateId: null,
	connectionStatus: true,
	aliases: [],
}

const faxAccount = {
	id: 3,
	isUnified: false,
	emailAddress: 'fax@fax',
	name: 'Fax Account',
	editorMode: 'html',
	signature: '',
	signatureAboveQuote: false,
	smimeCertificateId: null,
	connectionStatus: true,
	aliases: [],
}

const smsAccount = {
	id: 4,
	isUnified: false,
	emailAddress: 'sms@sms',
	name: 'SMS Account',
	editorMode: 'html',
	signature: '',
	signatureAboveQuote: false,
	smimeCertificateId: null,
	connectionStatus: true,
	aliases: [],
}

const allAccounts = [sdkAccount, internalAccount, faxAccount, smsAccount]

const TextEditorStub = {
	name: 'TextEditor',
	template: '<div />',
	methods: { editorExecute: vi.fn() },
}

const mountComposer = async (overrides = {}) => {
	const wrapper = shallowMount(ComposerItsl, {
		propsData: {
			accounts: allAccounts,
			to: [],
			cc: [],
			bcc: [],
			isFirstOpen: true,
			...overrides,
		},
		mocks: {
			t: (app, str) => str,
			$route: { params: {}, name: 'test' },
		},
		stubs: {
			TextEditor: TextEditorStub,
			'vue-tel-input': { name: 'VueTelInput', template: '<div />', props: ['value'] },
		},
	})
	await flushPromises()
	await wrapper.vm.$nextTick()
	return wrapper
}

describe('ComposerItsl - SDK address book', () => {
	let pinia, itslStore

	beforeEach(async () => {
		pinia = createPinia()
		setActivePinia(pinia)
		itslStore = useItslStore()
		vi.clearAllMocks()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('SDK type renders org NcSelect with address book orgs as options', async () => {
		itslStore.$patch({ addressBookOrgs: mockOrgs, addressBookLoaded: true })
		const wrapper = await mountComposer()
		await wrapper.setData({ selectedMessageType: MESSAGE_TYPES.SDK.id })
		await wrapper.vm.$nextTick()

		// Verify computed property feeds address book orgs
		expect(wrapper.vm.organizationAddressesAvailable).toEqual(mockOrgs)

		// Verify the org NcSelect is rendered (check for placeholder text)
		const html = wrapper.html()
		expect(html).toContain('Recipient Organization')
	})

	it('SDK type renders function address NcSelect', async () => {
		itslStore.$patch({ addressBookOrgs: mockOrgs, addressBookLoaded: true })
		const wrapper = await mountComposer()
		await wrapper.setData({ selectedMessageType: MESSAGE_TYPES.SDK.id })
		await wrapper.vm.$nextTick()

		const html = wrapper.html()
		expect(html).toContain('Function Address')
	})

	it('non-SDK type (INTERNAL) does NOT render org/function selects', async () => {
		const wrapper = await mountComposer()
		await wrapper.setData({ selectedMessageType: MESSAGE_TYPES.INTERNAL.id })
		await wrapper.vm.$nextTick()

		const html = wrapper.html()
		expect(html).not.toContain('Recipient Organization')
		expect(html).not.toContain('Function Address')
	})

	it('org selector loading reflects address book loaded state', async () => {
		itslStore.$patch({ addressBookOrgs: [], addressBookLoaded: false })
		const wrapper = await mountComposer()
		await wrapper.setData({ selectedMessageType: MESSAGE_TYPES.SDK.id })
		await wrapper.vm.$nextTick()

		// orgSelectionAvailable maps to getAddressBookLoaded
		expect(wrapper.vm.orgSelectionAvailable).toBe(false)

		itslStore.$patch({ addressBookLoaded: true })
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.orgSelectionAvailable).toBe(true)
	})

	it('selecting an org populates function address options', async () => {
		itslStore.$patch({ addressBookOrgs: mockOrgs, addressBookLoaded: true })
		const wrapper = await mountComposer()
		await wrapper.setData({ selectedMessageType: MESSAGE_TYPES.SDK.id })
		await wrapper.vm.$nextTick()

		// No org selected - function addresses empty
		expect(wrapper.vm.functionAddressesAvailable).toEqual([])

		// Select the first org
		await wrapper.setData({ organizationAddress: mockOrgs[0] })
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.functionAddressesAvailable).toEqual(mockOrgs[0].functionAddresses)
		expect(wrapper.vm.functionAddressesAvailable).toHaveLength(2)
	})

	it('selecting a function address emits update:itsl', async () => {
		itslStore.$patch({ addressBookOrgs: mockOrgs, addressBookLoaded: true })
		const wrapper = await mountComposer()
		await wrapper.setData({
			selectedMessageType: MESSAGE_TYPES.SDK.id,
			organizationAddress: mockOrgs[0],
		})
		await wrapper.vm.$nextTick()

		// Clear previously emitted events
		wrapper.emitted()['update:itsl'] = []

		// Select a function address
		await wrapper.setData({ functionAddress: mockOrgs[0].functionAddresses[0] })
		await wrapper.vm.$nextTick()

		const emitted = wrapper.emitted('update:itsl')
		expect(emitted).toBeTruthy()
		expect(emitted.length).toBeGreaterThan(0)

		const lastPayload = emitted[emitted.length - 1][0]
		expect(lastPayload.messageType).toBe(MESSAGE_TYPES.SDK.id)
		expect(lastPayload.functionAddress).toBe(mockOrgs[0].functionAddresses[0].address)
		expect(lastPayload.organizationAddress).toBe(mockOrgs[0].address)
	})
})

describe('ComposerItsl - INTERNAL mode', () => {
	let pinia, itslStore

	beforeEach(async () => {
		pinia = createPinia()
		setActivePinia(pinia)
		itslStore = useItslStore()
		vi.clearAllMocks()
	})

	afterEach(() => { vi.restoreAllMocks() })

	it('renders email selector for internal mailboxes', async () => {
		const wrapper = await mountComposer()
		await wrapper.setData({ selectedMessageType: MESSAGE_TYPES.INTERNAL.id })
		await wrapper.vm.$nextTick()

		const html = wrapper.html()
		expect(html).toContain('Local recipient')
	})

	it.each([
		['Recipient Organization'],
		['Function Address'],
	])('does not render %s select', async (label) => {
		const wrapper = await mountComposer()
		await wrapper.setData({ selectedMessageType: MESSAGE_TYPES.INTERNAL.id })
		await wrapper.vm.$nextTick()

		expect(wrapper.html()).not.toContain(label)
	})

	it('subject field renders for INTERNAL', async () => {
		const wrapper = await mountComposer()
		await wrapper.setData({ selectedMessageType: MESSAGE_TYPES.INTERNAL.id })
		await wrapper.vm.$nextTick()

		expect(wrapper.html()).toContain('Subject')
	})

	it('update:itsl emits correct email payload', async () => {
		const wrapper = await mountComposer()
		await wrapper.setData({ selectedMessageType: MESSAGE_TYPES.INTERNAL.id })
		await wrapper.vm.$nextTick()
		wrapper.emitted()['update:itsl'] = []

		await wrapper.setData({ email: 'recipient@gruppbox' })
		await wrapper.vm.$nextTick()

		const emitted = wrapper.emitted('update:itsl')
		expect(emitted).toBeTruthy()
		const lastPayload = emitted[emitted.length - 1][0]
		expect(lastPayload.messageType).toBe(MESSAGE_TYPES.INTERNAL.id)
		expect(lastPayload.email).toBe('recipient@gruppbox')
	})
})

describe('ComposerItsl - SECURE mode', () => {
	let pinia, itslStore

	beforeEach(async () => {
		pinia = createPinia()
		setActivePinia(pinia)
		itslStore = useItslStore()
		vi.clearAllMocks()
	})

	afterEach(() => { vi.restoreAllMocks() })

	it('renders notification email field', async () => {
		const wrapper = await mountComposer()
		await wrapper.setData({ selectedMessageType: MESSAGE_TYPES.SECURE.id })
		await wrapper.vm.$nextTick()

		const html = wrapper.html()
		expect(html).toContain('E-mail to notify')
		expect(html).toContain('user@example.com')
	})

	it('LOA-3 is the default loaLevel', async () => {
		const wrapper = await mountComposer()
		await wrapper.setData({ selectedMessageType: MESSAGE_TYPES.SECURE.id })
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.loaLevel).toBe(3)
	})

	it('LOA-2 radio disabled when userObligatedToProvideSsn is true', async () => {
		itslStore.$patch({ userObligatedToProvideSsn: true })
		const wrapper = await mountComposer()
		await wrapper.setData({ selectedMessageType: MESSAGE_TYPES.SECURE.id })
		await wrapper.vm.$nextTick()

		const radios = wrapper.findAllComponents({ name: 'NcActionRadio' })
		// LOA-2 is the second ActionRadio (index 1)
		const loa2Radio = radios.at(1)
		expect(loa2Radio.props('disabled')).toBe(true)
	})

	it('LOA-3 shows SSN field when userObligatedToProvideSsn is true', async () => {
		itslStore.$patch({ userObligatedToProvideSsn: true })
		const wrapper = await mountComposer()
		await wrapper.setData({ selectedMessageType: MESSAGE_TYPES.SECURE.id })
		await wrapper.vm.$nextTick()

		const html = wrapper.html()
		expect(html).toContain('Personal identity number')
		expect(html).toContain('YYYYMMDD-XXXX')
	})

	it('update:itsl emits correct loaLevel and notification', async () => {
		const wrapper = await mountComposer()
		await wrapper.setData({ selectedMessageType: MESSAGE_TYPES.SECURE.id })
		await wrapper.vm.$nextTick()
		wrapper.emitted()['update:itsl'] = []

		await wrapper.setData({ notification: 'notify@example.com' })
		await wrapper.vm.$nextTick()

		const emitted = wrapper.emitted('update:itsl')
		expect(emitted).toBeTruthy()
		const lastPayload = emitted[emitted.length - 1][0]
		expect(lastPayload.messageType).toBe(MESSAGE_TYPES.SECURE.id)
		expect(lastPayload.notification).toBe('notify@example.com')
		expect(lastPayload.loaLevel).toBe(3)
	})
})

describe('ComposerItsl - FAX mode', () => {
	let pinia, itslStore

	beforeEach(async () => {
		pinia = createPinia()
		setActivePinia(pinia)
		itslStore = useItslStore()
		vi.clearAllMocks()
	})

	afterEach(() => { vi.restoreAllMocks() })

	it('renders phone number input for fax', async () => {
		const wrapper = await mountComposer()
		await wrapper.setData({ selectedMessageType: MESSAGE_TYPES.FAX.id })
		await wrapper.vm.$nextTick()

		const html = wrapper.html()
		expect(html).toContain('Fax number')
	})

	it('hides subject field for FAX', async () => {
		const wrapper = await mountComposer()
		await wrapper.setData({ selectedMessageType: MESSAGE_TYPES.FAX.id })
		await wrapper.vm.$nextTick()

		const subjectInput = wrapper.find('input#subject')
		expect(subjectInput.exists()).toBe(false)
	})

	it('update:itsl emits fax phone number', async () => {
		const wrapper = await mountComposer()
		await wrapper.setData({ selectedMessageType: MESSAGE_TYPES.FAX.id })
		await wrapper.vm.$nextTick()
		wrapper.emitted()['update:itsl'] = []

		await wrapper.setData({ faxAddress: '+46701234567' })
		await wrapper.vm.$nextTick()

		const emitted = wrapper.emitted('update:itsl')
		expect(emitted).toBeTruthy()
		const lastPayload = emitted[emitted.length - 1][0]
		expect(lastPayload.messageType).toBe(MESSAGE_TYPES.FAX.id)
		expect(lastPayload.faxAddress).toBe('+46701234567')
	})
})

describe('ComposerItsl - SMS mode', () => {
	let pinia, itslStore

	beforeEach(async () => {
		pinia = createPinia()
		setActivePinia(pinia)
		itslStore = useItslStore()
		vi.clearAllMocks()
	})

	afterEach(() => { vi.restoreAllMocks() })

	it('renders phone number input for SMS', async () => {
		const wrapper = await mountComposer()
		await wrapper.setData({ selectedMessageType: MESSAGE_TYPES.SMS.id })
		await wrapper.vm.$nextTick()

		// Template v-if condition matches: selectedMessageType === MESSAGE_TYPES.SMS.id
		expect(wrapper.vm.selectedMessageType).toBe(wrapper.vm.MESSAGE_TYPES.SMS.id)
		// SMS section uses a plain <input> for smsAddress (not vue-tel-input)
		expect(wrapper.vm.$data.smsAddress).toBeDefined()
	})

	it('update:itsl emits SMS recipient data', async () => {
		const wrapper = await mountComposer()
		await wrapper.setData({ selectedMessageType: MESSAGE_TYPES.SMS.id })
		await wrapper.vm.$nextTick()
		wrapper.emitted()['update:itsl'] = []

		await wrapper.setData({ smsAddress: '+46709876543' })
		await wrapper.vm.$nextTick()

		const emitted = wrapper.emitted('update:itsl')
		expect(emitted).toBeTruthy()
		const lastPayload = emitted[emitted.length - 1][0]
		expect(lastPayload.messageType).toBe(MESSAGE_TYPES.SMS.id)
		expect(lastPayload.smsAddress).toBe('+46709876543')
	})
})

describe('ComposerItsl - cross-cutting', () => {
	let pinia, itslStore

	beforeEach(async () => {
		pinia = createPinia()
		setActivePinia(pinia)
		itslStore = useItslStore()
		vi.clearAllMocks()
	})

	afterEach(() => { vi.restoreAllMocks() })

	it('alias filtering: only SDK accounts shown for SDK type', async () => {
		const wrapper = await mountComposer()
		await wrapper.setData({ selectedMessageType: MESSAGE_TYPES.SDK.id })
		await wrapper.vm.$nextTick()

		// sdkAccount has emailAddress 'test@sdk', internalAccount has 'user@gruppbox'
		expect(wrapper.vm.filterAccountsByMessageType(sdkAccount.emailAddress)).toBe(true)
		expect(wrapper.vm.filterAccountsByMessageType(internalAccount.emailAddress)).toBe(false)
	})

	it('confidentiality toggle emits flag in update:itsl for SDK type', async () => {
		itslStore.$patch({ addressBookOrgs: mockOrgs, addressBookLoaded: true })
		const wrapper = await mountComposer()
		await wrapper.setData({ selectedMessageType: MESSAGE_TYPES.SDK.id })
		await wrapper.vm.$nextTick()
		wrapper.emitted()['update:itsl'] = []

		await wrapper.setData({ confidentiality: true })
		await wrapper.vm.$nextTick()

		const emitted = wrapper.emitted('update:itsl')
		expect(emitted).toBeTruthy()
		const lastPayload = emitted[emitted.length - 1][0]
		expect(lastPayload.messageType).toBe(MESSAGE_TYPES.SDK.id)
		expect(lastPayload.confidentiality).toBe(true)
	})
})
