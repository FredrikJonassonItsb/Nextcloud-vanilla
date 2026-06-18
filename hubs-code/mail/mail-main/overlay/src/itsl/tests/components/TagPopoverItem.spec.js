import { shallowMount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import TagPopoverItem from '../../components/TagPopoverItem.vue'

vi.mock('@nextcloud/vue', () => ({
	NcCheckboxRadioSwitch: { name: 'NcCheckboxRadioSwitch', template: '<div><slot /></div>', props: ['checked', 'type'] },
	NcButton: { name: 'NcButton', template: '<button @click="$emit(\'click\')"><slot /><slot name="icon" /></button>', props: ['type', 'ariaLabel'] },
	NcColorPicker: { name: 'NcColorPicker', template: '<div><slot /></div>', props: ['value', 'container'] },
}))

vi.mock('vue-material-design-icons/Pencil.vue', () => ({ default: { name: 'Pencil', template: '<span />', props: ['size'] } }))
vi.mock('vue-material-design-icons/Check.vue', () => ({ default: { name: 'Check', template: '<span />', props: ['size'] } }))
vi.mock('vue-material-design-icons/Close.vue', () => ({ default: { name: 'Close', template: '<span />', props: ['size'] } }))
vi.mock('vue-material-design-icons/Delete.vue', () => ({ default: { name: 'Delete', template: '<span />', props: ['size'] } }))

vi.mock('../../store/itslStore.js', async () => {
	const { defineStore } = await vi.importActual('pinia')
	return {
		default: defineStore('itsl', {
			state: () => ({
				pendingTagRemovals: {},
				pendingTagAdditions: {},
				canManageTags: false,
			}),
			actions: {
				updateTag: vi.fn(() => Promise.resolve()),
			},
		}),
	}
})

vi.mock('@/store/mainStore.js', async () => {
	const { defineStore } = await vi.importActual('pinia')
	return {
		default: defineStore('main', {
			state: () => ({
				envelopes: {},
			}),
			actions: {
				getEnvelopeTags: vi.fn(() => []),
			},
		}),
	}
})

vi.mock('@/util/tag.js', () => ({
	translateTagDisplayName: vi.fn((tag) => tag.displayName),
}))

vi.mock('@nextcloud/dialogs', () => ({
	showInfo: vi.fn(),
	showError: vi.fn(),
	TOAST_UNDO_TIMEOUT: 10000,
}))

describe('TagPopoverItem', () => {
	const sampleTag = {
		id: 1,
		displayName: 'Test Tag',
		color: '#ff0000',
		imapLabel: 'test-tag',
	}

	const sampleEnvelopes = [
		{ databaseId: 100, accountId: 1 },
	]

	let pinia

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
		vi.clearAllMocks()
	})

	const mountItem = (propsData = {}) => {
		return shallowMount(TagPopoverItem, {
			propsData: {
				tag: sampleTag,
				envelopes: sampleEnvelopes,
				...propsData,
			},
			mocks: { t: (app, str) => str },
		})
	}

	// --- View mode tests ---

	it('renders checkbox (NcCheckboxRadioSwitch)', () => {
		const wrapper = mountItem()
		expect(wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' }).exists()).toBe(true)
	})

	it('shows color dot for regular tags', () => {
		const wrapper = mountItem({ isAssignmentTag: false })
		expect(wrapper.find('.color-dot').exists()).toBe(true)
		expect(wrapper.find('.tag-popover-item__avatar').exists()).toBe(false)
	})

	it('shows avatar with initials for assignment tags', () => {
		const wrapper = mountItem({ isAssignmentTag: true })
		expect(wrapper.find('.tag-popover-item__avatar').exists()).toBe(true)
		expect(wrapper.find('.tag-popover-item__avatar').text()).toBe('TT')
	})

	it('search highlight: query "Test" highlights matching text', () => {
		const wrapper = mountItem({ searchQuery: 'Test' })
		expect(wrapper.vm.highlightedName).toBe('<strong>Test</strong> Tag')
	})

	it('XSS-safe: <script> in name is escaped in highlightedName', () => {
		const xssTag = { ...sampleTag, displayName: '<script>alert(1)</script>' }
		const wrapper = mountItem({ tag: xssTag })
		expect(wrapper.vm.highlightedName).not.toContain('<script>')
		expect(wrapper.vm.highlightedName).toContain('&lt;script&gt;')
	})

	it('pendingState prop overrides isApplied', () => {
		const wrapper = mountItem({ pendingState: true })
		expect(wrapper.vm.effectiveState).toBe(true)

		const wrapper2 = mountItem({ pendingState: false })
		expect(wrapper2.vm.effectiveState).toBe(false)
	})

	it('edit pencil shown when canManageTags=true', () => {
		const wrapper = mountItem({ canManageTags: true })
		expect(wrapper.findComponent({ name: 'Pencil' }).exists()).toBe(true)
	})

	it('edit pencil hidden when canManageTags=false', () => {
		const wrapper = mountItem({ canManageTags: false })
		expect(wrapper.findComponent({ name: 'Pencil' }).exists()).toBe(false)
	})

	it('clicking row emits toggle with tag', async () => {
		const wrapper = mountItem()
		await wrapper.find('.tag-popover-item').trigger('click')
		expect(wrapper.emitted('toggle')).toEqual([[sampleTag]])
	})

	// --- Edit mode tests ---

	it('startEdit: shows input with tag name and color picker', async () => {
		const wrapper = mountItem({ canManageTags: true })
		wrapper.vm.startEdit()
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.editing).toBe(true)
		expect(wrapper.vm.editName).toBe('Test Tag')
		expect(wrapper.vm.editColor).toBe('#ff0000')
		expect(wrapper.find('.tag-popover-item__input').exists()).toBe(true)
		expect(wrapper.findComponent({ name: 'NcColorPicker' }).exists()).toBe(true)
	})

	it('cancelEdit: returns to view mode', async () => {
		const wrapper = mountItem({ canManageTags: true })
		wrapper.vm.startEdit()
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.editing).toBe(true)

		wrapper.vm.cancelEdit()
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.editing).toBe(false)
	})

	it('delete button hidden for assignment tags', async () => {
		const wrapper = mountItem({ canManageTags: true, isAssignmentTag: true })
		wrapper.vm.startEdit()
		await wrapper.vm.$nextTick()

		const deleteButtons = wrapper.findAllComponents({ name: 'NcButton' })
			.filter((w) => w.attributes('aria-label') === 'Delete tag')
		expect(deleteButtons).toHaveLength(0)
	})

	it('delete button emits delete event', async () => {
		const wrapper = mountItem({ canManageTags: true, isAssignmentTag: false })
		wrapper.vm.startEdit()
		await wrapper.vm.$nextTick()

		wrapper.vm.confirmDelete()
		expect(wrapper.emitted('delete')).toEqual([[sampleTag]])
	})

	it('saveEdit: calls itslStore.updateTag with correct params', async () => {
		const wrapper = mountItem({ canManageTags: true })
		const spy = vi.spyOn(wrapper.vm.itslStore, 'updateTag').mockResolvedValue()
		wrapper.vm.startEdit()
		await wrapper.vm.$nextTick()

		wrapper.vm.editName = 'Updated Tag'
		wrapper.vm.editColor = '#00ff00'
		await wrapper.vm.saveEdit()

		expect(spy).toHaveBeenCalledWith(
			expect.anything(),
			expect.objectContaining({
				tag: sampleTag,
				displayName: 'Updated Tag',
				color: '#00ff00',
				accountId: 1,
			}),
		)
	})

	it('empty name validation: shows error, does not save', async () => {
		const { showError } = await import('@nextcloud/dialogs')
		const wrapper = mountItem({ canManageTags: true })
		wrapper.vm.startEdit()
		await wrapper.vm.$nextTick()

		wrapper.vm.editName = '   '
		await wrapper.vm.saveEdit()

		expect(showError).toHaveBeenCalledWith('Tag name cannot be empty')
		expect(wrapper.vm.editing).toBe(true)
	})
})
