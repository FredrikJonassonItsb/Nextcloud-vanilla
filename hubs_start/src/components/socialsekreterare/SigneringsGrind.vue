<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - #6 — Signerings-grind. Temporär brygga som löser den upplevda "flödet bröts"-
  - känslan efter att handläggaren signerat i underskriftstjänsten: en enkel
  - bekräftelse-kryssruta → en tydlig "Nästa steg"-knapp som öppnar CommitGrind.
  - INTE en inbäddad iframe (det är en separat, större backlog-post). Grinden
  - avancerar ALDRIG steg eller flippar provenans själv — det sker först på en
  - verifierad commit i CommitGrind/onCommitted.
-->
<template>
	<NcModal
		:show="true"
		:name="dialogTitle"
		size="small"
		@close="$emit('close')">
		<div class="signerings-grind">
			<p class="signerings-grind__lead">
				<DrawPenIcon :size="18" />
				<span>{{ t('hubs_start', 'Har du signerat dokumentet i underskriftstjänsten? Bekräfta nedan så går vi vidare till överföringen.') }}</span>
			</p>

			<NcCheckboxRadioSwitch :checked.sync="signerad">
				{{ t('hubs_start', 'Jag har signerat dokumentet') }}
			</NcCheckboxRadioSwitch>

			<div class="signerings-grind__footer">
				<NcButton @click="$emit('close')">
					{{ t('hubs_start', 'Avbryt') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="!signerad"
					@click="onNastaSteg">
					<template #icon>
						<TransferIcon :size="18" />
					</template>
					{{ t('hubs_start', 'Nästa steg: För till Treserva') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'

import DrawPenIcon from 'vue-material-design-icons/DrawPen.vue'
import TransferIcon from 'vue-material-design-icons/Transfer.vue'

import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'SigneringsGrind',

	components: { NcModal, NcButton, NcCheckboxRadioSwitch, DrawPenIcon, TransferIcon },

	props: {
		arende: {
			type: Object,
			required: true,
		},
	},

	data() {
		return {
			signerad: false,
		}
	},

	computed: {
		dialogTitle() {
			return t('hubs_start', 'Signering')
		},
	},

	methods: {
		t,

		/** Bekräftat signerat → låt föräldern öppna CommitGrind (typ 'signerat-beslut'). */
		onNastaSteg() {
			if (!this.signerad) {
				return
			}
			this.$emit('nasta-steg', this.arende)
		},
	},
}
</script>

<style scoped lang="scss">
.signerings-grind {
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
