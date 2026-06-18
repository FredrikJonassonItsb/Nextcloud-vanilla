<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<section class="hs-card nytta" aria-labelledby="nytta-title">
		<h3 id="nytta-title" class="hs-card__title">
			<ChartLineIcon :size="20" />
			{{ t('hubs_start', 'Nytta hittills') }}
		</h3>

		<!-- Headline numbers: replaced fax/letters + saved time -->
		<ul class="nytta__stats">
			<li class="nytta__stat">
				<span class="nytta__value">{{ replacedFax }}</span>
				<span class="nytta__label">{{ t('hubs_start', 'Ersatta fax') }}</span>
			</li>
			<li class="nytta__stat">
				<span class="nytta__value">{{ replacedLetters }}</span>
				<span class="nytta__label">{{ t('hubs_start', 'Ersatta pappersbrev') }}</span>
			</li>
			<li class="nytta__stat">
				<span class="nytta__value">{{ savedTimeLabel }}</span>
				<span class="nytta__label">{{ t('hubs_start', 'Uppskattad sparad tid') }}</span>
			</li>
		</ul>

		<!-- Volume per channel -->
		<h4 class="nytta__subtitle">
			{{ t('hubs_start', 'Volym per kanal') }}
		</h4>
		<ul class="nytta__channels">
			<li v-for="row in channelRows" :key="row.id" class="nytta__channel">
				<span class="nytta__channel-icon"
					:style="{ color: 'var(' + row.colorVar + ')' }">
					<component :is="row.icon" :size="18" />
				</span>
				<span class="nytta__channel-label">{{ row.label }}</span>
				<span class="nytta__bar" aria-hidden="true">
					<span class="nytta__bar-fill"
						:style="{ width: barWidth(row.count), background: 'var(' + row.colorVar + ')' }" />
				</span>
				<span class="nytta__channel-count">{{ row.count }}</span>
			</li>
		</ul>

		<!-- Schablon explanation + indicative note -->
		<p class="nytta__note">
			{{ t('hubs_start', 'Beräknat enligt Diggs schablon om 30 minuter sparad handläggningstid per digitalt ärende.') }}
		</p>
		<p class="nytta__indicative">
			<InformationOutlineIcon :size="16" class="nytta__indicative-icon" />
			{{ t('hubs_start', 'Siffrorna är indikativa och ersätts av verklig statistik när statistik-tjänsten är på plats.') }}
		</p>
	</section>
</template>

<script>
import ChartLineIcon from 'vue-material-design-icons/ChartLine.vue'
import InformationOutlineIcon from 'vue-material-design-icons/InformationOutline.vue'

import { translate as t, translatePlural as n } from '@nextcloud/l10n'

import store from '../store/index.js'
import { CHANNEL_ORDER, channelMeta } from '../services/channels.js'

/** Diggs schablon: minutes saved per digital case. */
const SCHABLON_MINUTES = 30

/**
 * Documented placeholder volumes per channel, used until the stats endpoint
 * lands. Clearly labelled as indicative in the UI. If the store ever exposes a
 * real `nytta` payload it is preferred (see CONTRACTS.md → NyttaWidget).
 */
const PLACEHOLDER_VOLUME = {
	sdk: 142,
	secure: 86,
	internal: 54,
	fax: 0,
	sms: 23,
}

const PLACEHOLDER_REPLACED_FAX = 318
const PLACEHOLDER_REPLACED_LETTERS = 207

