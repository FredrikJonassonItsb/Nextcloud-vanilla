<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - #5 — Avsluta-grind. Den TERMINALA åtgärden i livscykeln: för ärendet till
  - steg 'avslutat'. Detta är en ren steg-övergång (transitionSteg), INTE en ny
  - Treserva-registrering — akten är redan registrerad. En medveten bekräftelse
  - krävs eftersom avslut startar gallringen av Hubs-rummet.
  -
  - A9a/A9c — grinden samlar in ETT strukturerat motiv (radioval ur enum, aldrig
  - fri uppsats) som skickas vidare i steg-övergångens kontext så motorns A9-grind
  - (ArendeLifecycleService) släpper förbi den:
  -   • vid forhandsbedomning (→ avslutat utan utredning): inteInledaVal
  -     {orsak (radioval), beslutsfattare} — A9a.
  -   • annars (X → avslutat): avslutsmotiv {utfall (radioval), kvarstaende} — A9c.
  - Emit-formen matchar EXAKT kontrakts-nycklarna (MinaArenden trådar dem in i
  - transitionSteg-kontexten). 3 klick, ingen uppsats.
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

			<!-- A9a — förhandsbedömning som INTE leder till utredning: varför inte inleda
			     + vem som fattat beslutet. Motiveringen är särskilt viktig här
			     (SoL 20 kap. 2 § — motiverat beslut inom 14 dagar). -->
			<fieldset v-if="arForhandsbedomning" class="avsluta-grind__falt">
				<legend class="avsluta-grind__legend">
					{{ t('hubs_start', 'Varför inleds ingen utredning?') }}
				</legend>
				<NcCheckboxRadioSwitch
					v-for="o in orsakerInteInleda"
					:key="o.value"
					:value="o.value"
					:checked.sync="orsak"
					name="avsluta-inte-inleda-orsak"
					type="radio"
					:disabled="isRunning">
					{{ o.label }}
				</NcCheckboxRadioSwitch>

				<label class="avsluta-grind__label" for="avsluta-beslutsfattare">
					{{ t('hubs_start', 'Beslutsfattare') }}
				</label>
				<input
					id="avsluta-beslutsfattare"
					v-model="beslutsfattare"
					class="avsluta-grind__input"
					type="text"
					autocomplete="off"
					:placeholder="t('hubs_start', 'Namn eller roll (t.ex. 1:e socialsekreterare)')"
					:disabled="isRunning">
			</fieldset>

			<!-- A9c — övriga avslut (utredning/beslut/uppföljning → avslutat): utfallet
			     + om behov kvarstår. Underlaget för avslutsanteckningens utfall. -->
			<fieldset v-else class="avsluta-grind__falt">
				<legend class="avsluta-grind__legend">
					{{ t('hubs_start', 'Vad blev utfallet?') }}
				</legend>
				<NcCheckboxRadioSwitch
					v-for="u in utfallAvslut"
					:key="u.value"
					:value="u.value"
					:checked.sync="utfall"
					name="avsluta-utfall"
					type="radio"
					:disabled="isRunning">
					{{ u.label }}
				</NcCheckboxRadioSwitch>

				<NcCheckboxRadioSwitch
					:checked.sync="kvarstaende"
					:disabled="isRunning">
					{{ t('hubs_start', 'Behov kvarstår efter avslut (noteras för överlämning)') }}
				</NcCheckboxRadioSwitch>
			</fieldset>

			<!-- Mjuk varning: en aktiv övervägande-/omprövningsbevakning betyder att
			     ärendet står under lagstadgad uppföljning — avslut river den bevakningen.
			     Advisory, inte blockerande (avslut kan vara helt korrekt ändå). -->
			<p v-if="harAktivOmprovning" class="avsluta-grind__varning" role="status">
				<AlertIcon :size="16" />
				<span>{{ t('hubs_start', 'Ärendet har en aktiv övervägande-/omprövningsbevakning. Avslut avslutar den lagstadgade uppföljningen — säkerställ att den inte längre behövs.') }}</span>
			</p>

			<div class="avsluta-grind__footer">
				<NcButton :disabled="isRunning" @click="$emit('close')">
					{{ t('hubs_start', 'Avbryt') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="isRunning || !klarAttAvsluta"
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
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'

import ArchiveCheckIcon from 'vue-material-design-icons/ArchiveCheck.vue'
import AlertIcon from 'vue-material-design-icons/AlertCircleOutline.vue'

import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'AvslutaGrind',

	components: { NcModal, NcButton, NcLoadingIcon, NcCheckboxRadioSwitch, ArchiveCheckIcon, AlertIcon },

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
		/**
		 * Ärendets bevakningar (om föräldern känner dem) — driver den mjuka
		 * varningen om aktiv övervägande/omprövning. Valfri: utan lista visas
		 * ingen varning (graceful, aldrig ett falskt larm).
		 */
		bevakningar: {
			type: Array,
			default: () => [],
		},
	},

	data() {
		return {
			// A9a — inteInledaVal (förhandsbedömning → avslutat utan utredning).
			orsak: null,
			beslutsfattare: '',
			// A9c — avslutsmotiv (övriga steg → avslutat).
			utfall: null,
			kvarstaende: false,
		}
	},

	computed: {
		dialogTitle() {
			return t('hubs_start', 'Avsluta ärende')
		},

		/** Förhandsbedömning avslutas via inteInledaVal, alla andra steg via avslutsmotiv. */
		arForhandsbedomning() {
			return (this.arende && this.arende.steg) === 'forhandsbedomning'
		},

		/** A9a — orsaker att INTE inleda utredning (enum ur kontraktet). */
		orsakerInteInleda() {
			return [
				{ value: 'ingen_grund', label: t('hubs_start', 'Ingen grund för utredning') },
				{ value: 'annan_huvudman', label: t('hubs_start', 'Annan huvudman ansvarar') },
				{ value: 'redan_aktuell', label: t('hubs_start', 'Redan aktuell / pågående insats') },
				{ value: 'avskrivs', label: t('hubs_start', 'Avskrivs (t.ex. återtagen eller obefogad)') },
			]
		},

		/** A9c — utfall vid avslut (enum ur kontraktet). */
		utfallAvslut() {
			return [
				{ value: 'behov_tillgodosett', label: t('hubs_start', 'Behovet är tillgodosett') },
				{ value: 'flyttat', label: t('hubs_start', 'Personen har flyttat') },
				{ value: 'avbojt', label: t('hubs_start', 'Insats avböjd') },
				{ value: 'annan_insats', label: t('hubs_start', 'Övergått till annan insats') },
				{ value: 'overford_facksystem', label: t('hubs_start', 'Överförd till facksystemet') },
			]
		},

		/** Klar att avsluta: rätt obligatoriskt radioval är gjort för läget. */
		klarAttAvsluta() {
			return this.arForhandsbedomning ? !!this.orsak : !!this.utfall
		},

		/**
		 * Mjuk varning: finns en AKTIV övervägande-/omprövningsbevakning? Läser
		 * samma typ/status-fält som bevaknings-registret (typ innehåller
		 * 'overvagande'/'omprovning', status ej avbruten/avslutad).
		 */
		harAktivOmprovning() {
			return (this.bevakningar || []).some((b) => {
				const typ = String((b && b.typ) || '').toLowerCase()
				const status = String((b && b.status) || '').toLowerCase()
				const arOmprovning = typ.includes('overvagande') || typ.includes('övervägande')
					|| typ.includes('omprovning') || typ.includes('omprövning')
				const arAktiv = status !== 'avbruten' && status !== 'avslutad' && status !== 'slackt'
				return arOmprovning && arAktiv
			})
		},
	},

	methods: {
		t,

		/**
		 * Bygg kontext-objektet i EXAKT kontrakts-form och emitta. Andra argumentet
		 * (arende) behålls oförändrat — tredje (kontext) är nytt och trådas av
		 * MinaArenden in i transitionSteg-kontexten. Enum-koder, aldrig fri text.
		 */
		onAvsluta() {
			if (this.isRunning || !this.klarAttAvsluta) {
				return
			}
			let kontext
			if (this.arForhandsbedomning) {
				kontext = {
					inteInledaVal: {
						orsak: this.orsak,
						beslutsfattare: String(this.beslutsfattare || '').trim(),
					},
				}
			} else {
				kontext = {
					avslutsmotiv: {
						utfall: this.utfall,
						kvarstaende: !!this.kvarstaende,
					},
				}
			}
			this.$emit('avsluta', this.arende, kontext)
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

	&__falt {
		display: flex;
		flex-direction: column;
		gap: 4px;
		margin: 0;
		padding: 0;
		border: none;
	}

	&__legend {
		margin: 0 0 4px;
		padding: 0;
		font-weight: 600;
		font-size: 0.95rem;
	}

	&__label {
		margin: 10px 0 2px;
		font-weight: 600;
		font-size: 0.9rem;
	}

	&__input {
		width: 100%;
	}

	// Mjuk varning: varningston men lugn — advisory, aldrig blockerande.
	&__varning {
		display: flex;
		align-items: flex-start;
		gap: 8px;
		margin: 0;
		padding: 8px 12px;
		border-radius: var(--border-radius, 8px);
		background: var(--color-background-hover);
		color: var(--hs-status-warning, var(--color-warning-text, #9a5b00));
		font-size: 0.9rem;

		svg {
			flex-shrink: 0;
			margin-top: 1px;
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
