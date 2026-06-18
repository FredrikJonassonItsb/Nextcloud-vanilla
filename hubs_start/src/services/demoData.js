/**
 * Hubs Start — DEMO DATA (stub).
 *
 * ⚠️ STUB / DEMO ONLY. This module exists so the app can be shown on a *vanilla*
 * Nextcloud where the real sibling apps (sdkmc, mail, spreed, calendar) and their
 * OCS endpoints are NOT installed. When demo mode is on (see isDemo()), api.js
 * returns these in-memory fixtures instead of making network calls, so the whole
 * UI renders with realistic Swedish public-sector content and ZERO backend.
 *
 * On a real Hubs install, demo mode is OFF and none of this is used — api.js hits
 * the real sdkmc OCS endpoints. See DEMO.md for exactly what is stubbed.
 *
 * The fixtures below deliberately mirror the kunddemo scenario (Orosanmälan
 * Dnr SN 2026-0142, SDK från IVO/Försäkringskassan, säker e-post till medborgare,
 * inkommande fax, säkra möten med BankID-verifiering).
 */

import { loadState } from '@nextcloud/initial-state'

/** True when the page was rendered in demo mode (PageController injects the flag
 *  inside the 'boot' initial state). */
export function isDemo() {
	try {
		const boot = loadState('hubs_start', 'boot', {})
		return boot && boot.demoMode === true
	} catch (e) {
		return false
	}
}

const ch = (channel, channelLabel, messageType) => ({ channel, channelLabel, messageType })

const SDK = ch('sdk', 'SDK-Meddelande', 'sdk_message')
const SECURE = ch('secure', 'Säker E-post', 'secure_email')
const INTERNAL = ch('internal', 'Internpost', 'internal_message')
const FAX = ch('fax', 'Fax', 'fax_message')

/** Stable, non-Date.now timestamps (demo is static). */
const T = {
	t0905: '2026-06-13T09:05:00+02:00',
	t0848: '2026-06-13T08:48:00+02:00',
	t0832: '2026-06-13T08:32:00+02:00',
	t0815: '2026-06-13T08:15:00+02:00',
	t0740: '2026-06-13T07:40:00+02:00',
	yesterday: '2026-06-12T16:20:00+02:00',
	t1010: '2026-06-13T10:10:00+02:00',
	t1330: '2026-06-13T13:30:00+02:00',
	t1500: '2026-06-13T15:00:00+02:00',
}

const items = [
	{
		id: 'demo:1',
		title: 'Besvara orosanmälan — Socialkontoret',
		channel: SDK,
		status: 'ny',
		section: 'kraver_atgard',
		mailbox: 'Orosanmälan (funktionsbrevlåda)',
		dnr: 'SN 2026-0142',
		loa: 'LOA3',
		since: T.t0905,
		deepLink: { app: 'thread', params: { itslMailboxId: 1, mid: 1001 } },
		messageId: '1001',
		doneTag: '$done',
	},
	{
		id: 'demo:2',
		title: 'Åtgärda leveransproblem för Säker E-post',
		channel: SECURE,
		status: 'problem',
		section: 'kraver_atgard',
		mailbox: 'Vuxenenheten',
		dnr: 'SN 2026-0131',
		loa: null,
		since: T.t0848,
		deepLink: { app: 'thread', params: { itslMailboxId: 2, mid: 1002 } },
		messageId: '1002',
	},
	{
		id: 'demo:3',
		title: 'Tilldela SDK-Meddelande — IVO',
		channel: SDK,
		status: 'ny',
		section: 'otilldelat',
		mailbox: 'Myndighetspost',
		dnr: 'IVO 2026-5571',
		loa: 'LOA3',
		since: T.t0832,
		deepLink: { app: 'thread', params: { itslMailboxId: 3, mid: 1003 } },
		assignment: { imapLabel: '$assignee_axel', accountId: 3, threadRootId: '1003' },
		messageId: '1003',
	},
	{
		id: 'demo:4',
		title: 'Tilldela Internpost — Bemanningsenheten',
		channel: INTERNAL,
		status: 'ny',
		section: 'otilldelat',
		mailbox: 'Enhetschef Hemtjänst',
		dnr: null,
		loa: null,
		since: T.t0815,
		deepLink: { app: 'thread', params: { itslMailboxId: 4, mid: 1004 } },
		assignment: { imapLabel: '$assignee_axel', accountId: 4, threadRootId: '1004' },
		messageId: '1004',
	},
	{
		id: 'demo:5',
		title: 'Tilldela inkommen fax — Hälso- och sjukvård',
		channel: FAX,
		status: 'ny',
		section: 'otilldelat',
		mailbox: 'Kommunsjuksköterska',
		dnr: null,
		loa: null,
		since: T.t0740,
		deepLink: { app: 'thread', params: { itslMailboxId: 5, mid: 1005 } },
		assignment: { imapLabel: '$assignee_axel', accountId: 5, threadRootId: '1005' },
		messageId: '1005',
	},
	{
		id: 'demo:6',
		title: 'Granska SDK-Meddelande — Försäkringskassan',
		channel: SDK,
		status: 'ny',
		section: 'nytt',
		mailbox: 'Myndighetspost',
		dnr: 'FK 7782-2026',
		loa: 'LOA3',
		since: T.t0905,
		deepLink: { app: 'thread', params: { itslMailboxId: 3, mid: 1006 } },
		messageId: '1006',
		doneTag: '$done',
	},
	{
		id: 'demo:7',
		title: 'Granska Säker E-post — medborgarsvar',
		channel: SECURE,
		status: 'ny',
		section: 'nytt',
		mailbox: 'Vuxenenheten',
		dnr: 'SN 2026-0142',
		loa: 'LOA2',
		since: T.t0848,
		deepLink: { app: 'thread', params: { itslMailboxId: 2, mid: 1007 } },
		messageId: '1007',
		doneTag: '$done',
	},
	{
		id: 'demo:8',
		title: 'Bevaka kvittens för SDK-Meddelande',
		channel: SDK,
		status: 'vantar_kvittens',
		section: 'bevakas',
		mailbox: 'Orosanmälan (funktionsbrevlåda)',
		dnr: 'SN 2026-0140',
		loa: 'LOA3',
		since: T.yesterday,
		deepLink: { app: 'thread', params: { itslMailboxId: 1, mid: 1008 } },
		messageId: '1008',
	},
	{
		id: 'demo:9',
		title: 'Hantera Säker E-post — påminnelse skickad',
		channel: SECURE,
		status: 'besvarad',
		section: 'klart_idag',
		mailbox: 'Vuxenenheten',
		dnr: 'SN 2026-0128',
		loa: null,
		since: T.t0740,
		deepLink: { app: 'thread', params: { itslMailboxId: 2, mid: 1009 } },
		messageId: '1009',
	},
]

