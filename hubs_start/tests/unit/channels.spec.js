/**
 * Unit tests for src/services/channels.js — pure presentation logic.
 * @nextcloud/l10n is mapped to tests/mocks/nextcloud.js (identity translator),
 * so labels are asserted as their raw Swedish source strings.
 */

import {
	CHANNELS,
	CHANNEL_TO_MESSAGE_TYPE,
	CHANNEL_ORDER,
	channelMeta,
} from '../../src/services/channels.js'

describe('channelMeta', () => {
	it('returns correct label/id for SDK', () => {
		const meta = channelMeta(CHANNELS.SDK)
		expect(meta.id).toBe('sdk')
		expect(meta.label).toBe('SDK-Meddelande')
		expect(meta.colorVar).toBe('--hs-channel-sdk')
	})

	it('returns correct label/id for INTERNAL', () => {
		const meta = channelMeta(CHANNELS.INTERNAL)
		expect(meta.id).toBe('internal')
		expect(meta.label).toBe('Internpost')
		expect(meta.colorVar).toBe('--hs-channel-internal')
	})

	it('returns correct label/id for SECURE', () => {
		const meta = channelMeta(CHANNELS.SECURE)
		expect(meta.id).toBe('secure')
		expect(meta.label).toBe('Säker E-post')
		expect(meta.colorVar).toBe('--hs-channel-secure')
	})

	it('returns correct label/id for FAX', () => {
		const meta = channelMeta(CHANNELS.FAX)
		expect(meta.id).toBe('fax')
		expect(meta.label).toBe('Fax')
		expect(meta.colorVar).toBe('--hs-channel-fax')
	})

	it('returns correct label/id for SMS', () => {
		const meta = channelMeta(CHANNELS.SMS)
		expect(meta.id).toBe('sms')
		expect(meta.label).toBe('SMS')
		expect(meta.colorVar).toBe('--hs-channel-sms')
	})

	it('every metadata entry exposes an icon component', () => {
		for (const ch of CHANNEL_ORDER) {
			expect(channelMeta(ch).icon).toBeDefined()
		}
	})

	it('falls back to UNKNOWN for an unrecognised channel id', () => {
		const meta = channelMeta('not-a-real-channel')
		expect(meta.id).toBe(CHANNELS.UNKNOWN)
		expect(meta.id).toBe('unknown')
		expect(meta.label).toBe('Okänd kanal')
		expect(meta.colorVar).toBe('--hs-channel-unknown')
		expect(meta.icon).toBeDefined()
	})

	it('falls back to UNKNOWN for undefined/null input', () => {
		expect(channelMeta(undefined).id).toBe('unknown')
		expect(channelMeta(null).id).toBe('unknown')
	})
})

describe('CHANNEL_TO_MESSAGE_TYPE', () => {
	it('maps each channel id to its mail message-type', () => {
		expect(CHANNEL_TO_MESSAGE_TYPE).toEqual({
			sdk: 'sdk_message',
			internal: 'internal_message',
			secure: 'secure_email',
			fax: 'fax_message',
			sms: 'sms_message',
		})
	})

	it('has no mapping for the UNKNOWN channel', () => {
		expect(CHANNEL_TO_MESSAGE_TYPE[CHANNELS.UNKNOWN]).toBeUndefined()
	})
})

describe('CHANNEL_ORDER', () => {
	it('lists channels in the canonical display order', () => {
		expect(CHANNEL_ORDER).toEqual(['sdk', 'secure', 'internal', 'fax', 'sms'])
	})

	it('does not include the UNKNOWN channel', () => {
		expect(CHANNEL_ORDER).not.toContain(CHANNELS.UNKNOWN)
	})

	it('contains exactly the five concrete channels', () => {
		expect(CHANNEL_ORDER).toHaveLength(5)
		expect([...CHANNEL_ORDER].sort()).toEqual(
			[CHANNELS.SDK, CHANNELS.SECURE, CHANNELS.INTERNAL, CHANNELS.FAX, CHANNELS.SMS].sort(),
		)
	})
})
