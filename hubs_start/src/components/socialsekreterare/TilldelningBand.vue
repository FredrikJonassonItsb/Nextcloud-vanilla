<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Diskret tilldelnings-rad PÅ ärendekortet — gör tilldelnings-status + ursprung
  - synligt utan ett eget kort. EN kompakt rad: ägare ("mig"/namn), vem som
  - fördelade, datum, ev. NY-markör (24h) och ev. "Från mottagningen"-ursprung.
  - Ikon + text bär alltid signalen (aldrig bara färg). Matchar chip-grammatiken
  - från FristChip/ProvenansChip. "Mer" → emit('omfordela-begaran').
-->
<template>
	<div class="tilldelning-band" :class="rotKlass" role="note" :aria-label="ariaLabel">
		<!-- NY-markör (24h): graft från triage-strömmens nyhetsmarkör. Ikon + ord bär signalen. -->
		<span v-if="visaNy" class="tilldelning-band__ny">
			<StarIcon :size="14" />
			<span class="tilldelning-band__ny-text">{{ nyText }}</span>
		</span>

		<!-- Huvudrad: tilldelat → ägare + fördelare + datum; otilldelat → neutral -->
		<span class="tilldelning-band__status">
			<AccountCheckIcon v-if="tilldelat" :size="15" />
			<AccountQuestionIcon v-else :size="15" />
			<span class="tilldelning-band__status-text">{{ statusText }}</span>
		</span>

		<!-- Ursprungs-chip: kom in via mottagningen -->
		<span v-if="franMottagning" class="tilldelning-band__urskip">
			<InboxArrowDownIcon :size="13" />
			<span>{{ t('hubs_start', 'Från mottagningen') }}</span>
		</span>

		<!-- Diskret "Mer" → begär omfördelning -->
		<NcActions
			class="tilldelning-band__mer"
			type="tertiary"
			:force-menu="true"
			:aria-label="t('hubs_start', 'Fler åtgärder för tilldelning')">
			<template #icon>
				<DotsHorizontalIcon :size="18" />
			</template>
			<NcActionButton
				:close-after-click="true"
				@click="onOmfordela">
				<template #icon>
					<AccountSwitchIcon :size="20" />
				</template>
				{{ t('hubs_start', 'Omfördela till kollega') }}
			</NcActionButton>
		</NcActions>
	</div>
</template>

<script>
import NcActions from '@nextcloud/vue/dist/Components/NcActions.js'
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js'

import AccountCheckIcon from 'vue-material-design-icons/AccountCheck.vue'
import AccountQuestionIcon from 'vue-material-design-icons/AccountQuestion.vue'
import AccountSwitchIcon from 'vue-material-design-icons/AccountSwitch.vue'
import InboxArrowDownIcon from 'vue-material-design-icons/InboxArrowDown.vue'
import StarIcon from 'vue-material-design-icons/Star.vue'
import DotsHorizontalIcon from 'vue-material-design-icons/DotsHorizontal.vue'

import { translate as t } from '@nextcloud/l10n'
import { getCurrentUser } from '@nextcloud/auth'

