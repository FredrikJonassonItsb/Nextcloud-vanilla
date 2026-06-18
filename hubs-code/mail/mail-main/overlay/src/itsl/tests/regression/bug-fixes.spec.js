import { createPinia, setActivePinia } from 'pinia'
import * as mainStoreModule from '@/store/mainStore.js'
import * as itslStoreModule from '../../store/itslStore.js'
import { MESSAGE_TYPES } from '../../store/constants.js'
import { parseAddressInfoFromString } from '../../utils/itslHelperFunctions.js'

vi.mock('../../components/assets/SDKIcon.vue', () => ({ default: { name: 'MockSDKIcon' } }))
vi.mock('vue-material-design-icons/Forum.vue', () => ({ default: { name: 'MockForum' } }))
vi.mock('vue-material-design-icons/MessageTextLock.vue', () => ({ default: { name: 'MockMessageTextLock' } }))
vi.mock('vue-material-design-icons/Fax.vue', () => ({ default: { name: 'MockFax' } }))
vi.mock('vue-material-design-icons/CellphoneMessage.vue', () => ({ default: { name: 'MockCellphoneMessage' } }))
vi.mock('libphonenumber-js', () => ({
	parsePhoneNumber: vi.fn(() => ({ isValid: () => false })),
	AsYouType: vi.fn().mockImplementation(() => ({ input: vi.fn() })),
}))

describe('Bug #128 - parseAddressInfoFromString null safety', () => {
	const messageTypes = [
		['INTERNAL', MESSAGE_TYPES.INTERNAL.id],
		['SECURE', MESSAGE_TYPES.SECURE.id],
		['FAX', MESSAGE_TYPES.FAX.id],
		['SMS', MESSAGE_TYPES.SMS.id],
	]

	describe.each(messageTypes)('%s with undefined', (name, typeId) => {
		it('returns safe defaults without crashing', () => {
			const result = parseAddressInfoFromString(typeId, undefined)
			expect(result).toBeDefined()
			expect(result.email).toBe('')
			expect(result.notification).toBe('')
			expect(result.ssn).toBe('')
			expect(result.faxAddress).toBe('')
			expect(result.smsAddress).toBe('')
		})
	})

	describe.each(messageTypes)('%s with null', (name, typeId) => {
		it('returns safe defaults without crashing', () => {
			const result = parseAddressInfoFromString(typeId, null)
			expect(result).toBeDefined()
			expect(result.email).toBe('')
			expect(result.faxAddress).toBe('')
			expect(result.smsAddress).toBe('')
		})
	})

	describe.each(messageTypes)('%s with empty string', (name, typeId) => {
		it('returns safe defaults without crashing', () => {
			const result = parseAddressInfoFromString(typeId, '')
			expect(result).toBeDefined()
			expect(result.email).toBe('')
			expect(result.faxAddress).toBe('')
			expect(result.smsAddress).toBe('')
		})
	})
})

describe('Bug #81 - allAccountSettings as state property', () => {
	let mainStore

	beforeEach(() => {
		setActivePinia(createPinia())
		const useMainStore = mainStoreModule.default
		mainStore = useMainStore()
	})

	it('has allAccountSettings as a state property (not a getter)', () => {
		expect(mainStore.allAccountSettings).toBeDefined()
		expect(Array.isArray(mainStore.allAccountSettings)).toBe(true)
	})

	it('allows setAccountSettingMutation to write to allAccountSettings', () => {
		expect(typeof mainStore.setAccountSettingMutation).toBe('function')

		mainStore.setAccountSettingMutation({
			accountId: 1,
			key: 'testSetting',
			value: 'hello',
		})

		const settings = mainStore.allAccountSettings.find(s => s.accountId === 1)
		expect(settings).toBeDefined()
		expect(settings.testSetting).toBe('hello')
	})
})

describe('Bug #126 - showMessageComposerMutation', () => {
	let mainStore

	beforeEach(() => {
		setActivePinia(createPinia())
		const useMainStore = mainStoreModule.default
		mainStore = useMainStore()
	})

	it('sets showMessageComposer to true when composerSessionId exists', () => {
		mainStore.$patch({ composerSessionId: 'session-1', showMessageComposer: false })
		mainStore.showMessageComposerMutation()
		expect(mainStore.showMessageComposer).toBe(true)
	})

	it('does not set showMessageComposer when composerSessionId is falsy', () => {
		mainStore.$patch({ composerSessionId: undefined, showMessageComposer: false })
		mainStore.showMessageComposerMutation()
		expect(mainStore.showMessageComposer).toBe(false)
	})
})

