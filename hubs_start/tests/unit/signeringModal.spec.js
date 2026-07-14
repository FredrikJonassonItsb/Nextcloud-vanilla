/**
 * Component tests for SigneringModal.vue — tvånivåmodellens två vägar
 * (K-SIGN-1–4): godkann → signeringGodkann + journalfört kvitto ("Godkänt av
 * <roll>", ALDRIG signatur-ord); ades → påskrivarval (default: tilldelad
 * handläggare som beslutsfattare) → signeringBegar. api + store mockas;
 * assertions på vm-state + emitted (NcModal-stubben renderar inga slots).
 */
jest.mock('../../src/services/api.js', () => ({
	__esModule: true,
	signeringList: jest.fn(),
	signeringGodkann: jest.fn(),
	signeringBegar: jest.fn(),
	fetchArendeMedlemmar: jest.fn(),
}))
jest.mock('../../src/store/index.js', () => ({
	__esModule: true,
	default: { loadArende: jest.fn(), state: { arende: { full: {} } } },
}))
jest.mock('@nextcloud/auth', () => ({
	getCurrentUser: () => ({ uid: 'anna', displayName: 'Anna' }),
}))

import { signeringList, signeringGodkann, signeringBegar, fetchArendeMedlemmar } from '../../src/services/api.js'
import store from '../../src/store/index.js'
import { shallowMount } from '@vue/test-utils'
import SigneringModal from '../../src/components/socialsekreterare/SigneringModal.vue'

const ARENDE = {
	hubsCaseId: 'case-1',
	triageRef: '2026-IFO-0527',
	dnr: null,
	tilldelning: { status: 'tilldelat', agareUid: 'anna', agareNamn: 'Anna' },
}

const mountM = () => shallowMount(SigneringModal, { propsData: { arende: { ...ARENDE } } })

const flush = async () => {
	for (let i = 0; i < 8; i++) {
		await Promise.resolve()
	}
}

beforeEach(() => {
	signeringList.mockResolvedValue({ niva_matris: {}, poster: [] })
	signeringGodkann.mockResolvedValue({ journalfort: true, niva: 'godkann', tidpunkt: '2026-07-14T10:00:00Z' })
	signeringBegar.mockResolvedValue(null)
	fetchArendeMedlemmar.mockResolvedValue([
		{ uid: 'anna', roll: 'handlaggare', displayName: 'Anna' },
		{ uid: 'bo', roll: 'co_handlaggare', displayName: 'Bo' },
	])
	store.loadArende.mockResolvedValue({
		rum: {
			dokument: [
				{ fileid: 3, namn: 'journalanteckning.docx' },
				{ fileid: 7, namn: 'beslut-2026.docx', hash: 'abc123def4567890fedc' },
				'msg-1a2b3c.url',
			],
		},
	})
})

describe('SigneringModal — nivåupplösning (K-SIGN-1)', () => {
	it('läser nivån ur motorns niva_matris; beslutshandlingen är default-valet', async () => {
		signeringList.mockResolvedValue({ niva_matris: { beslut: 'godkann' }, poster: [] })
		const w = mountM()
		await flush()
		// msg-*.url-pekaren filtreras bort; beslutshandlingen auto-väljs.
		expect(w.vm.dokument).toHaveLength(2)
		expect(w.vm.valdDok.namn).toBe('beslut-2026.docx')
		expect(w.vm.niva).toBe('godkann')
		expect(w.vm.arAdes).toBe(false)
	})

	it('okänd handlingstyp i matrisen faller SÄKERT till ades (aldrig för låg nivå)', async () => {
		signeringList.mockResolvedValue({ niva_matris: {}, poster: [] })
		const w = mountM()
		await flush()
		expect(w.vm.niva).toBe('ades')
	})

	it('hash-prefix visas kort — aldrig hela hashen', async () => {
		const w = mountM()
		await flush()
		expect(w.vm.hashPrefix).toBe('abc123def4…')
	})
})

