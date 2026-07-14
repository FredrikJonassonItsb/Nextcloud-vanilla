/**
 * Hubs Start — API client (THE frontend↔backend contract).
 *
 * Every network call the SPA makes goes through this module. Components must NOT
 * call axios directly — they import these functions so endpoint paths and return
 * shapes live in exactly one place. See docs/CONTRACTS.md for the canonical
 * description of every shape referenced below.
 *
 * Conventions:
 *  - OCS endpoints return `response.data.ocs.data` (unwrapped here).
 *  - Legacy index.php endpoints return `response.data` (unwrapped here).
 *  - Navigation helpers (deep links) return a URL string; the caller assigns
 *    window.location — they are full-page redirects into the target app, never XHR.
 *
 * @typedef {object} ChannelInfo
 * @property {('sdk'|'internal'|'secure'|'fax'|'sms'|'unknown')} channel   Resolved transport
 * @property {string} channelLabel   Localised, Hubs-Academy term (e.g. "SDK-Meddelande")
 * @property {string} messageType    Mail message-type id (sdk_message|internal_message|secure_email|fax_message|sms_message)
 *
 * @typedef {object} Recipient
 * @property {string} id             Stable id (org id / mailbox id / free address)
 * @property {string} displayName    Human label ("Socialkontoret, Bergsby kommun")
 * @property {string} address        The routable address/value
 * @property {ChannelInfo} classification  Server-resolved channel for this recipient
 * @property {?string} ssn           Personnummer when known (citizen / secure_email)
 * @property {?string} sms           SMS number when known
 *
 * @typedef {object} QueueItem
 * @property {string} id             Stable id (e.g. "msg:{databaseId}")
 * @property {string} title          Verb-first heading ("Besvara SDK-meddelande från IVO")
 * @property {ChannelInfo} channel
 * @property {('ny'|'tilldelad'|'vantar_kvittens'|'besvarad'|'problem'|'klar')} status
 * @property {('kraver_atgard'|'otilldelat'|'nytt'|'bevakas'|'klart_idag')} section
 * @property {?string} mailbox       Function mailbox display name
 * @property {?string} dnr           Case number tag if present
 * @property {('LOA1'|'LOA2'|'LOA3'|null)} loa
 * @property {string} since          ISO timestamp for ordering / sinceIds
 * @property {object} deepLink       { app, params } resolved to a URL via deepLinks helper
 *
 * @typedef {object} Summary
 * @property {('LOA1'|'LOA2'|'LOA3')} loa
 * @property {object} counts         { kravAtgard, otilldelat, nytt, bevakas, klartIdag, problem }
 * @property {QueueItem[]} items     Triage items across all channels
 * @property {object[]} mailboxes    Function mailboxes: { id, name, unread, unassigned }
 * @property {object[]} watching     Absence delegations: { mailbox, owner, untilDate, direction }
 * @property {string[]} channelCoverage  Channels actually monitored for this user, e.g. ['sdk','secure','fax']
 * @property {?string} maxSinceId    Highest item id seen, pass back as sinceIds for incremental polls
 */

import axios from '@nextcloud/axios'
import { generateUrl, generateOcsUrl } from '@nextcloud/router'
import { isDemo, demo } from './demoData.js'
import ssDemo from './demo/socialsekreterare.js'

// DEMO MODE (stub): on a vanilla Nextcloud without the sdkmc/mail/spreed/calendar
// apps, the real OCS endpoints below do not exist. When PageController injects
// demoMode=true, every function short-circuits to in-memory fixtures (demoData.js)
// so the whole UI renders with no backend. On a real Hubs install demo mode is
// OFF and the network paths below run unchanged. See DEMO.md.
const DEMO = isDemo()

const SDKMC = (path) => generateUrl('/apps/sdkmc' + path)
const SDKMC_OCS = (path) => generateOcsUrl('apps/sdkmc/api/v1' + path)
const HUBS_OCS = (path) => generateOcsUrl('apps/hubs_start/api/v1' + path)
// 🔌 LIVE: hubs_arende OCS — the standalone ärende-motor (NC app, namespace
// OCA\HubsArende). When demo is OFF, the ärende-related functions below route
// here instead of to sdkmc. Effective prefix: /ocs/v2.php/apps/hubs_arende/api/v1
// See routes.php in the hubs_arende app and docs/FRONTEND-WIRING.md.
const HUBS_ARENDE_OCS = (path) => generateOcsUrl('apps/hubs_arende/api/v1' + path)

/** Unwrap an OCS response body. */
const ocsData = (response) => response?.data?.ocs?.data

/**
 * A7/A9 — plocka fram motorns grind-krav ur ett kastat OCS-fel. transitionSteg()
 * (och commit-flödet) svarar 400 med { error, grindKravs:true } när ett obligatoriskt
 * grindval saknas OCH flaggan är på (ArendeLifecycleService kastar
 * \InvalidArgumentException → controller 400). Returnerar { grindKravs, error } så
 * callern kan avgöra OM en grind-dialog ska öppnas (grindKravs===true) och visa
 * motorns orsak. grindKravs=false för alla andra fel (transport/403/…), som då
 * hanteras som vanliga fel. PII-fritt: error bär enum-koder, aldrig fri text.
 * @param {*} e ett kastat axios-/OCS-fel
 * @return {{grindKravs:boolean, error:?string}}
 */
export function grindKravFel(e) {
	const data = e && e.response && e.response.data && e.response.data.ocs && e.response.data.ocs.data
	if (data && data.grindKravs) {
		return { grindKravs: true, error: data.error || null }
	}
	return { grindKravs: false, error: (data && data.error) || null }
}

// ---------------------------------------------------------------------------
// Boot / identity
// ---------------------------------------------------------------------------

/**
 * Read the current login-security state and channel policy from sdkmc.
 * @return {Promise<object>} getSettings payload (loginSecurity, loa3Tag, policies…)
 */
export async function getSettings() {
	if (DEMO) return demo.getSettings()
	const res = await axios.get(SDKMC('/api/v2/frontend/getSettings'))
	return res.data
}

/**
 * Read per-user Hubs Start UI preferences (onboarding seen, keyboard mode).
 * @return {Promise<{onboardingSeen: boolean, keyboardMode: boolean, profile: ?string}>}
 */
export async function getPreferences() {
	if (DEMO) return demo.getPreferences()
	const res = await axios.get(HUBS_OCS('/preferences'))
	return ocsData(res)
}

/**
 * Persist per-user UI preferences (partial update).
 * @param {object} prefs subset of { onboardingSeen, keyboardMode }
 * @return {Promise<object>} the stored preferences
 */
export async function savePreferences(prefs) {
	if (DEMO) return demo.savePreferences(prefs)
	const res = await axios.put(HUBS_OCS('/preferences'), prefs)
	return ocsData(res)
}

// ---------------------------------------------------------------------------
// The aggregated summary (single source of truth — owned by sdkmc)
// ---------------------------------------------------------------------------

/**
 * Fetch the aggregated triage summary. This is the ONE server-side aggregation
 * endpoint (gov-portal's client-side fan-out is explicitly avoided).
 * @param {?string} sinceIds incremental cursor from a previous Summary.maxSinceId
 * @return {Promise<Summary>}
 */
