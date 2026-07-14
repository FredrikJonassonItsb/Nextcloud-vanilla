/**
 * Component tests for NivaBadge.vue — tvånivåmodellens kravnivå-badge
 * (K-SIGN-1/6). Kärninvarianten är K-SIGN-2: godkann-nivån får ALDRIG
 * benämnas underskrift/signatur i UI-text (ärlig etikettering).
 */
import { shallowMount } from '@vue/test-utils'
import NivaBadge from '../../src/components/socialsekreterare/NivaBadge.vue'

describe('NivaBadge — kravnivå per handlingstyp', () => {
	it('godkann → neutral "Godkänn"-badge', () => {
		const w = shallowMount(NivaBadge, { propsData: { niva: 'godkann' } })
		expect(w.text()).toContain('Godkänn')
		expect(w.classes()).toContain('niva-badge')
		expect(w.classes()).not.toContain('niva-badge--ades')
	})

	it('K-SIGN-2 — godkann-nivån använder ALDRIG orden underskrift/signatur', () => {
		const w = shallowMount(NivaBadge, { propsData: { niva: 'godkann' } })
		const synligt = (w.text() + ' '
			+ (w.attributes('aria-label') || '') + ' '
			+ (w.attributes('title') || '')).toLowerCase()
		expect(synligt).not.toMatch(/underskrift|signatur|signer/)
	})

	it('ades → accent-badge "e-underskrift"', () => {
		const w = shallowMount(NivaBadge, { propsData: { niva: 'ades' } })
		expect(w.text()).toContain('e-underskrift')
		expect(w.classes()).toContain('niva-badge--ades')
	})
})
