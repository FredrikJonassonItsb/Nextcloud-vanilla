/**
 * Tests for store.loadArende + store.enrichArende (#3 hybrid enrichment-at-expand).
 *
 * The engine card is cached under a STABLE ref (triageRef), then sdkmc-owned
 * diskussion-summary is merged in WITHOUT clobbering engine-honest fields. The api
 * module is mocked so no network runs and we control both the engine + enrichment
 * payloads.
 */
jest.mock('../../src/services/api.js', () => ({
	__esModule: true,
	default: {
		fetchArende: jest.fn(),
		fetchArendeEnrichment: jest.fn(),
	},
}))

import api from '../../src/services/api.js'
import store from '../../src/store/index.js'

const flush = () => new Promise((r) => setTimeout(r, 0))

beforeEach(() => {
	store.state.arende.full = {}
	api.fetchArende.mockReset()
	api.fetchArendeEnrichment.mockReset()
})

describe('loadArende + enrichArende', () => {
	it('caches under the ref and merges enrichment diskussion without overwriting engine fields', async () => {
		api.fetchArende.mockResolvedValue({
			steg: 'utredning',
			frist: { tone: 'warning' },
			provenance: { state: 'registrerad' },
			pekare: { talkToken: 'tok' },
			meddelanden: [],
			moten: [],
		})
		api.fetchArendeEnrichment.mockResolvedValue({
			diskussion: { olasta: 2, omnamnandeTillMig: true, deltagare: ['a', 'b'], meddelanden: [] },
			meddelanden: [],
			moten: [],
		})

		await store.loadArende('2026-IFO-0502')
		await flush() // enrichArende is fire-and-forget inside loadArende

		const full = store.state.arende.full['2026-IFO-0502']
		expect(full.steg).toBe('utredning') // engine-honest preserved
		expect(full.provenance.state).toBe('registrerad')
		expect(full.pekare.talkToken).toBe('tok')
		expect(full.diskussion.olasta).toBe(2) // enrichment merged in
		expect(full.diskussion.omnamnandeTillMig).toBe(true)
		expect(api.fetchArendeEnrichment).toHaveBeenCalledWith('tok')
	})

	it('skips enrichment when there is no talkToken (no throw, engine card intact)', async () => {
		api.fetchArende.mockResolvedValue({ steg: 'forhandsbedomning', pekare: { talkToken: null } })
		await store.loadArende('triage-1')
		await flush()
		expect(store.state.arende.full['triage-1'].steg).toBe('forhandsbedomning')
		expect(api.fetchArendeEnrichment).not.toHaveBeenCalled()
	})

	it('enrichment failure is non-fatal — the engine card stays cached', async () => {
		api.fetchArende.mockResolvedValue({ steg: 'beslut', pekare: { talkToken: 'tok' } })
		api.fetchArendeEnrichment.mockRejectedValue(new Error('spreed down'))
		await store.loadArende('case-y')
		await flush()
		expect(store.state.arende.full['case-y'].steg).toBe('beslut')
	})

	it('fetchArende error → null, nothing cached', async () => {
		api.fetchArende.mockRejectedValue(new Error('404'))
		const r = await store.loadArende('case-z')
		expect(r).toBeNull()
		expect(store.state.arende.full['case-z']).toBeUndefined()
	})

	it('returns the cached card without refetching on a second call', async () => {
		api.fetchArende.mockResolvedValue({ steg: 'utredning', pekare: { talkToken: null } })
		await store.loadArende('case-a')
		await flush()
		await store.loadArende('case-a')
		expect(api.fetchArende).toHaveBeenCalledTimes(1)
	})
})