export async function fetchSummary(sinceIds = null) {
	if (DEMO) return demo.fetchSummary(sinceIds)
	const res = await axios.get(SDKMC_OCS('/summary'), {
		params: sinceIds ? { sinceIds } : {},
	})
	return ocsData(res)
}

/**
 * Fetch delivery receipts (Skickat→Levererat→Läst→Besvarat) for outgoing
 * messages. Replaces the legacy 10-minute PENDING→REJECTED frontend heuristic.
 * @param {object} opts { status?: 'problem'|'pending'|'all', limit?: number }
 * @return {Promise<object[]>} receipts: { messageId, recipient, channel, state, updatedAt, deepLink }
 */
export async function fetchReceipts(opts = {}) {
	if (DEMO) return demo.fetchReceipts(opts)
	const res = await axios.get(SDKMC_OCS('/receipts'), { params: opts })
	return ocsData(res)
}

// ---------------------------------------------------------------------------
// Smart mottagare — recipient search + server-side channel classification
// ---------------------------------------------------------------------------

/**
 * Search recipients across the SDK address book, internal mailboxes and free
 * citizen addresses. Each candidate is returned ALREADY classified by the
 * server (channel suffix logic is encapsulated in sdkmc, never duplicated here).
 * @param {string} query free text (name, org, ssn, email, phone)
 * @return {Promise<Recipient[]>}
 */
export async function searchRecipients(query) {
	if (DEMO) return demo.searchRecipients(query)
	const res = await axios.get(SDKMC_OCS('/recipients/search'), { params: { query } })
	return ocsData(res)
}

/**
 * Classify an explicit, manually-entered recipient value (citizen email, ssn,
 * fax number…) so the composer can be opened with the correct channel.
 * @param {string} value
 * @return {Promise<ChannelInfo>}
 */
export async function classifyRecipient(value) {
	if (DEMO) return demo.classifyRecipient(value)
	const res = await axios.get(SDKMC_OCS('/recipients/classify'), { params: { value } })
	return ocsData(res)
}

// ---------------------------------------------------------------------------
// Meetings (Talk rooms + secure-meeting wizard)
// ---------------------------------------------------------------------------

/**
 * Today's secure meetings, merged server-side from CalDAV + Talk room state.
 * @return {Promise<object[]>} meetings: { token, title, start, end, participants,
 *                              bankIdRequired, verificationBadge, lobbyState, hasCall }
 */
export async function fetchTodaysMeetings() {
	if (DEMO) return demo.fetchTodaysMeetings()
	const res = await axios.get(SDKMC_OCS('/meetings/today'))
	return ocsData(res)
}

/**
 * Create a secure meeting in ONE server-side operation: Talk room + CalDAV event
 * (Talk link guaranteed in LOCATION) + BankID/SMS/securemail intents bound to the
 * event UID. Replaces the brittle calendar-sms.js DOM injection.
 * @param {object} payload see CONTRACTS.md → SecureMeetingRequest
 * @return {Promise<object>} { token, eventUid, start, end, smsStatus, protection }
 */
export async function createSecureMeeting(payload) {
	if (DEMO) return demo.createSecureMeeting(payload)
	const res = await axios.post(SDKMC_OCS('/secure-meeting'), payload)
	return ocsData(res)
}

/**
 * Live lobby status for a meeting (verified guests waiting).
 * @param {string} token Talk room token
 * @return {Promise<{waiting: object[], verifiedCount: number}>}
 */
export async function fetchLobbyStatus(token) {
	if (DEMO) return demo.fetchLobbyStatus(token)
	const res = await axios.get(SDKMC_OCS('/meetings/' + encodeURIComponent(token) + '/lobby'))
	return ocsData(res)
}

/**
 * Resolve a verified guest identity (BankID name + ssn-verification) for the
 * moderator panel. Uses the existing sdkmc guest-identity endpoint.
 * @param {string} token
 * @param {string} actorId
 * @return {Promise<{firstName: string, lastName: string, ssnVerified: boolean, onlyBankId: boolean}>}
 */
export async function fetchGuestIdentity(token, actorId) {
	if (DEMO) return demo.fetchGuestIdentity(token, actorId)
	const res = await axios.get(SDKMC('/api/v2/spreed/guest-identity/' + encodeURIComponent(token) + '/' + encodeURIComponent(actorId)))
	return res.data
}

// ---------------------------------------------------------------------------
// Egna anteckningar (#12) + ärende-berikning vid expand (#3/#15/#16)
// ---------------------------------------------------------------------------

/**
 * Handläggarens privata anteckningar ("Egna anteckningar"). Per-user, append-only,
 * krypterat — backas av Spreed Note-to-Self via en sdkmc-läs-yta. Aldrig delat.
 * @return {Promise<{notes: Array<{id:string, text:string, createdAt:string}>}>} newest-first
 */
export async function fetchNotes() {
	if (DEMO) return demo.fetchNotes()
	const res = await axios.get(SDKMC_OCS('/note-to-self'))
	return ocsData(res) ?? { notes: [] }
}

/**
 * Lägg till en privat anteckning. Body-fältet heter `text` (ej `message`).
 * @param {string} text
 * @return {Promise<{note:{id:string, text:string, createdAt:string}}>}
 */
export async function addNote(text) {
	if (DEMO) return demo.addNote(text)
	const res = await axios.post(SDKMC_OCS('/note-to-self'), { text })
	return ocsData(res)
}

/**
 * Berika ett expanderat ärendekort med ärenderums-innehåll (diskussion-summary)
 * som sdkmc äger, givet ärenderummets talk-token (ur motorns full.pekare.talkToken).
 * Tunn motor-princip (#3 hybrid): motorn ger pekaren, sdkmc berikar vid expand.
 * Graceful: tom-shape när token saknas, sdkmc/spreed ej nåbart eller vid fel.
 * @param {?string} talkToken ärenderummets diskussions-token
 * @return {Promise<{diskussion:object, meddelanden:Array, moten:Array}>}
 */
export async function fetchArendeEnrichment(talkToken) {
	const tom = { diskussion: { olasta: 0, omnamnandeTillMig: false, deltagare: [], meddelanden: [] }, meddelanden: [], moten: [] }
	if (!talkToken) return tom
	if (DEMO) return tom
	const res = await axios.get(SDKMC_OCS('/arende-enrichment'), { params: { talkToken } })
	return ocsData(res) ?? tom
}

// ---------------------------------------------------------------------------
// Triage actions (own the case, mark done) — delegate to sdkmc tag API
// ---------------------------------------------------------------------------

/**
 * Take ownership of a thread (sets the current user's assignment tag).
 * @param {string} imapLabel the assignment tag's imap label
 * @param {object} body { accountId, threadRootIds: string[] }
 * @return {Promise<object>}
 */
export async function takeThread(imapLabel, body) {
	if (DEMO) return demo.takeThread(imapLabel, body)
	const res = await axios.put(SDKMC('/api/thread/tags/' + encodeURIComponent(imapLabel)), body)
	return res.data
}

/**
 * Set or clear an arbitrary message tag (used for follow-up / done flags).
 * @param {string|number} messageId
 * @param {string} imapLabel
 * @param {boolean} value true → set (PUT), false → remove (DELETE)
 * @return {Promise<object>}
 */
