/**
 * Component tests for SigneringsGrind.vue — #6 sign-confirmation bridge.
 * It must NOT advance steg or navigate on its own; it only gates a "Nästa steg"
 * emit on the confirmation checkbox.
 */
import { shallowMount } from '@vue/test-utils'
import SigneringsGrind from '../../src/components/socialsekreterare/SigneringsGrind.vue'

const mk = () => shallowMount(SigneringsGrind, { propsData: { arende: { dnr: 'X', triageRef: 'X' } } })

describe('SigneringsGrind #6', () => {
	let origLoc
	beforeEach(() => {
		origLoc = window.location
		Object.defineProperty(window, 'location', { value: { href: '' }, writable: true, configurable: true })
	})
	afterEach(() => {
		Object.defineProperty(window, 'location', { value: origLoc, writable: true, configurable: true })
	})

	it('does not emit until the box is checked', () => {
		const w = mk()
		expect(w.vm.signerad).toBe(false)
		w.vm.onNastaSteg()
		expect(w.emitted('nasta-steg')).toBeFalsy()
	})

	it('emits nasta-steg with the arende once signed, and never navigates', () => {
		const w = mk()
		w.vm.signerad = true
		w.vm.onNastaSteg()
		expect(w.emitted('nasta-steg')).toBeTruthy()
		expect(w.emitted('nasta-steg')[0][0]).toEqual({ dnr: 'X', triageRef: 'X' })
		expect(window.location.href).toBe('') // no libresign redirect
	})
})
