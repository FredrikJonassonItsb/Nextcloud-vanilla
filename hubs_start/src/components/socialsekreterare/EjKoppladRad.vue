<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - En rad i "Ej ärendekopplat"-hinken (Zon 1c). Bygger på InflodeRad-grammatiken
  - men bär de SEX åtgärderna som tömmer hinken: koppla, skapa, besvara,
  - vidarebefordra, gallra (via juridisk grind) och registrera. Den föreslagna
  - åtgärden (rad.foreslagenAtgard) lyfts fram som primärknapp; resten samlas i en
  - "Mer"-meny. Ett synligt "varför" (rad.klassning.varfor) gör förslaget granskbart.
-->
<template>
	<li class="ej-kopplad-rad" :class="{ 'ej-kopplad-rad--old': overSla }">
		<!-- Kanalikon -->
		<span
			class="ej-kopplad-rad__channel"
			:style="{ '--hs-chip-color': 'var(' + channel.colorVar + ')' }"
			:title="channel.label">
			<component :is="channel.icon" :size="22" />
			<span class="ej-kopplad-rad__sr-only">{{ channel.label }}</span>
		</span>

		<!-- Identitet + metadata -->
		<div class="ej-kopplad-rad__body">
			<p class="ej-kopplad-rad__titel">{{ rad.titel }}</p>

			<p class="ej-kopplad-rad__meta">
				<!-- Korg-etikett -->
				<span class="ej-kopplad-rad__korg">
					<InboxIcon :size="14" />
					{{ korgLabel }}
				</span>

				<!-- Avsändare + identitets-badge (anonym = legitimt tillstånd, ej fel) -->
				<span class="ej-kopplad-rad__avsandare">{{ rad.avsandare }}</span>
				<span class="ej-kopplad-rad__identitet" :class="identitetClass">
					<component :is="identitetIcon" :size="14" />
					{{ identitetBadge }}
				</span>

				<!-- Inkom-tid -->
				<span class="ej-kopplad-rad__inkom">
					<InboxArrowDownIcon :size="14" />
					{{ t('hubs_start', 'inkom {tid}', { tid: inkomTid }) }}
				</span>

				<!-- Ålder mot SLA: röd när äldre än en arbetsdag -->
				<span
					v-if="alderText"
					class="ej-kopplad-rad__alder"
					:class="{ 'ej-kopplad-rad__alder--old': overSla }">
					<ClockAlertOutlineIcon v-if="overSla" :size="14" />
					<ClockOutlineIcon v-else :size="14" />
					{{ alderText }}
				</span>
			</p>

			<!-- Chips: typ-chip + koppling + ärvd frist -->
			<div class="ej-kopplad-rad__chips">
				<span
					class="ej-kopplad-rad__typ-chip"
					:class="{ 'ej-kopplad-rad__typ-chip--oklassad': !arKlassad }"
					:title="typChipTitle">
					<TagOutlineIcon v-if="arKlassad" :size="13" />
					<HelpRhombusOutlineIcon v-else :size="13" />
					<span>{{ messageTypeLabel }}</span>
					<span v-if="!arKlassad" class="ej-kopplad-rad__sr-only"> — {{ t('hubs_start', 'oklassad') }}</span>
				</span>
				<KopplingBadge
					:koppling="rad.koppling"
					@open-arende="$emit('koppla', rad)"
					@bekrafta="$emit('koppla', { rad, hubsCaseId: (rad.koppling && rad.koppling.hubsCaseId) || null })"
					@avvisa="$emit('avvisa-forslag', rad)" />
				<FristChip v-if="rad.frist" :frist="rad.frist" />
			</div>

			<!-- Synligt "varför" — gör klassningen och förslaget granskbart -->
			<p v-if="varfor" class="ej-kopplad-rad__varfor">
				<LightbulbOnOutlineIcon :size="14" />
				<span>{{ varfor }}</span>
			</p>
		</div>

		<!-- Åtgärder: föreslagen som primär + "Mer"-meny med de övriga fem -->
		<div class="ej-kopplad-rad__actions">
			<NcButton
				type="primary"
				class="ej-kopplad-rad__primar"
				@click="onAction(foreslagenKey)">
				<template #icon>
					<component :is="actionIcon(foreslagenKey)" :size="20" />
				</template>
				{{ actionLabel(foreslagenKey) }}
			</NcButton>

			<NcActions
				:menu-name="t('hubs_start', 'Mer')"
				:aria-label="t('hubs_start', 'Fler åtgärder för {titel}', { titel: rad.titel })">
				<template #icon>
					<DotsHorizontalIcon :size="20" />
				</template>
				<NcActionButton
					v-for="key in ovrigaKeys"
					:key="key"
					:close-after-click="true"
					@click="onAction(key)">
					<template #icon>
						<component :is="actionIcon(key)" :size="20" />
					</template>
					{{ actionLabel(key) }}
				</NcActionButton>
			</NcActions>
		</div>
	</li>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcActions from '@nextcloud/vue/dist/Components/NcActions.js'
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js'

