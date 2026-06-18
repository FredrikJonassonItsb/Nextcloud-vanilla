/**
 * Unit tests for src/store/index.js — pure state mutations.
 *
 * The store is a singleton over a shared Vue.observable state, so each test
 * resets the fields it touches in beforeEach. Vue, @nextcloud/initial-state and
 * the transitively-imported axios client are all provided by the jest mocks
 * (see jest.config.js moduleNameMapper) — no network is performed here because
 * applySummary / setActiveChannel / removeItem are synchronous and api-free.
 */

import store from '../../src/store/index.js'

beforeEach(() => {
	store.state.items = []
	store.state.maxSinceId = null
	store.state.activeChannel = 'all'
	store.state.counts = { kravAtgard: 0, otilldelat: 0, nytt: 0, bevakas: 0, klartIdag: 0, problem: 0 }
})

describe('applySummary — cold load', () => {
	it('replaces items wholesale when maxSinceId is unset', () => {
		store.state.items = [{ id: 'stale', since: '2020-01-01' }]
		store.applySummary({
			items: [
				{ id: 'a', since: '2026-06-10' },
				{ id: 'b', since: '2026-06-11' },
			],
			maxSinceId: 'b',
		})
		expect(store.state.items.map((i) => i.id)).toEqual(['a', 'b'])
		expect(store.state.maxSinceId).toBe('b')
	})

	it('treats a missing items array as an empty list on cold load', () => {
		store.state.items = [{ id: 'x', since: '2026-01-01' }]
		store.applySummary({ counts: { nytt: 3 } })
		expect(store.state.items).toEqual([])
	})

	it('applies summary-level fields (counts) when present', () => {
		store.applySummary({ items: [], counts: { nytt: 7 } })
		expect(store.state.counts).toEqual({ nytt: 7 })
	})

	it('ignores a null/undefined summary', () => {
		store.state.items = [{ id: 'keep', since: '2026-01-01' }]
		store.applySummary(null)
		store.applySummary(undefined)
		expect(store.state.items.map((i) => i.id)).toEqual(['keep'])
	})
})

describe('applySummary — incremental', () => {
	beforeEach(() => {
		store.state.items = [
			{ id: 'a', since: '2026-06-10' },
			{ id: 'b', since: '2026-06-11' },
		]
		store.state.maxSinceId = 'b'
	})

	it('upserts new items by id and keeps `since` descending order', () => {
		store.applySummary({
			items: [{ id: 'c', since: '2026-06-12' }],
			maxSinceId: 'c',
		})
		expect(store.state.items.map((i) => i.id)).toEqual(['c', 'b', 'a'])
		expect(store.state.maxSinceId).toBe('c')
	})

	it('updates an existing item in place rather than duplicating it', () => {
		store.applySummary({
			items: [{ id: 'a', since: '2026-06-13', status: 'klar' }],
			maxSinceId: 'a2',
		})
		const ids = store.state.items.map((i) => i.id)
		expect(ids).toEqual(['a', 'b'])
		expect(ids.filter((id) => id === 'a')).toHaveLength(1)
		const a = store.state.items.find((i) => i.id === 'a')
		expect(a.status).toBe('klar')
		expect(a.since).toBe('2026-06-13')
	})

	it('keeps maxSinceId unchanged when the payload omits it', () => {
		store.applySummary({ items: [{ id: 'c', since: '2026-06-12' }] })
		expect(store.state.maxSinceId).toBe('b')
	})
})

describe('setActiveChannel', () => {
	it('sets the active channel filter', () => {
		store.setActiveChannel('sdk')
		expect(store.state.activeChannel).toBe('sdk')
	})

	it('can reset back to all', () => {
		store.setActiveChannel('fax')
		store.setActiveChannel('all')
		expect(store.state.activeChannel).toBe('all')
	})
})

describe('removeItem', () => {
	beforeEach(() => {
		store.state.items = [{ id: 'a', since: '1' }, { id: 'b', since: '2' }, { id: 'c', since: '3' }]
	})

	it('removes the item with the matching id', () => {
		store.removeItem('b')
		expect(store.state.items.map((i) => i.id)).toEqual(['a', 'c'])
	})

	it('is a no-op for an unknown id', () => {
		store.removeItem('zzz')
		expect(store.state.items.map((i) => i.id)).toEqual(['a', 'b', 'c'])
	})
})
