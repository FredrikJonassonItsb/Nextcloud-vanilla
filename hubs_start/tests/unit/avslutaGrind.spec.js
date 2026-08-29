/**
 * Component tests for AvslutaGrind.vue — #5 the terminal "Avsluta ärende" gate.
 * Emits `avsluta` (the parent runs transitionSteg → 'avslutat'); respects isRunning.
 *
 * A9a/A9c — grinden samlar nu ETT strukturerat motiv innan avslut: vid
 * forhandsbedomning inteInledaVal {orsak, beslutsfattare}, annars avslutsmotiv
 * {utfall, kvarstaende}. Emit-formen är ('avsluta', arende, kontext) där kontext
 * bär kontrakts-nyckeln. Utan obligatoriskt radioval emittas inget (grinden är
 * inte klar).
 */
import { shallowMount } from '@vue/test-utils'
import AvslutaGrind from '../../src/components/socialsekreterare/AvslutaGrind.vue'

describe('AvslutaGrind #5', () => {
	it('emits avsluta + avslutsmotiv-kontext for a non-forhandsbedomning case', () => {
		const w = shallowMount(AvslutaGrind, { propsData: { arende: { hubsCaseId: 'X', steg: 'uppfoljning' } } })
		w.vm.utfall = 'behov_tillgodosett' // A9c — obligatoriskt utfall-radioval
		w.vm.onAvsluta()
		expect(w.emitted('avsluta')).toBeTruthy()
		expect(w.emitted('avsluta')[0][0]).toEqual({ hubsCaseId: 'X', steg: 'uppfoljning' })
		expect(w.emitted('avsluta')[0][1]).toEqual({ avslutsmotiv: { utfall: 'behov_tillgodosett', kvarstaende: false } })
	})

	it('emits inteInledaVal-kontext for a forhandsbedomning case', () => {
		const w = shallowMount(AvslutaGrind, { propsData: { arende: { hubsCaseId: 'X', steg: 'forhandsbedomning' } } })
		w.vm.orsak = 'ingen_grund' // A9a — obligatoriskt orsak-radioval
		w.vm.beslutsfattare = '1:e socialsekreterare'
		w.vm.onAvsluta()
		expect(w.emitted('avsluta')).toBeTruthy()
		expect(w.emitted('avsluta')[0][1]).toEqual({ inteInledaVal: { orsak: 'ingen_grund', beslutsfattare: '1:e socialsekreterare' } })
	})

	it('does not emit until the required motiv is chosen', () => {
		const w = shallowMount(AvslutaGrind, { propsData: { arende: { hubsCaseId: 'X', steg: 'uppfoljning' } } })
		w.vm.onAvsluta() // inget utfall valt ännu
		expect(w.emitted('avsluta')).toBeFalsy()
	})

	it('does not emit while a close is already running', () => {
		const w = shallowMount(AvslutaGrind, { propsData: { arende: { hubsCaseId: 'X', steg: 'uppfoljning' }, isRunning: true } })
		w.vm.utfall = 'behov_tillgodosett'
		w.vm.onAvsluta()
		expect(w.emitted('avsluta')).toBeFalsy()
	})
})