export default {
	name: 'TilldelningBand',

	components: {
		NcActions,
		NcActionButton,
		AccountCheckIcon,
		AccountQuestionIcon,
		AccountSwitchIcon,
		InboxArrowDownIcon,
		StarIcon,
		DotsHorizontalIcon,
	},

	props: {
		/**
		 * {
		 *   status:('otilldelat'|'tilldelat'),
		 *   agareUid?, agareNamn?,
		 *   fran?:('mottagning'|null),
		 *   tilldeladAv?, tilldeladDatum?(ISO|'YYYY-MM-DD'),
		 *   nyFor24h?:Boolean
		 * }
		 */
		tilldelning: {
			type: Object,
			default: () => ({ status: 'otilldelat' }),
		},
	},

	computed: {
		/** Tilldelat till en handläggare? */
		tilldelat() {
			return (this.tilldelning && this.tilldelning.status) === 'tilldelat'
		},

		/** Är ägaren nuvarande användare? Jämför UID mot inloggad session —
		 * aldrig namnsträngar ('Anna'-buggen: fel attribution för alla). */
		agareArMig() {
			const a = this.tilldelning || {}
			const jag = getCurrentUser()
			return !!(jag && a.agareUid && a.agareUid === jag.uid)
		},

		/** Ägarens visningsnamn — "mig" om det är jag, annars namnet. */
		agareVisning() {
			if (this.agareArMig) {
				return this.t('hubs_start', 'mig')
			}
			return (this.tilldelning && this.tilldelning.agareNamn) || this.t('hubs_start', 'okänd handläggare')
		},

		/** Den som fördelade (gruppledare/mottagning). */
		tilldeladAv() {
			return (this.tilldelning && this.tilldelning.tilldeladAv) || this.t('hubs_start', 'mottagningen')
		},

		/** Lokaliserat datum, t.ex. "13 jun". Tomt om saknas/ogiltigt. */
		datumText() {
			const raw = this.tilldelning && this.tilldelning.tilldeladDatum
			if (!raw) {
				return ''
			}
			const d = new Date(raw)
			if (isNaN(d.getTime())) {
				return String(raw)
			}
			return d.toLocaleDateString('sv-SE', { day: 'numeric', month: 'short' })
		},

		/** Kom ärendet in via mottagningen? */
		franMottagning() {
			return (this.tilldelning && this.tilldelning.fran) === 'mottagning'
		},

		/** Ny inom 24h — lyft fram så en nyfördelning inte drunknar. */
		visaNy() {
			return this.tilldelat && !!(this.tilldelning && this.tilldelning.nyFor24h)
		},

		/** Huvudradens text. */
		statusText() {
			if (!this.tilldelat) {
				return this.t('hubs_start', 'Otilldelat')
			}
			if (this.datumText) {
				return this.t('hubs_start', 'Tilldelad {agare} av {av} {datum}', {
					agare: this.agareVisning,
					av: this.tilldeladAv,
					datum: this.datumText,
				})
			}
			return this.t('hubs_start', 'Tilldelad {agare} av {av}', {
				agare: this.agareVisning,
				av: this.tilldeladAv,
			})
		},

		/** NY-markörens text — bär samma signal som ikonen. */
		nyText() {
			return this.t('hubs_start', 'NY — tilldelad dig av {av}', { av: this.tilldeladAv })
		},

		/** Rotklasser — accent när ny, ej-bärande för otilldelat. */
		rotKlass() {
			return {
				'tilldelning-band--ny': this.visaNy,
				'tilldelning-band--otilldelat': !this.tilldelat,
			}
		},

		/** Full mening för skärmläsare. */
		ariaLabel() {
			const delar = []
			if (this.visaNy) {
				delar.push(this.nyText)
			}
			delar.push(this.statusText)
			if (this.franMottagning) {
				delar.push(this.t('hubs_start', 'Från mottagningen'))
			}
			return delar.join('. ')
		},
	},

	methods: {
		t,

		onOmfordela() {
			this.$emit('omfordela-begaran', this.tilldelning)
		},
	},
}
</script>

<style scoped lang="scss">
.tilldelning-band {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 6px 10px;
	min-height: var(--hs-min-target, 24px);
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);

	// Status: ägare + fördelare + datum (tilldelat = lite starkare text).
	&__status {
		display: inline-flex;
		align-items: center;
		gap: 5px;
		min-width: 0;
	}

	&__status-text {
		overflow: hidden;
		text-overflow: ellipsis;
	}

	// Otilldelat: neutralt, ej accent. Ikon + ordet "Otilldelat" bär signalen.
	&--otilldelat &__status {
		color: var(--color-text-maxcontrast);
	}

	// Tilldelat (ej otilldelat): full textkontrast så ägaren syns.
	&:not(&--otilldelat) &__status {
		color: var(--color-main-text);
		font-weight: 600;
	}

	// NY-markör (24h): accent-piller. Ikon + ord bär signalen, ej bara färgen.
	&__ny {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		padding: 2px 9px;
		border-radius: var(--border-radius-pill, 16px);
		border: 1px solid var(--color-primary-element);
		background: color-mix(in srgb, var(--color-primary-element) 12%, var(--color-main-background));
		color: var(--color-primary-element);
		font-weight: 700;
		white-space: nowrap;
	}

	&__ny-text {
		text-transform: uppercase;
		letter-spacing: 0.02em;
	}

	// Ursprungs-chip "Från mottagningen": neutralt litet piller.
	&__urskip {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		padding: 2px 9px;
		border-radius: var(--border-radius-pill, 16px);
		border: 1px solid var(--color-border);
		background: color-mix(in srgb, var(--color-border) 12%, var(--color-main-background));
		color: var(--color-text-maxcontrast);
		font-weight: 600;
		white-space: nowrap;
	}

	// "Mer": diskret, skjuts till radens slut. Träffyta säkras av NcActions/hs-target.
	&__mer {
		margin-inline-start: auto;
	}
}

// Reflow @720px: rad får redan wrap via flex-wrap; "Mer" hålls kvar till höger.
@media (max-width: 720px) {
	.tilldelning-band__mer {
		margin-inline-start: auto;
	}
}
</style>