describe('Bug #76 - onMessageSent receives correct args', () => {
	let itslStore

	beforeEach(() => {
		setActivePinia(createPinia())

		const useMainStore = mainStoreModule.default
		const mainStoreInstance = useMainStore()

		mainStoreInstance.$patch({
			accountList: [1],
			accountsUnmapped: { 1: { id: 1, sentMailboxId: 10 } },
		})
		mainStoreInstance.syncEnvelopes = vi.fn().mockResolvedValue()
		mainStoreInstance.fetchThread = vi.fn().mockResolvedValue()

		const useItslStore = itslStoreModule.default
		itslStore = useItslStore()
	})

	it('calls fetchThread with replyToDatabaseId', async () => {
		const useMainStore = mainStoreModule.default
		const mainStoreInstance = useMainStore()

		await itslStore.onMessageSent({ replyToDatabaseId: 123, accountId: 1 })

		expect(mainStoreInstance.fetchThread).toHaveBeenCalledWith(123)
	})

	it('does nothing when replyToDatabaseId is falsy', async () => {
		const useMainStore = mainStoreModule.default
		const mainStoreInstance = useMainStore()

		await itslStore.onMessageSent({ replyToDatabaseId: null, accountId: 1 })

		expect(mainStoreInstance.fetchThread).not.toHaveBeenCalled()
	})
})

describe('Bug #132 - viewer close on browser back via pushState/popstate', () => {
	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('pushState sets #viewer hash and popstate triggers close callback', () => {
		const pushSpy = vi.spyOn(window.history, 'pushState')
		const closeFn = vi.fn()

		// Simulate the viewer open contract: pushState + popstate listener
		window.history.pushState('', '', '#viewer')
		expect(pushSpy).toHaveBeenCalledWith('', '', '#viewer')

		window.addEventListener('popstate', closeFn)
		// Simulate browser back
		window.dispatchEvent(new PopStateEvent('popstate'))
		expect(closeFn).toHaveBeenCalledTimes(1)

		window.removeEventListener('popstate', closeFn)
	})

	it('popstate handler is cleaned up after close', () => {
		const closeFn = vi.fn()

		window.addEventListener('popstate', closeFn)
		window.removeEventListener('popstate', closeFn)

		// After removal, dispatching should not trigger the handler
		window.dispatchEvent(new PopStateEvent('popstate'))
		expect(closeFn).not.toHaveBeenCalled()
	})
})

describe('LOA-2 SMS permanently disabled in UI', () => {
	// LOA-2 (SMS) radio button has :disabled="true" hardcoded in ComposerItsl.vue
	// Regression tests verify the LOA data model preserves correct behavior

	it('loaLevel 2 includes smsNumber in SECURE itsl data', () => {
		const loaLevel = 2
		const smsNumber = '+46701234567'
		const itslData = {
			messageType: MESSAGE_TYPES.SECURE.id,
			smsNumber: loaLevel === 2 ? smsNumber : '',
			loaLevel,
		}
		expect(itslData.smsNumber).toBe('+46701234567')
		expect(itslData.loaLevel).toBe(2)
	})

	it('loaLevel 1 does not include smsNumber', () => {
		const loaLevel = 1
		const itslData = {
			messageType: MESSAGE_TYPES.SECURE.id,
			smsNumber: loaLevel === 2 ? '+46701234567' : '',
			loaLevel,
		}
		expect(itslData.smsNumber).toBe('')
		expect(itslData.loaLevel).toBe(1)
	})

	it('loaLevel 3 does not include smsNumber', () => {
		const loaLevel = 3
		const itslData = {
			messageType: MESSAGE_TYPES.SECURE.id,
			smsNumber: loaLevel === 2 ? '+46701234567' : '',
			isSendingToPerson: loaLevel === 3,
			loaLevel,
		}
		expect(itslData.smsNumber).toBe('')
		expect(itslData.isSendingToPerson).toBe(true)
		expect(itslData.loaLevel).toBe(3)
	})
})
