<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Ny chatt i ärenderummet (1:n). Motorn skapar Talk-rummet med ärendets aktiva
  - åtkomstlista + TEAMET som deltagare och bokför en talk_room-pekare (chatten
  - gallras med ärendet). Namnet är valfritt — utan namn används det pseudonyma
  - hubsCaseId:t. OBS: skriv aldrig personuppgifter i rumsnamnet (namnet syns i
  - Talk-listan för alla deltagare).
-->
<template>
	<NcModal
		:show="true"
		:name="t('hubs_start', 'Ny chatt i ärendet')"
		size="small"
		:can-close="!isRunning"
		@close="$emit('close')">
		<div class="ny-chatt">
			<p class="ny-chatt__lead">
				<ForumIcon :size="18" />
				<span>{{ t('hubs_start', 'Chatten kopplas till ärenderummet: rätt personer blir deltagare automatiskt, den visas på ärendets teamsida och gallras med ärendet.') }}</span>
			</p>

			<label class="ny-chatt__label" for="ny-chatt-namn">{{ t('hubs_start', 'Namn (valfritt — inga personuppgifter)') }}</label>
			<input
				id="ny-chatt-namn"
				v-model="namn"
				class="ny-chatt__input"
				type="text"
				:placeholder="t('hubs_start', 'T.ex. Samverkan skola')"
				:disabled="isRunning"
				@keyup.enter="onSkapa">

			<div class="ny-chatt__footer">
				<NcButton :disabled="isRunning" @click="$emit('close')">
					{{ t('hubs_start', 'Avbryt') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="isRunning"
					@click="onSkapa">
					<template #icon>
						<NcLoadingIcon v-if="isRunning" :size="18" />
						<ForumIcon v-else :size="18" />
					</template>
					{{ t('hubs_start', 'Skapa chatt') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'

import ForumIcon from 'vue-material-design-icons/Forum.vue'

import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'NyChattModal',

	components: { NcModal, NcButton, NcLoadingIcon, ForumIcon },

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
			namn: '',
		}
	},

	methods: {
		t,
		onSkapa() {
			if (this.isRunning) {
				return
			}
			// Tomt namn ⇒ null ⇒ motorn använder det pseudonyma hubsCaseId:t (M2).
			const namn = this.namn.trim() || null
			this.$emit('skapa', this.arende, namn)
		},
	},
}
</script>

<style scoped lang="scss">
.ny-chatt {
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
