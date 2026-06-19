/**
 * Component tests for MinaAnteckningar.vue — #12 private per-user notes
 * (Spreed Note-to-Self wrapper). Append-only list, newest-first, graceful.
 */
jest.mock('../../src/services/api.js', () => ({
	__esModule: true,
	default: { fetchNotes: jest.fn(), addNote: jest.fn() },
}))

import api from '../../src/services/api.js'
import { shallowMount } from '@vue/test-utils'
import MinaAnteckningar from '../../src/components/socialsekreterare/MinaAnteckningar.vue'

const flush = () => new Promise((r) => setTimeout(r))

beforeEach(() => {
	api.fetchNotes.mockReset()
	api.addNote.mockReset()
})

describe('MinaAnteckningar #12', () => {
	it('loads notes on mount (newest-first as returned)', async () => {
		api.fetchNotes.mockResolvedValue({ notes: [{ id: '2', text: 'B', createdAt: 'x' }, { id: '1', text: 'A', createdAt: 'y' }] })
		const w = shallowMount(MinaAnteckningar, { propsData: { arende: { dnr: 'X' } } })
		await flush()
		expect(w.vm.notes.map((nn) => nn.text)).toEqual(['B', 'A'])
		expect(w.vm.loading).toBe(false)
	})

	it('saves a note, prepends it, and clears the draft', async () => {
		api.fetchNotes.mockResolvedValue({ notes: [] })
		api.addNote.mockResolvedValue({ note: { id: '9', text: 'Ny anteckning', createdAt: 'z' } })
		const w = shallowMount(MinaAnteckningar, { propsData: { arende: {} } })
		await flush()
		w.vm.utkast = 'Ny anteckning'
		await w.vm.onSpara()
		expect(api.addNote).toHaveBeenCalledWith('Ny anteckning')
		expect(w.vm.notes[0].text).toBe('Ny anteckning')
		expect(w.vm.utkast).toBe('')
	})

	it('does not save blank / whitespace-only drafts', async () => {
		api.fetchNotes.mockResolvedValue({ notes: [] })
		const w = shallowMount(MinaAnteckningar, { propsData: { arende: {} } })
		await flush()
		w.vm.utkast = '   '
		await w.vm.onSpara()
		expect(api.addNote).not.toHaveBeenCalled()
	})

	it('fetch error → empty list, not a throw', async () => {
		api.fetchNotes.mockRejectedValue(new Error('down'))
		const w = shallowMount(MinaAnteckningar, { propsData: { arende: {} } })
		await flush()
		expect(w.vm.notes).toEqual([])
		expect(w.vm.loading).toBe(false)
	})
})
