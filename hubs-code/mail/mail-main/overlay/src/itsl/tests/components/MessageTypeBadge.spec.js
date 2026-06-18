import { shallowMount } from '@vue/test-utils'
import MessageTypeBadge from '../../components/sidebar/MessageTypeBadge.vue'
import { MESSAGE_TYPES } from '../../store/constants.js'

vi.mock('../../components/assets/SDKIcon.vue', () => ({ default: { name: 'MockSDKIcon', template: '<span />', props: ['size'] } }))
vi.mock('vue-material-design-icons/Forum.vue', () => ({ default: { name: 'MockForum', template: '<span />', props: ['size'] } }))
vi.mock('vue-material-design-icons/MessageTextLock.vue', () => ({ default: { name: 'MockMessageTextLock', template: '<span />', props: ['size'] } }))
vi.mock('vue-material-design-icons/Fax.vue', () => ({ default: { name: 'MockFax', template: '<span />', props: ['size'] } }))
vi.mock('vue-material-design-icons/CellphoneMessage.vue', () => ({ default: { name: 'MockCellphoneMessage', template: '<span />', props: ['size'] } }))

vi.mock('../../utils/itslHelperFunctions.js', () => ({
	messageTypeToIcon: vi.fn((type) => {
		const map = {
			sdk_message: { name: 'MockSDKIcon', template: '<span />', props: ['size'] },
			internal_message: { name: 'MockForum', template: '<span />', props: ['size'] },
			secure_email: { name: 'MockMessageTextLock', template: '<span />', props: ['size'] },
			fax_message: { name: 'MockFax', template: '<span />', props: ['size'] },
			sms_message: { name: 'MockCellphoneMessage', template: '<span />', props: ['size'] },
		}
		return map[type]
	}),
}))

describe('MessageTypeBadge', () => {
	afterEach(() => {
		vi.restoreAllMocks()
	})

	const mountBadge = (propsData = {}) => {
		return shallowMount(MessageTypeBadge, {
			propsData,
			mocks: { t: (app, str) => str },
		})
	}

	it.each([
		[MESSAGE_TYPES.SDK.id, 'SDK Message'],
		[MESSAGE_TYPES.SECURE.id, 'Secure E-mail'],
		[MESSAGE_TYPES.INTERNAL.id, 'Internal Message'],
		[MESSAGE_TYPES.FAX.id, 'Fax Message'],
		[MESSAGE_TYPES.SMS.id, 'SMS Message'],
	])('renders correct label for type %s', (type, expectedLabel) => {
		const wrapper = mountBadge({ messageType: type })
		expect(wrapper.find('.message-type-badge__label').text()).toBe(expectedLabel)
	})

	it.each([
		[MESSAGE_TYPES.SDK.id, 'message-type-badge--sdk'],
		[MESSAGE_TYPES.SECURE.id, 'message-type-badge--secure'],
		[MESSAGE_TYPES.INTERNAL.id, 'message-type-badge--internal'],
		[MESSAGE_TYPES.FAX.id, 'message-type-badge--fax'],
		[MESSAGE_TYPES.SMS.id, 'message-type-badge--sms'],
	])('renders correct CSS class for type %s', (type, expectedClass) => {
		const wrapper = mountBadge({ messageType: type })
		expect(wrapper.classes()).toContain(expectedClass)
	})

	it.each([
		[MESSAGE_TYPES.SDK.id, 'SDK'],
		[MESSAGE_TYPES.SECURE.id, 'Secure'],
		[MESSAGE_TYPES.INTERNAL.id, 'Internal'],
		[MESSAGE_TYPES.FAX.id, 'Fax'],
		[MESSAGE_TYPES.SMS.id, 'SMS'],
	])('compact mode shows short label for type %s', (type, expectedLabel) => {
		const wrapper = mountBadge({ messageType: type, compact: true })
		expect(wrapper.find('.message-type-badge__label').text()).toBe(expectedLabel)
	})

	it('compact mode adds message-type-badge--compact class', () => {
		const wrapper = mountBadge({ messageType: MESSAGE_TYPES.SDK.id, compact: true })
		expect(wrapper.classes()).toContain('message-type-badge--compact')
	})

	it('renders nothing when messageType is null', () => {
		const wrapper = mountBadge({ messageType: null })
		expect(wrapper.find('.message-type-badge').exists()).toBe(false)
	})

	it('renders the icon component', () => {
		const wrapper = mountBadge({ messageType: MESSAGE_TYPES.SDK.id })
		expect(wrapper.find('.message-type-badge__icon').exists()).toBe(true)
	})

	it('renders empty label for unknown type', () => {
		const wrapper = mountBadge({ messageType: 'unknown_type' })
		expect(wrapper.find('.message-type-badge__label').text()).toBe('')
	})
})
