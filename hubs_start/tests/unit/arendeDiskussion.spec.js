/**
 * Component tests for ArendeDiskussion.vue — #10 "Öppna diskussionen".
 * The room is opened via the engine-supplied talk token (never hardcoded); the
 * control is honest (disabled) when the token is absent.
 */
import { shallowMount } from '@vue/test-utils'
import ArendeDiskussion from '../../src/components/socialsekreterare/ArendeDiskussion.vue'

const mk = (talkToken) => shallowMount(ArendeDiskussion, {
	propsData: { arende: {}, diskussion: { meddelanden: [] }, talkToken },
})

describe('ArendeDiskussion #10 — open room', () => {
	let origLoc
	beforeEach(() => {
		origLoc = window.location
		Object.defineProperty(window, 'location', { value: { href: '' }, writable: true, configurable: true })
	})
	afterEach(() => {
		Object.defineProperty(window, 'location', { value: origLoc, writable: true, configurable: true })
	})

	it('navigates to the room when a token is present', () => {
		const w = mk('4w54xxqc')
		w.vm.openRum()
		expect(window.location.href).toBe('/index.php/call/4w54xxqc')
	})

	it('does nothing when the token is absent (never a hardcoded room)', () => {
		const w = mk(null)
		w.vm.openRum()
		expect(window.location.href).toBe('')
	})

	it('rumTitle is honest about availability', () => {
		expect(mk('t').vm.rumTitle).toBe('Öppna ärenderummets diskussion')
		expect(mk(null).vm.rumTitle).toBe('Diskussionsrum saknas ännu')
	})
})
