<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - "Kräver åtgärd nu" som VARSEL-LISTA — inte en egen kortcontainer. Principen:
  - ETT ärende, ETT kort, EN arbetsyta (Mina ärenden). Varje varsel säger VAD som
  - ska göras och länkar NER till kortet (scroll + markering) där arbetet utförs.
  - Tidigare FLYTTADES heta kort hit, vilket tömde "Mina ärenden" — dubbelytan är
  - borta.
-->
<template>
	<section
		v-if="varsel.length"
		class="varsel-lista hs-card"
		:aria-label="t('hubs_start', 'Kräver åtgärd nu')">
		<h2 class="varsel-lista__title">
			🔥 {{ t('hubs_start', 'Kräver åtgärd nu') }}
			<NcCounterBubble class="varsel-lista__count">{{ varsel.length }}</NcCounterBubble>
		</h2>
		<ul class="varsel-lista__list" aria-live="polite">
			<li v-for="(v, i) in varsel" :key="i" class="varsel-lista__rad">
				<span class="varsel-lista__sym" aria-hidden="true">{{ v.sym }}</span>
				<span class="varsel-lista__vad">
					<strong>{{ v.rubrik }}</strong>
					<span class="varsel-lista__under">{{ v.under }}</span>
				</span>
				<button class="varsel-lista__ga hs-target" type="button" @click="$emit('ga-till', v.ref)">
					{{ t('hubs_start', 'Gå till ärendet') }} ↓
				</button>
			</li>
		</ul>
	</section>
</template>

<script>
import NcCounterBubble from '@nextcloud/vue/dist/Components/NcCounterBubble.js'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'VarselLista',

	components: { NcCounterBubble },

	props: {
		/** list<{sym, rubrik, under, ref}> — ref = kortets triageRef (ankaret). */
		varsel: {
			type: Array,
			default: () => [],
		},
	},

	methods: { t },
}
</script>

<style scoped lang="scss">
.varsel-lista {
	border: 1px solid var(--color-error, #b2211b);

	&__title {
		display: flex; align-items: center; gap: 8px;
		margin: 0 0 6px; font-size: 1rem;
		color: var(--color-error-text, var(--color-error, #b2211b));
	}
	&__list { list-style: none; margin: 0; padding: 0; }
	&__rad {
		display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
		padding: 8px 4px;
		& + & { border-top: 1px solid var(--color-border); }
	}
	&__sym { flex: none; width: 24px; text-align: center; }
	&__vad { flex: 1 1 280px; min-width: 0; display: flex; flex-direction: column; }
	&__under { color: var(--color-text-maxcontrast); font-size: 0.82rem; }
	&__ga {
		flex: none; font: inherit; font-size: 0.85rem; font-weight: 600; cursor: pointer;
		border: none; border-radius: var(--border-radius-pill, 16px); padding: 5px 14px;
		background: var(--color-primary-element-light, #e7f1f7);
		color: var(--color-primary-element);
		&:hover, &:focus-visible { filter: brightness(0.94); }
	}
}
</style>
