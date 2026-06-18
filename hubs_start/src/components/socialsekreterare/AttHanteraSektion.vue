<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Zon 1b — "Att hantera (mina korgar)". Inkommande som REDAN hör till ett av mina
  - pågående ärenden (arendekoppling:'hor_till'): kompletteringar, medborgarsvar,
  - remissvar, internpost. Detta är ÄRENDEARBETE, inte triage — det stänger gapet
  - "inkorg ↔ ärende". Default grupperat per ärende (barn-referensen) så allt som hör
  - till samma barn syns ihop oavsett korg. Tom-som-default = compliance-kvitto.
-->
<template>
	<section class="hs-card att-hantera" :aria-label="t('hubs_start', 'Att hantera (mina korgar)')">
		<!-- Rubrik: kollaps-chevron · titel · antal -->
		<h2 class="hs-card__title att-hantera__title">
			<button
				class="att-hantera__collapse hs-target"
				type="button"
				:aria-expanded="String(!kollapsad)"
				:aria-label="kollapsad
					? t('hubs_start', 'Visa Att hantera')
					: t('hubs_start', 'Dölj Att hantera')"
				@click="kollapsad = !kollapsad">
				<ChevronDownIcon v-if="!kollapsad" :size="20" />
				<ChevronRightIcon v-else :size="20" />
			</button>
			<IconBasket :size="20" />
			<span class="att-hantera__title-text">{{ t('hubs_start', 'Att hantera (mina korgar)') }}</span>
			<NcCounterBubble class="att-hantera__count">{{ items.length }}</NcCounterBubble>
		</h2>

		<template v-if="!kollapsad">
			<!-- Grupperingsväljare: aria-pressed-piller, aldrig bara färg -->
			<div
				class="att-hantera__gruppering"
				role="group"
				:aria-label="t('hubs_start', 'Gruppera inkommande')">
				<button
					v-for="val in grupperingsval"
					:key="val.key"
					class="att-hantera__grupp-knapp hs-target"
					type="button"
					:aria-pressed="String(gruppering === val.key)"
					@click="onSattGruppering(val.key)">
					<component :is="val.icon" :size="15" />
					<span>{{ val.label }}</span>
				</button>
			</div>

			<!-- Grupperade, kollapsbara grupper. aria-live: nya kompletteringar annonseras lugnt. -->
			<div
				class="att-hantera__groups"
				aria-live="polite"
				:aria-label="t('hubs_start', 'Inflöde till dina pågående ärenden')">
				<section
					v-for="grupp in grupper"
					:key="grupp.key"
					class="att-hantera__group">
					<h3 class="att-hantera__group-title">
						<button
							class="att-hantera__group-collapse hs-target"
							type="button"
							:aria-expanded="String(!arKollapsad(grupp.key))"
							:aria-label="arKollapsad(grupp.key)
								? t('hubs_start', 'Visa {grupp}', { grupp: grupp.label })
								: t('hubs_start', 'Dölj {grupp}', { grupp: grupp.label })"
							@click="vaxlaGrupp(grupp.key)">
							<ChevronDownIcon v-if="!arKollapsad(grupp.key)" :size="18" />
							<ChevronRightIcon v-else :size="18" />
						</button>
						<component :is="grupp.icon" :size="16" class="att-hantera__group-icon" />
						<span class="att-hantera__group-label">{{ grupp.label }}</span>
						<NcCounterBubble class="att-hantera__group-count">{{ grupp.items.length }}</NcCounterBubble>
					</h3>

					<ul
						v-show="!arKollapsad(grupp.key)"
						class="att-hantera__list">
						<InflodeRad
							v-for="item in grupp.items"
							:key="item.id"
							:rad="item"
							:actions="actionsFor(item)"
							@action="onAction"
							@open-arende="onOpenArende" />
					</ul>
				</section>
			</div>

			<!-- Tom-som-default: compliance-kvitto, inte tom skärm. -->
			<NcEmptyContent
				v-if="!items.length"
				class="att-hantera__empty"
				:name="t('hubs_start', 'Inget olöst inflöde till dina pågående ärenden.')"
				:description="emptyDescription">
				<template #icon>
					<IconCheck :size="40" />
				</template>
			</NcEmptyContent>
		</template>
	</section>
</template>

<script>
import NcCounterBubble from '@nextcloud/vue/dist/Components/NcCounterBubble.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'

import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import IconBasket from 'vue-material-design-icons/BasketOutline.vue'
import IconCheck from 'vue-material-design-icons/CheckCircleOutline.vue'
import IconArende from 'vue-material-design-icons/FolderAccountOutline.vue'
import IconKorg from 'vue-material-design-icons/InboxMultipleOutline.vue'
import IconTyp from 'vue-material-design-icons/ShapeOutline.vue'

