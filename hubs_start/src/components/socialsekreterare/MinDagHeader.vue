<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->

<template>
	<header class="min-dag-header hs-card">
		<div class="min-dag-header__rad">
			<h1 class="min-dag-header__halsning">{{ halsning }}</h1>

			<div class="min-dag-header__hoger">
				<!-- Datasuveränitets-prick (statisk) -->
				<span class="min-dag-header__suveranitet" role="status">
					<ShieldLockIcon :size="18" class="min-dag-header__suveranitet-ikon" aria-hidden="true" />
					<span class="min-dag-header__suveranitet-text">{{ suveranitetText }}</span>
				</span>

				<LoaChip :loa="loa" @upgrade="$emit('upgrade-loa')" />

				<NcButton type="tertiary"
					class="min-dag-header__knapp"
					:aria-label="t('hubs_start', 'Sök & kommandon (Ctrl eller Cmd + K)')"
					:title="t('hubs_start', 'Sök & kommandon (Ctrl/⌘K)')"
					@click="$emit('open-palette')">
					<template #icon>
						<MagnifyIcon :size="20" />
					</template>
					<span class="min-dag-header__knapp-text">{{ t('hubs_start', 'Ctrl/⌘K') }}</span>
				</NcButton>

				<NcButton type="tertiary"
					class="min-dag-header__knapp hs-target"
					:aria-label="t('hubs_start', 'Hjälp')"
					:title="t('hubs_start', 'Hjälp')"
					@click="$emit('open-help')">
					<template #icon>
						<HelpIcon :size="20" />
					</template>
				</NcButton>
			</div>
		</div>

		<!-- Dagspulsen — fyra klickbara räknare/filter -->
		<Dagspulsen
			:puls="puls"
			:aktivt-filter="aktivtFilter"
			@filter="$emit('filter', $event)" />
	</header>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'

import ShieldLockIcon from 'vue-material-design-icons/ShieldLock.vue'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'
import HelpIcon from 'vue-material-design-icons/HelpCircleOutline.vue'

import LoaChip from '../LoaChip.vue'
import Dagspulsen from './Dagspulsen.vue'

export default {
	name: 'MinDagHeader',

	components: {
		NcButton,
		ShieldLockIcon,
		MagnifyIcon,
		HelpIcon,
		LoaChip,
		Dagspulsen,
	},

	props: {
		loa: {
			type: String,
			default: 'LOA1',
		},
		profile: {
			type: String,
			default: '',
		},
		/** { fristerBrinner, motenIdag, attSignera, nyaInflode } */
		puls: {
			type: Object,
			default: () => ({}),
		},
		/** Active list filter from Dagspulsen, or null. */
		aktivtFilter: {
			type: String,
			default: null,
		},
	},

	computed: {
		/** Demo name per spec. */
		namn() {
			return 'Anna'
		},

		/** "God morgon, Anna · måndag 14 juni" — localised. */
		halsning() {
			const now = new Date()
			let datum = ''
			try {
				const veckodag = new Intl.DateTimeFormat('sv-SE', { weekday: 'long' }).format(now)
				const dag = new Intl.DateTimeFormat('sv-SE', { day: 'numeric', month: 'long' }).format(now)
				datum = veckodag + ' ' + dag
			} catch (e) {
				datum = now.toLocaleDateString()
			}
			return this.t('hubs_start', '{tidpunkt}, {namn} · {datum}', {
				tidpunkt: this.tidpunktHalsning,
				namn: this.namn,
				datum,
			})
		},

		/** Time-of-day greeting (God morgon / God dag / God kväll). */
		tidpunktHalsning() {
			const h = new Date().getHours()
			if (h < 10) {
				return this.t('hubs_start', 'God morgon')
			}
			if (h < 18) {
				return this.t('hubs_start', 'God dag')
			}
			return this.t('hubs_start', 'God kväll')
		},

		suveranitetText() {
			return this.t('hubs_start', 'Säker kanal · all data i er driftmiljö')
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped lang="scss">
.min-dag-header {
	position: sticky;
	top: 0;
	z-index: 10;
	background: var(--color-main-background);
	display: flex;
	flex-direction: column;
	gap: 12px;

	&__rad {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
	}

	&__halsning {
		margin: 0;
		font-size: 1.25rem;
		font-weight: 600;
		line-height: 1.3;
	}

	&__hoger {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 10px;
	}

	&__suveranitet {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		min-height: var(--hs-min-target);
		padding: 2px 10px;
		border-radius: var(--border-radius-pill, 16px);
		font-size: 0.82rem;
		color: var(--hs-status-success);
		background: rgba(45, 125, 68, 0.10);
		border: 1px solid var(--hs-status-success);
	}

	&__suveranitet-ikon {
		flex: 0 0 auto;
	}

	&__knapp-text {
		font-size: 0.85rem;
	}
}

// Datasuveränitetstexten döljs på smala skärmar; ikonen + status kvarstår.
@media (max-width: 560px) {
	.min-dag-header__suveranitet-text {
		display: none;
	}
}
</style>
