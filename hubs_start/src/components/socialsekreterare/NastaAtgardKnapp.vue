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
			<NcActionButton
				v-for="a in ovrigaAtgarder"
				:key="a.key"
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

export default {
	name: 'NastaAtgardKnapp',
	components: { NcButton, NcActions, NcActionButton, LightningBoltIcon, DotsHorizontalIcon },
	props: {
		arende: { type: Object, required: true },
	},
	computed: {
		nasta() {
			return nastaFor(this.arende)
		},
		ovrigaAtgarder() {
			// Övriga lagliga åtgärder i fasen (utöver den ledande).
			return [
				{ key: 'open-rum', label: t('hubs_start', 'Öppna ärenderum'), icon: 'FolderLock' },
				{ key: 'skicka', label: t('hubs_start', 'Skicka säkert meddelande'), icon: 'EmailFast' },
				{ key: 'boka-mote', label: t('hubs_start', 'Boka säkert möte'), icon: 'VideoPlus' },
				{ key: 'signera', label: t('hubs_start', 'Skicka för underskrift'), icon: 'FileSign' },
				{ key: 'commit', label: t('hubs_start', 'För till Treserva'), icon: 'FileExport' },
				{ key: 'bevakning', label: t('hubs_start', 'Skapa bevakning'), icon: 'BellPlus' },
			]
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
}
</style>