import { translate as t } from '@nextcloud/l10n'

import { channelMeta } from '../../services/channels.js'
import InflodeRad from './InflodeRad.vue'

export default {
	name: 'AttHanteraSektion',

	components: {
		NcCounterBubble,
		NcEmptyContent,
		ChevronDownIcon,
		ChevronRightIcon,
		IconBasket,
		IconCheck,
		IconArende,
		IconKorg,
		IconTyp,
		InflodeRad,
	},

	props: {
		/** InflodeRad-shape med arendekoppling:'hor_till'. */
		items: {
			type: Array,
			default: () => [],
		},
		/** Aktiv gruppering: 'arende' | 'korg' | 'typ'. */
		gruppering: {
			type: String,
			default: 'arende',
		},
		/** Aktiv korg-adress att filtrera på (null = alla mina korgar). */
		aktivKorg: {
			type: String,
			default: null,
		},
		/** Aktiv typ att filtrera på (null = alla typer). */
		aktivTyp: {
			type: String,
			default: null,
		},
	},

	data() {
		return {
			/** Hela sektionen kollapsad. */
			kollapsad: false,
			/** Set av grupp-nycklar som är kollapsade (default: alla öppna). */
			kollapsadeGrupper: [],
		}
	},

	computed: {
		/** Grupperingsväljarens tre lägen (label + aria-pressed-ikon). */
		grupperingsval() {
			return [
				{ key: 'arende', label: this.t('hubs_start', 'Per ärende'), icon: 'IconArende' },
				{ key: 'korg', label: this.t('hubs_start', 'Per korg'), icon: 'IconKorg' },
				{ key: 'typ', label: this.t('hubs_start', 'Per typ'), icon: 'IconTyp' },
			]
		},

		/** Items efter ev. korg-/typ-filter. */
		filtreradeItems() {
			return this.items.filter((rad) => {
				if (this.aktivKorg && (!rad.korg || rad.korg.addr !== this.aktivKorg)) {
					return false
				}
				if (this.aktivTyp && rad.messageType !== this.aktivTyp) {
					return false
				}
				return true
			})
		},

		/** Grupperade, ordnade grupper för rendering. */
		grupper() {
			const buckets = new Map()
			for (const rad of this.filtreradeItems) {
				const { key, label, icon } = this.gruppMeta(rad)
				if (!buckets.has(key)) {
					buckets.set(key, { key, label, icon, items: [] })
				}
				buckets.get(key).items.push(rad)
			}
			return Array.from(buckets.values())
		},

		/** Lärande tom-text — compliance-värde, inte bara "tomt". */
		emptyDescription() {
			return this.t('hubs_start', 'Här samlas svar och kompletteringar som hör till barn du redan arbetar med — remissvar, medborgarsvar och internpost. Tomt betyder att inget i dina korgar väntar på att fästas i rätt ärende.')
		},
	},

	methods: {
		t,

		/** Härleder grupp-nyckel, etikett och ikon för en rad utifrån aktiv gruppering. */
		gruppMeta(rad) {
			const koppling = rad.koppling || {}
			if (this.gruppering === 'korg') {
				const korg = rad.korg || {}
				return {
					key: 'korg:' + (korg.addr || 'okand'),
					label: korg.label || this.t('hubs_start', 'Okänd korg'),
					icon: 'IconKorg',
				}
			}
			if (this.gruppering === 'typ') {
				return {
					key: 'typ:' + (rad.messageType || 'okand'),
					label: this.typLabel(rad.messageType),
					icon: 'IconTyp',
				}
			}
			// Default: per ärende — gruppera på barn-referensen så allt för samma barn samlas.
			const ref = koppling.barnRef || koppling.dnr || this.t('hubs_start', 'Utan ärendereferens')
			return {
				key: 'arende:' + ref,
				label: ref,
				icon: 'IconArende',
			}
		},

		/** Läsbar typ-etikett (varumärkessäker, aldrig "Nextcloud"/"Talk"/"Circles"). */
		typLabel(typ) {
			const m = {
				orosanmalan: this.t('hubs_start', 'Orosanmälan'),
				komplettering: this.t('hubs_start', 'Komplettering'),
				fraga: this.t('hubs_start', 'Fråga'),
				remiss: this.t('hubs_start', 'Remissvar'),
				internpost: this.t('hubs_start', 'Internpost'),
				fax: this.t('hubs_start', 'Fax'),
				sdk_myndighet: this.t('hubs_start', 'SDK-myndighet'),
				skrap: this.t('hubs_start', 'Skräp'),
			}
			return m[typ] || this.t('hubs_start', 'Övrigt')
		},

		/**
		 * Typ-styrda åtgärder per rad. Frågor/medborgarsvar besvaras; övrigt
		 * ärendematerial fästs i ärenderummet med valbar bevakning.
		 */
		actionsFor(item) {
			if (item.messageType === 'fraga') {
				return [
					{ key: 'besvara', label: this.t('hubs_start', 'Besvara'), primary: true },
					{ key: 'spara-i-rum', label: this.t('hubs_start', 'Spara i ärenderum') },
				]
			}
			// komplettering, remiss, internpost, övrigt ärendematerial
			return [
				{ key: 'spara-i-rum', label: this.t('hubs_start', 'Spara i ärenderum'), primary: true },
				{ key: 'skapa-bevakning', label: this.t('hubs_start', 'Skapa bevakning') },
			]
		},

		/** Mappar en vald åtgärds key → rätt emit uppåt. */
		onAction({ key, rad }) {
			switch (key) {
			case 'spara-i-rum':
				this.$emit('spara-i-rum', rad)
				break
			case 'skapa-bevakning':
				this.$emit('skapa-bevakning', rad)
				break
			case 'besvara':
				this.$emit('besvara', rad)
				break
			default:
				break
			}
		},

		/** Re-emit från KopplingBadge via InflodeRad: öppna ärendet. */
		onOpenArende(koppling) {
			this.$emit('open-arende', koppling)
		},

		/** Toggla aktiv gruppering uppåt (kontrollerad av föräldern). */
		onSattGruppering(g) {
			if (g !== this.gruppering) {
				this.$emit('satt-gruppering', g)
			}
		},

		/** Är en given grupp kollapsad? */
		arKollapsad(key) {
			return this.kollapsadeGrupper.indexOf(key) !== -1
		},

		/** Växla en grupps kollaps-tillstånd. */
		vaxlaGrupp(key) {
			const i = this.kollapsadeGrupper.indexOf(key)
			if (i === -1) {
				this.kollapsadeGrupper.push(key)
			} else {
				this.kollapsadeGrupper.splice(i, 1)
			}
		},

		// Exponeras för mallens grupp-ikoner (channelMeta hålls importerad för paritet
		// med InflodeRad-kontraktet och framtida kanal-grupperingar).
		channelMeta,
	},
}
</script>

