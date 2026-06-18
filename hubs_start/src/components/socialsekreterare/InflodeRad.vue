<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Delad lätt radkomponent (en <li>) för inflöde. Används av både AttHanteraSektion
  - (1b) och EjKoppladSektion (1c). En rad: kanalikon · korg-etikett · avsändare +
  - identitets-badge (ej verifierad = legitimt anonymt tillstånd, ej fel) · inkom-tid ·
  - typ-chip · ärende-koppling · ärvd frist. Första primär-åtgärden som NcButton,
  - övriga i en "Mer"-meny. Aldrig klartext-PII — bara ärendereferenser.
-->
<template>
	<li class="inflode-rad">
		<!-- Kanalikon -->
		<span
			class="inflode-rad__channel"
			:style="{ '--hs-chip-color': 'var(' + channel.colorVar + ')' }"
			:title="channel.label">
			<component :is="channel.icon" :size="22" />
			<span class="inflode-rad__sr-only">{{ channel.label }}</span>
		</span>

		<!-- Body: korg, avsändare, identitet, tid, chips -->
		<div class="inflode-rad__body">
			<p class="inflode-rad__topp">
				<span class="inflode-rad__korg">
					<FolderAccountIcon :size="14" />
					{{ korgLabel }}
				</span>
				<span class="inflode-rad__titel">{{ rad.titel }}</span>
			</p>

			<p class="inflode-rad__meta">
				<span class="inflode-rad__avsandare">{{ rad.avsandare }}</span>
				<span
					class="inflode-rad__identitet"
					:class="identitetClass">
					<component :is="identitetIcon" :size="14" />
					{{ identitetBadge }}
				</span>
				<span class="inflode-rad__inkom">
					<IconInbox :size="14" />
					{{ t('hubs_start', 'inkom {tid}', { tid: inkomTid }) }}
				</span>
			</p>

			<div class="inflode-rad__chips">
				<span class="inflode-rad__typ-chip" :title="messageTypeLabel">
					<TagOutlineIcon :size="13" />
					<span class="inflode-rad__typ-text">{{ messageTypeLabel }}</span>
				</span>
				<KopplingBadge
					:koppling="rad.koppling"
					@open-arende="$emit('open-arende', rad)"
					@bekrafta="$emit('bekrafta', rad)"
					@avvisa="$emit('avvisa', rad)" />
				<FristChip v-if="rad.frist" :frist="rad.frist" />
			</div>
		</div>

		<!-- Åtgärder: primär som knapp, övriga i "Mer"-meny -->
		<div v-if="actions.length" class="inflode-rad__actions">
			<NcButton
				v-if="primarAction"
				type="primary"
				@click="onAction(primarAction)">
				<template v-if="primarAction.icon" #icon>
					<component :is="primarAction.icon" :size="20" />
				</template>
				{{ primarAction.label }}
			</NcButton>

			<NcActions
				v-if="ovrigaActions.length"
				:aria-label="t('hubs_start', 'Fler åtgärder')"
				:menu-name="t('hubs_start', 'Mer')"
				force-menu>
				<template #icon>
					<DotsHorizontalIcon :size="20" />
				</template>
				<NcActionButton
					v-for="action in ovrigaActions"
					:key="action.key"
					@click="onAction(action)">
					<template #icon>
						<component :is="actionIcon(action)" :size="20" />
					</template>
					{{ action.label }}
				</NcActionButton>
			</NcActions>
		</div>
	</li>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcActions from '@nextcloud/vue/dist/Components/NcActions.js'
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js'

import IconInbox from 'vue-material-design-icons/InboxArrowDown.vue'
import FolderAccountIcon from 'vue-material-design-icons/FolderAccount.vue'
import TagOutlineIcon from 'vue-material-design-icons/TagOutline.vue'
import IconVerified from 'vue-material-design-icons/ShieldCheck.vue'
import IconUnverified from 'vue-material-design-icons/AccountQuestion.vue'
import DotsHorizontalIcon from 'vue-material-design-icons/DotsHorizontal.vue'
import CircleMediumIcon from 'vue-material-design-icons/CircleMedium.vue'

import { translate as t } from '@nextcloud/l10n'

