<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Presentational variant: a campaign / progress card (headline value-of-total,
  - deadline, status breakdown with mini-bars). See docs/DEMO-WIDGETS-CONTRACT.md.
-->
<template>
	<section class="hs-card hs-progress" :aria-label="title">
		<h3 class="hs-card__title">
			<component :is="iconFor(leadIcon)" :size="20" />
			{{ title }}
		</h3>

		<div v-if="headline" class="hs-progress__headline">
			<div class="hs-progress__numbers">
				<span class="hs-progress__value">{{ headline.value }}</span>
				<span v-if="headline.total" class="hs-progress__total">/ {{ headline.total }}</span>
				<span v-if="headline.unit" class="hs-progress__unit">{{ headline.unit }}</span>
			</div>
			<p v-if="headline.caption" class="hs-progress__caption">{{ headline.caption }}</p>
			<div v-if="headline.total" class="hs-progress__bar" role="progressbar"
				:aria-valuenow="pct" aria-valuemin="0" aria-valuemax="100">
				<span class="hs-progress__bar-fill" :style="{ width: pct + '%' }" />
			</div>
		</div>

		<p v-if="descriptor.deadline" class="hs-progress__deadline" :style="{ color: toneColor(descriptor.deadline.tone) }">
			<ClockAlertIcon :size="16" />
			{{ descriptor.deadline.label }}
		</p>

		<ul v-if="breakdown.length" class="hs-progress__breakdown">
			<li v-for="(b, i) in breakdown" :key="i" class="hs-progress__brow">
				<span class="hs-progress__blabel">{{ b.label }}</span>
				<span class="hs-progress__btrack">
					<span class="hs-progress__bfill"
						:style="{ width: barWidth(b.count) + '%', background: toneColor(b.tone) }" />
				</span>
				<span class="hs-progress__bcount">{{ b.count }}</span>
			</li>
		</ul>

		<p v-if="descriptor.note" class="hs-progress__note">{{ descriptor.note }}</p>
	</section>
</template>

<script>
import ClockAlertIcon from 'vue-material-design-icons/ClockAlert.vue'
import { translate as t } from '@nextcloud/l10n'
import { toneColor } from '../services/tones.js'
import { iconFor } from '../services/icons.js'

export default {
	name: 'WidgetProgress',
	components: { ClockAlertIcon },
	props: {
		title: { type: String, default: '' },
		descriptor: { type: Object, required: true },
		leadIcon: { type: String, default: 'ChartBoxOutline' },
	},
	computed: {
		headline() {
			return this.descriptor.headline || null
		},
		breakdown() {
			return Array.isArray(this.descriptor.breakdown) ? this.descriptor.breakdown : []
		},
		pct() {
			const h = this.headline
			if (!h || !h.total) {
				return 0
			}
			return Math.min(100, Math.round((Number(h.value) / Number(h.total)) * 100))
		},
		maxCount() {
			return this.breakdown.reduce((m, b) => Math.max(m, Number(b.count) || 0), 0)
		},
	},
	methods: {
		t,
		toneColor,
		iconFor,
		barWidth(count) {
			if (!this.maxCount) {
				return 0
			}
			return Math.max(4, Math.round((Number(count) / this.maxCount) * 100))
		},
	},
}
</script>

<style scoped lang="scss">
.hs-progress {
	&__headline {
		margin-bottom: 12px;
	}

	&__numbers {
		display: flex;
		align-items: baseline;
		gap: 6px;
	}

	&__value {
		font-size: 2rem;
		font-weight: 700;
		line-height: 1;
	}

	&__total {
		font-size: 1.1rem;
		color: var(--color-text-maxcontrast);
	}

	&__unit {
		font-size: 0.9rem;
		color: var(--color-text-maxcontrast);
	}

	&__caption {
		margin: 4px 0 8px;
		color: var(--color-text-maxcontrast);
		font-size: 0.9rem;
	}

	&__bar {
		height: 10px;
		border-radius: 5px;
		background: var(--color-background-dark);
		overflow: hidden;
	}

	&__bar-fill {
		display: block;
		height: 100%;
		border-radius: 5px;
		background: var(--color-primary-element);
		transition: width 0.3s ease;
	}

	&__deadline {
		display: flex;
		align-items: center;
		gap: 4px;
		font-weight: 600;
		font-size: 0.9rem;
		margin: 0 0 12px;
	}

	&__breakdown {
		list-style: none;
		margin: 0 0 8px;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 6px;
	}

	&__brow {
		display: grid;
		grid-template-columns: minmax(120px, 1.4fr) 2fr auto;
		align-items: center;
		gap: 8px;
	}

	&__blabel {
		font-size: 0.85rem;
	}

	&__btrack {
		height: 8px;
		border-radius: 4px;
		background: var(--color-background-dark);
		overflow: hidden;
	}

	&__bfill {
		display: block;
		height: 100%;
		border-radius: 4px;
	}

	&__bcount {
		font-variant-numeric: tabular-nums;
		font-weight: 600;
		font-size: 0.85rem;
		min-width: 2.5ch;
		text-align: right;
	}

	&__note {
		margin: 0;
		font-size: 0.82rem;
		color: var(--color-text-maxcontrast);
	}
}
</style>
