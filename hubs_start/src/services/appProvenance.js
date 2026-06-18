/**
 * Hubs Start — widget app provenance + system-of-record.
 *
 * The teaching layer: for each widget, which app powers it, whether that app is
 * natively installed (so the dashboard can open it for real) or a proposed/
 * external integration, and — crucially — WHERE the data is ultimately stored.
 *
 * Architectural framing (customer requirement): Hubs is the MELLANLAGRING
 * (staging) of secure communication/signing/meetings/files. The SYSTEM OF RECORD
 * (slutlagring) is the verksamhetens ärendehanteringssystem (Treserva, Lifecare,
 * W3D3, Provisum, …). Each widget's provenance makes that handoff visible.
 */

import { generateUrl } from '@nextcloud/router'
import widgetApps from './widgetApps.js'

/**
 * @typedef {object} Provenance
 * @property {string} backingApp     human label, e.g. "Uppgifter (Deck)"
 * @property {string} [ncAppId]      installable Nextcloud app id if native
 * @property {('native'|'proposed-integration'|'external')} status
 * @property {string} [deepLink]     route to open the app
 * @property {string} prerequisites  what must exist to wire it for real
 * @property {string} systemOfRecord where data is ultimately stored
 */

/** Provenance entry for a widget id, or null. */
export function provenanceFor(widgetId) {
	return widgetApps[widgetId] || null
}

/** True when the backing app is installed and can be opened for real. */
export function isNative(p) {
	return !!(p && p.status === 'native' && (p.deepLink || p.ncAppId))
}

/**
 * Resolve the URL to open the backing app (real navigation — the app is
 * installed). Returns null when there is nothing to open.
 * @param {?Provenance} p
 * @return {?string}
 */
export function appUrl(p) {
	if (!p) {
		return null
	}
	// Concrete deep link (no unresolved {placeholders}) → use it.
	if (p.deepLink && !p.deepLink.includes('{')) {
		return p.deepLink.startsWith('/') ? generateUrl(p.deepLink) : p.deepLink
	}
	// Otherwise open the app root (the link template needs runtime ids).
	if (p.ncAppId) {
		return generateUrl('/apps/' + p.ncAppId)
	}
	return null
}

export default { provenanceFor, isNative, appUrl }
