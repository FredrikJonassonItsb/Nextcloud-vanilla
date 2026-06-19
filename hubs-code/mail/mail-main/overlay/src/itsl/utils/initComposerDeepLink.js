/**
 * Hubs Start → mail composer deep-link (LIVE module).
 *
 * Opens the ITSL composer when the mail SPA is entered at
 *   /apps/mail/new?type={messageType}&to={value}&case={dnr|hubsCaseId}
 * which is exactly what the dashboard's "Skicka säkert meddelande" button builds
 * (hubs_start/src/services/deepLinks.js → composerLink(type, to, caseRef)).
 *
 * Mechanism mirrors NewMessageButtonHeaderItsl.openNewMessageDropdown():
 *   itslStore.setMessageType(type) + mainStore.startComposerSession({ data: { itsl } }).
 *
 * The optional `case` param is carried into the composer session as
 * `itsl.caseRef` so the composer knows which ärende the message belongs to. The
 * actual durable koppling (tagging the SENT message onto the ärende) is then done
 * by REUSING the engine's already-verified Väg-A koppling
 * (hubs_arende OCS /inflode/koppla) once the message has a databaseId — see
 * onComposedForCase() below. We deliberately do NOT re-implement tagging here.
 *
 * Wiring (initITSL.js): import this and call initComposerDeepLink() inside
 * initITSL(), after initStore() (the Pinia stores must exist).
 */

import router from '../../router.js'
import useMainStore from '../../store/mainStore.js'
import useOutboxStore from '../../store/outboxStore.js'
import useItslStore from '../store/itslStore.js'
import { MESSAGE_TYPES } from '../store/constants.js'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

const VALID_MESSAGE_TYPES = new Set(Object.values(MESSAGE_TYPES).map((t) => t.id))

/**
 * Parse type/to/case out of a query (string | URLSearchParams | route.query object).
 * @param {string|URLSearchParams|object} query
 * @return {{ messageType: string, to: ?string, caseRef: ?string }|null}
 */
function parseComposerQuery(query) {
	let params
	if (query instanceof URLSearchParams) {
		params = query
	} else if (typeof query === 'string') {
		params = new URLSearchParams(query.startsWith('?') ? query.slice(1) : query)
	} else if (query && typeof query === 'object') {
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
	return { messageType, to: params.get('to') || null, caseRef: params.get('case') || null }
}

/**
 * Build the per-type `itsl` payload the composer understands, pre-filling the
 * recipient field for the channel and carrying the ärende-ref through.
 * @param {string} messageType
 * @param {?string} to
 * @param {?string} caseRef
 * @return {object}
 */
function buildItslPayload(messageType, to, caseRef) {
	const itsl = { messageType }
	if (caseRef) {
		// The ärende this message belongs to — consumed on send (onComposedForCase).
		itsl.caseRef = caseRef
	}
	if (to) {
		switch (messageType) {
		case MESSAGE_TYPES.SECURE.id: itsl.notification = to; break
		case MESSAGE_TYPES.INTERNAL.id: itsl.email = to; break
		case MESSAGE_TYPES.FAX.id: itsl.faxAddress = to; break
		case MESSAGE_TYPES.SMS.id: itsl.smsAddress = to; break
		default: break // SDK needs an org+function pair; leave the pickers to the user.
		}
	}
	return itsl
}

/**
 * Open the composer for a parsed deep link and remember the active caseRef so the
 * post-send koppling can fire.
 * @param {{ messageType: string, to: ?string, caseRef: ?string }} parsed
 */
async function openComposerFromDeepLink({ messageType, to, caseRef }) {
	const mainStore = useMainStore()
	const itslStore = useItslStore()
	itslStore.setMessageType(messageType)
	// Stash the caseRef for the send hook (the composer session data does not
	// reliably round-trip to the outbox send args).
	activeCaseRef = caseRef || null
	try {
		await mainStore.startComposerSession({
			isBlankMessage: true,
			data: { itsl: buildItslPayload(messageType, to, caseRef) },
		})
	} catch (error) {
		console.error('[hubs-start] composer deep-link failed to open', { messageType, to, error })
	}
}

// The case the currently-open deep-link composer is bound to (null when none).
let activeCaseRef = null

/**
 * After a message is sent for a deep-link composer that carried a caseRef, couple
 * the sent message to the ärende by REUSING the engine's verified Väg-A koppling
 * (hubs_arende OCS /inflode/koppla — writes the durable reference + pekare in the
 * ärendemappen and applies the visible case:-tag, all under the user's session
 * with per-message authz). Best-effort: a koppling failure never blocks the send.
 * @param {?number} sentDatabaseId the sent message's db id (when available)
 */
async function onComposedForCase(sentDatabaseId) {
	const caseRef = activeCaseRef
	activeCaseRef = null
	if (!caseRef || !sentDatabaseId) {
		return
	}
	try {
		await axios.post(
			generateOcsUrl('apps/hubs_arende/api/v1/inflode/koppla'),
			{ hubsCaseId: caseRef, rad: { messageIds: [String(sentDatabaseId)] } },
		)
	} catch (error) {
		// Honest best-effort: the message is already sent; a koppling failure is
		// surfaced in logs, not as a send error.
		console.error('[hubs-start] post-send ärende-koppling failed', { caseRef, sentDatabaseId, error })
	}
}

/**
 * Register the deep-link handler + the post-send koppling hook.
 */
export function initComposerDeepLink() {
	// (A) Cold load: /apps/mail/new?type=…
	try {
		const path = window.location && window.location.pathname
		if (path && /\/apps\/mail\/new\/?$/.test(path)) {
			const parsed = parseComposerQuery(window.location.search)
			if (parsed) {
				const open = () => openComposerFromDeepLink(parsed)
				if (typeof window.requestAnimationFrame === 'function') {
					window.requestAnimationFrame(open)
				} else {
					setTimeout(open, 0)
				}
			}
		}
	} catch (error) {
		console.error('[hubs-start] composer deep-link cold-load check failed', error)
	}

	// (B) SPA-internal navigation to …/new?type=…
	router.beforeEach((to, from, next) => {
		const path = to.path || ''
		if (/(^|\/)new\/?$/.test(path) && to.query && to.query.type) {
			const parsed = parseComposerQuery(to.query)
			if (parsed) {
				openComposerFromDeepLink(parsed)
			}
		}
		next()
	})

	// (C) Post-send koppling: when a deep-link composer (caseRef set) sends, couple
	// the sent message to the ärende via the engine's Väg-A endpoint.
	const outboxStore = useOutboxStore()
	outboxStore.$onAction(({ name, args, after }) => {
		if (name === 'sendMessage') {
			after((wasSent) => {
				if (wasSent && activeCaseRef) {
					// args[0] carries the message context; prefer an explicit sent id
					// when the outbox exposes one, else fall back to replyToDatabaseId.
					const a = args[0] || {}
					onComposedForCase(a.sentDatabaseId || a.id || a.databaseId || a.replyToDatabaseId || null)
				}
			})
		}
	})
}

export default { initComposerDeepLink }