describe('SigneringModal — godkann-vägen (K-SIGN-2)', () => {
	it('Godkänn → signeringGodkann med {handlingRef, filename, dokumentHash} → journalfört kvitto', async () => {
		signeringList.mockResolvedValue({ niva_matris: { beslut: 'godkann' }, poster: [] })
		const w = mountM()
		await flush()
		await w.vm.onGodkann()
		// handlingRef som STRÄNG — motorns OCS-parameter är string (strict_types).
		expect(signeringGodkann).toHaveBeenCalledWith('case-1', {
			handlingRef: '7',
			filename: 'beslut-2026.docx',
			dokumentHash: 'abc123def4567890fedc',
		})
		expect(signeringBegar).not.toHaveBeenCalled()
		expect(w.vm.resultat.typ).toBe('godkand')
		// Ärlig etikettering: "Godkänt av <roll>" — aldrig signatur-ord.
		expect(w.vm.resultat.roll).toBe('handläggare')
		expect(w.vm.kvittoText.toLowerCase()).not.toMatch(/underskrift|signatur|signer/)
		expect(w.emitted('klar')).toBeTruthy()
	})

	it('ej journalfört ⇒ ärligt fel, inget klar-kvitto', async () => {
		signeringList.mockResolvedValue({ niva_matris: { beslut: 'godkann' }, poster: [] })
		signeringGodkann.mockResolvedValue({ journalfort: false, error: 'grind_stangd' })
		const w = mountM()
		await flush()
		await w.vm.onGodkann()
		expect(w.vm.resultat).toBeNull()
		expect(w.vm.fel).toBe('grind_stangd')
		expect(w.emitted('klar')).toBeFalsy()
	})
})

describe('SigneringModal — ades-vägen (K-SIGN-3/9)', () => {
	it('default-påskrivare: den tilldelade handläggaren som beslutsfattare', async () => {
		signeringList.mockResolvedValue({ niva_matris: { beslut: 'ades' }, poster: [] })
		const w = mountM()
		await flush()
		expect(w.vm.arAdes).toBe(true)
		expect(w.vm.valdaUids).toEqual(['anna'])
	})

	it('Skicka → signeringBegar med signers-kontraktet {uid, role}', async () => {
		signeringList.mockResolvedValue({ niva_matris: { beslut: 'ades' }, poster: [] })
		signeringBegar.mockResolvedValue({
			signRequestId: 'sr-1', status: 'pending', signers: [], filename: 'beslut-2026.docx', niva: 'ades',
		})
		const w = mountM()
		await flush()
		w.vm.toggleSigner('bo', true)
		await w.vm.onSkicka()
		expect(signeringBegar).toHaveBeenCalledWith('case-1', {
			handlingRef: '7',
			filename: 'beslut-2026.docx',
			dokumentHash: 'abc123def4567890fedc',
			signers: [
				{ uid: 'anna', role: 'beslutsfattare' },
				{ uid: 'bo', role: 'co_handlaggare' },
			],
		})
		expect(w.vm.resultat.signRequestId).toBe('sr-1')
		expect(w.emitted('klar')[0][0].status).toBe('pending')
	})

	it('instant-signed (stubben) ⇒ klar-kvitto direkt med verklig status', async () => {
		signeringList.mockResolvedValue({ niva_matris: { beslut: 'ades' }, poster: [] })
		signeringBegar.mockResolvedValue({ signRequestId: 'sr-1', status: 'signed', signers: [] })
		const w = mountM()
		await flush()
		await w.vm.onSkicka()
		expect(w.vm.resultat.status).toBe('signed')
		expect(w.vm.kvittoText).toContain('E-underskrift klar')
		expect(w.emitted('klar')[0][0].status).toBe('signed')
	})

	it('inga valda påskrivare ⇒ ingen begäran skickas', async () => {
		signeringList.mockResolvedValue({ niva_matris: { beslut: 'ades' }, poster: [] })
		const w = mountM()
		await flush()
		w.vm.toggleSigner('anna', false)
		await w.vm.onSkicka()
		expect(signeringBegar).not.toHaveBeenCalled()
	})
})
