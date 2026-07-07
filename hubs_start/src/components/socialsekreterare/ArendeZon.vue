<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Zon 2 (pinnade heta kort) & Zon 3 (alla aktiva, segment-filtrerbara). Samma
  - ArendeKort, två lägen. Filtrering via knapp/tangentbord (WCAG 2.5.7), aldrig drag.
-->
<template>
	<section class="arende-zon" :class="{ 'arende-zon--pinned': pinned }">
		<header class="arende-zon__head">
			<h2 class="arende-zon__title">
				<FireIcon v-if="pinned" :size="20" class="arende-zon__fire" />
				{{ title }}
				<NcCounterBubble>{{ arenden.length }}</NcCounterBubble>
			</h2>
		</header>

		<!-- Zon 3: segment-filter på processteg -->
		<div v-if="!pinned" class="arende-zon__filter" role="tablist" :aria-label="t('hubs_start', 'Filtrera på processteg')">
			<!-- AGGREGAT-chipen ("Alla") har egen gråton (--aggregat) — den är
			     SUMMAN av stegen, inte ett eget steg-val (jfr KorgValjare). -->
			<button
				v-for="seg in segment"
				:key="seg.id || 'alla'"
				class="arende-zon__seg hs-target"
				:class="{ 'arende-zon__seg--active': filterSteg === seg.id, 'arende-zon__seg--aggregat': seg.id === null }"
				role="tab"
				:aria-selected="String(filterSteg === seg.id)"
				@click="$emit('filter-steg', seg.id)">
				{{ seg.label }}
				<span v-if="seg.count !== null" class="arende-zon__seg-n">{{ seg.count }}</span>
			</button>
		</div>

		<NcEmptyContent
			v-if="!arenden.length"
			:name="emptyText">
			<template #icon><CheckAllIcon :size="40" /></template>
		</NcEmptyContent>

		<div v-else class="arende-zon__kort">
			<ArendeKort
				v-for="a in arenden"
				:key="a.hubsCaseId || a.dnr || a.triageRef"
				:arende="a"
				:pinned="pinned || arVarslad(a)"
				:markerad="a.triageRef === markeradRef"
				:keyboard-mode="keyboardMode"
				@nasta-atgard="bubble('nasta-atgard', $event)"
				@open-rum="bubble('open-rum', $event)"
				@open-team="bubble('open-team', $event)"
				@ny-chatt="bubble('ny-chatt', $event)"
				@skapa-handling="bubble('skapa-handling', $event)"
				@omfordela-kollega="bubble('omfordela-kollega', $event)"
				@omfordela-begaran="bubble('omfordela-kollega', $event)"
				@skicka="bubble('skicka', $event)"
				@boka-mote="bubble('boka-mote', $event)"
				@signera="bubble('signera', $event)"
				@commit="(ar, extra) => $emit('commit', ar, extra)"
				@bevakning="bubble('bevakning', $event)"
				@godkann="(ar, mo) => $emit('godkann', ar, mo)"
				@expand="bubble('expand', $event)" />
		</div>
	</section>
</template>

<script>
import NcCounterBubble from '@nextcloud/vue/dist/Components/NcCounterBubble.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import FireIcon from 'vue-material-design-icons/Fire.vue'
import CheckAllIcon from 'vue-material-design-icons/CheckAll.vue'
import { translate as t } from '@nextcloud/l10n'
import ArendeKort from './ArendeKort.vue'

export default {
	name: 'ArendeZon',
	components: { NcCounterBubble, NcEmptyContent, FireIcon, CheckAllIcon, ArendeKort },
	props: {
		arenden: { type: Array, default: () => [] },
		pinned: { type: Boolean, default: false },
		title: { type: String, default: '' },
		filterSteg: { type: String, default: null },
		keyboardMode: { type: Boolean, default: false },
		/** triageRef för kortet som varsel-länken pekar på (scroll + markering). */
		markeradRef: { type: String, default: null },
		/** triageRefs med aktiva varsel — de korten får het-ram i listan. */
		varsladeRefs: { type: Array, default: () => [] },
	},
	computed: {
		segment() {
			const steg = ['forhandsbedomning', 'utredning', 'beslut', 'uppfoljning']
			const labels = { forhandsbedomning: t('hubs_start', 'Förhandsbed.'), utredning: t('hubs_start', 'Utredning'), beslut: t('hubs_start', 'Beslut'), uppfoljning: t('hubs_start', 'Uppföljning') }
			return [{ id: null, label: t('hubs_start', 'Alla'), count: null }]
				.concat(steg.map((s) => ({ id: s, label: labels[s], count: null })))
		},
		emptyText() {
			if (this.pinned) {
				return t('hubs_start', 'Inga heta ärenden just nu. Inga förfallna frister — inget barn mellan stolarna.')
			}
			if (this.filterSteg) {
				return t('hubs_start', 'Inga ärenden i detta steg just nu.')
			}
			return t('hubs_start', 'Inga aktiva ärenden.')
		},
	},
	methods: {
		t,
		arVarslad(a) {
			return this.varsladeRefs.includes(a.triageRef)
		},
		bubble(name, payload) {
			this.$emit(name, payload)
		},
	},
}
</script>

<style scoped lang="scss">
.arende-zon {
	&__head { margin-bottom: 8px; }
	&__title { display: flex; align-items: center; gap: 8px; font-size: 1.1rem; font-weight: 700; margin: 0; }
	&__fire { color: var(--hs-status-error); }

	&__filter { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; }
	&__seg {
		display: inline-flex;
		align-items: center;
		gap: 5px;
		padding: 4px 12px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-pill, 16px);
		background: var(--color-main-background);
		color: var(--color-main-text);
		font-size: 0.85rem;
		cursor: pointer;
		&:hover { background: var(--color-background-hover); }
		&--active { background: var(--color-primary-element); color: var(--color-primary-element-text); border-color: var(--color-primary-element); }

		// AGGREGAT-chipen ("Alla"): egen gråton + streckad ram som signalerar
		// "summan av stegen", inte ett eget steg-val (samma mönster som KorgValjare).
		&--aggregat:not(&--active) {
			background: var(--color-background-dark);
			border-style: dashed;
			font-weight: 500;

			&:hover {
				background: var(--color-background-hover);
			}
		}

		&--aggregat#{&}--active {
			border-style: dashed;
		}
	}
	&__seg-n { font-size: 0.75rem; opacity: 0.8; }

	&__kort { display: flex; flex-direction: column; gap: 12px; }
}
</style>
