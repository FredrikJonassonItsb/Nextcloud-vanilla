/**
 * Hubs Start — triage sections & statuses (GOV.UK Task-list inspired).
 *
 * Sections are a fixed, ordered set (Superhuman split-inbox model): the queue is
 * NEVER a blended stream. Statuses are deliberately few (GOV.UK principle: start
 * minimal). Both are returned by the server on each QueueItem; this module only
 * provides ordering + localised labels + colour tokens so rendering is uniform.
 */

import { translate as t } from '@nextcloud/l10n'

/** Fixed section order, top→bottom. */
export const SECTIONS = [
	{ id: 'kraver_atgard', labelKey: 'Kräver åtgärd' },
	{ id: 'otilldelat', labelKey: 'Otilldelat' },
	{ id: 'nytt', labelKey: 'Nytt' },
	{ id: 'bevakas', labelKey: 'Bevakas' },
	{ id: 'klart_idag', labelKey: 'Klart idag' },
]

/** Localised section label. */
export function sectionLabel(id) {
	const s = SECTIONS.find((x) => x.id === id)
	return s ? t('hubs_start', s.labelKey) : id
}

/**
 * GOV.UK-style statuses. `tone` maps to a NcCounterBubble / tag colour token.
 * @type {Object<string,{labelKey:string,tone:string}>}
 */
export const STATUSES = {
	ny: { labelKey: 'Ny', tone: 'info' },
	tilldelad: { labelKey: 'Tilldelad', tone: 'neutral' },
	vantar_kvittens: { labelKey: 'Väntar på kvittens', tone: 'warning' },
	besvarad: { labelKey: 'Besvarad', tone: 'success' },
	problem: { labelKey: 'Problem', tone: 'error' },
	klar: { labelKey: 'Klar', tone: 'success' },
}

/** Localised status label. */
export function statusLabel(id) {
	const s = STATUSES[id]
	return s ? t('hubs_start', s.labelKey) : id
}

/** Status tone token (for colour). */
export function statusTone(id) {
	return STATUSES[id]?.tone ?? 'neutral'
}

export default { SECTIONS, sectionLabel, STATUSES, statusLabel, statusTone }
