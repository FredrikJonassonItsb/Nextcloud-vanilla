/**
 * Component tests for SigneringPanel.vue — statuskedjan, per-part-status och
 * auto-pollen (K-SIGN-5/6/7/9/22). api-modulen mockas; poll-testerna kör jests
 * fake timers (intervallet) + rena microtask-flushar (mockade promises).
 * Assertions på vm-state + plain-element-text (Nc*-komponenterna är stubbar).
 */
jest.mock('../../src/services/api.js', () => ({
	__esModule: true,
	signeringList: jest.fn(),
	signeringRefresh: jest.fn(),
	signeringFornya: jest.fn(),
	signeringAvbryt: jest.fn(),
	signeringPaminn: jest.fn(),
}))

import { signeringList, signeringRefresh, signeringFornya, signeringAvbryt, signeringPaminn } from '../../src/services/api.js'
import { shallowMount } from '@vue/test-utils'
import SigneringPanel from '../../src/components/socialsekreterare/SigneringPanel.vue'

/** SigneringDTO-fixture enligt OCS-kontraktet. */
const dto = (status, extra = {}) => ({
	signRequestId: 'sr-1',
	handlingRef: 7,
	filename: 'beslut.docx',
	niva: 'ades',
	status,
	signers: [
		{ uid: 'anna', role: 'beslutsfattare', status: status === 'pending' ? 'vantar' : 'signerad', tidpunkt: status === 'pending' ? null : '2026-07-14T10:00:00Z' },
		{ uid: 'bo', role: 'co_handlaggare', status: status === 'signed' ? 'signerad' : 'vantar', tidpunkt: status === 'signed' ? '2026-07-14T10:05:00Z' : null },
	],
	padesLevel: status === 'signed' ? 'PAdES-B-LTA' : null,
	createdAt: '2026-07-14T09:00:00Z',
	updatedAt: '2026-07-14T09:00:00Z',
	expiresAt: null,
	avvisadSkal: null,
	...extra,
})

const mountP = () => shallowMount(SigneringPanel, {
	propsData: { arende: { hubsCaseId: 'case-1', triageRef: '2026-IFO-0527' } },
})

/** Flush enbart microtasks (fungerar under fake timers — inga setTimeout-behov). */
const flush = async () => {
	for (let i = 0; i < 8; i++) {
		await Promise.resolve()
	}
}

beforeEach(() => {
	signeringList.mockResolvedValue({ niva_matris: {}, poster: [] })
	signeringRefresh.mockResolvedValue(null)
	signeringFornya.mockResolvedValue(null)
	signeringAvbryt.mockResolvedValue(null)
	signeringPaminn.mockResolvedValue({ paminnelse: true })
})

afterEach(() => {
	jest.useRealTimers()
})

describe('SigneringPanel — statusrendering (K-SIGN-6/7/9)', () => {
	it('tom panel: ärligt tomläge, ingen poll', async () => {
		const w = mountP()
		await flush()
		expect(w.text()).toContain('Ingen e-underskrift är begärd i ärendet.')
		expect(w.vm.pollTimer).toBeNull()
		w.destroy()
	})

	it('pending: kedjan börjar på Skickad, per-part visar Väntar', async () => {
		signeringList.mockResolvedValue({ niva_matris: {}, poster: [dto('pending')] })
		const w = mountP()
		await flush()
		expect(w.vm.aktivtSteg(w.vm.poster[0])).toBe(0)
		expect(w.vm.signeradeAntal(w.vm.poster[0])).toBe(0)
		expect(w.text()).toContain('Skickad')
		expect(w.text()).toContain('Väntar')
		// Per-part-raderna: uid + roll-etikett.
		expect(w.text()).toContain('anna')
		expect(w.text()).toContain('beslutsfattare')
		expect(w.text()).toContain('medhandläggare')
		w.destroy()
	})

	it('partially_signed: Signerad X av Y-steget är aktivt, tidpunkt visas', async () => {
		signeringList.mockResolvedValue({ niva_matris: {}, poster: [dto('partially_signed')] })
		const w = mountP()
		await flush()
		expect(w.vm.aktivtSteg(w.vm.poster[0])).toBe(1)
		expect(w.vm.signeradeAntal(w.vm.poster[0])).toBe(1)
		expect(w.text()).toContain('Delvis signerad')
		w.destroy()
	})

	it('signed: hela kedjan klar + faktisk PAdES-nivå (U7) + ingen poll', async () => {
		signeringList.mockResolvedValue({ niva_matris: {}, poster: [dto('signed')] })
		const w = mountP()
		await flush()
		expect(w.vm.aktivtSteg(w.vm.poster[0])).toBe(3)
		expect(w.text()).toContain('Klar')
		expect(w.vm.klarText(w.vm.poster[0])).toContain('PAdES-B-LTA')
		expect(w.vm.pollTimer).toBeNull()
		w.destroy()
	})

	it('rejected: åtgärdbar väg (K-SIGN-7) med avvisad-skäl', async () => {
		signeringList.mockResolvedValue({ niva_matris: {}, poster: [dto('rejected', { avvisadSkal: 'fel person' })] })
		const w = mountP()
		await flush()
		expect(w.text()).toContain('Avvisad')
		expect(w.vm.negativText(w.vm.poster[0])).toContain('fel person')
		expect(w.vm.arNegativ(w.vm.poster[0])).toBe(true)
		w.destroy()
	})

	it('expired: åtgärdbar väg med förnya-uppmaning', async () => {
		signeringList.mockResolvedValue({ niva_matris: {}, poster: [dto('expired')] })
		const w = mountP()
		await flush()
		expect(w.text()).toContain('Utgången')
		expect(w.vm.negativText(w.vm.poster[0])).toContain('Förnya')
		w.destroy()
	})

	it('avbruten: gråmarkerad, ingen poll', async () => {
		signeringList.mockResolvedValue({ niva_matris: {}, poster: [dto('avbruten')] })
		const w = mountP()
		await flush()
		expect(w.text()).toContain('Avbruten')
		expect(w.vm.pollTimer).toBeNull()
		w.destroy()
	})
})

