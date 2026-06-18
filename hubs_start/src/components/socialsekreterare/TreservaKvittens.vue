<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Kvittens-/retention-ytan som knyter ihop "Hubs = mellanlagring, facksystemet =
  - slutlagring". Visar VERIFIERADE Treserva-commits (slutlagring) och gallringsklockan
  - för Hubs-kopian. Read-only: ingen rad får antyda att gallring sker på en kryssruta —
  - bara på verifierad återkoppling från facksystemet. Färg är aldrig enda bärare
  - (CheckDecagram + texten "Verifierad" bär signalen).
-->
<template>
	<section
		class="hs-card hs-card-sektion treserva-kvittens"
		:aria-label="t('hubs_start', 'Kvittenser & gallring (Treserva)')">
		<h2 class="hs-card__title treserva-kvittens__title">
			<button
				class="treserva-kvittens__toggle hs-target"
				type="button"
				:aria-expanded="String(!kollapsad)"
				:aria-controls="listId"
				@click="kollapsad = !kollapsad">
				<ChevronDownIcon v-if="!kollapsad" :size="20" />
				<ChevronRightIcon v-else :size="20" />
			</button>

			<FileCheckOutlineIcon :size="20" />
			{{ t('hubs_start', 'Kvittenser & gallring (Treserva)') }}

			<NcCounterBubble class="treserva-kvittens__count">
				{{ String(receipts.length) }}
			</NcCounterBubble>
		</h2>

		<!-- Förklarande mikrocopy: varför ytan finns och vad som gallras (och vad som INTE räcker). -->
		<p v-show="!kollapsad" class="treserva-kvittens__intro">
			{{ t('hubs_start', 'Verifierad återkoppling från facksystemet. Hubs-kopian (mellanlagring) gallras efter bekräftad överföring — aldrig på enbart en kryssruta.') }}
		</p>

		<!-- Verifierade kvittenser. Annonseras artigt; ny rad dyker upp efter committering. -->
		<ul
			v-show="!kollapsad && receipts.length"
			:id="listId"
			class="treserva-kvittens__list"
			aria-live="polite"
			:aria-label="t('hubs_start', 'Verifierade kvittenser från Treserva')">
			<li
				v-for="kvitto in receipts"
				:key="kvitto.id"
				class="treserva-kvittens__rad">
				<!-- Verifierad-markör: ikon + text + färg (aldrig bara färg). -->
				<span class="treserva-kvittens__verifierad">
					<CheckDecagramIcon :size="16" />
					<span class="treserva-kvittens__verifierad-text">{{ t('hubs_start', 'Verifierad') }}</span>
				</span>

				<div class="treserva-kvittens__innehall">
					<!-- Identitet: pseudonym + diarienummer, aldrig rå hubsCaseId. -->
					<p class="treserva-kvittens__ident">
						<span class="treserva-kvittens__barn">{{ kvitto.barnRef }}</span>
						<span class="treserva-kvittens__dnr">{{ t('hubs_start', 'dnr {dnr}', { dnr: kvitto.dnr }) }}</span>
						<span class="treserva-kvittens__typ">{{ typLabel(kvitto.typ) }}</span>
					</p>

					<!-- Slutlagring: vad som fördes och när. -->
					<p class="treserva-kvittens__slutlagring">
						{{ t('hubs_start', 'Fört till slutlagring {datum}', { datum: kortDatumTid(kvitto.committedAt) }) }}
					</p>

					<!-- Retention-rad i ProvenansChip-stil: Hubs-kopian gallras (om satt). -->
					<p
						v-if="kvitto.gallrasDatum"
						class="treserva-kvittens__retention">
						<TimerSandIcon :size="13" />
						<span>{{ t('hubs_start', 'Hubs-kopian gallras {datum}', { datum: kortDatum(kvitto.gallrasDatum) }) }}</span>
					</p>

					<!-- Källa: diskret härkomst. -->
					<p v-if="kvitto.kalla" class="treserva-kvittens__kalla">
						{{ t('hubs_start', 'via {kalla}', { kalla: kvitto.kalla }) }}
					</p>
				</div>
			</li>
		</ul>

		<!-- Tomt läge: lärande, inte bara "tomt". -->
		<NcEmptyContent
			v-if="!receipts.length"
			class="treserva-kvittens__empty"
			:name="t('hubs_start', 'Inga kvittenser än')"
			:description="t('hubs_start', 'När du för en handling till Treserva dyker det verifierade kvittot upp här.')">
			<template #icon>
				<FileCheckOutlineIcon :size="40" />
			</template>
		</NcEmptyContent>
	</section>
</template>

<script>
import NcCounterBubble from '@nextcloud/vue/dist/Components/NcCounterBubble.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'

import CheckDecagramIcon from 'vue-material-design-icons/CheckDecagram.vue'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import FileCheckOutlineIcon from 'vue-material-design-icons/FileCheckOutline.vue'
import TimerSandIcon from 'vue-material-design-icons/TimerSand.vue'

import { translate as t, translatePlural as n } from '@nextcloud/l10n'

