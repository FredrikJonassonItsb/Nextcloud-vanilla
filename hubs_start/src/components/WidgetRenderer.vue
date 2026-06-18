<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Resolves a widget id to a component for the persona-driven layout:
  -   1. one of the 8 REAL components (fed live from the store), or
  -   2. a VARIANT component (WidgetQueue/Progress/Stat/Files) fed a demo descriptor, or
  -   3. WidgetFallback (proposed feature with no descriptor yet).
  - Re-emits widget events (take/open/done/join/action) up to the Start view.
-->
<template>
	<div class="hs-widget">
		<component
			:is="resolved.component"
			v-bind="resolved.props"
			v-on="resolved.listeners" />
		<WidgetProvenance v-if="provenance" :provenance="provenance" />
	</div>
</template>

<script>
import store from '../store/index.js'
import { widgetMeta } from '../services/personas.js'
import { descriptorFor } from '../services/demoWidgets.js'
import { provenanceFor } from '../services/appProvenance.js'
import WidgetProvenance from './WidgetProvenance.vue'

// Real components (live from store)
import AttHanteraQueue from './AttHanteraQueue.vue'
import DagensMoten from './DagensMoten.vue'
import KvittensWidget from './KvittensWidget.vue'
import FunktionsbrevladorWidget from './FunktionsbrevladorWidget.vue'
import BevakningarWidget from './BevakningarWidget.vue'
import BokningsbaraTider from './BokningsbaraTider.vue'
import NyttaWidget from './NyttaWidget.vue'
import SystemHalsa from './SystemHalsa.vue'

// Variant components (demo descriptors)
import WidgetQueue from './WidgetQueue.vue'
import WidgetProgress from './WidgetProgress.vue'
import WidgetStat from './WidgetStat.vue'
import WidgetFiles from './WidgetFiles.vue'
import WidgetFallback from './WidgetFallback.vue'

const VARIANTS = {
	queue: WidgetQueue,
	progress: WidgetProgress,
	stat: WidgetStat,
	files: WidgetFiles,
}

const CATEGORY_ICON = {
	kommunikation: 'EmailFast',
	signering: 'FileSign',
	uppgifter: 'BellPlus',
	filer: 'FolderLock',
	mote: 'VideoPlus',
	ärende: 'FileDocumentEdit',
	compliance: 'ShieldCheck',
	statistik: 'ChartBoxOutline',
	persona: 'ViewDashboardOutline',
}

export default {
	name: 'WidgetRenderer',

	components: {
		AttHanteraQueue, DagensMoten, KvittensWidget, FunktionsbrevladorWidget,
		BevakningarWidget, BokningsbaraTider, NyttaWidget, SystemHalsa,
		WidgetQueue, WidgetProgress, WidgetStat, WidgetFiles, WidgetFallback,
		WidgetProvenance,
	},

	props: {
		widgetId: { type: String, required: true },
	},

	data() {
		return { state: store.state, store }
	},

	computed: {
		/** Provenance (backing app + system-of-record) for this widget. */
		provenance() {
			return provenanceFor(this.widgetId)
		},

		/** Resolve the widget id to { component, props, listeners }. */
		resolved() {
			const id = this.widgetId
			const real = this.realWidget(id)
			if (real) {
				return real
			}

			const descriptor = descriptorFor(id)
			const meta = widgetMeta(id)
			if (descriptor && VARIANTS[descriptor.variant]) {
				return {
					component: VARIANTS[descriptor.variant],
					props: {
						title: meta.title,
						descriptor,
						leadIcon: CATEGORY_ICON[meta.category] || 'ViewDashboardOutline',
					},
					listeners: { action: (p) => this.$emit('action', { ...(p || {}), widgetId: id }) },
				}
			}

			// No component and no descriptor → honest "proposed feature" card.
			return {
				component: WidgetFallback,
				props: { title: meta.title, description: meta.description, feature: meta.feature },
				listeners: {},
			}
		},
	},

	methods: {
		bubble(name) {
			return (payload) => this.$emit(name, payload)
		},

		/** Wiring for the 8 real components fed from the live store. */
		realWidget(id) {
			const s = this.state
			switch (id) {
			case 'attHantera':
				return {
					component: AttHanteraQueue,
					props: {
						items: s.items,
						counts: s.counts,
						activeChannel: s.activeChannel,
						channelCoverage: s.channelCoverage,
						keyboardMode: s.prefs.keyboardMode,
					},
					listeners: {
						'change-channel': (c) => this.store.setActiveChannel(c),
						'toggle-keyboard': (v) => this.store.setKeyboardMode(v),
						take: this.bubble('take'),
						open: this.bubble('open'),
						done: this.bubble('done'),
					},
				}
			case 'dagensMoten':
				return { component: DagensMoten, props: { meetings: s.meetings }, listeners: { join: this.bubble('join') } }
			case 'kvittenser':
				return { component: KvittensWidget, props: { receipts: s.receipts }, listeners: { open: this.bubble('open') } }
			case 'funktionsbrevlador':
				return { component: FunktionsbrevladorWidget, props: { mailboxes: s.mailboxes }, listeners: {} }
			case 'bevakningar':
				return { component: BevakningarWidget, props: { watching: s.watching }, listeners: {} }
			case 'bokningsbaraTider':
				return { component: BokningsbaraTider, props: { configs: s.appointmentConfigs }, listeners: {} }
			case 'nytta':
				return { component: NyttaWidget, props: {}, listeners: {} }
			case 'systemhalsa':
				return { component: SystemHalsa, props: {}, listeners: {} }
			default:
				return null
			}
		},
	},
}
</script>

<style scoped lang="scss">
.hs-widget {
	display: flex;
	flex-direction: column;
}
</style>