const counts = {
	kravAtgard: items.filter((i) => i.section === 'kraver_atgard').length,
	otilldelat: items.filter((i) => i.section === 'otilldelat').length,
	nytt: items.filter((i) => i.section === 'nytt').length,
	bevakas: items.filter((i) => i.section === 'bevakas').length,
	klartIdag: items.filter((i) => i.section === 'klart_idag').length,
	problem: items.filter((i) => i.status === 'problem').length,
}

const summary = {
	loa: 'LOA3',
	counts,
	items,
	mailboxes: [
		{ id: 1, name: 'Orosanmälan (funktionsbrevlåda)', unread: 4, unassigned: 2 },
		{ id: 2, name: 'Vuxenenheten', unread: 3, unassigned: 1 },
		{ id: 3, name: 'Myndighetspost', unread: 6, unassigned: 1 },
		{ id: 5, name: 'Kommunsjuksköterska', unread: 1, unassigned: 1 },
	],
	watching: [
		{ mailbox: 'Barn & Familj', owner: 'Anna Lindqvist', untilDate: '2026-06-24T17:00:00+02:00', direction: 'incoming' },
	],
	channelCoverage: ['sdk', 'secure', 'internal', 'fax', 'sms'],
	maxSinceId: T.t0905,
}

// KvittensWidget renders receipt.channel as a string id via channelMeta().
const receipts = [
	{ messageId: '2001', recipient: 'SN 2026-0131', channel: 'secure', state: 'problem', updatedAt: T.t0848, deepLink: { app: 'thread', params: { itslMailboxId: 2, mid: 2001 } } },
	{ messageId: '2002', recipient: 'IVO 2026-5571', channel: 'sdk', state: 'besvarat', updatedAt: T.t0832, deepLink: { app: 'thread', params: { itslMailboxId: 3, mid: 2002 } } },
	{ messageId: '2003', recipient: 'SN 2026-0142', channel: 'secure', state: 'last', updatedAt: T.t0815, deepLink: { app: 'thread', params: { itslMailboxId: 2, mid: 2003 } } },
	{ messageId: '2004', recipient: 'FK 7782-2026', channel: 'sdk', state: 'levererat', updatedAt: T.t0740, deepLink: { app: 'thread', params: { itslMailboxId: 3, mid: 2004 } } },
	{ messageId: '2005', recipient: 'Hemtjänst Väster', channel: 'internal', state: 'skickat', updatedAt: T.yesterday, deepLink: { app: 'thread', params: { itslMailboxId: 4, mid: 2005 } } },
]

