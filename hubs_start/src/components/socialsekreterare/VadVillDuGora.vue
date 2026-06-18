<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<!-- Kollapsad: tunn rad som expanderar igen + liten "stäng tips"-länk -->
	<div
		v-if="collapsed"
		class="vvg vvg--collapsed hs-card">
		<button
			type="button"
			class="vvg__reopen"
			:aria-expanded="false"
			@click="onReopen">
			<HelpCircleOutline :size="18" />
			<span class="vvg__reopen-text">{{ t('hubs_start', 'Vad vill du göra?') }}</span>
			<ChevronRight :size="18" />
		</button>
		<a
			href="#"
			class="vvg__dismiss"
			@click.prevent="onDismiss">
			{{ t('hubs_start', 'stäng tips') }}
		</a>
	</div>

	<!-- Utfälld: fem stora verbknappar -->
	<section
		v-else
		class="vvg hs-card"
		:aria-label="t('hubs_start', 'Vad vill du göra?')">
		<div class="vvg__head">
			<h2 class="hs-card__title vvg__title">
				{{ t('hubs_start', 'Vad vill du göra?') }}
			</h2>
			<a
				href="#"
				class="vvg__dismiss"
				@click.prevent="onDismiss">
				{{ t('hubs_start', 'stäng tips') }}
			</a>
		</div>

		<div class="vvg__grid">
			<button
				v-for="verb in verbs"
				:key="verb.id"
				type="button"
				class="vvg__verb hs-target"
				@click="onVerb(verb.id)">
				<span class="vvg__verb-icon" aria-hidden="true">
					<component :is="iconFor(verb.icon)" :size="28" />
				</span>
				<span class="vvg__verb-label">{{ verb.label }}</span>
				<span v-if="verb.sub" class="vvg__verb-sub">{{ verb.sub }}</span>
			</button>
		</div>
	</section>
</template>

<script>
import HelpCircleOutline from 'vue-material-design-icons/HelpCircleOutline.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import { translate as t } from '@nextcloud/l10n'

import { iconFor } from '../../services/icons.js'

export default {
	name: 'VadVillDuGora',

	components: {
		HelpCircleOutline,
		ChevronRight,
	},

	props: {
		dismissed: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			// Lokal override: tillåter att den vane fäller ut den kollapsade raden
			// igen utan att röra preferensen (som styrs av @dismiss i containern).
			reopened: false,
		}
	},

	computed: {
		collapsed() {
			return this.dismissed && !this.reopened
		},

		/** De fem verben = hela yrket. Ikon + verb + undertext. */
		verbs() {
			return [
				{
					id: 'taEmot',
					icon: 'InboxArrowDown',
					label: t('hubs_start', 'Ta emot anmälan'),
					sub: t('hubs_start', 'starta förhandsbedömning'),
				},
				{
					id: 'utredning',
					icon: 'FileDocumentEdit',
					label: t('hubs_start', 'Arbeta med utredning'),
					sub: '',
				},
				{
					id: 'mote',
					icon: 'VideoPlus',
					label: t('hubs_start', 'Boka möte'),
					sub: '',
				},
				{
					id: 'signera',
					icon: 'FileSign',
					label: t('hubs_start', 'Signera beslut'),
					sub: '',
				},
				{
					id: 'foljUpp',
					icon: 'BellRing',
					label: t('hubs_start', 'Följ upp'),
					sub: '',
				},
			]
		},
	},

	watch: {
		// Om containern fäller ihop oss på nytt, nollställ den lokala utfällningen.
		dismissed(now) {
			if (now) {
				this.reopened = false
			}
		},
	},

	methods: {
		t,
		iconFor,

		onVerb(id) {
			this.$emit('verb', id)
		},

		onDismiss() {
			this.reopened = false
			this.$emit('dismiss')
		},

		onReopen() {
			this.reopened = true
		},
	},
}
</script>

<style scoped lang="scss">
.vvg {
	&__head {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
		margin-bottom: 12px;
	}

	&__title {
		margin: 0;
	}

	&__dismiss {
		flex: 0 0 auto;
		font-size: 0.85rem;
		color: var(--color-text-maxcontrast);
		text-decoration: none;
		padding: 4px 6px;
		border-radius: var(--border-radius, 6px);

		&:hover,
		&:focus-visible {
			color: var(--color-main-text);
			text-decoration: underline;
		}
	}

	/* Fem jämnbreda kort i rad; wrap till flera rader i smal vy. */
	&__grid {
		display: grid;
		grid-template-columns: repeat(5, minmax(0, 1fr));
		gap: 12px;

		@media (max-width: 900px) {
			grid-template-columns: repeat(3, minmax(0, 1fr));
		}

		@media (max-width: 560px) {
			grid-template-columns: repeat(2, minmax(0, 1fr));
		}
	}

	&__verb {
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: flex-start;
		gap: 8px;
		min-height: 96px;
		padding: 16px 12px;
		text-align: center;
		cursor: pointer;
		background: var(--color-background-hover);
		border: 2px solid var(--color-border);
		border-radius: var(--hs-card-radius);
		color: var(--color-main-text);
		transition: border-color 0.1s ease, background-color 0.1s ease;

		&:hover {
			background: var(--color-primary-element-light);
			border-color: var(--color-primary-element);
		}

		&:focus-visible {
			outline: 2px solid var(--color-primary-element);
			outline-offset: 2px;
		}
	}

	&__verb-icon {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		width: 44px;
		height: 44px;
		border-radius: 50%;
		background: var(--color-primary-element-light);
		color: var(--color-primary-element);
	}

	&__verb-label {
		font-weight: 600;
		font-size: 0.95rem;
		line-height: 1.2;
	}

	&__verb-sub {
		font-size: 0.8rem;
		color: var(--color-text-maxcontrast);
		line-height: 1.2;
	}

	/* Kollapsad tunn rad */
	&--collapsed {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
		padding: 8px 16px;
	}

	&__reopen {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		min-height: var(--hs-min-target);
		padding: 4px 6px;
		background: transparent;
		border: none;
		border-radius: var(--border-radius, 6px);
		color: var(--color-main-text);
		font-size: 0.95rem;
		font-weight: 500;
		cursor: pointer;

		&:hover,
		&:focus-visible {
			background: var(--color-background-hover);
		}

		&:focus-visible {
			outline: 2px solid var(--color-primary-element);
			outline-offset: 1px;
		}
	}

	&__reopen-text {
		white-space: nowrap;
	}
}
</style>