import InboxIcon from 'vue-material-design-icons/Inbox.vue'
import InboxArrowDownIcon from 'vue-material-design-icons/InboxArrowDown.vue'
import TagOutlineIcon from 'vue-material-design-icons/TagOutline.vue'
import ClockOutlineIcon from 'vue-material-design-icons/ClockOutline.vue'
import ClockAlertOutlineIcon from 'vue-material-design-icons/ClockAlertOutline.vue'
import LightbulbOnOutlineIcon from 'vue-material-design-icons/LightbulbOnOutline.vue'
import HelpRhombusOutlineIcon from 'vue-material-design-icons/HelpRhombusOutline.vue'
import DotsHorizontalIcon from 'vue-material-design-icons/DotsHorizontal.vue'
import ShieldCheckIcon from 'vue-material-design-icons/ShieldCheck.vue'
import AccountQuestionIcon from 'vue-material-design-icons/AccountQuestion.vue'

// Åtgärdsikoner
import LinkVariantIcon from 'vue-material-design-icons/LinkVariant.vue'
import FolderPlusOutlineIcon from 'vue-material-design-icons/FolderPlusOutline.vue'
import ReplyOutlineIcon from 'vue-material-design-icons/ReplyOutline.vue'
import ShareOutlineIcon from 'vue-material-design-icons/ShareOutline.vue'
import DeleteOutlineIcon from 'vue-material-design-icons/DeleteOutline.vue'
import ArchiveArrowDownOutlineIcon from 'vue-material-design-icons/ArchiveArrowDownOutline.vue'

import { translate as t } from '@nextcloud/l10n'

import { channelMeta } from '../../services/channels.js'
import { typLabel } from '../../services/messageTypes.js'
import KopplingBadge from './KopplingBadge.vue'
import FristChip from './FristChip.vue'

// De sex åtgärderna, i menyordning. Varje key → event-namn (identiskt) + ikon-komponent.
const ACTION_KEYS = ['koppla', 'skapa', 'besvara', 'vidarebefordra', 'gallra', 'registrera']
const ACTION_ICON = {
	koppla: 'LinkVariantIcon',
	skapa: 'FolderPlusOutlineIcon',
	besvara: 'ReplyOutlineIcon',
	vidarebefordra: 'ShareOutlineIcon',
	gallra: 'DeleteOutlineIcon',
	registrera: 'ArchiveArrowDownOutlineIcon',
}