import { channelMeta } from '../../services/channels.js'
import KopplingBadge from './KopplingBadge.vue'
import FristChip from './FristChip.vue'

export default {
	name: 'InflodeRad',

	components: {
		NcButton,
		NcActions,
		NcActionButton,
		IconInbox,
		FolderAccountIcon,
		TagOutlineIcon,
		IconVerified,
		IconUnverified,
		DotsHorizontalIcon,
		CircleMediumIcon,
		KopplingBadge,
		FristChip,
	},

	props: {
		/** InflodeRad-shape (se kontraktet i arkitekturen). */
		rad: {
			type: Object,
			required: true,
		},
		/** [{ key, label, primary?:Boolean, icon? }] */
		actions: {
			type: Array,
			default: () => [],
		},
	},

	computed: {
		/** Resolved kanal-presentation (ikon + etikett + färg). */
		channel() {
			return channelMeta(this.rad.channel && this.rad.channel.channel)
		},

		/** Korg-etikett — funktionsadressens läsbara namn. */
		korgLabel() {
			return (this.rad.korg && this.rad.korg.label) || ''
		},

		/** Svensk etikett för meddelandetypen. */
		messageTypeLabel() {
			const m = {
				orosanmalan: this.t('hubs_start', 'Orosanmälan'),
				komplettering: this.t('hubs_start', 'Komplettering'),
				fraga: this.t('hubs_start', 'Fråga'),
				remiss: this.t('hubs_start', 'Remiss'),
				internpost: this.t('hubs_start', 'Internpost'),
				fax: this.t('hubs_start', 'Fax'),
				sdk_myndighet: this.t('hubs_start', 'SDK-myndighet'),
				skrap: this.t('hubs_start', 'Skräp'),
			}
			return m[this.rad.messageType] || this.rad.messageType || ''
		},

		/** Identitets-badge — "Ej verifierad — anonym" är ett legitimt tillstånd. */
		identitetBadge() {
			const identitet = this.rad.identitet || {}
			if (!identitet.verifierad) {
				return identitet.badge || this.t('hubs_start', 'Ej verifierad — anonym')
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
				? 'inflode-rad__identitet--verifierad'
				: 'inflode-rad__identitet--anonym'
		},

		/** Lokaliserad inkom-tid (kort: tid + dag/månad). */
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

		/** Första action med primary:true, annars första action. */
		primarAction() {
			if (!this.actions.length) {
				return null
			}
			return this.actions.find((a) => a.primary) || this.actions[0]
		},

		/** Övriga åtgärder hamnar i "Mer"-menyn. */
		ovrigaActions() {
			const primar = this.primarAction
			return this.actions.filter((a) => a !== primar)
		},
	},

	methods: {
		t,

		/** Garanterad ikon för en menyåtgärd (NcActions kräver att varje barn har en). */
		actionIcon(action) {
			return action.icon || CircleMediumIcon
		},

		onAction(action) {
			this.$emit('action', { key: action.key, rad: this.rad })
		},
	},
}
</script>

<style scoped lang="scss">
.inflode-rad {
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

	&__topp {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 6px 12px;
		margin: 0;
	}

	&__korg {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		font-size: 0.82rem;
		font-weight: 600;
		color: var(--color-text-maxcontrast);
	}

	&__titel {
		font-weight: 600;
		font-size: 0.98rem;
		color: var(--color-main-text);
		overflow: hidden;
		text-overflow: ellipsis;
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

		// Ej verifierad = legitimt anonymt tillstånd (varning-ton, ej fel).
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

	// Typ-chip: samma chip-grammatik som FristChip (pill, ikon + text, color-mix).
	&__typ-chip {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		padding: 2px 9px;
		border-radius: var(--border-radius-pill, 16px);
		border: 1px solid var(--color-border);
		background: color-mix(in srgb, var(--color-border) 12%, var(--color-main-background));
		color: var(--color-main-text);
		font-size: 0.76rem;
		font-weight: 600;
		white-space: nowrap;
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

	// Reflow @720px: body och actions wrappar (ingen horisontell scroll).
	@media (max-width: 720px) {
		flex-wrap: wrap;

		.inflode-rad__actions {
			width: 100%;
			justify-content: flex-start;
		}
	}
}
</style>