export async function setMessageTag(messageId, imapLabel, value) {
	if (DEMO) return demo.setMessageTag(messageId, imapLabel, value)
	const url = SDKMC('/api/messages/' + encodeURIComponent(String(messageId)) + '/tags/' + encodeURIComponent(imapLabel))
	const res = value ? await axios.put(url) : await axios.delete(url)
	return res.data
}

/**
 * Fas F (Väg A) — durabel, IDOR-säker koppling av ett meddelande till ett ärende.
 *
 * Körs i ANVÄNDARENS session: sdkmc tag-routen scopar per-meddelande mot
 * slutanvändaren (ingen service-konto-IDOR). Sätter SYNLIGA taggar i mail-klienten
 * (case:{id} → "Ärende {ref}", + "Behandlad") och skriver — via hubs_arende — en
 * referens-fil + pekare i ärendemappen (NEVER-SoR: pekare, ej kopia). "Kopplat" ska
 * visas i UI:t först när kvittot är verifierat.
 *
 * @param {string|number} messageId  sdkmc/mail meddelande-DB-id
 * @param {string} hubsCaseId        målärendets UUID
 * @return {Promise<object>} hubs_arende-kvitto { ok, verifierad, taggade, referenser }
 */
export async function kopplaMeddelandeTillArende(messageId, hubsCaseId) {
	if (DEMO) return { ok: true, verifierad: false, referenser: 1, demo: true }
	// The inflöde-feed row id is 'inf:<dbId>' (sdkmc InflodeFeedService); the engine
	// couples by the numeric mail-message db-id (InfodeController::inflodeMessageIds →
	// intval). 'inf:6' intval'd to 0 and was silently dropped → no reference written.
	// Normalise the prefix away so the real db-id reaches the engine.
	const ids = [messageId]
		.map((id) => String(id).replace(/^inf:/, ''))
		.filter((id) => id !== '')
	// 1) DURABEL koppling FÖRST: referens-fil i ärendemappen + pekar-bokföring
	//    (hubs_arende). Detta är den primära "i akten"-artefakten (NEVER-SoR) och får
	//    INTE blockeras av att mail-taggen misslyckas.
	const res = await axios.post(HUBS_ARENDE_OCS('/inflode/koppla'), { hubsCaseId, rad: { messageIds: ids } })
	const kvitto = ocsData(res) ?? res.data
	// 2) Synliga, user-scopade taggar i mail-klienten (per-meddelande-authz i sdkmc).
	//    BEST-EFFORT: ett taggfel (t.ex. ett syntetiskt demo-meddelande utan riktig
	//    IMAP-rad, eller ett meddelande användaren inte äger) fäller INTE kopplingen —
	//    referensen i akten är redan skriven.
	try {
		await axios.put(SDKMC('/api/thread/tags/' + encodeURIComponent('case:' + hubsCaseId)), { ids })
		await axios.put(SDKMC('/api/thread/tags/behandlad'), { ids })
		kvitto.taggSatt = true
	} catch (e) {
		kvitto.taggSatt = false
	}
	return kvitto
}

// ---------------------------------------------------------------------------
// Appointment configs (bookable slots)
// ---------------------------------------------------------------------------

/**
 * Bookable appointment configurations (calendar app), surfaced from its hidden
 * left panel onto the dashboard.
 * @return {Promise<object[]>} configs: { id, name, token, bookingUrl, totalBookings }
 */
export async function fetchAppointmentConfigs() {
	if (DEMO) return demo.fetchAppointmentConfigs()
	const res = await axios.get(generateUrl('/apps/calendar/v1/appointment_configs'))
	return res.data?.data ?? res.data
}

// ---------------------------------------------------------------------------
// Socialsekreterare "Mina ärenden" redesign (ärende-centric)
// ---------------------------------------------------------------------------

/**
 * Aggregated ärende summary for the socialsekreterare view: Dagspulsen +
 * triage-inflöde + collapsed ärendekort. ONE server-side aggregation (no fan-out).
 * @return {Promise<{puls:object, triage:object[], arenden:object[], moten:object[], klartIdag:number, steg:string[]}>}
 */
export async function fetchArendeSummary() {
	if (DEMO) return ssDemo.fetchArendeSummary()
	// 🔌 LIVE: hubs_arende OCS — GET /arende-summary?mine=1 (Arende#summary).
	// Dashboard aggregate (counts + frist-färger, aldrig innehåll).
	// mine=1 ⇒ MEDLEMSBASERAT: "Mina ärenden" = ärenden där JAG finns i
	// medlemsledgern (krets/handläggare/co/observatör) — inte hela enheten.
	// Gruppledarens fördelningsvy läser hela enheten via /fordelning-summary.
	const res = await axios.get(HUBS_ARENDE_OCS('/arende-summary'), { params: { mine: '1' } })
	return ocsData(res)
}

/**
 * Full ärende (with flik content) — lazy-loaded when a card expands.
 * @param {string} dnr
 * @return {Promise<object>}
 */
export async function fetchArende(dnr) {
	if (DEMO) return ssDemo.fetchArende(dnr)
	// 🔌 LIVE: hubs_arende OCS — GET /arende/{ref} (Arende#show).
	// {ref} resolves by hubsCaseId OR dnr (O(1) register lookup); the JS param
	// stays named `dnr` so the component contract is unchanged.
	const res = await axios.get(HUBS_ARENDE_OCS('/arende/' + encodeURIComponent(dnr)))
	return ocsData(res)
}

/**
 * Commit a handling to Treserva via the Frends iPaaS connector (verified
 * callback). Drives CommitGrind; on success the provenance flips and the Hubs
 * Retention countdown starts (never on a checkbox).
 * @param {object} payload { arende, typ, artefakter }
 * @return {Promise<{ok:boolean, dnr:string, committedAt:string, gallrasDatum:string}>}
 */
export async function commitToTreserva(payload) {
	if (DEMO) return ssDemo.commitToTreserva(payload)
	// 🔌 LIVE: hubs_arende OCS — POST /treserva/commit (Arende#commit).
	// The controller signature is commit(string $hubsCaseId, array $payload),
	// so the case identity is hoisted out of the body and the remaining
	// commit-payload ({arende, typ, artefakter}) is nested under `payload`.
	// On success the verified receipt drives the provenance-flip + retention.
	const hubsCaseId = payload?.hubsCaseId ?? payload?.arende?.hubsCaseId ?? payload?.arende?.dnr ?? payload?.dnr
	const res = await axios.post(HUBS_ARENDE_OCS('/treserva/commit'), { hubsCaseId, payload })
	return ocsData(res)
}

/**
 * Kontakter-favoriter (resolverat aggregat: personlig ∪ funktions-delad). Driver
 * FavoritValjare i mottagar-/komponeringsytan. DEMO: resolver-stub (favoriter.js).
 * PROD: sdkmc GET /favoriter (IManager::search + DIGG-resolve). Se DEMO-STUBS.md.
 * @param {object} opts { lista?: string }
 * @return {Promise<object[]>}
 */
export async function fetchFavoriter(opts = {}) {
	if (DEMO) return ssDemo.fetchFavoriter(opts)
	const res = await axios.get(SDKMC_OCS('/favoriter'), { params: opts })
	return ocsData(res)
}

