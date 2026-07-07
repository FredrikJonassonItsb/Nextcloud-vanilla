/**
 * Unit tests for src/services/deepLinks.js — URL builders.
 *
 * @nextcloud/router is mapped to tests/mocks/nextcloud.js, whose generateUrl
 * prepends '/index.php' and substitutes {placeholders} (URL-encoded) from params.
 * We therefore assert on the resulting path fragments rather than absolute URLs.
 */

import deepLinks, {
	threadLink,
	composerLink,
	deckLink,
	mailboxLink,
	callLink,
	spreedRoomLink,
	teamLink,
	loa3UpgradeLink,
	resolve,
} from '../../src/services/deepLinks.js'

describe('threadLink', () => {
	it('targets the sdkmc mailbox-link redirect with the mid query', () => {
		expect(threadLink(42, 1001)).toBe('/index.php/apps/sdkmc/mailbox-link/42?mid=1001')
	})

	it('URL-encodes the mid', () => {
		expect(threadLink(7, 'a b')).toBe('/index.php/apps/sdkmc/mailbox-link/7?mid=a%20b')
	})
})

describe('composerLink', () => {
	it('builds /apps/mail/new with the message type', () => {
		expect(composerLink('sdk_message')).toBe('/index.php/apps/mail/new?type=sdk_message')
	})

	it('appends an encoded recipient when provided', () => {
		expect(composerLink('secure_email', 'user@example.com')).toBe(
			'/index.php/apps/mail/new?type=secure_email&to=user%40example.com',
		)
	})

	it('omits the to param when recipient is null', () => {
		expect(composerLink('fax_message', null)).toBe('/index.php/apps/mail/new?type=fax_message')
	})
})

describe('mailboxLink', () => {
	it('builds the mail box route', () => {
		expect(mailboxLink('INBOX')).toBe('/index.php/apps/mail/box/INBOX')
	})
})

describe('callLink', () => {
	it('builds the Talk call route from a token', () => {
		expect(callLink('abc123')).toBe('/index.php/call/abc123')
	})
})

describe('deckLink', () => {
	it('builds the Deck board route from a board id', () => {
		expect(deckLink(3)).toBe('/index.php/apps/deck/board/3')
	})

	it('falls back to the Deck board list when no board id is known (404-safe)', () => {
		expect(deckLink(null)).toBe('/index.php/apps/deck/')
		expect(deckLink()).toBe('/index.php/apps/deck/')
	})
})

describe('spreedRoomLink', () => {
	it('builds the room route from a token', () => {
		expect(spreedRoomLink('4w54xxqc')).toBe('/index.php/call/4w54xxqc')
	})

	it('returns null when no token is known (caller must disable the control)', () => {
		expect(spreedRoomLink(null)).toBeNull()
		expect(spreedRoomLink('')).toBeNull()
		expect(spreedRoomLink(undefined)).toBeNull()
	})
})

describe('teamLink', () => {
	it('builds the Contacts team deep link from a circle singleId', () => {
		expect(teamLink('NlPWA6hFlhIBpudXzx7AmmagVDSyLxj'))
			.toBe('/index.php/apps/contacts/direct/circle/NlPWA6hFlhIBpudXzx7AmmagVDSyLxj')
	})

	it('returns null when no team id is known (caller must hide the control)', () => {
		expect(teamLink(null)).toBeNull()
		expect(teamLink('')).toBeNull()
		expect(teamLink(undefined)).toBeNull()
	})
})

// Regression: the default-export object MUST expose every helper a component calls
// as `deepLinks.<fn>` (MinaArenden imports the default and calls deepLinks.deckLink).
// deckLink was added as a named export but originally omitted from the default
// object, so deepLinks.deckLink was undefined → the Bevakning button threw at runtime.
describe('default export surface', () => {
	it('exposes every helper used via the default import', () => {
		for (const fn of ['threadLink', 'composerLink', 'deckLink', 'mailboxLink', 'callLink', 'spreedRoomLink', 'arenderumLink', 'teamLink', 'fileLink', 'loa3UpgradeLink', 'resolve']) {
			expect(typeof deepLinks[fn]).toBe('function')
		}
	})

	it('deepLinks.deckLink resolves a board through the default import', () => {
		expect(deepLinks.deckLink(7)).toBe('/index.php/apps/deck/board/7')
	})
})

describe('loa3UpgradeLink', () => {
	it('uses the Hubs Start return url by default', () => {
		expect(loa3UpgradeLink()).toBe(
			'/index.php/apps/sdkmc/upgradeToLoa3?returnUrl=' +
				encodeURIComponent('/index.php/apps/hubs_start/'),
		)
	})

	it('encodes a custom return url', () => {
		expect(loa3UpgradeLink('/somewhere?x=1')).toBe(
			'/index.php/apps/sdkmc/upgradeToLoa3?returnUrl=' +
				encodeURIComponent('/somewhere?x=1'),
		)
	})
})

describe('resolve', () => {
	it('resolves a thread descriptor', () => {
		expect(resolve({ app: 'thread', params: { itslMailboxId: 5, mid: 9 } })).toBe(
			'/index.php/apps/sdkmc/mailbox-link/5?mid=9',
		)
	})

	it('resolves a composer descriptor', () => {
		expect(resolve({ app: 'composer', params: { messageType: 'sms_message', to: '+46700000000' } })).toBe(
			'/index.php/apps/mail/new?type=sms_message&to=' + encodeURIComponent('+46700000000'),
		)
	})

	it('resolves a mailbox descriptor', () => {
		expect(resolve({ app: 'mailbox', params: { mailboxId: 'Sent' } })).toBe(
			'/index.php/apps/mail/box/Sent',
		)
	})

	it('resolves a call descriptor', () => {
		expect(resolve({ app: 'call', params: { token: 'tok' } })).toBe('/index.php/call/tok')
	})

	it('resolves a room descriptor', () => {
		expect(resolve({ app: 'room', params: { token: 'tok' } })).toBe('/index.php/call/tok')
	})

	it('falls back to the Hubs Start home for a null descriptor', () => {
		expect(resolve(null)).toBe('/index.php/apps/hubs_start/')
	})

	it('falls back to the Hubs Start home for a descriptor without an app', () => {
		expect(resolve({ params: {} })).toBe('/index.php/apps/hubs_start/')
	})

	it('falls back to the Hubs Start home for an unknown app', () => {
		expect(resolve({ app: 'wat', params: {} })).toBe('/index.php/apps/hubs_start/')
	})
})
