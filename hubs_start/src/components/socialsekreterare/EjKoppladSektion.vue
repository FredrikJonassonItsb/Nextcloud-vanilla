<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - "Ej ärendekopplat"-hinken (Zon 1c). En uttrycklig mellanstation för löst inflöde:
  - INGEN rad får försvinna utan att kopplas, registreras eller gallras med dokumenterat
  - stöd (OSL 5:1, arkivlagen 1990:782). Räknaren blir röd när rader blir äldre än en
  - arbetsdag. @gallra öppnar ALDRIG en direkt radering — den öppnar den juridiska
  - GallringsGrind-grinden där handlingstyp och gallringsbeslut måste väljas först.
-->
<template>
	<section class="hs-card ej-kopplad" :aria-label="t('hubs_start', 'Ej ärendekopplat')">
		<h2 class="hs-card__title ej-kopplad__title">
			<button
				class="ej-kopplad__toggle hs-target"
				type="button"
				:aria-expanded="String(!kollapsad)"
				:aria-controls="listId"
				@click="kollapsad = !kollapsad; anvandarStyrd = true">
				<ChevronDownIcon v-if="!kollapsad" :size="20" />
				<ChevronRightIcon v-else :size="20" />
			</button>

			<LinkVariantOffIcon :size="20" />
			{{ t('hubs_start', 'Ej ärendekopplat') }}

			<NcCounterBubble
				class="ej-kopplad__count"
				:type="antalGamla ? 'highlighted' : undefined"
				:style="antalGamla ? { '--ej-kopplad-count': 'var(--hs-status-error)' } : null"
				:class="{ 'ej-kopplad__count--old': antalGamla }">
				{{ raknareText }}
			</NcCounterBubble>
		</h2>

		<!-- Aggregerad lista över alla behöriga korgar. Annonseras artigt. -->
		<ul
			v-show="!kollapsad"
			:id="listId"
			class="ej-kopplad__list"
			aria-live="polite"
			:aria-label="t('hubs_start', 'Löst inflöde utan ärendekoppling')">
			<EjKoppladRad
				v-for="rad in items"
				:key="rad.id"
				:rad="rad"
				@koppla="onKoppla"
				@skapa="onSkapa"
				@besvara="onBesvara"
				@vidarebefordra="onVidarebefordra"
				@gallra="onGallra"
				@registrera="onRegistrera"
				@avvisa-forslag="$emit('avvisa-forslag', $event)" />
		</ul>

		<NcEmptyContent
			v-if="!items.length && !kollapsad"
			class="ej-kopplad__empty"
			:name="t('hubs_start', 'Ej ärendekopplat: 0 — allt inflöde triagerat.')"
			:description="emptyDescription">
			<template #icon>
				<CheckCircleOutlineIcon :size="40" />
			</template>
		</NcEmptyContent>

		<!-- Den juridiska grinden öppnas i stället för direkt radering. -->
		<GallringsGrind
			v-if="gallringsRad"
			:rad="gallringsRad"
			:handlingstyper="handlingstyper"
			@gallra="onGrindGallra"
			@registrera="onGrindRegistrera"
			@close="gallringsRad = null" />
	</section>
</template>

<script>
import NcCounterBubble from '@nextcloud/vue/dist/Components/NcCounterBubble.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'

import LinkVariantOffIcon from 'vue-material-design-icons/LinkVariantOff.vue'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import CheckCircleOutlineIcon from 'vue-material-design-icons/CheckCircleOutline.vue'

import { translate as t, translatePlural as n } from '@nextcloud/l10n'

import EjKoppladRad from './EjKoppladRad.vue'
import GallringsGrind from './GallringsGrind.vue'

