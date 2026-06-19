/**
 * Hubs Start — mail overlay routerhook (DROP-IN SNIPPET).
 *
 * ⚠ STATUS (2026-06-19): INTEGRATED. This recipe now lives as a real module at
 *   hubs-code/mail/mail-main/overlay/src/itsl/utils/initComposerDeepLink.js and is
 *   called from initITSL() (after initStore()). The live module additionally:
 *     - parses `&case={dnr|hubsCaseId}` and carries it as itsl.caseRef, and
 *     - on send, couples the sent message to the ärende by REUSING the engine's
 *       verified Väg-A koppling (hubs_arende OCS /inflode/koppla) — no re-tagging.
 *   This snippet is kept as the documented reference. The mail overlay must be
 *   REBUILT + REDEPLOYED for the integration to take effect, and the send-time
 *   koppling needs GUI verification (login + an actual send).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  INTEGRATION (overlay/src/itsl/utils/initITSL.js)
 * ─────────────────────────────────────────────────────────────────────────────
 *  This file is NOT imported as-is. Copy the marked sections into the real
 *  `initITSL.js`:
 *
 *  1) Add the import near the other store imports at the top of initITSL.js
 *     (it reuses the already-imported `router`, `useMainStore`, `useItslStore`,
 *     `MESSAGE_TYPES`). If you prefer to keep this as its own module, place this
 *     file at overlay/src/itsl/utils/initComposerDeepLink.js and import it:
 *
 *        import { initComposerDeepLink } from './initComposerDeepLink.js'
 *
 *  2) Add EXACTLY ONE call inside initITSL(), immediately after
 *     initInterceptors() (and after initStore(), so the stores exist):
 *
 *        export function initITSL() {
 *            ...
 *            initInterceptors()
 *            initStore()
 *            initComposerDeepLink()   // ← Hubs Start: add this single line
 *            initTags()
 *            ...
 *        }
 *
 *  Why right after initInterceptors()/initStore(): the deep-link opens the
 *  composer via the same path the New-message button uses
 *  (itslStore.setMessageType + mainStore.startComposerSession), which needs the
 *  Pinia stores initialised. We register a router.beforeEach() for SPA-internal
 *  navigations and ALSO run once on first load (the cold-load case, where the
 *  page is opened directly at /apps/mail/new?type=…&to=…).
 *
 *  Deep-link contract (see hubs_start/src/services/deepLinks.js → composerLink):
 *      /apps/mail/new?type={messageType}&to={value}
 *      messageType ∈ sdk_message | internal_message | secure_email |
 *                    fax_message | sms_message   (== MESSAGE_TYPES.*.id)
 *      to = optional recipient address/value (URL-encoded)
 *
 *  Mechanism reused (verified against NewMessageButtonHeaderItsl.vue):
 *      itslStore.setMessageType(type.id)
 *      mainStore.startComposerSession({ isBlankMessage: true, data: { itsl: { messageType, ... } } })
 *  The `itsl.messageType` is consumed by ComposerItsl.vue#beforeMount
 *  (this.selectedMessageType = this.itsl.messageType).
 *
 *  AGPL-3.0-or-later — matches the mail overlay licence.
 * ─────────────────────────────────────────────────────────────────────────────
 */

// ── BEGIN: copy these imports into initITSL.js (most already exist there) ──────
// import router from '../../router.js'                    // already imported in initITSL.js
// import useMainStore from '../../store/mainStore.js'     // already imported in initITSL.js
// import useItslStore from '../store/itslStore.js'        // already imported in initITSL.js
// import { MESSAGE_TYPES } from '../store/constants.js'   // already imported in initITSL.js
// ── END imports ───────────────────────────────────────────────────────────────

/**
 * Set of valid messageType ids, derived from the single source of truth
 * (MESSAGE_TYPES) so we never drift from the composer's accepted types.
 */
const VALID_MESSAGE_TYPES = new Set(
	Object.values(MESSAGE_TYPES).map((t) => t.id),
)

/**
 * Parse `?type=` and `?to=` out of a query string or URLSearchParams.
 * @param {string|URLSearchParams} query raw `location.search` or a route query object
 * @return {{ messageType: string, to: ?string }|null} null when no valid type present
 */
function parseComposerQuery(query) {
	let params
	if (query instanceof URLSearchParams) {
		params = query
	} else if (typeof query === 'string') {
		params = new URLSearchParams(query.startsWith('?') ? query.slice(1) : query)
	} else if (query && typeof query === 'object') {
		// vue-router `to.query` is a plain object { type, to }
		params = new URLSearchParams()
		for (const [k, v] of Object.entries(query)) {
			if (v != null) params.set(k, String(v))
		}
	} else {
		return null
	}

	const messageType = params.get('type')
	if (!messageType || !VALID_MESSAGE_TYPES.has(messageType)) {
		return null
	}
	const to = params.get('to')
	return { messageType, to: to || null }
}