export default {
	name: 'NyttaWidget',

	components: {
		ChartLineIcon,
		InformationOutlineIcon,
	},

	data() {
		return {
			store,
			state: store.state,
		}
	},

	computed: {
		/** Optional real stats payload, if the store ever provides one. */
		nytta() {
			return this.state.nytta || null
		},

		replacedFax() {
			return this.nytta?.replacedFax ?? PLACEHOLDER_REPLACED_FAX
		},

		replacedLetters() {
			return this.nytta?.replacedLetters ?? PLACEHOLDER_REPLACED_LETTERS
		},

		/** Per-channel volume rows in canonical channel order. */
		channelRows() {
			const volume = this.nytta?.volumePerChannel || PLACEHOLDER_VOLUME
			return CHANNEL_ORDER.map((id) => {
				const meta = channelMeta(id)
				return {
					id,
					label: meta.label,
					icon: meta.icon,
					colorVar: meta.colorVar,
					count: Number(volume[id] || 0),
				}
			})
		},

		/** Total digital volume across channels (drives saved-time estimate). */
		totalVolume() {
			return this.channelRows.reduce((sum, row) => sum + row.count, 0)
		},

		/** Largest single-channel count, for proportional bar widths. */
		maxChannelCount() {
			return this.channelRows.reduce((max, row) => Math.max(max, row.count), 0)
		},

		/** Saved minutes per Diggs 30-min schablon. */
		savedMinutes() {
			return this.nytta?.savedMinutes ?? (this.totalVolume * SCHABLON_MINUTES)
		},

		/** Human-friendly saved-time label (hours, rounded). */
		savedTimeLabel() {
			const hours = Math.round(this.savedMinutes / 60)
			return this.n('hubs_start', '{hours} timme', '{hours} timmar', hours, { hours })
		},
	},

	methods: {
		/** Proportional bar width as a CSS percentage string. */
		barWidth(count) {
			if (!this.maxChannelCount) {
				return '0%'
			}
			const pct = Math.round((count / this.maxChannelCount) * 100)
			return Math.max(pct, count > 0 ? 4 : 0) + '%'
		},

		t,
		n,
	},
}
</script>

<style scoped lang="scss">
.nytta {
	&__stats {
		list-style: none;
		margin: 0 0 16px;
		padding: 0;
		display: grid;
		grid-template-columns: repeat(3, 1fr);
		gap: 12px;
	}

	&__stat {
		display: flex;
		flex-direction: column;
		gap: 2px;
		padding: 8px 0;
	}

	&__value {
		font-size: 1.5rem;
		font-weight: 700;
		line-height: 1.1;
		color: var(--color-main-text);
	}

	&__label {
		font-size: 0.8rem;
		color: var(--color-text-maxcontrast);
	}

	&__subtitle {
		font-size: 0.9rem;
		font-weight: 600;
		margin: 0 0 8px;
		color: var(--color-main-text);
	}

	&__channels {
		list-style: none;
		margin: 0 0 16px;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 8px;
	}

	&__channel {
		display: grid;
		grid-template-columns: 20px minmax(120px, 1fr) 2fr auto;
		align-items: center;
		gap: 8px;
	}

	&__channel-icon {
		display: inline-flex;
		align-items: center;
	}

	&__channel-label {
		font-size: 0.85rem;
		color: var(--color-main-text);
	}

	&__bar {
		display: block;
		height: 8px;
		border-radius: 4px;
		background: var(--color-background-dark);
		overflow: hidden;
	}

	&__bar-fill {
		display: block;
		height: 100%;
		border-radius: 4px;
		min-width: 0;
		transition: width 0.3s ease;
	}

	&__channel-count {
		font-size: 0.85rem;
		font-variant-numeric: tabular-nums;
		font-weight: 600;
		text-align: right;
		min-width: 2.5ch;
		color: var(--color-main-text);
	}

	&__note {
		font-size: 0.8rem;
		color: var(--color-text-maxcontrast);
		margin: 0 0 8px;
		line-height: 1.4;
	}

	&__indicative {
		display: flex;
		align-items: flex-start;
		gap: 6px;
		font-size: 0.8rem;
		font-style: italic;
		color: var(--color-text-maxcontrast);
		margin: 0;
		line-height: 1.4;
	}

	&__indicative-icon {
		flex: 0 0 auto;
		margin-top: 1px;
	}
}
</style>