<style scoped lang="scss">
.att-hantera {
	&__title {
		display: flex;
		align-items: center;
		gap: 8px;
		margin-bottom: 12px;
	}

	&__title-text {
		min-width: 0;
		overflow: hidden;
		text-overflow: ellipsis;
	}

	&__collapse,
	&__group-collapse {
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
	}

	&__gruppering {
		display: flex;
		flex-wrap: wrap;
		gap: 6px;
		margin-bottom: 12px;
	}

	&__grupp-knapp {
		display: inline-flex;
		align-items: center;
		gap: 5px;
		padding: 4px 12px;
		border-radius: var(--border-radius-pill, 16px);
		border: 1px solid var(--color-border);
		background: var(--color-main-background);
		color: var(--color-text-maxcontrast);
		font-size: 0.82rem;
		font-weight: 600;
		cursor: pointer;

		&:hover {
			background: var(--color-background-hover);
		}

		// Aktiv toggle: primärfärg, men ikon + text + aria-pressed bär signalen — aldrig bara färg.
		&[aria-pressed='true'] {
			border-color: var(--color-primary-element);
			background: var(--color-primary-element-light, var(--color-background-hover));
			color: var(--color-primary-element);
		}
	}

	&__groups {
		display: flex;
		flex-direction: column;
		gap: 16px;
	}

	&__group {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}

	&__group-title {
		display: flex;
		align-items: center;
		gap: 6px;
		margin: 0;
		padding-bottom: 4px;
		border-bottom: 1px solid var(--color-border);
		font-size: 0.92rem;
		font-weight: 600;
		color: var(--color-main-text);
	}

	&__group-icon {
		color: var(--color-text-maxcontrast);
		flex: 0 0 auto;
	}

	&__group-label {
		min-width: 0;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__group-count {
		margin-inline-start: 2px;
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

	// Reflow @720px: grupperingsväljaren får inte skapa horisontell scroll.
	@media (max-width: 720px) {
		&__gruppering {
			width: 100%;
		}

		&__grupp-knapp {
			flex: 1 1 auto;
			justify-content: center;
		}
	}
}
</style>