export default {
	name: 'EjKoppladRad',

	components: {
		NcButton,
		NcActions,
		NcActionButton,
		InboxIcon,
		InboxArrowDownIcon,
		TagOutlineIcon,
		ClockOutlineIcon,
		ClockAlertOutlineIcon,
		LightbulbOnOutlineIcon,
		HelpRhombusOutlineIcon,
		DotsHorizontalIcon,
		ShieldCheckIcon,
		AccountQuestionIcon,
		LinkVariantIcon,
		FolderPlusOutlineIcon,
		ReplyOutlineIcon,
		ShareOutlineIcon,
		DeleteOutlineIcon,
		ArchiveArrowDownOutlineIcon,
		KopplingBadge,
		FristChip,
	},

	props: {
		/** InflodeRad-shape med klassning + alder + foreslagenAtgard. */
		rad: {
			type: Object,
			required: true,
		},
	},

	computed: {
		/** Resolved kanalpresentation (ikon + etikett + färg). */
		channel() {
			return channelMeta(this.rad.channel && this.rad.channel.channel)
		},

		/** Korg-etikett ur rad.korg. */
		korgLabel() {
			return (this.rad.korg && this.rad.korg.label) || this.t('hubs_start', 'Okänd korg')
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
			return this.identitetVerifierad ? 'ShieldCheckIcon' : 'AccountQuestionIcon'
		},

		identitetClass() {
			return this.identitetVerifierad
				? 'ej-kopplad-rad__identitet--verifierad'
				: 'ej-kopplad-rad__identitet--anonym'
		},

		/** Lokaliserad inkom-tid (datum + tid). */
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

		/** Har raden passerat en arbetsdag utan att triageras? */
		overSla() {
			return !!(this.rad.alder && this.rad.alder.overSla)
		},

		/** Ålderstext, t.ex. "3 dagar gammal". Tom om ålder saknas. */
		alderText() {
			const dagar = this.rad.alder && this.rad.alder.dagar
			if (dagar === null || dagar === undefined) {
				return ''
			}
			if (dagar <= 0) {
				return this.t('hubs_start', 'inkom idag')
			}
			return this.t('hubs_start', '{n} dagar gammal', { n: dagar })
		},

		/** Kanal-etikett (server-lokaliserad om den finns, annars channelMeta-fallback).
		 * Används som typ-chip-fallback för oklassade rader. */
		channelLabel() {
			return (this.rad.channel && this.rad.channel.channelLabel) || this.channel.label
		},

		/** Delad svensk typ-etikett (services/messageTypes.js); null = okänd/oklassad typ. */
		typEtikett() {
			return typLabel(this.rad.messageType)
		},

		/** Raden är klassad bara när typen har en känd etikett. */
		arKlassad() {
			return this.typEtikett !== null
		},

		/** Människovänlig etikett för meddelandetypen. Faller ALDRIG tillbaka till ett
		 * rått maskin-id: en oklassad rad visar kanal-etiketten i stället. */
		messageTypeLabel() {
			if (this.arKlassad) {
				return this.typEtikett
			}
			return this.channelLabel || this.t('hubs_start', 'Oklassad')
		},

		/** Tooltip för typ-chippet — markerar oklassad utan att hitta på en typ. */
		typChipTitle() {
			return this.arKlassad
				? this.messageTypeLabel
				: this.t('hubs_start', 'Oklassad — {kanal}', { kanal: this.channelLabel })
		},

		/** Synligt "varför" ur klassningen — gör förslaget granskbart. */
		varfor() {
			return (this.rad.klassning && this.rad.klassning.varfor) || ''
		},

		/** Föreslagen default-åtgärd; faller tillbaka på "koppla" om ej satt/ogiltig. */
		foreslagenKey() {
			const f = this.rad.foreslagenAtgard
			return ACTION_KEYS.includes(f) ? f : 'koppla'
		},

		/** De övriga fem åtgärderna (allt utom den föreslagna), i menyordning. */
		ovrigaKeys() {
			return ACTION_KEYS.filter((k) => k !== this.foreslagenKey)
		},
	},

	methods: {
		t,

		/** Etikett för en åtgärds-key. */
		actionLabel(key) {
			const map = {
				koppla: this.t('hubs_start', 'Koppla till befintligt ärende'),
				skapa: this.t('hubs_start', 'Skapa nytt ärende'),
				besvara: this.t('hubs_start', 'Besvara utan ärende'),
				vidarebefordra: this.t('hubs_start', 'Vidarebefordra (fel mottagare)'),
				gallra: this.t('hubs_start', 'Gallra utan ärende'),
				registrera: this.t('hubs_start', 'Registrera utan ärende'),
			}
			return map[key] || key
		},

		/** Ikon-komponentnamn för en åtgärds-key. */
		actionIcon(key) {
			return ACTION_ICON[key] || 'DotsHorizontalIcon'
		},

		/** Emittera åtgärdens event med raden. */
		onAction(key) {
			this.$emit(key, this.rad)
		},
	},
}
</script>

<style scoped lang="scss">
.ej-kopplad-rad {
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

	// Äldre än en arbetsdag: markerad vänsterkant (ikon + tal bär samma signal, ej bara färg).
	&--old {
		border-inline-start: 3px solid var(--hs-status-error);
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

	&__korg,
	&__inkom,
	&__alder {
		display: inline-flex;
		align-items: center;
		gap: 4px;
	}

	&__identitet {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		font-weight: 600;

		&--verifierad { color: var(--hs-status-success); }
		&--anonym { color: var(--hs-status-warning); }
	}

	&__alder--old {
		color: var(--hs-status-error);
		font-weight: 600;
	}

	&__chips {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 8px;
		margin-top: 2px;
	}

	// Typ-chip följer chip-grammatiken (pill, ikon + text, color-mix-bakgrund).
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

		// Oklassad = provisoriskt tillstånd (ej fel): streckad ram + dämpad ton +
		// ikon + sr-only-ord bär signalen (aldrig enbart färg). Samma mönster som InflodeRad.
		&--oklassad {
			border-style: dashed;
			color: var(--color-text-maxcontrast);
		}
	}

	&__varfor {
		display: flex;
		align-items: flex-start;
		gap: 6px;
		margin: 0;
		font-size: 0.82rem;
		font-style: italic;
		color: var(--color-text-maxcontrast);
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

	// Reflow @720px: body och actions wrappar, ingen horisontell scroll.
	@media (max-width: 720px) {
		flex-wrap: wrap;

		.ej-kopplad-rad__actions {
			width: 100%;
			justify-content: flex-start;
		}
	}
}
</style>