/**
 * Verifierade Treserva-kvittenser (kvittens-/retention-ytan).
 * DEMO: Treserva-stubben (treserva.js). PROD: sdkmc GET /treserva/receipts.
 * @return {Promise<object[]>}
 */
export async function fetchTreservaReceipts() {
	if (DEMO) return ssDemo.fetchReceipts()
	// 🔌 LIVE: hubs_arende OCS — commit-kvittenser ur registret (provenance=registrerad). Ärende-domän.
	const res = await axios.get(HUBS_ARENDE_OCS('/treserva/receipts'))
	return ocsData(res)
}

/**
 * 🔌 SEAM[treserva.skapa]: atomär "skapa ärende" ur ett inflöde (triage).
 * DEMO: Treserva-stubben mintar hubsCaseId+dnr och returnerar ett nytt ärende.
 * PROD: sdkmc POST /arende (register + ärenderum + ACL + Deck + chattrum + klocka).
 * @param {object} rad InflodeRad
 * @return {Promise<object>} nytt ärende
 */
/**
 * Boundary adapter: map the sdkmc inflöde-row's deterministic channel
 * `messageType` onto the ärende-motorns `arendeTyp` registry id (which keys
 * every saga step). sdkmc emits the message/channel type (orosanmalan,
 * komplettering, bistandsansokan, samverkan, …); the engine speaks its own
 * arendeTyp vocabulary. Returns '' when no case-creating type applies (e.g. an
 * internpost fördelnings-rad) so the caller surfaces an honest error instead of
 * the engine minting a mis-typed case. See ArendeTypRegistry in hubs_arende.
 */
const MESSAGE_TYPE_TO_ARENDE_TYP = {
	orosanmalan: 'orosanmalan',
	komplettering: 'komplettering',
	bistandsansokan: 'ansokan_bistand',
	samverkan: 'vard_samverkan',
	// Registry ids accepted as-is, so a feed that already carries an arendeTyp
	// (rather than a raw messageType) keeps working.
	ansokan_bistand: 'ansokan_bistand',
	ekonomi: 'ekonomi',
	familjeratt: 'familjeratt',
	rattsligt_tvang: 'rattsligt_tvang',
	vard_samverkan: 'vard_samverkan',
	verkstallighet: 'verkstallighet',
}

/**
 * Korg (function-mailbox) → arendeTyp keyword routing for the REAL sdkmc feed,
 * where the row carries the CHANNEL messageType (sdk_message/internal_message/…)
 * and NOT the content type. The content type lives in the korg the message
 * landed in: the orosanmälan group mailbox (korg "Orosanmälan" /
 * orosanmalan@gruppbox) routes to the 'orosanmalan' ärendetyp, etc. First match
 * wins, so put narrower keywords before broader ones.
 */
const KORG_KEYWORD_TO_ARENDE_TYP = [
	[/orosanm/i, 'orosanmalan'],
	[/komplett/i, 'komplettering'],
	[/familjer/i, 'familjeratt'],
	[/(samverkan|\bsip\b)/i, 'vard_samverkan'],
	[/verkst[äa]ll/i, 'verkstallighet'],
	[/(tv[åa]ng|r[äa]ttslig|\blvu\b|\blvm\b)/i, 'rattsligt_tvang'],
	[/(ekonom|f[öo]rs[öo]rj)/i, 'ekonomi'],
	[/(bist[åa]nd|ans[öo]kan)/i, 'ansokan_bistand'],
]

/** Resolve the engine arendeTyp for an inflöde-rad. Empty string = unmappable. */
export function arendeTypForRad(rad) {
	const explicit = rad && (rad.arendeTyp || rad.arendetyp)
	if (explicit) return String(explicit)
	// Demo data carries the content type directly in messageType.
	const mt = rad && (rad.messageType || (rad.channel && rad.channel.messageType))
	if (mt && MESSAGE_TYPE_TO_ARENDE_TYP[mt]) return MESSAGE_TYPE_TO_ARENDE_TYP[mt]
	// Real feed: derive from the korg (the function-mailbox the message landed in).
	const korg = rad && rad.korg
	const hay = [korg && korg.label, korg && korg.addr].filter(Boolean).join(' ')
	for (const [re, typ] of KORG_KEYWORD_TO_ARENDE_TYP) {
		if (re.test(hay)) {
			return typ
		}
	}
	return ''
}

/**
 * Tagga källmeddelandet/-na för ett ärende i ANVÄNDARENS session (case:{id} +
 * behandlad), via sdkmc:s per-meddelande-tag-rutt. IDOR-säkert (sdkmc scopar per
 * inloggad användare; ingen service-konto-tagg → ingen IDOR). Best-effort: ett
 * taggfel får ALDRIG fälla det redan skapade ärendet. setMessageTag auto-skapar
 * taggen om den saknas. 'behandlad' gör att raden faller ur "Att ta emot"-feeden.
 * @param {Array<string|number>} ids meddelande-DB-id (utan 'inf:'-prefix)
 * @param {string} hubsCaseId
 * @return {Promise<boolean>} true om alla taggar sattes
 */
async function taggaCaseMeddelande(ids, hubsCaseId) {
	if (DEMO) return true
	// Per-tagg-felhantering: en try runt hela loopen avbröt på FÖRSTA felet —
	// felade case:-taggen (t.ex. 404 på en meddelandekopia användaren inte äger,
	// B1a) sattes aldrig 'behandlad' ens när den skulle lyckas, och raden föll
	// aldrig ur "Att ta emot". Försök därför ALLA taggar; kontraktet (false om
	// någon misslyckades) är oförändrat.
	let allaSatta = true
	for (const id of ids) {
		for (const tagg of ['case:' + hubsCaseId, 'behandlad']) {
			try {
				await setMessageTag(id, tagg, true)
			} catch (e) {
				allaSatta = false
			}
		}
	}
	return allaSatta
}

