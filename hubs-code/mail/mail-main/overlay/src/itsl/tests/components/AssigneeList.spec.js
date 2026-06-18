import { shallowMount } from '@vue/test-utils'
import AssigneeList from '../../components/sidebar/AssigneeList.vue'

vi.mock('vue-material-design-icons/Close.vue', () => ({ default: { name: 'Close', template: '<span />', props: ['size'] } }))

describe('AssigneeList', () => {
	const sampleAssignees = [
		{ id: 1, displayName: 'John Doe', imapLabel: 'john', color: '#ff0000' },
		{ id: 2, displayName: 'Jane Smith', imapLabel: 'jane', color: '#00ff00' },
	]

	afterEach(() => {
		vi.restoreAllMocks()
	})

	const mountList = (propsData = {}) => {
		return shallowMount(AssigneeList, {
			propsData: { assignees: sampleAssignees, ...propsData },
			mocks: { t: (app, str) => str },
		})
	}

	it('renders list of assignees with avatars showing initials', () => {
		const wrapper = mountList()
		const items = wrapper.findAll('.assignee-list__item')
		expect(items).toHaveLength(2)
		const avatars = wrapper.findAll('.assignee-list__avatar')
		expect(avatars.at(0).text()).toBe('JD')
		expect(avatars.at(1).text()).toBe('JS')
	})

	it('getInitials returns first 2 chars for single word', () => {
		const wrapper = mountList()
		expect(wrapper.vm.getInitials('Admin')).toBe('AD')
	})

	it('getInitials returns first+last initials for two words', () => {
		const wrapper = mountList()
		expect(wrapper.vm.getInitials('John Doe')).toBe('JD')
	})

	it('getInitials returns first+last initials for three words', () => {
		const wrapper = mountList()
		expect(wrapper.vm.getInitials('John Middle Doe')).toBe('JD')
	})

	it('getInitials returns "?" for empty/null', () => {
		const wrapper = mountList()
		expect(wrapper.vm.getInitials('')).toBe('?')
		expect(wrapper.vm.getInitials(null)).toBe('?')
		expect(wrapper.vm.getInitials(undefined)).toBe('?')
	})

	it('clicking name emits search with imapLabel', async () => {
		const wrapper = mountList()
		await wrapper.findAll('.assignee-list__name').at(0).trigger('click')
		expect(wrapper.emitted('search')).toEqual([['john']])
	})

	it('clicking remove button emits remove with id', async () => {
		const wrapper = mountList({ editable: true })
		await wrapper.findAll('.assignee-list__remove').at(0).trigger('click')
		expect(wrapper.emitted('remove')).toEqual([[1]])
	})

	it('editable=false hides remove buttons', () => {
		const wrapper = mountList({ editable: false })
		expect(wrapper.find('.assignee-list__remove').exists()).toBe(false)
	})

	it('empty assignees array renders nothing', () => {
		const wrapper = mountList({ assignees: [] })
		expect(wrapper.find('.assignee-list').exists()).toBe(false)
	})
})
