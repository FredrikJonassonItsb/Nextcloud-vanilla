<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Den ledande knappen — låg kognitiv last. Etiketten härleds ur state machine
  - (steg, tillstånd) → åtgärd. Sekundärt "[▾ gör annat]" med övriga lagliga
  - åtgärder i fasen. Den enda visuellt dominanta knappen på kortet.
-->
<template>
	<div class="nasta">
		<NcButton
			type="primary"
			class="nasta__primar"
			wide
			@click="$emit('nasta-atgard', arende)">
			<template #icon>
				<LightningBoltIcon :size="18" />
			</template>
			{{ nasta.label }}
		</NcButton>

		<NcActions :aria-label="t('hubs_start', 'Gör annat')" class="nasta__mer">
			<template #icon>
				<DotsHorizontalIcon :size="20" />
			</template>
			<!-- close-after-click: menyn får ALDRIG ligga kvar ovanpå en modal som
			     menyvalet öppnar (Ny chatt/Boka möte) — hittad i live-test. -->
			<!-- A10 — tunga åtgärder (signera/commit) i FEL steg tas inte bort: de tonas
			     ned med en förklaring ("ovanligt i detta steg"). Icke-linjärt arbete är
			     legitimt, men den ovanliga vägen ska synas som ovanlig. Åtgärden går
			     fortfarande att välja (aldrig blockerad). -->
			<NcActionButton
				v-for="a in ovrigaAtgarder"
				:key="a.key"
				:close-after-click="true"
				:class="{ 'nasta__atgard--nedtonad': a.nedtonad }"
				:title="a.forklaring || undefined"
				:description="a.nedtonad ? a.forklaring : ''"
				:aria-label="a.nedtonad ? (a.label + ' — ' + a.forklaring) : undefined"
				@click="$emit('action', a.key)">
				<template #icon>
					<component :is="iconFor(a.icon)" :size="20" />
				</template>
				{{ a.label }}
			</NcActionButton>
		</NcActions>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcActions from '@nextcloud/vue/dist/Components/NcActions.js'
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js'
import LightningBoltIcon from 'vue-material-design-icons/LightningBolt.vue'
import DotsHorizontalIcon from 'vue-material-design-icons/DotsHorizontal.vue'
import { translate as t } from '@nextcloud/l10n'
import { iconFor } from '../../services/icons.js'
import { nastaFor } from '../../services/arendeFlow.js'

/**
 * A10 — tunga/terminala åtgärder → de steg där de är NORMALA. I andra steg tonas
 * de ned (men tas aldrig bort). Härlett ur livscykeln:
 *   • signera (skicka beslut för underskrift) — normalt i beslut-steget.
 *   • commit (för till Treserva) — normalt när det finns något att registrera:
 *     utredning→beslut (utredningen förs över) och beslut (delgivning/registrering).
 * Åtgärder som inte står med här är alltid neutrala (öppna rum, chatt, möte, m.fl.).
 */
const TUNG_ATGARD_STEG = {
	signera: ['beslut'],
	commit: ['utredning', 'beslut'],
}

export default {
	name: 'NastaAtgardKnapp',
	components: { NcButton, NcActions, NcActionButton, LightningBoltIcon, DotsHorizontalIcon },
	props: {
		arende: { type: Object, required: true },
	},
	computed: {
		/** Exponera steg-kartan i mallens/computedens räckvidd. */
		tungAtgardSteg() {
			return TUNG_ATGARD_STEG
		},
		nasta() {
			return nastaFor(this.arende)
		},
		ovrigaAtgarder() {
			// Övriga lagliga åtgärder i fasen (utöver den ledande). De tunga, terminala
			// åtgärderna (signera/commit) är steg-medvetna: i fel steg tonas de ned med
			// en förklaring i st.f. att tas bort (A10 — icke-linjärt arbete är legitimt).
			const bas = [
				{ key: 'open-rum', label: t('hubs_start', 'Öppna ärenderum'), icon: 'FolderLock' },
				{ key: 'ny-chatt', label: t('hubs_start', 'Ny chatt i ärendet'), icon: 'Forum' },
				{ key: 'skicka', label: t('hubs_start', 'Skicka säkert meddelande'), icon: 'EmailFast' },
				{ key: 'boka-mote', label: t('hubs_start', 'Boka säkert möte'), icon: 'VideoPlus' },
				{ key: 'skapa-handling', label: t('hubs_start', 'Skapa handling från mall'), icon: 'FileDocumentPlus' },
				{ key: 'signera', label: t('hubs_start', 'Skicka för underskrift'), icon: 'FileSign' },
				{ key: 'commit', label: t('hubs_start', 'För till Treserva'), icon: 'FileExport' },
				{ key: 'bevakning', label: t('hubs_start', 'Skapa bevakning'), icon: 'BellPlus' },
				// Omfördelning bor här i menyn (inte som huvudknapp): ett ägt ärende
				// TAS inte — det omfördelas medvetet till en namngiven kollega.
				{ key: 'omfordela-kollega', label: t('hubs_start', 'Omfördela till kollega'), icon: 'AccountSwitch' },
			]
			const steg = (this.arende && this.arende.steg) || null
			const ovanligt = t('hubs_start', 'Ovanligt i detta steg')
			return bas.map((a) => {
				const normala = this.tungAtgardSteg[a.key]
				// Bara de definierade tunga åtgärderna är steg-filtrerade; övriga alltid neutrala.
				if (normala && steg && !normala.includes(steg)) {
					return { ...a, nedtonad: true, forklaring: ovanligt }
				}
				return { ...a, nedtonad: false, forklaring: null }
			})
		},
	},
	methods: { t, iconFor },
}
</script>

<style scoped lang="scss">
.nasta {
	display: flex;
	align-items: stretch;
	gap: 6px;

	&__primar {
		flex: 1;
		min-height: 44px;
	}

	// A10 — nedtonad tung åtgärd i fel steg: dämpad men FULLT klickbar (aldrig
	// disabled). Ikon + etikett mattas; förklaringen visas som NcActionButtons
	// inbyggda description-undertext (action-button__description).
	&__atgard--nedtonad {
		:deep(.action-button__icon),
		:deep(.action-button__longtext),
		:deep(.action-button__text) {
			opacity: 0.55;
		}

		:deep(.action-button__description) {
			font-style: italic;
		}
	}
}
</style>
