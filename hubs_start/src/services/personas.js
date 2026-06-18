/**
 * Hubs Start — persona helpers over the generated persona catalog.
 *
 * The catalog (personaConfig.js) is produced from the persona research/design
 * (docs/PERSONA-DASHBOARD-SPEC.md). It defines the widget catalog, the primary
 * actions catalog, the proposed-apps roadmap, and the 6 persona layouts. These
 * helpers resolve ids → objects for the persona-driven dashboard.
 */

import config from './personaConfig.js'

const widgetById = Object.fromEntries((config.widgets || []).map((w) => [w.id, w]))
const actionById = Object.fromEntries((config.primaryActions || []).map((a) => [a.id, a]))

export const personaConfig = config

/** Lightweight list for the persona switcher. */
export function listPersonas() {
	return (config.personas || []).map((p) => ({ id: p.id, label: p.label, tagline: p.tagline }))
}

export const defaultPersonaId = (config.personas && config.personas[0] && config.personas[0].id) || null

/** Resolve a persona by id (falls back to the first persona). */
export function getPersona(id) {
	const personas = config.personas || []
	return personas.find((p) => p.id === id) || personas[0] || null
}

/** Catalog metadata for a widget id (title/feature/dataSource/description). */
export function widgetMeta(id) {
	return widgetById[id] || { id, title: id, category: 'persona', feature: '', dataSource: 'proposed', description: '' }
}

/** Resolve a persona's ordered primary action objects from its ids. */
export function actionsForPersona(persona) {
	if (!persona) {
		return []
	}
	return (persona.primaryActionIds || []).map((id) => actionById[id]).filter(Boolean)
}

/** Resolve an action by id. */
export function actionMeta(id) {
	return actionById[id] || null
}

export default { personaConfig, listPersonas, defaultPersonaId, getPersona, widgetMeta, actionsForPersona, actionMeta }
