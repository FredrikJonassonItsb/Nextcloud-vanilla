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
export function composerLink(messageType, to = null) {
	let url = generateUrl('/apps/mail/new') + '?type=' + encodeURIComponent(messageType)
	if (to) {
		url += '&to=' + encodeURIComponent(to)
	}
	return url
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
 * Open the ärenderum (the groupfolder whose mount_point === hubsCaseId) in Files.
 * @param {string} hubsCaseId groupfolder mount_point = hubsCaseId
 * @return {string}
 */
export function arenderumLink(hubsCaseId) {
	return generateUrl('/apps/files/?dir=/' + encodeURIComponent(String(hubsCaseId)))
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
	default:
		return generateUrl('/apps/hubs_start/')
	}
}

export default { threadLink, composerLink, mailboxLink, callLink, arenderumLink, fileLink, loa3UpgradeLink, resolve }
