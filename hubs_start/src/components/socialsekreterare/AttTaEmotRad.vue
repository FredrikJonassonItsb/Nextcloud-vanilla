<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->

<template>
	<li class="att-ta-emot-rad">
		<!-- Kanalikon -->
		<span
			class="att-ta-emot-rad__channel"
			:style="{ '--hs-chip-color': 'var(' + channel.colorVar + ')' }"
			:title="channel.label">
			<component :is="channel.icon" :size="22" />
			<span class="att-ta-emot-rad__sr-only">{{ channel.label }}</span>
		</span>

		<!-- Identitet + metadata -->
		<div class="att-ta-emot-rad__body">
			<p class="att-ta-emot-rad__titel">{{ rad.titel }}</p>

			<p class="att-ta-emot-rad__meta">
				<span class="att-ta-emot-rad__avsandare">{{ rad.avsandare }}</span>
				<span
					class="att-ta-emot-rad__identitet"
					:class="identitetClass">
					<component :is="identitetIcon" :size="14" />
					{{ identitetBadge }}
				</span>
				<span class="att-ta-emot-rad__inkom">
					<IconInbox :size="14" />
					{{ t('hubs_start', 'inkom {tid}', { tid: inkomTid }) }}
				</span>
			</p>

			<div class="att-ta-emot-rad__chips">
				<FristChip v-if="rad.frist" :frist="rad.frist" />
				<ProvenansChip :provenance="rad.provenance" />
			</div>
		</div>

		<!-- Åtgärder -->
		<div class="att-ta-emot-rad__actions">
			<NcButton
				type="primary"
				class="att-ta-emot-rad__start"
				@click="onTriage('start')">
				<template #icon>
					<IconStart :size="20" />
				</template>
				{{ t('hubs_start', 'Ta emot & starta förhandsbedömning') }}
			</NcButton>
			<NcButton
				type="secondary"
				@click="onTriage('koppla')">
				<template #icon>
					<IconLink :size="20" />
				</template>
				{{ t('hubs_start', 'Koppla till befintligt ärende') }}
			</NcButton>
			<NcButton
				type="tertiary"
				:aria-label="t('hubs_start', 'Visa inkommande: {titel}', { titel: rad.titel })"
				@click="onOpen">
				<template #icon>
					<IconShow :size="20" />
				</template>
				{{ t('hubs_start', 'Visa') }}
			</NcButton>
		</div>
	</li>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'

import IconInbox from 'vue-material-design-icons/InboxArrowDown.vue'
import IconStart from 'vue-material-design-icons/PlayCircleOutline.vue'
import IconLink from 'vue-material-design-icons/LinkVariant.vue'
import IconShow from 'vue-material-design-icons/EyeOutline.vue'
import IconVerified from 'vue-material-design-icons/ShieldCheck.vue'
import IconUnverified from 'vue-material-design-icons/AccountQuestion.vue'

import { translate as t } from '@nextcloud/l10n'

import { channelMeta } from '../../services/channels.js'
import FristChip from './FristChip.vue'
import ProvenansChip from './ProvenansChip.vue'

export default {
	name: 'AttTaEmotRad',

	components: {
		NcButton,
		IconInbox,
		IconStart,
		IconLink,
		IconShow,
		IconVerified,
		IconUnverified,
		FristChip,
		ProvenansChip,
	},

	props: {
		rad: {
			type: Object,
			required: true,
		},
	},

	computed: {
		/** Resolved channel presentation descriptor (icon + label + colour). */
		channel() {
			return channelMeta(this.rad.channel && this.rad.channel.channel)
		},

		/** Identitets-badge text — legitimate "Ej verifierad — anonym" when unverified. */
		identitetBadge() {
			const identitet = this.rad.identitet || {}
			if (!identitet.verifierad) {
				return identitet.badge || t('hubs_start', 'Ej verifierad — anonym')
			}
			return identitet.badge
		},

		identitetVerifierad() {
			return !!(this.rad.identitet && this.rad.identitet.verifierad)
		},

		identitetIcon() {
			return this.identitetVerifierad ? 'IconVerified' : 'IconUnverified'
		},

		identitetClass() {
			return this.identitetVerifierad
				? 'att-ta-emot-rad__identitet--verifierad'
				: 'att-ta-emot-rad__identitet--anonym'
		},

		/** Localised incoming time (date + time, e.g. "kl. 07:58 · 14 jun"). */
		inkomTid() {
			const raw = this.rad.inkomDatum
			if (!raw) {
				return ''
			}
			const d = new Date(raw)
			if (isNaN(d.getTime())) {
				return String(raw)
			}
			return d.toLocaleString('sv-SE', {
				hour: '2-digit',
				minute: '2-digit',
				day: 'numeric',
				month: 'short',
			})
		},
	},

	methods: {
		t,

		onTriage(mode) {
			this.$emit('triage', this.rad, mode)
		},

		onOpen() {
			this.$emit('open', this.rad)
		},
	},
}
</script>

<style scoped lang="scss">
.att-ta-emot-rad {
	display: flex;
	align-items: flex-start;
	gap: 12px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-main-background);
	list-style: none;

	&:hover {
		background: var(--color-background-hover);
	}

	&__channel {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		flex: 0 0 auto;
		width: 36px;
		height: 36px;
		border-radius: var(--border-radius, 8px);
		color: var(--hs-chip-color, var(--color-main-text));
		background: var(--color-background-dark);
	}

	&__body {
		flex: 1 1 auto;
		min-width: 0;
		display: flex;
		flex-direction: column;
		gap: 6px;
	}

	&__titel {
		margin: 0;
		font-weight: 600;
		font-size: 0.98rem;
		color: var(--color-main-text);
	}

	&__meta {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 6px 12px;
		margin: 0;
		font-size: 0.85rem;
		color: var(--color-text-maxcontrast);
	}

	&__identitet {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		font-weight: 600;

		&--verifierad {
			color: var(--hs-status-success);
		}

		&--anonym {
			color: var(--hs-status-warning);
		}
	}

	&__inkom {
		display: inline-flex;
		align-items: center;
		gap: 4px;
	}

	&__chips {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 8px;
		margin-top: 2px;
	}

	&__actions {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 8px;
		flex: 0 0 auto;
		justify-content: flex-end;
	}

	&__sr-only {
		position: absolute;
		width: 1px;
		height: 1px;
		padding: 0;
		margin: -1px;
		overflow: hidden;
		clip: rect(0, 0, 0, 0);
		white-space: nowrap;
		border: 0;
	}

	// Reflow: stack actions under the body in narrow / portrait (hembesök, 400 %).
	@media (max-width: 720px) {
		flex-wrap: wrap;

		.att-ta-emot-rad__actions {
			width: 100%;
			justify-content: flex-start;
		}
	}
}
</style>