describe('SigneringPanel — åtgärder (K-SIGN-7)', () => {
	it('Förnya: signeringFornya → NY post ersätter i listan', async () => {
		signeringList.mockResolvedValue({ niva_matris: {}, poster: [dto('rejected')] })
		signeringFornya.mockResolvedValue(dto('pending', { signRequestId: 'sr-2' }))
		const w = mountP()
		await flush()
		await w.vm.fornya(w.vm.poster[0])
		expect(signeringFornya).toHaveBeenCalledWith('case-1', 'sr-1')
		expect(w.vm.poster.some((p) => p.signRequestId === 'sr-2')).toBe(true)
		w.destroy()
	})

	it('Avbryt: skäl-enum krävs och journalförs (aldrig fri text)', async () => {
		signeringList.mockResolvedValue({ niva_matris: {}, poster: [dto('pending')] })
		signeringAvbryt.mockResolvedValue(dto('avbruten'))
		const w = mountP()
		await flush()
		const post = w.vm.poster[0]
		w.vm.oppnaAvbryt(post)
		// INGET förval — skälet väljs aktivt (samma princip som grindarna).
		expect(w.vm.avbrytSkal).toBe('')
		w.vm.avbrytSkal = 'fel_handling'
		await w.vm.avbryt(post)
		expect(signeringAvbryt).toHaveBeenCalledWith('case-1', 'sr-1', 'fel_handling')
		expect(w.vm.poster[0].status).toBe('avbruten')
		w.destroy()
	})

	it('Påminn: journalförd påminnelse via motorn (v1 — ingen Talk)', async () => {
		signeringList.mockResolvedValue({ niva_matris: {}, poster: [dto('pending')] })
		const w = mountP()
		await flush()
		await w.vm.paminn(w.vm.poster[0])
		expect(signeringPaminn).toHaveBeenCalledWith('case-1', 'sr-1')
		w.destroy()
	})

	it('Uppdatera: idempotent refresh ersätter DTO:n (K-SIGN-22)', async () => {
		signeringList.mockResolvedValue({ niva_matris: {}, poster: [dto('pending')] })
		signeringRefresh.mockResolvedValue(dto('partially_signed'))
		const w = mountP()
		await flush()
		await w.vm.uppdatera(w.vm.poster[0])
		expect(signeringRefresh).toHaveBeenCalledWith('case-1', 'sr-1')
		expect(w.vm.poster[0].status).toBe('partially_signed')
		w.destroy()
	})
})

describe('SigneringPanel — auto-poll (K-SIGN-6/22)', () => {
	it('pollar var 10:e sekund medan pending; stannar + emittar signed vid klart', async () => {
		jest.useFakeTimers()
		signeringList.mockResolvedValue({ niva_matris: {}, poster: [dto('pending')] })
		signeringRefresh.mockResolvedValue(dto('signed'))
		const w = mountP()
		await flush()
		// Pågående post ⇒ pollen är igång.
		expect(w.vm.pollTimer).not.toBeNull()
		jest.advanceTimersByTime(10000)
		await flush()
		expect(signeringRefresh).toHaveBeenCalledTimes(1)
		// Verklig status signed ⇒ emit uppåt (driver nastaAtgard-kedjan, K-SIGN-8) …
		expect(w.emitted('signed')).toBeTruthy()
		expect(w.emitted('signed')[0][0].status).toBe('signed')
		// … och pollen släcks (watchern flushas på nextTick).
		await w.vm.$nextTick()
		expect(w.vm.pollTimer).toBeNull()
		jest.advanceTimersByTime(30000)
		await flush()
		expect(signeringRefresh).toHaveBeenCalledTimes(1)
		w.destroy()
	})

	it('ingen poll utan pågående poster', async () => {
		jest.useFakeTimers()
		signeringList.mockResolvedValue({ niva_matris: {}, poster: [dto('signed')] })
		const w = mountP()
		await flush()
		expect(w.vm.pollTimer).toBeNull()
		jest.advanceTimersByTime(30000)
		expect(signeringRefresh).not.toHaveBeenCalled()
		w.destroy()
	})

	it('clearInterval vid destroy (fliken stängs)', async () => {
		jest.useFakeTimers()
		signeringList.mockResolvedValue({ niva_matris: {}, poster: [dto('pending')] })
		const w = mountP()
		await flush()
		expect(w.vm.pollTimer).not.toBeNull()
		w.destroy()
		expect(w.vm.pollTimer).toBeNull()
		jest.advanceTimersByTime(30000)
		expect(signeringRefresh).not.toHaveBeenCalled()
	})
})
