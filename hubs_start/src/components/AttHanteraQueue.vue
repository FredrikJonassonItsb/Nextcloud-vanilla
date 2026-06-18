<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->

<template>
	<div
		class="hs-card att-hantera"
		:class="{ 'att-hantera--keyboard': keyboardMode }"
		@keydown="onKeydown">
		<!-- Honest coverage declaration -->
		<p class="att-hantera__coverage">
			<span class="att-hantera__coverage-label">{{ t('hubs_start', 'Bevakade kanaler:') }}</span>
			<span
				v-for="ch in coverageChannels"
				:key="'cov-' + ch.id"
				class="att-hantera__coverage-chip"
				:style="{ '--hs-chip-color': 'var(' + ch.colorVar + ')' }">
				<component :is="ch.icon" :size="16" class="att-hantera__coverage-icon" />
				{{ ch.label }}
			</span>
			<span v-if="!coverageChannels.length" class="att-hantera__coverage-empty">
				{{ t('hubs_start', 'Inga kanaler bevakas') }}
			</span>
		</p>

		<!-- Channel filter tabs: Alla + each covered channel -->
		<div
			class="att-hantera__tabs"
			role="tablist"
			:aria-label="t('hubs_start', 'Filtrera ärenden per kanal')">
			<button
				v-for="tab in channelTabs"
				:key="'tab-' + tab.id"
				type="button"
				role="tab"
				class="att-hantera__tab hs-target"
				:class="{ 'att-hantera__tab--active': activeChannel === tab.id }"
				:aria-selected="String(activeChannel === tab.id)"
				@click="changeChannel(tab.id)">
				<component
					:is="tab.icon"
					v-if="tab.icon"
					:size="18"
					class="att-hantera__tab-icon" />
				<span class="att-hantera__tab-label">{{ tab.label }}</span>
				<NcCounterBubble class="att-hantera__tab-count">{{ tab.count }}</NcCounterBubble>
			</button>
		</div>

		<!-- Keyboard-mode toggle -->
		<div class="att-hantera__toolbar">
			<NcCheckboxRadioSwitch
				type="switch"
				:checked="keyboardMode"
				@update:checked="onToggleKeyboard">
				{{ t('hubs_start', 'Tangentbordsläge') }}
			</NcCheckboxRadioSwitch>
			<span class="att-hantera__hint">
				{{ keyboardMode
					? t('hubs_start', 'j/k: flytta · a: ta · o: öppna · e: klart')
					: t('hubs_start', 'Aktivera tangentbordsläge för snabbtangenter (j/k/a/o/e).') }}
			</span>
		</div>

		<!-- The queue (announces incoming items politely) -->
		<div
			ref="queue"
			class="att-hantera__sections"
			role="group"
			tabindex="0"
			:aria-label="t('hubs_start', 'Ärendekö — att hantera')"
			aria-live="polite"
			:aria-busy="false">
			<NcEmptyContent
				v-if="isWholeQueueEmpty"
				:name="t('hubs_start', 'Allt hanterat — inga ägarlösa ärenden')">
				<template #icon>
					<IconCheckAll :size="40" />
				</template>
			</NcEmptyContent>

			<template v-else>
				<QueueSection
					v-for="section in sections"
					:key="section.id"
					:section="section"
					:items="itemsBySection[section.id]"
					@take="onTake"
					@open="onOpen"
					@done="onDone" />
			</template>
		</div>
	</div>
</template>

<script>
import NcCounterBubble from '@nextcloud/vue/dist/Components/NcCounterBubble.js'
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'

import IconCheckAll from 'vue-material-design-icons/CheckAll.vue'

import { translate as t } from '@nextcloud/l10n'

import QueueSection from './QueueSection.vue'
import { CHANNEL_ORDER, channelMeta } from '../services/channels.js'
import { SECTIONS } from '../services/sections.js'

