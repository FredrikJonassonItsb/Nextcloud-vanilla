<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Presentational variant: a queue of action rows (title, status, deadline,
  - channel chip, badges, optional primary action). Driven by a demo descriptor
  - (see docs/DEMO-WIDGETS-CONTRACT.md). Emits @action when a row CTA is clicked.
-->
<template>
	<section class="hs-card hs-queue" :aria-label="title">
		<h3 class="hs-card__title">
			<component :is="iconFor(leadIcon)" :size="20" />
			{{ title }}
		</h3>

		<p v-if="descriptor.headerStat" class="hs-queue__headerstat">
			<span class="hs-queue__dot" :style="{ background: toneColor(descriptor.headerStat.tone) }" />
			<strong>{{ descriptor.headerStat.value }}</strong> {{ descriptor.headerStat.label }}
		</p>

		<ul v-if="rows.length" class="hs-queue__list">
			<li v-for="row in rows" :key="row.id" class="hs-queue__row">
				<span v-if="row.channel" class="hs-queue__channel" :title="channelMeta(row.channel).label">
					<component :is="channelMeta(row.channel).icon" :size="18" />
				</span>
				<span class="hs-queue__body">
					<span class="hs-queue__title">{{ row.title }}</span>
					<span v-if="row.subtitle" class="hs-queue__subtitle">{{ row.subtitle }}</span>
					<span v-if="hasMeta(row)" class="hs-queue__meta">
						<span v-if="row.status" class="hs-queue__tag" :style="tagStyle(row.status.tone)">{{ row.status.label }}</span>
						<span v-if="row.deadline" class="hs-queue__deadline" :style="{ color: toneColor(row.deadline.tone) }">
							<ClockOutlineIcon :size="14" />
							{{ row.deadline.label }}
						</span>
						<span v-for="(b, i) in (row.badges || [])" :key="i" class="hs-queue__badge" :style="{ borderColor: toneColor(b.tone) }">
							<component :is="iconFor(b.icon)" v-if="b.icon" :size="13" />
							{{ b.label }}
						</span>
					</span>
				</span>
				<NcButton v-if="row.primaryAction"
					type="secondary"
					class="hs-queue__action"
					@click="$emit('action', { widget: title, row })">
					{{ row.primaryAction.label }}
				</NcButton>
			</li>
		</ul>

		<NcEmptyContent v-else :name="descriptor.emptyText || t('hubs_start', 'Inget att hantera')">
			<template #icon><CheckAllIcon :size="40" /></template>
		</NcEmptyContent>
	</section>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import ClockOutlineIcon from 'vue-material-design-icons/ClockOutline.vue'
import CheckAllIcon from 'vue-material-design-icons/CheckAll.vue'
import { translate as t } from '@nextcloud/l10n'
import { channelMeta } from '../services/channels.js'
import { toneColor } from '../services/tones.js'
import { iconFor } from '../services/icons.js'

export default {
	name: 'WidgetQueue',
	components: { NcButton, NcEmptyContent, ClockOutlineIcon, CheckAllIcon },
	props: {
		title: { type: String, default: '' },
		descriptor: { type: Object, required: true },
		leadIcon: { type: String, default: 'InboxArrowDown' },
	},
	computed: {
		rows() {
			return Array.isArray(this.descriptor.rows) ? this.descriptor.rows : []
		},
	},
	methods: {
		t,
		channelMeta,
		toneColor,
		iconFor,
		hasMeta(row) {
			return row.status || row.deadline || (row.badges && row.badges.length)
		},
		tagStyle(tone) {
			return { background: toneColor(tone), color: '#fff' }
		},
	},
}
</script>

<style scoped lang="scss">
.hs-queue {
	&__headerstat {
		display: flex;
		align-items: center;
		gap: 6px;
		margin: -4px 0 12px;
		font-size: 0.9rem;
		color: var(--color-text-maxcontrast);
	}

	&__dot {
		width: 10px;
		height: 10px;
		border-radius: 50%;
		display: inline-block;
	}

	&__list {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 4px;
	}

	&__row {
		display: flex;
		align-items: center;
		gap: 10px;
		padding: 8px 6px;
		border-radius: var(--border-radius-large, 12px);
		min-height: 44px;

		&:hover {
			background: var(--color-background-hover);
		}
	}

	&__channel {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		min-width: 28px;
		height: 28px;
		border-radius: 8px;
		background: var(--color-background-dark);
		color: var(--color-main-text);
		flex: 0 0 auto;
	}

	&__body {
		display: flex;
		flex-direction: column;
		gap: 2px;
		min-width: 0;
		flex: 1;
	}

	&__title {
		font-weight: 600;
		line-height: 1.25;
	}

	&__subtitle {
		font-size: 0.83rem;
		color: var(--color-text-maxcontrast);
	}

	&__meta {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 6px;
		margin-top: 2px;
	}

	&__tag {
		font-size: 0.72rem;
		font-weight: 600;
		padding: 1px 8px;
		border-radius: var(--border-radius-pill, 16px);
		white-space: nowrap;
	}

	&__deadline {
		display: inline-flex;
		align-items: center;
		gap: 3px;
		font-size: 0.78rem;
		font-weight: 600;
	}

	&__badge {
		display: inline-flex;
		align-items: center;
		gap: 3px;
		font-size: 0.72rem;
		padding: 1px 7px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-pill, 16px);
		color: var(--color-text-maxcontrast);
	}

	&__action {
		flex: 0 0 auto;
	}
}
</style>
