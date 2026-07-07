<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Folkbokföringsuppslag (Navet) till ärendets partsregister. Modalen samlar in
  - personnummer, roll och ett OBLIGATORISKT ändamål (journalförs med uppslaget
  - för spårbarhet) och emittar 'uppslag' — motorn gör själva uppslaget och
  - authz-grindar vem som ser uppgifterna. PII-visning för behörig handläggare
  - är avsedd; invarianten är behörighetsgränsen.
-->
<template>
	<NcModal
		:show="true"
		:name="t('hubs_start', 'Hämta part från folkbokföringen')"
		size="small"
		:can-close="!isRunning"
		@close="$emit('close')">
		<div class="part-uppslag">
			<p class="part-uppslag__lead">
				<ShieldLockIcon :size="18" />
				<span>{{ t('hubs_start', 'Uppslaget hämtar folkbokföringsuppgifter till ärendets partsregister. Endast behöriga i ärendet ser uppgifterna.') }}</span>
			</p>

			<label class="part-uppslag__label" for="part-uppslag-pnr">{{ t('hubs_start', 'Personnummer') }}</label>
			<input
				id="part-uppslag-pnr"
				v-model="personnummer"
				class="part-uppslag__input"
				type="text"
				inputmode="numeric"
				:placeholder="t('hubs_start', 'ÅÅÅÅMMDDNNNN (12 siffror)')"
				:disabled="isRunning"
				@input="felmeddelande = null">

			<label class="part-uppslag__label" for="part-uppslag-roll">{{ t('hubs_start', 'Roll') }}</label>
			<select
				id="part-uppslag-roll"
				v-model="roll"
				class="part-uppslag__input"
				:disabled="isRunning">
				<option v-for="r in rollAlternativ" :key="r.value" :value="r.value">
					{{ r.label }}
				</option>
			</select>

			<label class="part-uppslag__label" for="part-uppslag-andamal">{{ t('hubs_start', 'Ändamål') }}</label>
			<input
				id="part-uppslag-andamal"
				v-model="andamal"
				class="part-uppslag__input"
				type="text"
				:placeholder="t('hubs_start', 'T.ex. Dokumentifyllnad utredning')"
				:disabled="isRunning"
				@input="felmeddelande = null"
				@keyup.enter="onHamta">
			<p class="part-uppslag__hjalp">
				{{ t('hubs_start', 'Ändamålet journalförs tillsammans med uppslaget (spårbarhet).') }}
			</p>

			<label class="part-uppslag__checkbox">
				<input
					v-model="inkluderaVardnadshavare"
					type="checkbox"
					:disabled="isRunning"
					@change="vardnadshavareRord = true">
				<span>{{ t('hubs_start', 'Hämta även vårdnadshavare (för barn)') }}</span>
			</label>

			<p v-if="felmeddelande" class="part-uppslag__fel" role="alert">
				{{ felmeddelande }}
			</p>

			<div class="part-uppslag__footer">
				<NcButton :disabled="isRunning" @click="$emit('close')">
					{{ t('hubs_start', 'Avbryt') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="isRunning || !kanHamta"
					@click="onHamta">
					<template #icon>
						<NcLoadingIcon v-if="isRunning" :size="18" />
						<AccountSearchIcon v-else :size="18" />
					</template>
					{{ t('hubs_start', 'Hämta') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'

import AccountSearchIcon from 'vue-material-design-icons/AccountSearch.vue'
import ShieldLockIcon from 'vue-material-design-icons/ShieldLock.vue'

import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'PartUppslagModal',

	components: { NcModal, NcButton, NcLoadingIcon, AccountSearchIcon, ShieldLockIcon },

	props: {
		isRunning: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			personnummer: '',
			roll: 'barn',
			andamal: '',
			// Default ikryssad eftersom roll default är barn.
			inkluderaVardnadshavare: true,
			// När användaren själv rört checkboxen slutar watchern justera den.
			vardnadshavareRord: false,
			felmeddelande: null,
			rollAlternativ: [
				{ value: 'barn', label: t('hubs_start', 'Barn') },
				{ value: 'vardnadshavare', label: t('hubs_start', 'Vårdnadshavare') },
				{ value: 'anmalare', label: t('hubs_start', 'Anmälare') },
				{ value: 'motpart', label: t('hubs_start', 'Motpart') },
				{ value: 'samverkanspart', label: t('hubs_start', 'Samverkanspart') },
				{ value: 'annan', label: t('hubs_start', 'Annan') },
			],
		}
	},

	computed: {
		/** Personnummer rensat från bindestreck och mellanslag. */
		rensatPersonnummer() {
			return this.personnummer.replace(/[\s-]/g, '')
		},
		pnrGiltigt() {
			return /^\d{12}$/.test(this.rensatPersonnummer)
		},
		andamalGiltigt() {
			return this.andamal.trim() !== ''
		},
		kanHamta() {
			return this.pnrGiltigt && this.andamalGiltigt
		},
	},

	watch: {
		roll(nyRoll) {
			if (!this.vardnadshavareRord) {
				this.inkluderaVardnadshavare = nyRoll === 'barn'
			}
		},
	},

	methods: {
		t,
		onHamta() {
			if (this.isRunning) {
				return
			}
			if (!this.pnrGiltigt) {
				this.felmeddelande = t('hubs_start', 'Personnumret måste vara exakt 12 siffror (ÅÅÅÅMMDDNNNN).')
				return
			}
			if (!this.andamalGiltigt) {
				this.felmeddelande = t('hubs_start', 'Ange ändamål — det journalförs tillsammans med uppslaget.')
				return
			}
			this.felmeddelande = null
			this.$emit('uppslag', {
				personnummer: this.rensatPersonnummer,
				roll: this.roll,
				andamal: this.andamal.trim(),
				inkluderaVardnadshavare: this.inkluderaVardnadshavare,
			})
		},
	},
}
</script>

<style scoped lang="scss">
.part-uppslag {
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

	&__hjalp {
		margin: -8px 0 0;
		font-size: 0.85em;
		color: var(--color-text-maxcontrast);
	}

	&__checkbox {
		display: flex;
		align-items: center;
		gap: 8px;

		input {
			margin: 0;
		}
	}

	&__fel {
		margin: 0;
		color: var(--color-error, #b3261e);
	}

	&__footer {
		display: flex;
		justify-content: flex-end;
		gap: 8px;
		margin-top: 8px;
	}
}
</style>
