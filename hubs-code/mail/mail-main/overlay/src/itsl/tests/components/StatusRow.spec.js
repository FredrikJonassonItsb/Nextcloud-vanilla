import { shallowMount } from '@vue/test-utils'
import StatusRow from '../../components/sidebar/StatusRow.vue'

vi.mock('@nextcloud/vue', () => ({
	NcCheckboxRadioSwitch: { name: 'NcCheckboxRadioSwitch', template: '<div><slot /></div>', props: ['checked', 'type'] },
}))

vi.mock('vue-material-design-icons/Star.vue', () => ({ default: { name: 'Star', template: '<span />', props: ['size'] } }))
vi.mock('vue-material-design-icons/Alert.vue', () => ({ default: { name: 'Alert', template: '<span />', props: ['size'] } }))

describe('StatusRow', () => {
	afterEach(() => {
		vi.restoreAllMocks()
	})

	const mountRow = (propsData = {}) => {
		return shallowMount(StatusRow, {
			propsData: { label: 'Starred', icon: 'star', ...propsData },
			mocks: { t: (app, str) => str },
		})
	}

	it('renders label text', () => {
		const wrapper = mountRow({ label: 'Important' })
		expect(wrapper.find('.status-row__label').text()).toBe('Important')
	})

	it('shows Star icon when icon="star"', () => {
		const wrapper = mountRow({ icon: 'star' })
		expect(wrapper.findComponent({ name: 'Star' }).exists()).toBe(true)
		expect(wrapper.findComponent({ name: 'Alert' }).exists()).toBe(false)
	})

	it('shows Alert icon when icon="important"', () => {
		const wrapper = mountRow({ icon: 'important' })
		expect(wrapper.findComponent({ name: 'Alert' }).exists()).toBe(true)
		expect(wrapper.findComponent({ name: 'Star' }).exists()).toBe(false)
	})

	it('active state uses activeColor for icon', () => {
		const wrapper = mountRow({ active: true, activeColor: '#ffcc00' })
		const iconSpan = wrapper.find('.status-row__icon')
		expect(iconSpan.element.style.color).toBe('rgb(255, 204, 0)')
	})

	it('inactive state uses maxcontrast color for icon', () => {
		const wrapper = mountRow({ active: false })
		expect(wrapper.vm.iconStyle.color).toBe('var(--color-text-maxcontrast)')
	})

	it('clicking row emits toggle', async () => {
		const wrapper = mountRow()
		await wrapper.find('.status-row').trigger('click')
		expect(wrapper.emitted('toggle')).toHaveLength(1)
	})

	it('debounce: rapid clicks only emit once within 50ms window', async () => {
		let now = 1000
		vi.spyOn(Date, 'now').mockImplementation(() => now)

		const wrapper = mountRow()

		await wrapper.find('.status-row').trigger('click')
		// Second click within 50ms
		now = 1010
		await wrapper.find('.status-row').trigger('click')

		expect(wrapper.emitted('toggle')).toHaveLength(1)

		// Third click after 50ms passes
		now = 1060
		await wrapper.find('.status-row').trigger('click')
		expect(wrapper.emitted('toggle')).toHaveLength(2)
	})
})