const meetings = [
	{
		token: 'demomotea',
		title: 'Säkert möte – Orosanmälan (vårdnadshavare)',
		start: T.t1010,
		end: '2026-06-13T10:40:00+02:00',
		participants: 1,
		bankIdRequired: true,
		verificationBadge: 'green',
		lobbyState: 1,
		hasCall: false,
	},
	{
		token: 'demomoteb',
		title: 'Säkert möte – SIP-möte hemtjänst',
		start: T.t1330,
		end: '2026-06-13T14:15:00+02:00',
		participants: 0,
		bankIdRequired: true,
		verificationBadge: 'purple',
		lobbyState: 0,
		hasCall: false,
	},
	{
		token: 'demomotec',
		title: 'Säkert möte – Budgetrådgivning',
		start: T.t1500,
		end: '2026-06-13T15:30:00+02:00',
		participants: 0,
		bankIdRequired: false,
		verificationBadge: null,
		lobbyState: 0,
		hasCall: false,
	},
]

/** Live lobby snapshot for the first meeting (one verified guest waiting). */
const lobby = {
	demomotea: { waiting: [{ actorId: 'guest-1', displayName: 'Verifierad deltagare', verified: true }], verifiedCount: 1 },
}

const appointmentConfigs = [
	{ id: 1, name: 'Nybesök socialrådgivning (30 min)', token: 'demoapptA', bookingUrl: 'https://demo.hubs.se/apps/calendar/appointment/demoapptA', totalBookings: 7 },
	{ id: 2, name: 'Budget- och skuldrådgivning (45 min)', token: 'demoapptB', bookingUrl: 'https://demo.hubs.se/apps/calendar/appointment/demoapptB', totalBookings: 3 },
]

const recipients = [
	{ id: 'org-ivo', displayName: 'IVO — Inspektionen för vård och omsorg', address: 'ivo.tillsyn@sdk', classification: SDK, ssn: null, sms: null },
	{ id: 'org-fk', displayName: 'Försäkringskassan — Sjukförsäkring', address: 'fk.sjuk@sdk', classification: SDK, ssn: null, sms: null },
	{ id: 'mb-vux', displayName: 'Vuxenenheten (funktionsbrevlåda)', address: 'vuxenenheten@gruppbox', classification: INTERNAL, ssn: null, sms: null },
	{ id: 'colleague-anna', displayName: 'Anna Lindqvist (Barn & Familj)', address: 'anna.lindqvist@personlig', classification: INTERNAL, ssn: null, sms: null },
	{ id: 'citizen-1', displayName: 'Medborgare — Erik Svensson', address: 'erik.svensson@example.se', classification: SECURE, ssn: '19850312-XXXX', sms: '070-123 45 67' },
	{ id: 'fax-vc', displayName: 'Vårdcentralen Centrum (fax)', address: '0521234567@fax', classification: FAX, ssn: null, sms: null },
]

export const demo = {
	getSettings: () => ({ loginSecurity: { loa: 'LOA3' }, loa3Tag: '$label1', demo: true }),
	getPreferences: () => ({ onboardingSeen: true, keyboardMode: false, profile: 'forvaltare' }),
	savePreferences: (p) => ({ onboardingSeen: true, keyboardMode: false, ...p }),
	fetchSummary: () => JSON.parse(JSON.stringify(summary)),
	fetchReceipts: () => JSON.parse(JSON.stringify(receipts)),
	searchRecipients: (q) => {
		const query = String(q || '').toLowerCase().trim()
		if (!query) {
			return JSON.parse(JSON.stringify(recipients))
		}
		return recipients.filter((r) => r.displayName.toLowerCase().includes(query)
			|| r.address.toLowerCase().includes(query))
	},
	classifyRecipient: (value) => {
		const v = String(value || '').toLowerCase()
		if (v.endsWith('@sdk')) return SDK
		if (v.endsWith('@gruppbox') || v.endsWith('@personlig')) return INTERNAL
		if (v.endsWith('@fax')) return FAX
		if (v.includes('@')) return SECURE
		return ch('unknown', 'Okänd kanal', '')
	},
	fetchTodaysMeetings: () => JSON.parse(JSON.stringify(meetings)),
	createSecureMeeting: (payload) => ({
		token: 'demonewmt',
		eventUid: 'hubs-demo-0001',
		start: payload?.start || T.t1500,
		end: payload?.end || '2026-06-13T15:30:00+02:00',
		smsStatus: payload?.sendSms ? 'queued' : 'none',
		protection: {
			bankId: payload?.requireBankId !== false,
			sms: !!payload?.sendSms,
			secureEmail: !!payload?.sendSecureEmailInvite,
		},
	}),
	fetchLobbyStatus: (token) => lobby[token] || { waiting: [], verifiedCount: 0 },
	fetchGuestIdentity: () => ({ firstName: 'Verifierad', lastName: 'Deltagare', ssnVerified: true, onlyBankId: false }),
	takeThread: () => ({ status: 'ok' }),
	setMessageTag: () => ({ status: 'ok' }),
	fetchAppointmentConfigs: () => JSON.parse(JSON.stringify(appointmentConfigs)),
}

export default { isDemo, demo }
