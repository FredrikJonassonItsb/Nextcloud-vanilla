<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->

<template>
	<div class="dagspulsen" role="group" :aria-label="t('hubs_start', 'Dagens läge — fem räknare, klicka för att filtrera')">
		<button
			v-for="r in raknare"
			:key="r.key"
			type="button"
			class="dagspulsen__piller hs-target"
			:class="{
				'dagspulsen__piller--aktiv': aktivtFilter === r.key,
				'dagspulsen__piller--het': r.het,
			}"
			:style="r.aktiv ? { '--hs-puls-accent': r.accent } : null"
			:aria-pressed="aktivtFilter === r.key ? 'true' : 'false'"
			:aria-label="r.ariaLabel"
			@click="$emit('filter', r.key)">
			<span class="dagspulsen__ikon" aria-hidden="true">
				<component :is="r.icon" :size="22" />
			</span>
			<span class="dagspulsen__tal">{{ r.tal }}</span>
			<span class="dagspulsen__etikett">{{ r.etikett }}</span>
		</button>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

import { iconFor } from '../../services/icons.js'
import { toneColor } from '../../services/tones.js'

import IconVideo from 'vue-material-design-icons/Video.vue'
import IconAt from 'vue-material-design-icons/At.vue'

export default {
	name: 'Dagspulsen',

	props: {
		/** { fristerBrinner, motenIdag, attSignera, nyaInflode, omnamnanden } */
		puls: {
			type: Object,
			default: () => ({ fristerBrinner: 0, motenIdag: 0, attSignera: 0, nyaInflode: 0, omnamnanden: 0 }),
		},
		/** Active list filter, or null. */
		aktivtFilter: {
			type: String,
			default: null,
		},
	},

	computed: {
		raknare() {
			const p = this.puls || {}
			const frister = Number(p.fristerBrinner || 0)
			const moten = Number(p.motenIdag || 0)
			const signera = Number(p.attSignera || 0)
			const nya = Number(p.nyaInflode || 0)
			const omnamnanden = Number(p.omnamnanden || 0)

			return [
				{
					key: 'frist',
					icon: iconFor('ClockAlert'),
					tal: frister,
					etikett: this.t('hubs_start', 'frister brinner'),
					het: frister > 0,
					accent: toneColor('error'),
					ariaLabel: this.t('hubs_start', '{n} frister brinner — filtrera listan', { n: frister }),
				},
				{
					key: 'mote',
					icon: IconVideo,
					tal: moten,
					etikett: this.t('hubs_start', 'möten idag'),
					het: false,
					accent: toneColor('info'),
					ariaLabel: this.t('hubs_start', '{n} möten idag — filtrera listan', { n: moten }),
				},
				{
					key: 'signera',
					icon: iconFor('FileSign'),
					tal: signera,
					etikett: this.t('hubs_start', 'att signera'),
					het: false,
					accent: toneColor('info'),
					ariaLabel: this.t('hubs_start', '{n} att signera — filtrera listan', { n: signera }),
				},
				{
					key: 'inflode',
					icon: iconFor('InboxArrowDown'),
					tal: nya,
					etikett: this.t('hubs_start', 'nya'),
					het: false,
					accent: toneColor('info'),
					ariaLabel: this.t('hubs_start', '{n} nya att ta emot — filtrera listan', { n: nya }),
				},
				{
					key: 'omnamnanden',
					icon: IconAt,
					tal: omnamnanden,
					etikett: this.t('hubs_start', 'omnämnanden'),
					het: false,
					accent: toneColor('info'),
					ariaLabel: this.t('hubs_start', '{n} omnämnanden väntar på dig — filtrera listan', { n: omnamnanden }),
				},
			].map((r) => ({ ...r, aktiv: this.aktivtFilter === r.key }))
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped lang="scss">
.dagspulsen {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;

	&__piller {
		flex: 1 1 0;
		min-width: 140px;
		min-height: 44px;
		display: grid;
		grid-template-columns: auto 1fr;
		grid-template-rows: auto auto;
		align-items: center;
		column-gap: 10px;
		row-gap: 0;
		padding: 8px 14px;
		border: 2px solid var(--color-border);
		border-radius: var(--border-radius-large, 12px);
		background: var(--color-main-background);
		color: var(--color-main-text);
		cursor: pointer;
		text-align: start;
		transition: background 0.1s ease, border-color 0.1s ease;

		&:hover {
			background: var(--color-background-hover);
		}

		&:focus-visible {
			outline: 2px solid var(--color-primary-element);
			outline-offset: 2px;
		}

		// Active filter = filled accent + ring (never colour alone — ikon+tal+text bär informationen).
		&--aktiv {
			border-color: var(--hs-puls-accent, var(--color-primary-element));
			background: var(--color-primary-element-light, var(--color-background-hover));
			color: var(--color-main-text);
			font-weight: 700;
		}
	}

	&__ikon {
		grid-row: 1 / span 2;
		display: inline-flex;
		align-items: center;
		justify-content: center;
		color: var(--color-text-maxcontrast);
	}

	// Röd när frister brinner (>0): ikon + tal markeras, men text "frister brinner" bär samma signal.
	&__piller--het &__ikon,
	&__piller--het &__tal {
		color: var(--hs-status-error);
	}

	&__piller--aktiv &__ikon {
		color: var(--hs-puls-accent, var(--color-primary-element));
	}

	&__tal {
		grid-column: 2;
		grid-row: 1;
		font-size: 1.35rem;
		font-weight: 700;
		line-height: 1.1;
	}

	&__etikett {
		grid-column: 2;
		grid-row: 2;
		font-size: 0.78rem;
		color: var(--color-text-maxcontrast);
		line-height: 1.2;
	}

	&__piller--aktiv &__etikett {
		color: var(--color-main-text);
	}
}

// Reflow: wrap till 2×2 i smal vy.
@media (max-width: 560px) {
	.dagspulsen__piller {
		flex: 1 1 calc(50% - 8px);
		min-width: calc(50% - 8px);
	}
}
</style>
