/**
 * Hubs Start — merged demo descriptors for the 29 persona-rendered widgets.
 *
 * ⚠️ DEMO ONLY. These fixtures feed the variant components (WidgetQueue/Progress/
 * Stat/Files) so the persona dashboards render rich, realistic content on a vanilla
 * Nextcloud. On a real install these widgets are backed by their proposed apps/
 * services. Source files live in ./demo/ (generated, see DEMO-WIDGETS-CONTRACT.md).
 */

import queuesA from './demo/queues-a.js'
import queuesB from './demo/queues-b.js'
import progress from './demo/progress.js'
import stats from './demo/stats.js'
import files from './demo/files.js'
import extra from './demo/extra.js'
import newcases from './demo/newcases.js'

const demoWidgets = {
	...queuesA,
	...queuesB,
	...progress,
	...stats,
	...files,
	...extra,
	...newcases,
}

/** Descriptor for a widget id, or null if none (→ renderer uses Fallback). */
export function descriptorFor(widgetId) {
	return demoWidgets[widgetId] || null
}

export default demoWidgets