export default {
	name: 'TreservaKvittens',

	components: {
		NcCounterBubble,
		NcEmptyContent,
		CheckDecagramIcon,
		ChevronDownIcon,
		ChevronRightIcon,
		FileCheckOutlineIcon,
		TimerSandIcon,
	},

	props: {
		/**
		 * Verifierade Treserva-kvittenser (se services/demo/treserva.js → listReceipts):
		 * { id, hubsCaseId, dnr, barnRef, typ, committedAt:ISO, gallrasDatum:'YYYY-MM-DD'|null, verifierad:Boolean, kalla:String }
		 */
		receipts: {
			type: Array,
			default: () => [],
		},
	},

	data() {
		return {
			kollapsad: false,
		}
	},

	computed: {
		/** Stabilt id för aria-controls-kopplingen toggle ↔ lista. */
		listId() {
			return 'treserva-kvittens-list'
		},
	},

	methods: {
		t,
		n,

		/** Läsbar etikett för handlingstyp — faller tillbaka på rå typ om okänd. */
		typLabel(typ) {
			const karta = {
				utredning: this.t('hubs_start', 'Utredning'),
				beslut: this.t('hubs_start', 'Beslut'),
				aktualisering: this.t('hubs_start', 'Aktualisering'),
				handling: this.t('hubs_start', 'Handling'),
			}
			return karta[typ] || typ || this.t('hubs_start', 'Handling')
		},

		/** Kort datum, t.ex. "15 jun" (för gallringsdatum 'YYYY-MM-DD'). */
		kortDatum(d) {
			if (!d) {
				return ''
			}
			try {
				return new Date(d).toLocaleDateString('sv-SE', { day: 'numeric', month: 'short' })
			} catch (e) {
				return d
			}
		},

		/** Kort datum + tid, t.ex. "15 jun 09:30" (för committedAt ISO). */
		kortDatumTid(iso) {
			if (!iso) {
				return ''
			}
			try {
				const d = new Date(iso)
				const datum = d.toLocaleDateString('sv-SE', { day: 'numeric', month: 'short' })
				const tid = d.toLocaleTimeString('sv-SE', { hour: '2-digit', minute: '2-digit' })
				return this.t('hubs_start', '{datum} {tid}', { datum, tid })
			} catch (e) {
				return iso
			}
		},
	},
}
</script>

<style scoped lang="scss">
.treserva-kvittens {
	&__title {
		margin-bottom: 8px;
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
	}

	&__intro {
		margin: 0 0 12px;
		font-size: 0.85rem;
		color: var(--color-text-maxcontrast);
	}

	&__list {
		display: flex;
		flex-direction: column;
		gap: 8px;
		margin: 0;
		padding: 0;
		list-style: none;
	}

	&__rad {
		display: flex;
		align-items: flex-start;
		gap: 10px;
		padding: 10px 12px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large, 12px);
		background: var(--color-main-background);
	}

	// Verifierad-markör: chip-grammatik (pill, ikon + text), grön ton — men texten bär signalen.
	&__verifierad {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		flex: 0 0 auto;
		padding: 2px 9px;
		border-radius: var(--border-radius-pill, 16px);
		border: 1px solid var(--hs-status-success);
		background: color-mix(in srgb, var(--hs-status-success) 12%, var(--color-main-background));
		color: var(--hs-status-success);
		font-size: 0.76rem;
		font-weight: 600;
		white-space: nowrap;
	}

	&__verifierad-text {
		color: var(--hs-status-success);
	}

	&__innehall {
		display: flex;
		flex-direction: column;
		gap: 4px;
		min-width: 0;
		flex: 1 1 auto;
	}

	&__ident {
		display: flex;
		align-items: baseline;
		flex-wrap: wrap;
		gap: 4px 8px;
		margin: 0;
		color: var(--color-main-text);
	}

	&__barn {
		font-weight: 600;
	}

	&__dnr {
		font-size: 0.82rem;
		color: var(--color-text-maxcontrast);
	}

	&__typ {
		font-size: 0.76rem;
		font-weight: 600;
		padding: 1px 8px;
		border-radius: var(--border-radius-pill, 16px);
		border: 1px solid var(--color-border);
		color: var(--color-text-maxcontrast);
		white-space: nowrap;
	}

	&__slutlagring {
		margin: 0;
		font-size: 0.85rem;
		color: var(--color-main-text);
	}

	// Retention-rad i ProvenansChip-stil: diskret, neutral, ikon + text.
	&__retention {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		align-self: flex-start;
		margin: 0;
		padding: 2px 9px;
		border-radius: var(--border-radius-pill, 16px);
		border: 1px solid var(--color-border);
		background: var(--color-main-background);
		font-size: 0.76rem;
		color: var(--color-text-maxcontrast);
		white-space: nowrap;
	}

	&__kalla {
		margin: 0;
		font-size: 0.72rem;
		color: var(--color-text-maxcontrast);
	}

	&__empty {
		margin: 8px 0 0;
	}

	// Reflow: stapla verifierad-markören ovanför innehållet på smala ytor.
	@media (max-width: 720px) {
		&__rad {
			flex-direction: column;
			gap: 6px;
		}
	}
}
</style>
