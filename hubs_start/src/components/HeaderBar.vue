<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<header class="header-bar hs-card">
		<div class="header-bar__intro">
			<h1 class="header-bar__greeting">{{ greeting }}</h1>
			<p class="header-bar__date">{{ todayLabel }}</p>
			<p v-if="personaTagline" class="header-bar__tagline">
				<span class="header-bar__persona-chip">{{ personaLabel }}</span>
				{{ personaTagline }}
			</p>
		</div>

		<div class="header-bar__status">
			<PersonaSwitcher
				v-if="demoMode && personas.length"
				:personas="personas"
				:active="activePersona"
				@change="$emit('persona-change', $event)" />

			<LoaChip
				:loa="loa"
				@upgrade="onUpgrade" />

			<span class="header-bar__residency">
				<IconDatabaseLock :size="18" class="header-bar__residency-icon" />
				{{ t('hubs_start', 'All data lagras i er driftmiljö') }}
			</span>
		</div>
	</header>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

import IconDatabaseLock from 'vue-material-design-icons/DatabaseLock.vue'

import LoaChip from './LoaChip.vue'
import PersonaSwitcher from './PersonaSwitcher.vue'

export default {
	name: 'HeaderBar',

	components: {
		LoaChip,
		PersonaSwitcher,
		IconDatabaseLock,
	},

	props: {
		loa: { type: String, default: 'LOA1' },
		profile: { type: String, default: '' },
		demoMode: { type: Boolean, default: false },
		personas: { type: Array, default: () => [] },
		activePersona: { type: String, default: '' },
		personaLabel: { type: String, default: '' },
		personaTagline: { type: String, default: '' },
	},

	computed: {
		greeting() {
			const hour = new Date().getHours()
			if (hour < 10) {
				return t('hubs_start', 'God morgon')
			}
			if (hour < 18) {
				return t('hubs_start', 'God dag')
			}
			return t('hubs_start', 'God kväll')
		},

		todayLabel() {
			const now = new Date()
			try {
				const formatted = now.toLocaleDateString('sv-SE', {
					weekday: 'long',
					day: 'numeric',
					month: 'long',
					year: 'numeric',
				})
				return formatted.charAt(0).toUpperCase() + formatted.slice(1)
			} catch (e) {
				return now.toLocaleDateString()
			}
		},
	},

	methods: {
		t,

		onUpgrade() {
			this.$emit('upgrade-loa')
		},
	},
}
</script>

<style scoped lang="scss">
.header-bar {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-start;
	justify-content: space-between;
	gap: 16px;

	&__intro {
		min-width: 0;
	}

	&__greeting {
		margin: 0;
		font-size: 1.4rem;
		font-weight: 700;
		line-height: 1.2;
	}

	&__date {
		margin: 4px 0 0;
		color: var(--color-text-maxcontrast);
		font-size: 0.95rem;
	}

	&__tagline {
		margin: 8px 0 0;
		font-size: 0.92rem;
		color: var(--color-main-text);
		max-width: 60ch;
		line-height: 1.35;
	}

	&__persona-chip {
		display: inline-block;
		font-size: 0.78rem;
		font-weight: 600;
		padding: 1px 9px;
		margin-inline-end: 8px;
		border-radius: var(--border-radius-pill, 16px);
		background: var(--color-primary-element);
		color: var(--color-primary-element-text);
		vertical-align: middle;
	}

	&__status {
		display: flex;
		flex-direction: column;
		align-items: flex-end;
		gap: 8px;
	}

	&__residency {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		color: var(--color-text-maxcontrast);
		font-size: 0.85rem;
	}

	&__residency-icon {
		flex: 0 0 auto;
	}

	@media (max-width: 680px) {
		&__status {
			align-items: flex-start;
		}
	}
}
</style>
