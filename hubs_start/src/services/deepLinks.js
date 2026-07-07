/**
 * Hubs Start — deep-link resolver.
 *
 * Every link OUT of the dashboard into a target app goes through here, so route
 * conventions live in one verifiable place (gov-portal broke on hand-written,
 * unverified routes). These are full-page navigations, not XHR.
 *
 * The composer deep-link (`/apps/mail/new`) relies on the small routerhook added
 * to the mail overlay — see backend-additions/mail/initITSL-additions.js.
 */

import { generateUrl } from '@nextcloud/router'

/**
 * Open an exact mail thread via sdkmc's verified mailbox-link redirect.
 * Always lands in the correct account/thread regardless of which inbox it lives in.
 * @param {string|number} itslMailboxId
 * @param {string|number} mid message id
 * @return {string}
 */
export function threadLink(itslMailboxId, mid) {
	return generateUrl('/apps/sdkmc/mailbox-link/{itslMailboxId}', { itslMailboxId })
		+ '?mid=' + encodeURIComponent(String(mid))
}

/**
 * Open the mail composer pre-filled with a channel and recipient.
 * Requires the mail routerhook (backend-additions/mail).
 * @param {string} messageType sdk_message|internal_message|secure_email|fax_message|sms_message
 * @param {?string} to recipient address/value
 * @return {string}
 */
export function composerLink(messageType, to = null, caseRef = null) {
	let url = generateUrl('/apps/mail/new') + '?type=' + encodeURIComponent(messageType)
	if (to) {
		url += '&to=' + encodeURIComponent(to)
	}
	// caseRef (dnr/hubsCaseId) lets the mail routerhook pre-apply the case:-tag so
	// the sent message lands tagged on the ärendet directly (no manual koppling).
	if (caseRef) {
		url += '&case=' + encodeURIComponent(String(caseRef))
	}
	return url
}

/**
 * Open a Deck board. Falls back to the Deck board list when no board id is known
 * (404-safe — never a hardcoded board that may not exist in this kommun).
 * @param {?string|number} boardId
 * @return {string}
 */
export function deckLink(boardId = null) {
	return boardId
		? generateUrl('/apps/deck/board/{boardId}', { boardId })
		: generateUrl('/apps/deck/')
}

/** Open a mail virtual/special mailbox. */
export function mailboxLink(mailboxId) {
	return generateUrl('/apps/mail/box/{mailboxId}', { mailboxId })
}

/** Join / open a Talk call by token. */
export function callLink(token) {
	return generateUrl('/call/{token}', { token })
}

/**
 * Open a diskussion-/enhetschatt-rum by its room token. Returns null when no token
 * is known so the caller MUST disable the control / show an honest empty state —
 * never a hardcoded room. Same underlying route as callLink, kept separate so the
 * call-site intent (öppna rummet, inte ring upp) is explicit.
 * @param {?string} token room token
 * @return {?string} url, or null when token is absent
 */
export function spreedRoomLink(token) {
	if (!token) {
		return null
	}
	return generateUrl('/call/{token}', { token })
}

/**
 * Open the ärenderum (the groupfolder whose mount_point === hubsCaseId) in Files.
 * @param {string} hubsCaseId groupfolder mount_point = hubsCaseId
 * @return {string}
 */
export function arenderumLink(hubsCaseId) {
	return generateUrl('/apps/files/?dir=/' + encodeURIComponent(String(hubsCaseId)))
}

/**
 * Open the ärenderummets TEAM (the per-case circle that ties the room together:
 * members, akt, diskussion) in the Contacts team view. Returns null when no
 * team id is known so the caller MUST hide/disable the control — never a
 * fabricated team (same honest-null contract as spreedRoomLink).
 * @param {?string} teamId circle singleId from the engine's pekare block
 * @return {?string} url, or null when teamId is absent
 */
export function teamLink(teamId) {
	if (!teamId) {
		return null
	}
	// The circles app's own canonical deep link (Circle::getUrl()) — redirects
	// into the Contacts team view.
	return generateUrl('/apps/contacts/direct/circle/{teamId}', { teamId })
}

/**
 * Open a single file in Files by its full path (parent dir gets focus on the file).
 * @param {string} path full path to the file, e.g. /HUBS-2026-0001/utredning.pdf
 * @return {string}
 */
export function fileLink(path) {
	const p = String(path || '')
	const slash = p.lastIndexOf('/')
	const dir = slash > 0 ? p.slice(0, slash) : '/'
	const name = slash >= 0 ? p.slice(slash + 1) : p
	let url = generateUrl('/apps/files/?dir=' + encodeURIComponent(dir))
	if (name) {
		url += '&scrollto=' + encodeURIComponent(name)
	}
	return url
}

/**
 * Self-service LOA3 upgrade (replaces the abrupt forced logout). Returns the
 * user to Hubs Start afterwards.
 * @param {?string} returnUrl
 * @return {string}
 */
export function loa3UpgradeLink(returnUrl = null) {
	const ret = returnUrl || generateUrl('/apps/hubs_start/')
	return generateUrl('/apps/sdkmc/upgradeToLoa3') + '?returnUrl=' + encodeURIComponent(ret)
}

/**
 * Öppna meddelandemodulen (säker post — mail-appen med sdkmc-overlayn).
 * @return {string}
 */
export function mailModuleLink() {
	return generateUrl('/apps/mail/')
}

/**
 * Öppna kalendermodulen.
 * @return {string}
 */
export function calendarModuleLink() {
	return generateUrl('/apps/calendar/')
}

/**
 * Resolve a QueueItem.deepLink descriptor ({ app, params }) to a URL.
 * @param {object} deepLink
 * @return {string}
 */
export function resolve(deepLink) {
	if (!deepLink || !deepLink.app) {
		return generateUrl('/apps/hubs_start/')
	}
	switch (deepLink.app) {
	case 'thread':
		return threadLink(deepLink.params.itslMailboxId, deepLink.params.mid)
	case 'composer':
		return composerLink(deepLink.params.messageType, deepLink.params.to)
	case 'mailbox':
		return mailboxLink(deepLink.params.mailboxId)
	case 'call':
		return callLink(deepLink.params.token)
	case 'room':
		return spreedRoomLink(deepLink.params.token)
	default:
		return generateUrl('/apps/hubs_start/')
	}
}

export default { threadLink, composerLink, deckLink, mailboxLink, callLink, spreedRoomLink, arenderumLink, teamLink, fileLink, loa3UpgradeLink, mailModuleLink, calendarModuleLink, resolve }