export async function skapaArende(rad) {
	if (DEMO) return ssDemo.skapaArende(rad)
	// 🔌 LIVE: hubs_arende OCS — POST /arende (Arende#createCase).
	// Runs the SAGA (R0 säkerhetsskydd-grind → R1 UUID → R2 register-INSERT →
	// R8 frist → R10). Idempotent on conversationId.
	//
	// The register stores ONLY PII-free coordination state — the engine rejects
	// personnummer by design — so we translate the inflöde-rad into a minimal,
	// pseudonymous payload here and NEVER forward the message PII (titel,
	// avsändare, personnummer stay in the inflöde/SoR). objektRef/triageRef are
	// only sent when the rad already carries a pseudonym, never derived from titel.
	const payload = { arendeTyp: arendeTypForRad(rad) }
	// Durabelt ankare: conversationId om satt, annars messageId/id med 'inf:'-prefixet
	// strippat (motorn är idempotent på conversationId; 'inf:6' skulle annars driva
	// en mis-typad/dubblerad case).
	const cid = (rad && rad.conversationId) || String((rad && (rad.messageId || rad.id)) || '').replace(/^inf:/, '')
	if (cid) payload.conversationId = String(cid)
	// Forwarda inkomDatum: motorn ankrar R8-fristen på inkom (ej now()).
	if (rad && rad.inkomDatum) payload.inkomDatum = rad.inkomDatum
	// messageIds: strippa 'inf:'-prefixet så R9 case:-taggning kan ske.
	const mid = String((rad && rad.id) || '').replace(/^inf:/, '')
	payload.messageIds = [mid].filter(Boolean)
	if (rad && rad.objektRef) payload.objektRef = String(rad.objektRef)
	if (rad && rad.triageRef) payload.triageRef = String(rad.triageRef)
	if (rad && rad.enhet) payload.enhet = String(rad.enhet)
	try {
		const res = await axios.post(HUBS_ARENDE_OCS('/arende'), { rad: payload })
		const nytt = ocsData(res)
		// Tagga källmeddelandet i användarens session så det (a) får synlig case:/
		// behandlad-tagg i meddelandegränssnittet och (b) faller ur "Att ta emot".
		// Motorn taggar INTE själv (service-konto = IDOR, avstängt). Best-effort —
		// fäller aldrig det redan skapade ärendet; resultatet bärs i nytt.taggSatt.
		if (nytt && nytt.hubsCaseId) {
			const ids = (payload.messageIds || []).filter(Boolean)
			if (ids.length) {
				nytt.taggSatt = await taggaCaseMeddelande(ids, nytt.hubsCaseId)
			}
		}
		return nytt
	} catch (e) {
		// Surface the engine's OCS error verbatim (400 { error } / 403
		// { avvisad, reason }) so the caller shows an honest failure instead of a
		// false success. Only a genuine transport error rethrows.
		const data = e && e.response && e.response.data && e.response.data.ocs && e.response.data.ocs.data
		if (data) return data
		throw e
	}
}

/**
 * Avancera ett ärende ett steg i livscykel-grafen (inflode→forhandsbedomning→
 * utredning→beslut→uppfoljning→avslutat). Kör grindarna server-side (A7/A9):
 * hela KONTEXT-objektet skickas med så motorn kan konsumera override/inteInledaVal/
 * kommuniceringVal/avslutsmotiv (se ArendeLifecycleService::transitionera). Ett
 * saknat obligatoriskt grindval ger 400 { error, grindKravs } så callern kan visa
 * rätt grind-dialog och skicka om.
 *
 * @param {string} ref hubsCaseId, dnr eller triageRef
 * @param {string} nyttSteg målsteget enligt ArendeLifecycleService-grafen
 * @param {(object|boolean)} [kontext] grind-kontexten (alla nycklar valfria):
 *        { skyddsbedomningKvitterad?, override?:{skal}, inteInledaVal?:{orsak,beslutsfattare},
 *          kommuniceringVal?:{gjord,skal?}, avslutsmotiv?:{utfall,kvarstaende?} }.
 *        BAKÅTKOMPAT: en boolean tolkas som legacy-{skyddsbedomningKvitterad}
 *        (den enda tredje-arg som fanns innan grindarna wirades).
 * @return {Promise<{ok:boolean, steg:string}>}
 */
export async function transitionSteg(ref, nyttSteg, kontext = {}) {
	// Normalisera legacy-boolean → kontext-objekt (bakåtkompatibilitet).
	const ctx = (typeof kontext === 'boolean')
		? { skyddsbedomningKvitterad: kontext }
		: (kontext || {})
	if (DEMO) return { ok: true, steg: nyttSteg }
	// Hela kontexten läggs bredvid nyttSteg i bodyn (controllern plockar ut de
	// enskilda nycklarna och trådar dem in i lifecycleService->transitionera).
	const res = await axios.post(HUBS_ARENDE_OCS('/arende/' + encodeURIComponent(ref) + '/steg'), { nyttSteg, ...ctx })
	return ocsData(res)
}

/**
 * Tilldela ett ärende till en handläggare (sätter agareUid + status=tilldelat
 * och skriver om ACL). I prod orkestreras detta atomärt av hubs_arende.
 * @param {string} ref hubsCaseId eller dnr
 * @param {string} uid handläggarens uid
 * @return {Promise<{ok:boolean, ref:string, uid:string}>}
 */
export async function tilldela(ref, uid) {
	// 🔌 LIVE: hubs_arende OCS — POST /arende/{ref}/tilldela (Arende#tilldela).
	// Body { uid } matches the controller signature tilldela(string $ref, string $uid).
	// DEMO har ingen tilldela-stub → optimistisk no-op (samma mönster som inflodeAction).
	if (DEMO) return { ok: true, ref, uid }
	const res = await axios.post(HUBS_ARENDE_OCS('/arende/' + encodeURIComponent(ref) + '/tilldela'), { uid })
	return ocsData(res)
}

/**
 * Skapa YTTERLIGARE en chatt i ärenderummet (1:n — t.ex. ett separat
 * samverkansrum). Motorn skapar Talk-rummet med ärendets AKTIVA åtkomstlista
 * som deltagare, registrerar en talk_room-pekare (gallras med ärendet) och
 * lägger ärendets TEAM som deltagare så chatten listas på teamsidan.
 * @param {string} ref hubsCaseId, dnr eller triageRef
 * @param {?string} namn valfritt rumsnamn (pseudonymt hubsCaseId om utelämnat)
 * @return {Promise<{ok:boolean, talkToken:?string}>}
 */
export async function skapaArendeChatt(ref, namn = null) {
	// 🔌 LIVE: hubs_arende OCS — POST /arende/{ref}/talkrum (Arende#laggTillTalkrum).
	if (DEMO) return { ok: true, talkToken: null }
	const res = await axios.post(HUBS_ARENDE_OCS('/arende/' + encodeURIComponent(ref) + '/talkrum'), { namn })
	return ocsData(res)
}

/**
 * Sök användare via plattformens STANDARD-autocomplete (samma källa som
 * delnings-/omnämnande-väljarna) — handläggaren ska aldrig behöva skriva
 * ett exakt användar-id (Fredrik 2026-07-07).
 * @param {string} search fritext (namn eller id)
 * @return {Promise<Array<{uid:string, namn:string}>>}
 */
export async function sokAnvandare(search) {
	if (DEMO) return []
	const res = await axios.get(generateOcsUrl('core/autocomplete/get'), {
		params: { search, itemType: '', itemId: '', shareTypes: [0], limit: 20 },
	})
	return (ocsData(res) || []).map((p) => ({ uid: p.id, namn: p.label || p.id }))
}

// ---------------------------------------------------------------------------
// PARTSREGISTRET — ärendets parter (motorns enda PII-tabell; Parter-fliken).
// PII i svaren är AVSEDD för behörig handläggare (authz i motorn via
// ArendeService::show; invarianten är behörighetsgränsen, inte döljning).
// Uppslag går via FolkbokforingPort (stub på dev15; Navet via Frends i prod).
// ---------------------------------------------------------------------------

/**
 * Ärendets parter (barn, vårdnadshavare, anmälare …) ur partsregistret.
 * @param {string} ref hubsCaseId, dnr eller triageRef
 * @return {Promise<Array<object>>} parter (kan vara tom)
 */
export async function fetchArendeParter(ref) {
	// 🔌 LIVE: hubs_arende OCS — GET /arende/{ref}/parter (Part#parter).
	if (DEMO) return []
	const res = await axios.get(HUBS_ARENDE_OCS('/arende/' + encodeURIComponent(ref) + '/parter'))
	return (ocsData(res) || {}).parter || []
}

