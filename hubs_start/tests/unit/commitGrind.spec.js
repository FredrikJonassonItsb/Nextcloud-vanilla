/**
 * Component tests for CommitGrind.vue — #5 Treserva document selection.
 * Decision #5: all documents pre-checked; handläggaren unchecks to exclude.
 *
 * The api module is mocked; advanceTo (the 800ms Frends staging) is overridden to
 * resolve immediately so onCommit completes synchronously. Assertions are on
 * component state + emitted events + the api call args (no DOM-in-modal-slot finds).
 */
jest.mock('../../src/services/api.js', () => ({
	__esModule: true,
	default: { commitToTreserva: jest.fn() },
}))

import api from '../../src/services/api.js'
import { shallowMount } from '@vue/test-utils'
import CommitGrind from '../../src/components/socialsekreterare/CommitGrind.vue'

const mountG = (payload) => {
	const w = shallowMount(CommitGrind, {
		propsData: { arende: { dnr: '2026-IFO-0502', triageRef: '2026-IFO-0502', provenance: {} }, payload },
	})
	w.vm.advanceTo = () => Promise.resolve() // skip the 800ms staging timers
	return w
}

beforeEach(() => {
	api.commitToTreserva.mockReset()
	api.commitToTreserva.mockResolvedValue({ ok: true, verifierad: true, dnr: '2026-IFO-0999', gallrasDatum: '2026-09-01', receipt: {} })
})

describe('CommitGrind #5 — document selection', () => {
	it('pre-checks every document; normalizes {namn,fileid} objects and strings', () => {
		const w = mountG({ typ: 'utredning', dokument: [{ fileid: 1, namn: 'A' }, 'B (utkast)'] })
		expect(w.vm.valda).toEqual([
			{ fileid: 1, namn: 'A', vald: true },
			{ fileid: null, namn: 'B (utkast)', vald: true },
		])
	})

	it('commits only the checked docs (unchecked excluded; vald flag stripped)', async () => {
		const w = mountG({ typ: 'utredning', dokument: [{ fileid: 1, namn: 'A' }, { fileid: 2, namn: 'B' }] })
		w.vm.valda[1].vald = false // handläggaren unchecks B
		await w.vm.onCommit()
		expect(api.commitToTreserva).toHaveBeenCalledTimes(1)
		expect(api.commitToTreserva.mock.calls[0][0].valdaDokument).toEqual([{ fileid: 1, namn: 'A' }])
		// the selection rides the @committed event for the parent's second (idempotent) commit
		expect(w.emitted('committed')[0][1]).toEqual([{ fileid: 1, namn: 'A' }])
	})

	it('empty document list still commits (honest-empty), valdaDokument = []', async () => {
		const w = mountG({ typ: 'beslut', dokument: [] })
		expect(w.vm.valda).toEqual([])
		await w.vm.onCommit()
		expect(api.commitToTreserva).toHaveBeenCalledWith(expect.objectContaining({ valdaDokument: [] }))
		expect(w.emitted('committed')).toBeTruthy()
	})

	it('missing dokument key → empty selection, still commits', async () => {
		const w = mountG({ typ: 'beslut' })
		expect(w.vm.valda).toEqual([])
		await w.vm.onCommit()
		expect(api.commitToTreserva).toHaveBeenCalled()
	})
})

describe('CommitGrind #6 — embedded signing confirmation (no modal stacking)', () => {
	it('kraverSignering gates the commit until "Jag har signerat" is checked', async () => {
		const w = mountG({ typ: 'signerat-beslut', kraverSignering: true })
		expect(w.vm.kraverSignering).toBe(true)
		// Not confirmed yet → onCommit is a no-op (the button is also disabled).
		await w.vm.onCommit()
		expect(api.commitToTreserva).not.toHaveBeenCalled()
		// Confirm signed → commit proceeds.
		w.vm.signeradBekraftad = true
		await w.vm.onCommit()
		expect(api.commitToTreserva).toHaveBeenCalledTimes(1)
		expect(w.emitted('committed')).toBeTruthy()
	})

	it('a normal commit (no kraverSignering) is never gated', async () => {
		const w = mountG({ typ: 'beslut' })
		expect(w.vm.kraverSignering).toBe(false)
		await w.vm.onCommit()
		expect(api.commitToTreserva).toHaveBeenCalledTimes(1)
	})
})
