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
	default: { commitToTreserva: jest.fn(), signeringList: jest.fn() },
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

/** Flush the async created()-fetches (hamtaSigneringStatus/hamtaDokument). */
const flushP = () => new Promise((resolve) => setTimeout(resolve, 0))

beforeEach(() => {
	api.commitToTreserva.mockReset()
	api.commitToTreserva.mockResolvedValue({ ok: true, verifierad: true, dnr: '2026-IFO-0999', gallrasDatum: '2026-09-01', receipt: {} })
	// K-SIGN-6 — default: ingen signeringspost i motorn ⇒ fallback-checkboxen.
	api.signeringList.mockReset()
	api.signeringList.mockResolvedValue({ niva_matris: {}, poster: [] })
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
	it('kraverSignering gates the commit until signed AND ≥1 doc is chosen (A9b)', async () => {
		// A9b — signering-committet kräver bekräftad signering OCH minst ett dokument.
		const w = mountG({ typ: 'signerat-beslut', kraverSignering: true, dokument: [{ fileid: 1, namn: 'Beslut' }] })
		expect(w.vm.kraverSignering).toBe(true)
		// Not confirmed yet → onCommit is a no-op (the button is also disabled).
		await w.vm.onCommit()
		expect(api.commitToTreserva).not.toHaveBeenCalled()
		// Confirm signed → commit proceeds (a doc is selected by default).
		w.vm.signeradBekraftad = true
		await w.vm.onCommit()
		expect(api.commitToTreserva).toHaveBeenCalledTimes(1)
		expect(w.emitted('committed')).toBeTruthy()
	})

	it('A9b — signed but no doc selected stays gated', async () => {
		const w = mountG({ typ: 'signerat-beslut', kraverSignering: true, dokument: [{ fileid: 1, namn: 'Beslut' }] })
		w.vm.signeradBekraftad = true
		w.vm.valda[0].vald = false // avmarkerar det enda dokumentet
		expect(w.vm.kanForaOver).toBe(false)
		await w.vm.onCommit()
		expect(api.commitToTreserva).not.toHaveBeenCalled()
	})

	it('a normal commit (no kraverSignering) is never gated', async () => {
		const w = mountG({ typ: 'beslut' })
		expect(w.vm.kraverSignering).toBe(false)
		await w.vm.onCommit()
		expect(api.commitToTreserva).toHaveBeenCalledTimes(1)
	})
})

describe('CommitGrind #A9b — advisory kommunicering (utredning→beslut)', () => {
	it('shows the checklist only in the utredning step', () => {
		// visaKommunicering härleds ur arende.steg.
		expect(shallowMountWithSteg('utredning').vm.visaKommunicering).toBe(true)
		expect(shallowMountWithSteg('beslut').vm.visaKommunicering).toBe(false)
	})

	it('emits kommuniceringVal {gjord:false} by default (no pre-check bias, T4/IVO)', async () => {
		// "Parterna har kommunicerats" får INTE vara förkryssad — ett förvalt
		// rättssäkerhetsintyg bevisar inget aktivt ställningstagande. Default är
		// därför gjord:false; handläggaren bockar aktivt i eller anger skäl.
		const w = shallowMountWithSteg('utredning', { typ: 'utredning', dokument: [{ fileid: 1, namn: 'Utredning' }] })
		await w.vm.onCommit()
		expect(w.emitted('committed')[0][2]).toEqual({ gjord: false })
	})

	it('emits kommuniceringVal {gjord:true} when the handläggare actively affirms', async () => {
		const w = shallowMountWithSteg('utredning', { typ: 'utredning', dokument: [{ fileid: 1, namn: 'Utredning' }] })
		w.vm.kommuniceringGjord = true
		await w.vm.onCommit()
		expect(w.emitted('committed')[0][2]).toEqual({ gjord: true })
	})

	it('emits kommuniceringVal {gjord:false, skal} when not communicated', async () => {
		const w = shallowMountWithSteg('utredning', { typ: 'utredning', dokument: [{ fileid: 1, namn: 'Utredning' }] })
		w.vm.kommuniceringGjord = false
		w.vm.kommuniceringSkalVal = 'sker_i_beslut'
		await w.vm.onCommit()
		expect(w.emitted('committed')[0][2]).toEqual({ gjord: false, skal: 'sker_i_beslut' })
	})

	it('carries null kommuniceringVal outside the utredning step', async () => {
		const w = shallowMountWithSteg('beslut', { typ: 'beslut', dokument: [{ fileid: 1, namn: 'X' }] })
		await w.vm.onCommit()
		expect(w.emitted('committed')[0][2]).toBeNull()
	})
})