/**
 * Ärenderummets anslutna (medlemsledger: handläggare/co/observatör/krets) med
 * roll + visningsnamn. Egen billig ledger-läsning (ArendeController::medlemmar)
 * så "Anslutna"-panelen kan läsas om vid varje expand utan den tunga full-cachen.
 * @param {string} ref hubsCaseId, dnr eller triageRef
 * @return {Promise<Array<{uid:string, roll:string, displayName?:string}>>}
 */
export async function fetchArendeMedlemmar(ref) {
	// 🔌 LIVE: hubs_arende OCS — GET /arende/{ref}/medlemmar (ArendeController#medlemmar).
	if (DEMO) return []
	const res = await axios.get(HUBS_ARENDE_OCS('/arende/' + encodeURIComponent(ref) + '/medlemmar'))
	return (ocsData(res) || {}).medlemmar || []
}

/**
 * Slå upp en person i folkbokföringen (Navet) och skriv in i partsregistret.
 * Ändamålet är obligatoriskt (journalförs; SoLPuL-nödvändighet, K-NAV-4.2).
 * @param {string} ref ärendereferens
 * @param {{personnummer:string, roll:string, andamal:string, inkluderaVardnadshavare?:boolean}} payload
 * @return {Promise<{ok:boolean, part:?object, vardnadshavare:Array, relationer:Array}>}
 */
export async function uppslagPart(ref, payload) {
	// 🔌 LIVE: hubs_arende OCS — POST /arende/{ref}/part/uppslag (Part#uppslag).
	if (DEMO) return { ok: true, part: null, vardnadshavare: [], relationer: [] }
	const res = await axios.post(HUBS_ARENDE_OCS('/arende/' + encodeURIComponent(ref) + '/part/uppslag'), payload)
	return ocsData(res)
}

/**
 * Lägg till en part manuellt (utan folkbokföringsuppslag). Skydd är
 * OBLIGATORISKT — fail-closed, motorn avvisar tomt/okänt värde.
 * @param {string} ref ärendereferens
 * @param {{roll:string, skydd:string, namn?:string, personnummer?:string, adress?:string, kontakt?:string}} data
 * @return {Promise<{ok:boolean, part:object}>}
 */
export async function skapaPartManuell(ref, data) {
	// 🔌 LIVE: hubs_arende OCS — POST /arende/{ref}/part (Part#laggTill).
	if (DEMO) return { ok: true, part: { id: 0, ...data, kalla: 'manuell' } }
	const res = await axios.post(HUBS_ARENDE_OCS('/arende/' + encodeURIComponent(ref) + '/part'), data)
	return ocsData(res)
}

/**
 * Uppdatera en befintlig part mot folkbokföringen (re-uppslag på lagrat pnr) —
 * rättelse-garantin K-NAV-4.4. Ändamålet är obligatoriskt.
 * @param {string} ref ärendereferens
 * @param {number} id partens id
 * @param {string} andamal ändamål med uppslaget (journalförs)
 * @return {Promise<{ok:boolean, part:object}>}
 */
export async function uppdateraPartFranNavet(ref, id, andamal) {
	// 🔌 LIVE: hubs_arende OCS — POST /arende/{ref}/part/{id}/uppdatera (Part#uppdatera).
	if (DEMO) return { ok: true, part: null }
	const res = await axios.post(HUBS_ARENDE_OCS('/arende/' + encodeURIComponent(ref) + '/part/' + encodeURIComponent(String(id)) + '/uppdatera'), { andamal })
	return ocsData(res)
}

/**
 * Ta bort en part ur partsregistret (journalförs).
 * @param {string} ref ärendereferens
 * @param {number} id partens id
 * @return {Promise<{ok:boolean}>}
 */
export async function taBortPart(ref, id) {
	// 🔌 LIVE: hubs_arende OCS — DELETE /arende/{ref}/part/{id} (Part#taBort).
	if (DEMO) return { ok: true }
	const res = await axios.delete(HUBS_ARENDE_OCS('/arende/' + encodeURIComponent(ref) + '/part/' + encodeURIComponent(String(id))))
	return ocsData(res)
}

/**
 * ★ LEGAL-FRIST ★ Registrera per-part-delgivning (FL 33 §) — flyttar
 * överklagandefristen till partsnivå. Laga kraft = senaste partens frist.
 * @param {string} ref ärendereferens
 * @param {number} id partens id
 * @param {string} datum delgivningsdatum (YYYY-MM-DD)
 * @param {string} metod ordinar|forenklad|muntlig|kungorelse|stamning
 * @return {Promise<{ok:boolean, part:object}>}
 */
export async function setPartDelgivning(ref, id, datum, metod = 'ordinar') {
	// 🔌 LIVE: hubs_arende OCS — POST /arende/{ref}/part/{id}/delgivning (Part#setDelgivning).
	if (DEMO) return { ok: true, part: null }
	const res = await axios.post(HUBS_ARENDE_OCS('/arende/' + encodeURIComponent(ref) + '/part/' + encodeURIComponent(String(id)) + '/delgivning'), { datum, metod })
	return ocsData(res)
}

/**
 * ★ LEGAL-FRIST ★ Undanta en part från delgivning (OSL 10:3 / skyddad adress /
 * våld) — parten ska medvetet inte nås och håller inte upp laga kraft.
 * @param {string} ref ärendereferens
 * @param {number} id partens id
 * @param {string} grund osl_10_3|skyddad_adress|vald|annan
 * @return {Promise<{ok:boolean, part:object}>}
 */
export async function undantaPartDelgivning(ref, id, grund) {
	// 🔌 LIVE: hubs_arende OCS — POST /arende/{ref}/part/{id}/delgivning/undanta (Part#undantaDelgivning).
	if (DEMO) return { ok: true, part: null }
	const res = await axios.post(HUBS_ARENDE_OCS('/arende/' + encodeURIComponent(ref) + '/part/' + encodeURIComponent(String(id)) + '/delgivning/undanta'), { grund })
	return ocsData(res)
}

// ---------------------------------------------------------------------------
// HANDLING-FRÅN-MALL (fas 1) — skapa en ifylld handling ur mallbiblioteket.
// Motorn läser mallen ur den delade mallmappen, fyller ärendedata (register +
// partsregister, skyddsgrindat) och lägger dokumentet i ärenderummets akt.
// ---------------------------------------------------------------------------

/**
 * Mallbibliotekets dokumentmallar (för mall-väljaren).
 * @param {string} ref ärendereferens (authz)
 * @return {Promise<{ok:boolean, tillganglig:boolean, mallar:Array<{id:string, namn:string, mapp:string}>}>}
 */
export async function fetchArendeMallar(ref) {
	// 🔌 LIVE: hubs_arende OCS — GET /arende/{ref}/mallar (Handling#mallar).
	if (DEMO) return { ok: true, tillganglig: false, mallar: [] }
	const res = await axios.get(HUBS_ARENDE_OCS('/arende/' + encodeURIComponent(ref) + '/mallar'))
	return ocsData(res)
}