export default {
	name: 'EjKoppladSektion',

	components: {
		NcCounterBubble,
		NcEmptyContent,
		LinkVariantOffIcon,
		ChevronDownIcon,
		ChevronRightIcon,
		CheckCircleOutlineIcon,
		EjKoppladRad,
		GallringsGrind,
	},

	props: {
		/** InflodeRad med arendekoppling:'ej_kopplat', aggregerade över alla behöriga korgar. */
		items: {
			type: Array,
			default: () => [],
		},
		/** Aktiv korg (filterkontext från sektionsskalet). */
		aktivKorg: {
			type: [String, Object],
			default: null,
		},
		/** Aktiv typ (filterkontext från sektionsskalet). */
		aktivTyp: {
			type: String,
			default: null,
		},
		/** Handlingstyper ur dokumenthanteringsplanen — vidarebefordras till grinden. */
		handlingstyper: {
			type: Array,
			default: () => [],
		},
	},

	data() {
		return {
			// TOM panel startar kollapsad (en rad + räknare 0); auto-expanderar när
			// innehåll kommer, tills användaren själv togglar. Samma mönster som
			// AttHanteraSektion.
			kollapsad: (this.items || []).length === 0,
			anvandarStyrd: false,
			// Raden vars gallring väntar i grinden (null = grinden stängd).
			gallringsRad: null,
		}
	},
	watch: {
		items(nya) {
			if (!this.anvandarStyrd) {
				this.kollapsad = (nya || []).length === 0
			}
		},
	},

	computed: {
		/** Antal rader som är äldre än en arbetsdag (SLA-överskridna). */
		antalGamla() {
			return this.items.filter((rad) => rad && rad.alder && rad.alder.overSla).length
		},

		/** Räknartext: "7" eller "7 — 2 över SLA · äldsta 3 dagar" (två sanna mått). */
		raknareText() {
			const totalt = this.items.length
			if (!this.antalGamla) {
				return String(totalt)
			}
			const maxDagar = this.items.reduce((m, rad) => {
				const d = (rad && rad.alder && rad.alder.dagar) || 0
				return d > m ? d : m
			}, 0)
			const aldreText = this.n(
				'hubs_start',
				'{n} över SLA · äldsta {dagar} dag',
				'{n} över SLA · äldsta {dagar} dagar',
				maxDagar,
				{ n: this.antalGamla, dagar: maxDagar },
			)
			return this.t('hubs_start', '{totalt} — {aldre}', { totalt, aldre: aldreText })
		},

		/** Stabilt id för aria-controls-kopplingen toggle ↔ lista. */
		listId() {
			return 'ej-kopplad-list'
		},

		/** Lärande tom-text — efterlevnadsvärde, inte bara "tomt". */
		emptyDescription() {
			return this.t('hubs_start', 'Allt löst inflöde är triagerat. Ingen rad har försvunnit utan att kopplas, registreras eller gallras med dokumenterat stöd.')
		},
	},

	methods: {
		t,
		n,

		onKoppla(rad) {
			this.$emit('koppla', rad)
		},

		onSkapa(rad) {
			this.$emit('skapa', rad)
		},

		onBesvara(rad) {
			this.$emit('besvara', rad)
		},

		onVidarebefordra(rad) {
			this.$emit('vidarebefordra', rad)
		},

		/** Gallra går ALDRIG direkt — öppna den juridiska grinden i stället. */
		onGallra(rad) {
			this.gallringsRad = rad
		},

		// KONTRAKT mot föräldern (MinaArenden): @registrera och @gallra emittar
		// ETT objekt { rad, handlingstyp } där handlingstyp är
		// { id, label, gallringsbeslut } eller null (okänd/ej vald typ).

		onRegistrera(rad) {
			this.$emit('registrera', { rad, handlingstyp: null })
		},

		/** Grinden gav grönt ljus för gallring med vald handlingstyp. */
		onGrindGallra(payload) {
			this.$emit('gallra', { rad: payload.rad, handlingstyp: payload.handlingstyp || null })
			this.gallringsRad = null
		},

		/** Grinden styrde om till registrering (tvingande väg för ej-ringa handling). */
		onGrindRegistrera(payload) {
			this.$emit('registrera', { rad: payload.rad, handlingstyp: payload.handlingstyp || null })
			this.gallringsRad = null
		},
	},
}
</script>

<style scoped lang="scss">
.ej-kopplad {
	&__title {
		margin-bottom: 12px;
	}

	&__toggle {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		padding: 0;
		border: none;
		background: transparent;
		color: var(--color-main-text);
		cursor: pointer;
		border-radius: var(--border-radius, 8px);

		&:hover {
			background: var(--color-background-hover);
		}
	}

	&__count {
		margin-inline-start: 2px;

		// Röd när rader är SLA-överskridna (tal + highlight-typ bär signalen, ej bara färg).
		&--old {
			--color-primary-element: var(--ej-kopplad-count, var(--hs-status-error));
			--color-primary-element-text: var(--color-primary-element-text);
		}
	}

	&__list {
		display: flex;
		flex-direction: column;
		gap: 8px;
		margin: 0;
		padding: 0;
		list-style: none;
	}

	&__empty {
		margin: 8px 0 0;
	}
}
</style>
