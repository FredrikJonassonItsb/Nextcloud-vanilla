/**
 * Component tests for InflodeRad.vue — the #19 type-chip fallback and the #1
 * excerpt / verified-source badge.
 *
 * THIS is the test layer whose absence let the wave-0/1 defects through: the
 * earlier tests ran against DEMO data (which carries content messageTypes + real
 * badges), masking how the row renders against PROD-shaped sdkmc data (channel
 * transport ids, empty identitet, no excerpt). We mount with prod-shaped props.
 *
 * shallowMount stubs the child components (NcButton/KopplingBadge/FristChip/icons),
 * so assertions are on InflodeRad's own template text/classes.
 */
import { shallowMount } from '@vue/test-utils'
import InflodeRad from '../../src/components/socialsekreterare/InflodeRad.vue'

const base = {
	id: 'inf:1',
	channel: { channel: 'sdk', channelLabel: 'SDK-Meddelande', messageType: 'sdk_message' },
	korg: { label: 'Orosanmälan', addr: 'orosanmalan@gruppbox' },
	avsandare: 'SDK-Meddelande',
	identitet: { badge: '', verifierad: false },
	titel: 'Inkommande',
	inkomDatum: '2026-06-14T08:00:00',
}
const mk = (rad) => shallowMount(InflodeRad, { propsData: { rad, actions: [] } })
const typText = (w) => w.find('.inflode-rad__typ-text').text()

describe('InflodeRad — #19 type-chip fallback', () => {
	it('shows the localized type label for a classified row', () => {
		const w = mk({ ...base, messageType: 'orosanmalan' })
		expect(typText(w)).toBe('Orosanmälan')
		expect(w.find('.inflode-rad__typ-chip--oklassad').exists()).toBe(false)
	})

	it('falls back to the channel label for a channel-transport id (never blank, never a raw id)', () => {
		const w = mk({
			...base,
			messageType: 'internal_message',
			channel: { channel: 'internal', channelLabel: 'Internpost', messageType: 'internal_message' },
		})
		const txt = typText(w)
		expect(txt).toBe('Internpost') // channelLabel, not the raw id
		expect(txt).not.toBe('')
		expect(txt).not.toBe('internal_message')
		expect(w.find('.inflode-rad__typ-chip--oklassad').exists()).toBe(true)
		expect(w.text()).toContain('oklassad') // sr-only marker
	})

	it('falls back to the channel label when messageType is missing', () => {
		const w = mk({ ...base, messageType: undefined })
		expect(typText(w)).toBe('SDK-Meddelande')
		expect(typText(w)).not.toBe('')
	})

	it('falls back to channelMeta "Okänd kanal" when the channel is unknown too', () => {
		const w = mk({ ...base, messageType: undefined, channel: undefined })
		expect(typText(w)).toBe('Okänd kanal')
		expect(w.find('.inflode-rad__typ-chip--oklassad').exists()).toBe(true)
	})
})

describe('InflodeRad — #1 excerpt + verified-source badge (absent-safe)', () => {
	it('renders an excerpt when present', () => {
		const w = mk({ ...base, messageType: 'orosanmalan', excerpt: 'Kort PII-fri text' })
		expect(w.find('.inflode-rad__excerpt').text()).toBe('Kort PII-fri text')
	})

	it('renders no excerpt line when absent or blank', () => {
		expect(mk({ ...base, messageType: 'orosanmalan' }).find('.inflode-rad__excerpt').exists()).toBe(false)
		expect(mk({ ...base, messageType: 'orosanmalan', excerpt: '   ' }).find('.inflode-rad__excerpt').exists()).toBe(false)
	})

	it('renders the verified-source badge only on === true', () => {
		expect(mk({ ...base, messageType: 'orosanmalan', verifieradKalla: true }).find('.inflode-rad__kalla-badge').exists()).toBe(true)
		expect(mk({ ...base, messageType: 'orosanmalan' }).find('.inflode-rad__kalla-badge').exists()).toBe(false)
		expect(mk({ ...base, messageType: 'orosanmalan', verifieradKalla: false }).find('.inflode-rad__kalla-badge').exists()).toBe(false)
	})

	it('uses a custom badge label when provided', () => {
		const w = mk({ ...base, messageType: 'orosanmalan', verifieradKalla: true, kallaBadge: 'SITHS-källa' })
		expect(w.find('.inflode-rad__kalla-badge').text()).toContain('SITHS-källa')
	})
})

describe('InflodeRad — brand rule', () => {
	it('never renders forbidden product names', () => {
		const w = mk({ ...base, messageType: undefined })
		expect(w.text()).not.toMatch(/Nextcloud|Talk|Circles/)
	})
})