/**
 * Förifyllnadsförslag för handlingen: fält ur registret + partsregistret,
 * skyddsgrindade (K-NAV-6.1 — varnade fält är tomma med förklaring).
 * @param {string} ref ärendereferens
 * @return {Promise<{ok:boolean, falt:Array, skyddsniva:string, varningar:Array}>}
 */
export async function fetchHandlingUtkast(ref, mallId = null) {
	// 🔌 LIVE: hubs_arende OCS — GET /arende/{ref}/handling-utkast (Handling#utkast).
	// S4: med mallId filtreras utkastet till mallens FAKTISKA fält
	// (malldefinitionen — dialogen visar aldrig fält mallen saknar).
	if (DEMO) return { ok: true, falt: [], skyddsniva: 'ingen', varningar: [] }
	const params = mallId ? { mallId } : {}
	// Med mallId kan motorn dessutom generera ett källförankrat AI-narrativ via
	// orkestreraren (inline-läge) — det tar tid (lokal LLM), så tåla längre latens.
	// Utan mallId (första hämtningen) är det bara register-uppslag → normal snabbhet.
	const res = await axios.get(
		HUBS_ARENDE_OCS('/arende/' + encodeURIComponent(ref) + '/handling-utkast'),
		{ params, timeout: mallId ? 45000 : undefined },
	)
	return ocsData(res)
}

/**
 * Generera handlingen: mall + fält → ifylld .docx i ärenderummets akt
 * (pekare + journal; gallras med ärendet enligt policy).
 * @param {string} ref ärendereferens
 * @param {{mallId:string, falt:Object<string,string>}} payload
 * @return {Promise<{ok:boolean, filnamn:string, antalErsatta:number}>}
 */
export async function skapaHandling(ref, payload) {
	// 🔌 LIVE: hubs_arende OCS — POST /arende/{ref}/handling (Handling#skapa).
	if (DEMO) return { ok: true, filnamn: 'demo-handling.docx', antalErsatta: 0 }
	const res = await axios.post(HUBS_ARENDE_OCS('/arende/' + encodeURIComponent(ref) + '/handling'), payload)
	return ocsData(res)
}

/**
 * Ärendets MEDDELANDEN (kortets Meddelanden-flik): alla meddelanden kopplade
 * via case:-taggen, alla kanaler/riktningar, ACL-buret till mina korgar.
 * @param {string} hubsCaseId
 * @return {Promise<Array<{id:number, amne:string, inkom:?string, olast:boolean, kanal:?object, deepLink:object}>>}
 */
export async function fetchCaseMessages(hubsCaseId) {
	// 🔌 LIVE: sdkmc OCS — GET /case-messages?ref= (OCS\CaseMessages#index).
	// DEMO: fliken faller tillbaka på fixture-kortets inline-meddelanden.
	if (DEMO) return []
	const res = await axios.get(SDKMC_OCS('/case-messages'), { params: { ref: hubsCaseId } })
	return ocsData(res).meddelanden || []
}

/**
 * Ärendets bokade MÖTEN (kortets Möten-flik): kommande + genomförda, matchade
 * på bokningens dnr-märkning i kalendern.
 * @param {string[]} refs dnr + hubsCaseId
 * @return {Promise<{kommande:object[], genomforda:object[]}>}
 */
export async function fetchArendeMeetings(refs) {
	// 🔌 LIVE: sdkmc OCS — GET /arende-meetings?refs= (OCS\Meeting#forCase).
	if (DEMO) return { kommande: [], genomforda: [] }
	const res = await axios.get(SDKMC_OCS('/arende-meetings'), { params: { refs: (refs || []).filter(Boolean).join(',') } })
	const d = ocsData(res)
	return { kommande: d.kommande || [], genomforda: d.genomforda || [] }
}

/**
 * Ärendets HÄNDELSEJOURNAL (kortets Historik & beslut-flik): motorns audit-spår
 * (skapad/steg/tilldelad/medlem/registrerad/rum/kopplad), äldst först.
 * @param {string} ref hubsCaseId eller dnr
 * @return {Promise<Array<{typ:string, detalj:?object, aktorUid:string, tid:?string}>>}
 */
export async function fetchArendeHistorik(ref) {
	// 🔌 LIVE: hubs_arende OCS — GET /arende/{ref}/historik (Arende#historik).
	if (DEMO) return []
	const res = await axios.get(HUBS_ARENDE_OCS('/arende/' + encodeURIComponent(ref) + '/historik'))
	return ocsData(res).handelser || []
}

/**
 * Ärendets BEVAKNINGAR (kortets Bevakningar-flik): REGISTER-RADER ur bevaknings-
 * registret via motorn (förstaklassiga watches — titel/villkor/status/frist), inte
 * längre läs-projektioner av Deck-kort. Handläggaren behöver ingen Deck-behörighet.
 * @param {string} ref hubsCaseId eller dnr
 * @return {Promise<Array<object>>} bevakningar (register-rader; se Bevakning-shapen)
 */
export async function fetchArendeBevakningar(ref) {
	// 🔌 LIVE: hubs_arende OCS — GET /arende/{ref}/bevakningar (Arende#bevakningar).
	if (DEMO) return []
	const res = await axios.get(HUBS_ARENDE_OCS('/arende/' + encodeURIComponent(ref) + '/bevakningar'))
	return ocsData(res).bevakningar || []
}

/**
 * Skapa en ad hoc-bevakning på ett ärende (den tidigare döda "skapa bevakning" —
 * villkor manuell_kvittering). Rubriken får ALDRIG bära PII. Kvitto först på
 * verifierat svar (aldrig optimistisk succé).
 * @param {string} ref hubsCaseId eller dnr
 * @param {{titel:string, fristDue?:?string, recurringDagar?:?number, lagstadgad?:boolean}} data
 * @return {Promise<object>} den skapade bevakningen (register-rad)
 */
export async function skapaBevakning(ref, { titel, fristDue = null, recurringDagar = null, lagstadgad = false }) {
	// 🔌 LIVE: hubs_arende OCS — POST /arende/{ref}/bevakning (Arende#skapaBevakning).
	if (DEMO) return { ok: true, bevakning: null, demo: true }
	const res = await axios.post(HUBS_ARENDE_OCS('/arende/' + encodeURIComponent(ref) + '/bevakning'), {
		titel, fristDue, recurringDagar, lagstadgad,
	})
	return ocsData(res)
}

/**
 * Kvittera (klarmarkera) en manuell bevakning. Recurring föder en ny cykel; övriga
 * slocknar. Endast tillåtet på aktiv + manuell_kvittering (motorn validerar).
 * @param {string} ref hubsCaseId eller dnr
 * @param {number} id bevakningens id
 * @return {Promise<object>} den uppdaterade bevakningen
 */
export async function kvitteraBevakning(ref, id) {
	// 🔌 LIVE: hubs_arende OCS — POST /arende/{ref}/bevakning/{id}/kvittera (Arende#kvitteraBevakning).
	if (DEMO) return { ok: true, bevakning: null, demo: true }
	const res = await axios.post(HUBS_ARENDE_OCS('/arende/' + encodeURIComponent(ref) + '/bevakning/' + encodeURIComponent(String(id)) + '/kvittera'))
	return ocsData(res)
}

