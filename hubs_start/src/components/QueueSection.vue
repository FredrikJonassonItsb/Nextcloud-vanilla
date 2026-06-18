<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->

<template>
	<section class="queue-section">
		<h3 class="queue-section__header">
			<span class="queue-section__label">{{ label }}</span>
			<NcCounterBubble class="queue-section__count">{{ items.length }}</NcCounterBubble>
		</h3>

		<p v-if="!items.length" class="queue-section__empty">
			{{ emptyText }}
		</p>

		<ul v-else class="queue-section__list">
			<QueueItem
				v-for="item in items"
				:key="item.id"
				:item="item"
				@take="$emit('take', $event)"
				@open="$emit('open', $event)"
				@done="$emit('done', $event)" />
		</ul>
	</section>
</template>

<script>
import NcCounterBubble from '@nextcloud/vue/dist/Components/NcCounterBubble.js'

import QueueItem from './QueueItem.vue'

import { translate as t } from '@nextcloud/l10n'
import { sectionLabel } from '../services/sections.js'

export default {
	name: 'QueueSection',

	components: {
		NcCounterBubble,
		QueueItem,
	},

	props: {
		/** One of SECTIONS (see services/sections.js). */
		section: {
			type: Object,
			required: true,
		},
		/** QueueItems already filtered/grouped for this section. */
		items: {
			type: Array,
			default: () => [],
		},
	},

	computed: {
		label() {
			return sectionLabel(this.section.id)
		},
		/** Section-appropriate one-line empty state. */
		emptyText() {
			switch (this.section.id) {
			case 'kraver_atgard':
				return t('hubs_start', 'Inget kräver åtgärd')
			case 'otilldelat':
				return t('hubs_start', 'Inga ägarlösa ärenden')
			case 'nytt':
				return t('hubs_start', 'Inget nytt')
			case 'bevakas':
				return t('hubs_start', 'Inget bevakas')
			case 'klart_idag':
				return t('hubs_start', 'Inget klart idag ännu')
			default:
				return t('hubs_start', 'Inget att visa')
			}
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped lang="scss">
.queue-section {
	margin-bottom: 20px;

	&__header {
		display: flex;
		align-items: center;
		gap: 8px;
		margin: 0 0 8px;
		font-size: 0.95rem;
		font-weight: 600;
	}

	&__label {
		flex: 0 1 auto;
	}

	&__empty {
		margin: 0;
		padding: 4px 0;
		color: var(--color-text-maxcontrast);
		font-size: 0.9rem;
	}

	&__list {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-direction: column;
	}
}
</style>
