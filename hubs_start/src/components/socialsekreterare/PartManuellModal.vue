<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Lägg till part manuellt i partsregistret (utan uppslag). Motorn authz-grindar
  - visningen — PII får registreras och visas för behörig handläggare. K-NAV-5.2:
  - vid skyddad folkbokföring får adress INTE registreras (fältet disablas och
  - töms); posten hanteras via Skatteverkets förmedlingstjänst.
-->
<template>
	<NcModal
		:show="true"
		:name="t('hubs_start', 'Lägg till part manuellt')"
		size="small"
		:can-close="!isRunning"
		@close="$emit('close')">
		<div class="part-manuell">
			<p class="part-manuell__lead">
				<AccountPlusIcon :size="18" />
				<span>{{ t('hubs_start', 'Parten registreras direkt i ärendets partsregister utan uppslag. Uppgifterna kan verifieras i efterhand.') }}</span>
			</p>

			<label class="part-manuell__label" for="part-manuell-roll">{{ t('hubs_start', 'Roll') }}</label>
			<select
				id="part-manuell-roll"
				v-model="roll"
				class="part-manuell__input"
				:disabled="isRunning">
				<option v-for="r in rollAlternativ" :key="r.value" :value="r.value">
					{{ r.label }}
				</option>
			</select>

			<label class="part-manuell__label" for="part-manuell-namn">{{ t('hubs_start', 'Namn') }}</label>
			<input
				id="part-manuell-namn"
				v-model="namn"
				class="part-manuell__input"
				type="text"
				:placeholder="t('hubs_start', 'För- och efternamn')"
				:disabled="isRunning"
				@keyup.enter="onSkapa">

			<label class="part-manuell__label" for="part-manuell-pnr">{{ t('hubs_start', 'Personnummer (valfritt)') }}</label>
			<input
				id="part-manuell-pnr"
				v-model="personnummer"
				class="part-manuell__input"
				type="text"
				inputmode="numeric"
				:placeholder="t('hubs_start', 'ÅÅÅÅMMDD-XXXX')"
				:disabled="isRunning"
				@keyup.enter="onSkapa">

			<label class="part-manuell__label" for="part-manuell-adress">{{ t('hubs_start', 'Adress (valfri)') }}</label>
			<textarea
				id="part-manuell-adress"
				v-model="adress"
				class="part-manuell__input part-manuell__textarea"
				rows="3"
				:placeholder="t('hubs_start', 'Gatuadress, postnummer och ort')"
				:disabled="isRunning || adressSparrad" />
			<p v-if="adressSparrad" class="part-manuell__hint part-manuell__hint--skydd">
				<ShieldLockIcon :size="14" />
				<span>{{ t('hubs_start', 'Adress får inte registreras vid skyddad folkbokföring — post hanteras via Skatteverkets förmedlingstjänst.') }}</span>
			</p>

			<label class="part-manuell__label" for="part-manuell-kontakt">{{ t('hubs_start', 'Kontakt (valfri)') }}</label>
			<input
				id="part-manuell-kontakt"
				v-model="kontakt"
				class="part-manuell__input"
				type="text"
				:placeholder="t('hubs_start', 'Telefon eller e-post')"
				:disabled="isRunning"
				@keyup.enter="onSkapa">

			<fieldset class="part-manuell__skydd">
				<legend class="part-manuell__label">{{ t('hubs_start', 'Skydd') }}</legend>
				<label
					v-for="s in skyddAlternativ"
					:key="s.value"
					class="part-manuell__skydd-val"
					:class="{ 'part-manuell__skydd-val--vald': skydd === s.value }">
					<input
						v-model="skydd"
						type="radio"
						name="part-manuell-skydd"
						:value="s.value"
						:disabled="isRunning">
					<span class="part-manuell__skydd-text">
						<span class="part-manuell__skydd-titel">{{ s.label }}</span>
						<span class="part-manuell__skydd-besk">{{ s.beskrivning }}</span>
					</span>
				</label>
			</fieldset>

			<p v-if="fel" class="part-manuell__fel">
				<AlertCircleIcon :size="16" />
				<span>{{ fel }}</span>
			</p>

			<div class="part-manuell__footer">
				<NcButton :disabled="isRunning" @click="$emit('close')">
					{{ t('hubs_start', 'Avbryt') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="isRunning"
					@click="onSkapa">
					<template #icon>
						<NcLoadingIcon v-if="isRunning" :size="18" />
						<AccountPlusIcon v-else :size="18" />
					</template>
					{{ t('hubs_start', 'Lägg till') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'

import AccountPlusIcon from 'vue-material-design-icons/AccountPlus.vue'
import ShieldLockIcon from 'vue-material-design-icons/ShieldLock.vue'
import AlertCircleIcon from 'vue-material-design-icons/AlertCircle.vue'

import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'PartManuellModal',

	components: { NcModal, NcButton, NcLoadingIcon, AccountPlusIcon, ShieldLockIcon, AlertCircleIcon },

	props: {
		isRunning: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			roll: 'anmalare',
			namn: '',
			personnummer: '',
			adress: '',
			kontakt: '',
			skydd: 'ingen',
			fel: null,
		}
	},

	computed: {
		rollAlternativ() {
			return [
				{ value: 'barn', label: t('hubs_start', 'Barn') },
				{ value: 'vardnadshavare', label: t('hubs_start', 'Vårdnadshavare') },
				{ value: 'anmalare', label: t('hubs_start', 'Anmälare') },
				{ value: 'motpart', label: t('hubs_start', 'Motpart') },
				{ value: 'samverkanspart', label: t('hubs_start', 'Samverkanspart') },
				{ value: 'annan', label: t('hubs_start', 'Annan') },
			]
		},
		skyddAlternativ() {
			return [
				{
					value: 'ingen',
					label: t('hubs_start', 'Ingen'),
					beskrivning: t('hubs_start', 'Inga skyddsbehov registrerade.'),
				},
				{
					value: 'sekretessmarkering',
					label: t('hubs_start', 'Sekretessmarkerad'),
					beskrivning: t('hubs_start', 'Varningssignal — skadeprövning krävs före utlämnande. Adressen registreras och visas.'),
				},
				{
					value: 'skyddad_folkbokforing',
					label: t('hubs_start', 'Skyddad folkbokföring'),
					beskrivning: t('hubs_start', 'Adress får inte registreras — post går via Skatteverkets förmedlingstjänst.'),
				},
			]
		},
		adressSparrad() {
			return this.skydd === 'skyddad_folkbokforing'
		},
	},

	watch: {
		// K-NAV-5.2: vid skyddad folkbokföring får adress inte lagras — töm direkt.
		skydd(nytt) {
			if (nytt === 'skyddad_folkbokforing') {
				this.adress = ''
			}
		},
	},

	methods: {
		t,
		onSkapa() {
			if (this.isRunning) {
				return
			}
			this.fel = null

			if (!this.roll || !this.skydd) {
				this.fel = t('hubs_start', 'Roll och skydd måste anges.')
				return
			}

			// Personnummer är valfritt, men om angivet krävs exakt 12 siffror
			// efter rensning (ÅÅÅÅMMDDXXXX).
			let pnr = null
			if (this.personnummer.trim()) {
				const rensat = this.personnummer.replace(/\D/g, '')
				if (rensat.length !== 12) {
					this.fel = t('hubs_start', 'Personnumret måste bestå av 12 siffror (ÅÅÅÅMMDDXXXX).')
					return
				}
				pnr = rensat
			}

			// K-NAV-5.2: adress lagras aldrig vid skyddad folkbokföring.
			const adress = this.adressSparrad ? null : (this.adress.trim() || null)

			this.$emit('skapa', {
				roll: this.roll,
				skydd: this.skydd,
				namn: this.namn.trim(),
				personnummer: pnr,
				adress,
				kontakt: this.kontakt.trim() || null,
			})
		},
	},
}
</script>

<style scoped lang="scss">
.part-manuell {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 20px;

	&__lead {
		display: flex;
		gap: 8px;
		align-items: flex-start;
		color: var(--color-text-maxcontrast);
		margin-bottom: 4px;
	}

	&__label {
		font-weight: bold;
		margin-top: 4px;
	}

	&__input {
		width: 100%;
	}

	&__textarea {
		resize: vertical;
	}

	&__hint {
		display: flex;
		gap: 6px;
		align-items: flex-start;
		font-size: 0.9em;
		color: var(--color-text-maxcontrast);

		&--skydd {
			color: var(--color-error);
		}
	}

	&__skydd {
		border: none;
		padding: 0;
		margin: 0;
		display: flex;
		flex-direction: column;
		gap: 6px;
	}

	&__skydd-val {
		display: flex;
		gap: 10px;
		align-items: flex-start;
		padding: 8px 10px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
		cursor: pointer;

		&--vald {
			border-color: var(--color-primary-element);
			background-color: var(--color-primary-element-light);
		}

		input {
			margin-top: 3px;
			flex-shrink: 0;
		}
	}

	&__skydd-text {
		display: flex;
		flex-direction: column;
		gap: 2px;
	}

	&__skydd-titel {
		font-weight: bold;
	}

	&__skydd-besk {
		font-size: 0.9em;
		color: var(--color-text-maxcontrast);
	}

	&__fel {
		display: flex;
		gap: 6px;
		align-items: center;
		color: var(--color-error);
	}

	&__footer {
		display: flex;
		justify-content: flex-end;
		gap: 8px;
		margin-top: 8px;
	}
}
</style>