describe('CommitGrind K-SIGN-6 — motorns verkliga signeringsstatus', () => {
	const dto = (status, extra = {}) => ({
		signRequestId: 'sr-1',
		handlingRef: 7,
		filename: 'beslut.docx',
		niva: 'ades',
		status,
		signers: [{ uid: 'anna', role: 'beslutsfattare', status: status === 'signed' ? 'signerad' : 'vantar', tidpunkt: null }],
		padesLevel: status === 'signed' ? 'PAdES-B-LTA' : null,
		createdAt: '2026-07-14T09:00:00Z',
		updatedAt: '2026-07-14T09:00:00Z',
		expiresAt: null,
		avvisadSkal: null,
		...extra,
	})

	it('signed signeringspost ersätter checkboxen — commit släpps utan manuell bekräftelse', async () => {
		api.signeringList.mockResolvedValue({ niva_matris: {}, poster: [dto('signed')] })
		const w = mountG({ typ: 'signerat-beslut', kraverSignering: true, dokument: [{ fileid: 1, namn: 'Beslut' }] })
		await flushP()
		expect(w.vm.eSignKlar).toBe(true)
		// Faktisk uppnådd PAdES-nivå stämplas i klar-texten (U7).
		expect(w.vm.eSignKlarText).toContain('PAdES-B-LTA')
		expect(w.vm.kanForaOver).toBe(true)
		await w.vm.onCommit()
		expect(api.commitToTreserva).toHaveBeenCalledTimes(1)
		expect(w.emitted('committed')).toBeTruthy()
	})

	it('pågående begäran låser grinden — även ett (dolt) manuellt kryss släpper inte', async () => {
		api.signeringList.mockResolvedValue({ niva_matris: {}, poster: [dto('pending')] })
		const w = mountG({ typ: 'signerat-beslut', kraverSignering: true, dokument: [{ fileid: 1, namn: 'Beslut' }] })
		await flushP()
		expect(w.vm.eSignKlar).toBe(false)
		w.vm.signeradBekraftad = true
		expect(w.vm.kanForaOver).toBe(false)
		await w.vm.onCommit()
		expect(api.commitToTreserva).not.toHaveBeenCalled()
	})

	it('avbrutna poster räknas inte — fallback-checkboxen gäller igen', async () => {
		api.signeringList.mockResolvedValue({ niva_matris: {}, poster: [dto('avbruten')] })
		const w = mountG({ typ: 'signerat-beslut', kraverSignering: true, dokument: [{ fileid: 1, namn: 'Beslut' }] })
		await flushP()
		expect(w.vm.signeringPost).toBeNull()
		w.vm.signeradBekraftad = true
		await w.vm.onCommit()
		expect(api.commitToTreserva).toHaveBeenCalledTimes(1)
	})

	it('signeringList-fel ⇒ ärlig fallback (checkboxen), aldrig en låst grind', async () => {
		api.signeringList.mockRejectedValue(new Error('nere'))
		const w = mountG({ typ: 'signerat-beslut', kraverSignering: true, dokument: [{ fileid: 1, namn: 'Beslut' }] })
		await flushP()
		expect(w.vm.signeringPost).toBeNull()
		w.vm.signeradBekraftad = true
		expect(w.vm.kanForaOver).toBe(true)
	})
})

/** Mount-hjälp med ett givet ärende-steg (driver visaKommunicering) + skippade timers. */
function shallowMountWithSteg(steg, payload = { typ: 'utredning' }) {
	const w = shallowMount(CommitGrind, {
		propsData: { arende: { dnr: '2026-IFO-0502', triageRef: '2026-IFO-0502', provenance: {}, steg }, payload },
	})
	w.vm.advanceTo = () => Promise.resolve()
	return w
}
