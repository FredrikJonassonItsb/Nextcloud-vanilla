<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Omfördela ett ärende till en namngiven kollega (menyval — ett ägt ärende
  - "tas" inte, det omfördelas medvetet). Motorn validerar att kollegan är en
  - riktig användare, kör hela handoff:en (ACL, grupp, kalender-re-home, chatt)
  - och notifierar den nya handläggaren.
-->
<template>
	<NcModal
		:show="true"
		:name="t('hubs_start', 'Omfördela ärendet')"
		size="small"
		:can-close="!isRunning"
		@close="$emit('close')">
		<div class="omfordela">
			<p class="omfordela__lead">
				<AccountSwitchIcon :size="18" />
				<span>{{ t('hubs_start', 'Ärendet flyttas till kollegan: hen blir handläggare, får åtkomst till rummet och notifieras. Din åtkomst följer handoff-reglerna.') }}</span>
			</p>

			<label class="omfordela__label" for="omfordela-uid">{{ t('hubs_start', 'Kollega') }}</label>
			<!-- Plattformens standard-användarsök (namn räcker — inget exakt uid). -->
			<AnvandarValjare
				v-model="uid"
				:placeholder="t('hubs_start', 'Sök kollega (namn)')"
				:disabled="isRunning" />

			<div class="omfordela__footer">
				<NcButton :disabled="isRunning" @click="$emit('close')">
					{{ t('hubs_start', 'Avbryt') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="!uid.trim() || isRunning"
					@click="onOmfordela">
					<template #icon>
						<NcLoadingIcon v-if="isRunning" :size="18" />
						<AccountSwitchIcon v-else :size="18" />
					</template>
					{{ t('hubs_start', 'Omfördela') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'

import AccountSwitchIcon from 'vue-material-design-icons/AccountSwitch.vue'

import { translate as t } from '@nextcloud/l10n'
import AnvandarValjare from './AnvandarValjare.vue'

export default {
	name: 'OmfordelaModal',

	components: { NcModal, NcButton, NcLoadingIcon, AccountSwitchIcon, AnvandarValjare },

	props: {
		arende: {
			type: Object,
			required: true,
		},
		isRunning: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			uid: '',
		}
	},

	methods: {
		t,
		onOmfordela() {
			const uid = this.uid.trim()
			if (!uid || this.isRunning) {
				return
			}
			this.$emit('omfordela', this.arende, uid)
		},
	},
}
</script>

<style scoped lang="scss">
.omfordela {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;

	&__lead {
		display: flex;
		gap: 8px;
		align-items: flex-start;
		color: var(--color-text-maxcontrast);
	}

	&__label {
		font-weight: bold;
	}

	&__input {
		width: 100%;
	}

	&__footer {
		display: flex;
		justify-content: flex-end;
		gap: 8px;
		margin-top: 8px;
	}
}
</style>
