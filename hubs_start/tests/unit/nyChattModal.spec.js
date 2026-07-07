/**
 * Component tests for NyChattModal.vue — "Ny chatt i ärendet" (1:n extra talkrum).
 * Emits `skapa` (arende, namn|null); tomt namn normaliseras till null så motorn
 * använder det pseudonyma hubsCaseId:t (M2). Respects isRunning.
 */
import { shallowMount } from '@vue/test-utils'
import NyChattModal from '../../src/components/socialsekreterare/NyChattModal.vue'

describe('NyChattModal', () => {
	it('emits skapa with the arende and a trimmed name', () => {
		const w = shallowMount(NyChattModal, { propsData: { arende: { hubsCaseId: 'X' } } })
		w.setData({ namn: '  Samverkan skola  ' })
		w.vm.onSkapa()
		expect(w.emitted('skapa')).toBeTruthy()
		expect(w.emitted('skapa')[0][0]).toEqual({ hubsCaseId: 'X' })
		expect(w.emitted('skapa')[0][1]).toBe('Samverkan skola')
	})

	it('normalises an empty name to null (engine falls back to pseudonym)', () => {
		const w = shallowMount(NyChattModal, { propsData: { arende: { hubsCaseId: 'X' } } })
		w.setData({ namn: '   ' })
		w.vm.onSkapa()
		expect(w.emitted('skapa')[0][1]).toBeNull()
	})

	it('does not emit while a create is already running', () => {
		const w = shallowMount(NyChattModal, { propsData: { arende: { hubsCaseId: 'X' }, isRunning: true } })
		w.vm.onSkapa()
		expect(w.emitted('skapa')).toBeFalsy()
	})
})
