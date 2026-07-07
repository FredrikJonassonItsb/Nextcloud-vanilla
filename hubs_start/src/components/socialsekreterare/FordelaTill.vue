<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Gruppledarens primäråtgärd på ett ofördelat ärende: välj ansvarig utredare.
  - Varje val visar belastningen i parentes — "Sara (8)" — så chefen ser lasten i
  - samma blick som beslutet. Utredare nära tak markeras (ikon + text "nära tak",
  - aldrig bara färg). Knapp/tangentbord, ingen drag-only (WCAG 2.5.7).
-->
<template>
	<!-- Tomt läge: ingen utredare att välja ⇒ knappen visas men är avstängd,
	     med en text som förklarar varför (aldrig en tom, död meny). -->
	<div v-if="!utredare.length" class="fordela-till fordela-till--tom">
		<NcButton
			type="primary"
			:disabled="true"
			:aria-label="ariaLabel">
			<template #icon>
				<AccountArrowRightIcon :size="20" />
			</template>
			{{ t('hubs_start', 'Fördela till …') }}
		</NcButton>
		<p class="fordela-till__tom-text">
			{{ t('hubs_start', 'Inga utredare i enheten kunde hämtas.') }}
		</p>
	</div>
	<NcActions
		v-else
		class="fordela-till"
		type="primary"
		:menu-name="t('hubs_start', 'Fördela till …')"
		:aria-label="ariaLabel">
		<template #icon>
			<AccountArrowRightIcon :size="20" />
		</template>
		<NcActionButton
			v-for="u in utredare"
			:key="u.namn"
			:close-after-click="true"
			@click="onValj(u)">
			<template #icon>
				<AlertOutlineIcon v-if="u.naraTak" :size="20" class="fordela-till__tak-ikon" />
				<AccountIcon v-else :size="20" />
			</template>
			{{ etikett(u) }}
		</NcActionButton>
	</NcActions>
</template>

<script>
import NcActions from '@nextcloud/vue/dist/Components/NcActions.js'
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'

import AccountArrowRightIcon from 'vue-material-design-icons/AccountArrowRight.vue'
import AccountIcon from 'vue-material-design-icons/Account.vue'
import AlertOutlineIcon from 'vue-material-design-icons/AlertOutline.vue'

import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'FordelaTill',
	components: { NcActions, NcActionButton, NcButton, AccountArrowRightIcon, AccountIcon, AlertOutlineIcon },
	props: {
		arende: { type: Object, required: true },
		/** [{ namn, aktiva:Number, roda:Number, naraTak:Boolean, uid?:String }] — uid föredras vid emit. */
		utredare: { type: Array, default: () => [] },
	},
	computed: {
		ariaLabel() {
			return this.t('hubs_start', 'Fördela ärendet till en utredare')
		},
	},
	methods: {
		t,
		/** "Sara (8)" — eller "Mia (19) — nära tak" så lasten syns vid valet. */
		etikett(u) {
			const aktiva = Number(u.aktiva || 0)
			const bas = this.t('hubs_start', '{namn} ({aktiva})', { namn: u.namn, aktiva })
			return u.naraTak
				? this.t('hubs_start', '{bas} — nära tak', { bas })
				: bas
		},
		onValj(u) {
			// utredareUid MÅSTE bära ett användar-id, inte ett visningsnamn — annars
			// träffar tilldelningen fel/ingen användare. Motorn (ArendeService) sätter
			// idag 'namn' = uid i live-svaret, så namn är sista fallback när objektet
			// saknar uid/id-fält.
			this.$emit('fordela', { arende: this.arende, utredareUid: u.uid ?? u.id ?? u.namn })
		},
	},
}
</script>

<style scoped lang="scss">
.fordela-till {
	&__tak-ikon {
		color: var(--hs-status-warning);
	}

	&--tom {
		display: flex;
		flex-direction: column;
		gap: 4px;
	}

	&__tom-text {
		margin: 0;
		color: var(--color-text-maxcontrast);
		font-size: 13px;
	}
}
</style>