export default {
	name: 'AttHanteraQueue',

	components: {
		NcCounterBubble,
		NcCheckboxRadioSwitch,
		NcEmptyContent,
		IconCheckAll,
		QueueSection,
	},

	props: {
		items: {
			type: Array,
			default: () => [],
		},
		counts: {
			type: Object,
			default: () => ({}),
		},
		activeChannel: {
			type: String,
			default: 'all',
		},
		channelCoverage: {
			type: Array,
			default: () => [],
		},
		keyboardMode: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			/** Fixed, ordered triage sections (presentation order). */
			sections: SECTIONS,
			/** Id of the keyboard-focused item (only used in keyboardMode). */
			focusedItemId: null,
		}
	},

	computed: {
		/** Channels actually monitored, in canonical order, as display descriptors. */
		coverageChannels() {
			return CHANNEL_ORDER
				.filter((ch) => this.channelCoverage.includes(ch))
				.map((ch) => channelMeta(ch))
		},

		/** Items belonging to the active channel filter (or all). */
		filteredItems() {
			if (this.activeChannel === 'all') {
				return this.items
			}
			return this.items.filter((item) => item.channel && item.channel.channel === this.activeChannel)
		},

		/** Filter tabs: "Alla" first, then each covered channel with its count. */
		channelTabs() {
			const tabs = [{
				id: 'all',
				label: t('hubs_start', 'Alla'),
				icon: null,
				count: this.items.length,
			}]
			for (const ch of CHANNEL_ORDER) {
				if (!this.channelCoverage.includes(ch)) {
					continue
				}
				const meta = channelMeta(ch)
				tabs.push({
					id: ch,
					label: meta.label,
					icon: meta.icon,
					count: this.items.filter((item) => item.channel && item.channel.channel === ch).length,
				})
			}
			return tabs
		},

		/** Filtered items grouped by section id (keyed for each fixed section). */
		itemsBySection() {
			const groups = {}
			for (const section of this.sections) {
				groups[section.id] = []
			}
			for (const item of this.filteredItems) {
				if (groups[item.section]) {
					groups[item.section].push(item)
				}
			}
			return groups
		},

		/** True when, for the active filter, no items remain in any section. */
		isWholeQueueEmpty() {
			return this.filteredItems.length === 0
		},

		/** Flat, top→bottom ordered list of visible items (for j/k navigation). */
		flatVisibleItems() {
			const flat = []
			for (const section of this.sections) {
				for (const item of this.itemsBySection[section.id]) {
					flat.push(item)
				}
			}
			return flat
		},
	},

	methods: {
		t,

		changeChannel(channel) {
			this.$emit('change-channel', channel)
		},

		onToggleKeyboard(value) {
			this.$emit('toggle-keyboard', !!value)
		},

		onTake(item) {
			this.$emit('take', item)
		},

		onOpen(item) {
			this.$emit('open', item)
		},

		onDone(item) {
			this.$emit('done', item)
		},

		/**
		 * Keyboard triage shortcuts, active only in keyboardMode. j/k move the
		 * focus cursor, a/o/e act on the focused item. No focus trapping: any other
		 * key (Tab included) keeps native behaviour so the keyboard path is intact.
		 */
		onKeydown(e) {
			if (!this.keyboardMode) {
				return
			}
			// Never hijack keys while typing in a field/switch.
			const tag = (e.target && e.target.tagName) || ''
			if (/^(INPUT|TEXTAREA|SELECT)$/.test(tag) || (e.target && e.target.isContentEditable)) {
				return
			}
			if (e.ctrlKey || e.metaKey || e.altKey) {
				return
			}

			const list = this.flatVisibleItems
			if (!list.length) {
				return
			}

			switch (e.key) {
			case 'j':
				e.preventDefault()
				this.moveFocus(1)
				break
			case 'k':
				e.preventDefault()
				this.moveFocus(-1)
				break
			case 'a': {
				const item = this.focusedItem()
				if (item) {
					e.preventDefault()
					this.onTake(item)
				}
				break
			}
			case 'o': {
				const item = this.focusedItem()
				if (item) {
					e.preventDefault()
					this.onOpen(item)
				}
				break
			}
			case 'e': {
				const item = this.focusedItem()
				if (item) {
					e.preventDefault()
					this.onDone(item)
				}
				break
			}
			}
		},

		/** Resolve the currently focused item object from its id. */
		focusedItem() {
			const list = this.flatVisibleItems
			if (!list.length) {
				return null
			}
			return list.find((i) => i.id === this.focusedItemId) || null
		},

		/** Move the focus cursor by delta over the flat visible list and focus its row. */
		moveFocus(delta) {
			const list = this.flatVisibleItems
			if (!list.length) {
				return
			}
			let idx = list.findIndex((i) => i.id === this.focusedItemId)
			if (idx === -1) {
				idx = delta > 0 ? 0 : list.length - 1
			} else {
				idx = Math.min(list.length - 1, Math.max(0, idx + delta))
			}
			this.focusedItemId = list[idx].id
			this.focusRow(idx)
		},

		/**
		 * Move real DOM focus to the nth focusable queue row so focus is never
		 * hidden and screen readers track the cursor. Rows render as list items.
		 */
		focusRow(index) {
			this.$nextTick(() => {
				const root = this.$refs.queue
				if (!root) {
					return
				}
				const rows = root.querySelectorAll('li')
				const row = rows[index]
				if (!row) {
					return
				}
				const focusable = row.querySelector('a, button, [tabindex]') || row
				if (typeof focusable.focus === 'function') {
					focusable.focus()
				}
			})
		},
	},
}
</script>

<style scoped lang="scss">
.att-hantera {
	display: flex;
	flex-direction: column;
	gap: 12px;

	&__coverage {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 8px;
		margin: 0;
		font-size: 0.9rem;
		color: var(--color-text-maxcontrast);
	}

	&__coverage-label {
		font-weight: 600;
	}

	&__coverage-chip {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		padding: 2px 8px;
		border-radius: var(--border-radius-pill, 16px);
		background: var(--color-background-hover);
		color: var(--color-main-text);
		border-inline-start: 3px solid var(--hs-chip-color, var(--color-border));
	}

	&__coverage-icon {
		color: var(--hs-chip-color, currentColor);
	}

	&__tabs {
		display: flex;
		flex-wrap: wrap;
		gap: 6px;
	}

	&__tab {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		min-height: 34px;
		padding: 4px 12px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-pill, 16px);
		background: var(--color-main-background);
		color: var(--color-main-text);
		font-size: 0.9rem;
		cursor: pointer;

		&:hover {
			background: var(--color-background-hover);
		}

		&:focus-visible {
			outline: 2px solid var(--color-primary-element);
			outline-offset: 2px;
		}

		&--active {
			background: var(--color-primary-element);
			color: var(--color-primary-element-text);
			border-color: var(--color-primary-element);
		}
	}

	&__tab-count {
		margin-inline-start: 2px;
	}

	&__toolbar {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 12px;
	}

	&__hint {
		font-size: 0.85rem;
		color: var(--color-text-maxcontrast);
	}

	&__sections {
		display: flex;
		flex-direction: column;
		gap: 16px;
	}
}
</style>