/**
 * Avbryt en bevakning (ej längre relevant) — monotont slutläge.
 * @param {string} ref hubsCaseId eller dnr
 * @param {number} id bevakningens id
 * @return {Promise<object>} den avbrutna bevakningen
 */
export async function avbrytBevakning(ref, id) {
	// 🔌 LIVE: hubs_arende OCS — DELETE /arende/{ref}/bevakning/{id} (Arende#avbrytBevakning).
	if (DEMO) return { ok: true, bevakning: null, demo: true }
	const res = await axios.delete(HUBS_ARENDE_OCS('/arende/' + encodeURIComponent(ref) + '/bevakning/' + encodeURIComponent(String(id))))
	return ocsData(res)
}

/**
 * Sätt delgivningsdatum (FL 33 §) → föder/uppdaterar överklagandebevakningen
 * (3 veckor → laga kraft).
 * @param {string} ref hubsCaseId eller dnr
 * @param {string} datum 'YYYY-MM-DD'
 * @return {Promise<object>} överklagande-bevakningen
 */
export async function setDelgivningsdatum(ref, datum) {
	// 🔌 LIVE: hubs_arende OCS — POST /arende/{ref}/delgivning (Arende#setDelgivning).
	if (DEMO) return { ok: true, bevakning: null, demo: true }
	const res = await axios.post(HUBS_ARENDE_OCS('/arende/' + encodeURIComponent(ref) + '/delgivning'), { datum })
	return ocsData(res)
}

/**
 * Lägg till en kollega i ärenderummet (co-handläggare/observatör) — motorn
 * uppdaterar ledger, per-case-grupp, chattdeltagande och notifierar kollegan.
 * @param {string} ref hubsCaseId eller dnr
 * @param {string} uid kollegans användar-id
 * @param {string} [roll] co_handlaggare (default) | observator
 * @return {Promise<{ok:boolean}>}
 */
export async function laggTillMedlem(ref, uid, roll = 'co_handlaggare') {
	// 🔌 LIVE: hubs_arende OCS — POST /arende/{ref}/medlem (Arende#laggTillMedlem).
	if (DEMO) return { ok: true }
	const res = await axios.post(HUBS_ARENDE_OCS('/arende/' + encodeURIComponent(ref) + '/medlem'), { uid, roll })
	return ocsData(res)
}

/**
 * Multi-korg inflöde summary: behörighetsfiltrerade korgar + inflöde-rader
 * (KorgValjare-piller + de tre banden "Att ta emot"/"Att hantera"/"Ej
 * ärendekopplat"). Klassning + ärende-match sker server-side (ingen fan-out).
 * @return {Promise<{korgar:object[], inflode:object[]}>}
 */
export async function fetchInflodeSummary() {
	if (DEMO) return ssDemo.fetchInflodeSummary()
	// 🔌 LIVE: sdkmc OCS — GET /inflode-summary. sdkmc äger DATAKÄLLAN (läser de
	// verkliga funktions-brevlådornas INBOX). hubs_arende-motorns /inflode-summary
	// kör visserligen klassning+ärende-match men har INGEN rå-feed wirad ännu
	// (resolveInflodeRows() = tom) → att peka hit skulle tömma inflödet. Tills
	// cross-app-enrichmenten är byggd (sdkmc-feed → hubs_arende klass+match) läser
	// vi sdkmc; zonOf() defaultar otriagerade orosanmälningar till 1a "Att ta emot".
	const res = await axios.get(SDKMC_OCS('/inflode-summary'))
	return ocsData(res)
}

/**
 * Gruppledarens fördelningsvy (roll-läge 'fordelning'): att-fördela-ärenden +
 * utredarnas belastning (tal + frist-färg, ALDRIG innehåll) + mottagningens
 * pågående. Exponerar bara det fördelaren har åtkomst till (IConditionalWidget).
 * @return {Promise<{attFordela:object[], utredare:object[], mottagningPagaende:number}>}
 */
export async function fetchFordelningSummary() {
	if (DEMO) return ssDemo.fetchFordelningSummary()
	// 🔌 LIVE: hubs_arende OCS — fördelningsvy ur otilldelade ärenden (ärende-domän, ej sdkmc).
	const res = await axios.get(HUBS_ARENDE_OCS('/fordelning-summary'))
	return ocsData(res)
}

/**
 * Team-/enhetschatt-trådar (lugn sidopanel). Visar bara olästa + omnämnanden,
 * aldrig en rå inkorgs-siffra.
 * @return {Promise<object[]>} team: { id, label, olasta, omnamnanden }
 */
export async function fetchTeam() {
	if (DEMO) return ssDemo.fetchTeam()
	const res = await axios.get(SDKMC_OCS('/team'))
	return ocsData(res)
}

/**
 * Ej-kopplat / inflöde-åtgärd (koppla|skapa|besvara|vidarebefordra|gallra|
 * registrera|tilldela|omfordela|gorTillHandling). I prod orkestrerar sdkmc
 * atomärt (register + tag + ACL + Deck + logg). Demo: optimistisk stub.
 * @param {string} action one of the verbs above
 * @param {object} payload action-specific body
 * @return {Promise<{ok:boolean}>}
 */
export async function inflodeAction(action, payload) {
	if (DEMO) return { ok: true, action, ...payload }
	// Boundary-routing: ärende-verben (skapa/koppla/registrera/gallra) ägs av hubs_arende-
	// motorn; meddelande-verben (besvara/vidarebefordra) av sdkmc. sdkmc avvisar ärende-verb
	// med 400 'agas_av_arende_motorn' och vice versa, så routningen måste ske här.
	// OBS: 'skapa-bevakning' är INTE ett inflöde-verb — det routades tidigare hit och
	// föll till ett obefintligt sdkmc-endpoint (/inflode/skapa-bevakning) medan UI:t
	// visade en falsk "Bevakning skapad."-toast. Bevakningar skapas nu via skapaBevakning()
	// mot motorns /arende/{ref}/bevakning; callern öppnar ärendets Bevakningar-flik i st.f.
	const ARENDE_VERB = ['skapa', 'koppla', 'registrera', 'gallra']
	const base = ARENDE_VERB.includes(action) ? HUBS_ARENDE_OCS : SDKMC_OCS
	const res = await axios.post(base('/inflode/' + encodeURIComponent(action)), payload)
	return ocsData(res)
}

export default {
	fetchArendeSummary,
	fetchArende,
	fetchInflodeSummary,
	fetchFordelningSummary,
	fetchTeam,
	fetchFavoriter,
	fetchTreservaReceipts,
	skapaArende,
	transitionSteg,
	grindKravFel,
	tilldela,
	inflodeAction,
	commitToTreserva,
	getSettings,
	getPreferences,
	savePreferences,
	fetchSummary,
	fetchReceipts,
	searchRecipients,
	classifyRecipient,
	fetchTodaysMeetings,
	createSecureMeeting,
	fetchLobbyStatus,
	fetchGuestIdentity,
	takeThread,
	setMessageTag,
	kopplaMeddelandeTillArende,
	fetchAppointmentConfigs,
	fetchNotes,
	addNote,
	fetchArendeEnrichment,
	fetchArendeHistorik,
	fetchArendeParter,
	fetchArendeBevakningar,
	skapaBevakning,
	kvitteraBevakning,
	avbrytBevakning,
	setDelgivningsdatum,
}
