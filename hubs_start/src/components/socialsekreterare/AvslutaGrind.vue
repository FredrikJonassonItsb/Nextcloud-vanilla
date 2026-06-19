<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - #5 — Avsluta-grind. Den TERMINALA åtgärden i livscykeln: för ärendet till
  - steg 'avslutat'. Detta är en ren steg-övergång (transitionSteg), INTE en ny
  - Treserva-registrering — akten är redan registrerad. En medveten bekräftelse
  - krävs eftersom avslut startar gallringen av Hubs-rummet.
-->
<template>
	<NcModal
		:show="true"
		:name="dialogTitle"
		size="small"
		:can-close="!isRunning"
		@close="$emit('close')">
		<div class="avsluta-grind">
			<p class="avsluta-grind__lead">
				<ArchiveCheckIcon :size="18" />
				<span>{{ t('hubs_start', 'Avsluta ärendet? Det markeras som avslutat och Hubs-rummet börjar gallras enligt gallringsregeln. Slutlagringen i facksystemet påverkas inte.') }}</span>
			</p>

			<div class="avsluta-grind__footer">
				<NcButton :disabled="isRunning" @click="$emit('close')">
					{{ t('hubs_start', 'Avbryt') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="isRunning"
					@click="onAvsluta">
					<template #icon>
						<NcLoadingIcon v-if="isRunning" :size="18" />
						<ArchiveCheckIcon v-else :size="18" />
					</template>
					{{ t('hubs_start', 'Avsluta ärende') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'

import ArchiveCheckIcon from 'vue-material-design-icons/ArchiveCheck.vue'

import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'AvslutaGrind',

	components: { NcModal, NcButton, NcLoadingIcon, ArchiveCheckIcon },

	props: {
		arende: {
			type: Object,
			required: true,
		},
		/** Föräldern sätter denna sant medan transitionSteg pågår. */
		isRunning: {
			type: Boolean,
			default: false,
		},
	},

	computed: {
		dialogTitle() {
			return t('hubs_start', 'Avsluta ärende')
		},
	},

	methods: {
		t,

		onAvsluta() {
			if (this.isRunning) {
				return
			}
			this.$emit('avsluta', this.arende)
		},
	},
}
</script>

<style scoped lang="scss">
.avsluta-grind {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 8px 4px;

	&__lead {
		display: flex;
		align-items: flex-start;
		gap: 8px;
		margin: 0;

		svg {
			flex-shrink: 0;
			margin-top: 1px;
			color: var(--color-text-maxcontrast);
		}
	}

	&__footer {
		display: flex;
		justify-content: flex-end;
		gap: 8px;
		padding-top: 4px;
	}
}
</style>