/**
 * Build the `itsl` payload that ComposerItsl.vue#beforeMount understands for a
 * given message type, pre-filling the recipient field that matches the channel.
 *
 * ComposerItsl reads recipient data from the `itsl` object per-type:
 *   - secure_email  → itsl.notification (the e-mail to notify)
 *   - internal_message → itsl.email
 *   - fax_message   → itsl.faxAddress
 *   - sms_message   → itsl.smsAddress
 *   - sdk_message   → org/function address (looked up in the address book; a raw
 *                     `to` string can't be resolved to org+function here, so we
 *                     only preselect the SDK type and leave the address pickers
 *                     for the user — see TODO below).
 * @param {string} messageType one of MESSAGE_TYPES.*.id
 * @param {?string} to recipient value from the deep link
 * @return {object} the `itsl` data object for startComposerSession
 */
function buildItslPayload(messageType, to) {
	const itsl = { messageType }
	if (!to) {
		return itsl
	}
	switch (messageType) {
	case MESSAGE_TYPES.SECURE.id:
		itsl.notification = to
		break
	case MESSAGE_TYPES.INTERNAL.id:
		itsl.email = to
		break
	case MESSAGE_TYPES.FAX.id:
		itsl.faxAddress = to
		break
	case MESSAGE_TYPES.SMS.id:
		itsl.smsAddress = to
		break
	case MESSAGE_TYPES.SDK.id:
		// TODO(hubs-start): an SDK message needs an organizationAddress +
		// functionAddress pair resolved from the address book; a single `to`
		// string can't be mapped reliably here. The composer opens with the SDK
		// type preselected and empty pickers. If Smart mottagare starts passing a
		// resolved SDK function address, set itsl.organizationAddress /
		// itsl.functionAddress here (see ComposerItsl.setAddressesFromAddressBook).
		break
	default:
		break
	}
	return itsl
}

/**
 * Open the ITSL composer for a parsed deep link using the SAME mechanism as
 * NewMessageButtonHeaderItsl.openNewMessageDropdown():
 *   1. itslStore.setMessageType(messageType)  — drives the new-message path
 *   2. mainStore.startComposerSession({ isBlankMessage: true, data: { itsl } })
 * @param {{ messageType: string, to: ?string }} parsed result of parseComposerQuery
 */
async function openComposerFromDeepLink({ messageType, to }) {
	const mainStore = useMainStore()
	const itslStore = useItslStore()

	// Mirror NewMessageButtonHeaderItsl: set the type on the itslStore so any
	// downstream code that reads getSelectedMessageType is consistent, then open
	// a blank composer session carrying the per-type `itsl` payload.
	itslStore.setMessageType(messageType)

	const data = { itsl: buildItslPayload(messageType, to) }

	try {
		await mainStore.startComposerSession({
			isBlankMessage: true,
			data,
		})
	} catch (error) {
		// Non-fatal: a failed deep-link open must never break app boot/navigation.
		console.error('[hubs-start] composer deep-link failed to open', { messageType, to, error })
	}
}

/**
 * Register the composer deep-link handler.
 *
 * Handles two entry paths:
 *   (A) Cold load — the browser is pointed directly at /apps/mail/new?type=…&to=…
 *       (a full-page navigation from Hubs Start via deepLinks.composerLink()).
 *       We inspect window.location once on init.
 *   (B) SPA-internal navigation — vue-router routes to /new?type=…&to=… without a
 *       page reload (e.g. a future in-app link). We hook router.beforeEach().
 *
 * Both paths converge on openComposerFromDeepLink(). The `/new` route itself is
 * intentionally NOT a real mail route — we open the composer overlay and let the
 * navigation fall through to the default mailbox so the user lands somewhere sane
 * behind the composer.
 */
export function initComposerDeepLink() {
	// ── (A) Cold load ────────────────────────────────────────────────────────
	// Match the path mail is mounted under. The mail SPA is served from
	// /apps/mail/… ; the deep link path component we care about ends in `/new`.
	try {
		const path = window.location && window.location.pathname
		if (path && /\/apps\/mail\/new\/?$/.test(path)) {
			const parsed = parseComposerQuery(window.location.search)
			if (parsed) {
				// Defer to next tick so the Vue app + stores are fully mounted
				// before we open the composer overlay.
				if (typeof window.requestAnimationFrame === 'function') {
					window.requestAnimationFrame(() => openComposerFromDeepLink(parsed))
				} else {
					setTimeout(() => openComposerFromDeepLink(parsed), 0)
				}
			}
		}
	} catch (error) {
		console.error('[hubs-start] composer deep-link cold-load check failed', error)
	}

	// ── (B) SPA-internal navigation ──────────────────────────────────────────
	// TODO(hubs-start): the exact route `name`/`path` mail uses for a blank
	// "/new" composer is not a standard mail route. We therefore match on the
	// path tail (`…/new`) and on a `type` query param rather than a route name,
	// which is robust regardless of how the route is declared. If mail later
	// adds a named composer route, switch the guard to `to.name === '<name>'`.
	router.beforeEach((to, from, next) => {
		const path = to.path || ''
		const isComposerDeepLink = /(^|\/)new\/?$/.test(path) && to.query && to.query.type
		if (isComposerDeepLink) {
			const parsed = parseComposerQuery(to.query)
			if (parsed) {
				// Open the composer overlay, then continue navigation so the SPA
				// lands on a valid view (the composer renders on top of it).
				openComposerFromDeepLink(parsed)
			}
		}
		next()
	})
}

export default { initComposerDeepLink }
