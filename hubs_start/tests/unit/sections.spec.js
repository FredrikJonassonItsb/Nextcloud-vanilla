/**
 * Unit tests for src/services/sections.js — fixed section/status ordering and labels.
 * @nextcloud/l10n is mapped to the identity translator mock, so labels resolve to
 * their raw Swedish source strings.
 */

import {
	SECTIONS,
	STATUSES,
	sectionLabel,
	statusLabel,
	statusTone,
} from '../../src/services/sections.js'

describe('SECTIONS', () => {
	it('preserves the fixed top→bottom order', () => {
		expect(SECTIONS.map((s) => s.id)).toEqual([
			'kraver_atgard',
			'otilldelat',
			'nytt',
			'bevakas',
			'klart_idag',
		])
	})

	it('pairs each id with its label key', () => {
		expect(SECTIONS).toEqual([
			{ id: 'kraver_atgard', labelKey: 'Kräver åtgärd' },
			{ id: 'otilldelat', labelKey: 'Otilldelat' },
			{ id: 'nytt', labelKey: 'Nytt' },
			{ id: 'bevakas', labelKey: 'Bevakas' },
			{ id: 'klart_idag', labelKey: 'Klart idag' },
		])
	})
})

describe('sectionLabel', () => {
	it('returns the localised label for a known section', () => {
		expect(sectionLabel('kraver_atgard')).toBe('Kräver åtgärd')
		expect(sectionLabel('klart_idag')).toBe('Klart idag')
	})

	it('returns the id verbatim for an unknown section', () => {
		expect(sectionLabel('does_not_exist')).toBe('does_not_exist')
	})
})

describe('statusTone', () => {
	it('maps each known status to its tone token', () => {
		expect(statusTone('ny')).toBe('info')
		expect(statusTone('tilldelad')).toBe('neutral')
		expect(statusTone('vantar_kvittens')).toBe('warning')
		expect(statusTone('besvarad')).toBe('success')
		expect(statusTone('problem')).toBe('error')
		expect(statusTone('klar')).toBe('success')
	})

	it('falls back to neutral for an unknown status', () => {
		expect(statusTone('mystery')).toBe('neutral')
		expect(statusTone(undefined)).toBe('neutral')
	})

	it('matches the tone stored on each STATUSES entry', () => {
		for (const [id, def] of Object.entries(STATUSES)) {
			expect(statusTone(id)).toBe(def.tone)
		}
	})
})

describe('statusLabel', () => {
	it('returns the localised label for a known status', () => {
		expect(statusLabel('ny')).toBe('Ny')
		expect(statusLabel('vantar_kvittens')).toBe('Väntar på kvittens')
		expect(statusLabel('klar')).toBe('Klar')
	})

	it('returns the id verbatim for an unknown status', () => {
		expect(statusLabel('nope')).toBe('nope')
	})
})
