/**
 * Component tests for AvslutaGrind.vue — #5 the terminal "Avsluta ärende" gate.
 * Emits `avsluta` (the parent runs transitionSteg → 'avslutat'); respects isRunning.
 */
import { shallowMount } from '@vue/test-utils'
import AvslutaGrind from '../../src/components/socialsekreterare/AvslutaGrind.vue'

describe('AvslutaGrind #5', () => {
	it('emits avsluta with the arende on confirm', () => {
		const w = shallowMount(AvslutaGrind, { propsData: { arende: { hubsCaseId: 'X' } } })
		w.vm.onAvsluta()
		expect(w.emitted('avsluta')).toBeTruthy()
		expect(w.emitted('avsluta')[0][0]).toEqual({ hubsCaseId: 'X' })
	})

	it('does not emit while a close is already running', () => {
		const w = shallowMount(AvslutaGrind, { propsData: { arende: { hubsCaseId: 'X' }, isRunning: true } })
		w.vm.onAvsluta()
		expect(w.emitted('avsluta')).toBeFalsy()
	})
})
