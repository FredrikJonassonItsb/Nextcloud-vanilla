import { shallowMount } from '@vue/test-utils'
import TagList from '../../components/sidebar/TagList.vue'

vi.mock('vue-material-design-icons/Close.vue', () => ({ default: { name: 'Close', template: '<span />', props: ['size'] } }))

describe('TagList', () => {
	const sampleTags = [
		{ id: 1, displayName: 'Urgent', color: '#ff0000', imapLabel: 'urgent' },
		{ id: 2, displayName: 'Review', color: '#00ff00', imapLabel: 'review' },
	]

	afterEach(() => {
		vi.restoreAllMocks()
	})

	const mountList = (propsData = {}) => {
		return shallowMount(TagList, {
			propsData: { tags: sampleTags, ...propsData },
			mocks: { t: (app, str) => str },
		})
	}

	it('renders tags with colored backgrounds', () => {
		const wrapper = mountList()
		const items = wrapper.findAll('.tag-list__item')
		expect(items).toHaveLength(2)
		const bg = wrapper.findAll('.tag-list__bg')
		expect(bg.at(0).element.style.backgroundColor).toBe('rgb(255, 0, 0)')
		expect(bg.at(1).element.style.backgroundColor).toBe('rgb(0, 255, 0)')
	})

	it('clicking label emits search with imapLabel', async () => {
		const wrapper = mountList()
		await wrapper.findAll('.tag-list__label').at(0).trigger('click')
		expect(wrapper.emitted('search')).toEqual([['urgent']])
	})

	it('clicking remove emits remove with id', async () => {
		const wrapper = mountList({ editable: true })
		await wrapper.findAll('.tag-list__remove').at(0).trigger('click')
		expect(wrapper.emitted('remove')).toEqual([[1]])
	})

	it('editable=false hides remove buttons', () => {
		const wrapper = mountList({ editable: false })
		expect(wrapper.find('.tag-list__remove').exists()).toBe(false)
	})

	it('empty tags array renders nothing', () => {
		const wrapper = mountList({ tags: [] })
		expect(wrapper.find('.tag-list').exists()).toBe(false)
	})
})
