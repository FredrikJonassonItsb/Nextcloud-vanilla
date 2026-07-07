<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Den juridiska gallringsgrinden. En allmän handling får ALDRIG raderas utan
  - dokumenterat stöd (OSL 5:1, arkivlagen 1990:782). "Gallra" är därför aldrig ett
  - naket klick: handläggaren måste välja handlingstyp ur dokumenthanteringsplanen,
  - se gallrings-/bevarandebeslutet och — om handlingen inte är av ringa betydelse —
  - tvingas in på vägen "Registrera utan ärende" istället för att radera.
-->
<template>
	<NcModal
		class="gallrings-grind"
		:name="t('hubs_start', 'Gallra utan ärende')"
		:show="true"
		size="normal"
		@close="onClose">
		<div class="gallrings-grind__body">
			<!-- Vad gäller raden — referens, aldrig klartext-PII -->
			<p class="gallrings-grind__rad" role="note">
				<FileDocumentOutlineIcon :size="16" />
				<span>{{ radReferens }}</span>
			</p>

			<!-- Den principiella spärren, alltid synlig -->
			<p class="gallrings-grind__princip">
				{{ t('hubs_start', 'En allmän handling får inte gallras utan stöd i dokumenthanteringsplanen. Välj handlingstyp för att se gällande beslut.') }}
			</p>

			<!-- Spärrande tomt-läge: i live utan konfigurerad plan får inget gallras. -->
			<div
				v-if="planSaknas"
				class="gallrings-grind__plan-saknas"
				role="alert">
				<AlertOctagonOutlineIcon :size="18" />
				<span>{{ t('hubs_start', 'Kommunens dokumenthanteringsplan är inte konfigurerad — gallring kan inte genomföras med dokumenterat stöd.') }}</span>
			</div>

			<!-- Steg 1: välj handlingstyp -->
			<template v-else>
				<label class="gallrings-grind__label" for="gallrings-grind-typ">
					{{ t('hubs_start', 'Handlingstyp enligt dokumenthanteringsplan') }}
				</label>
				<NcSelect
					v-model="valdTyp"
					input-id="gallrings-grind-typ"
					class="gallrings-grind__select"
					:options="typer"
					label="label"
					:clearable="false"
					:placeholder="t('hubs_start', 'Välj handlingstyp …')"
					:aria-label="t('hubs_start', 'Välj handlingstyp enligt dokumenthanteringsplan')" />
			</template>

			<!-- Steg 2: konsekvenstext (gallrings-/bevarandebeslut) -->
			<div
				v-if="valdTyp && !planSaknas"
				class="gallrings-grind__konsekvens"
				:class="konsekvensClass"
				role="status"
				aria-live="polite">
				<component :is="konsekvensIcon" :size="18" />
				<div class="gallrings-grind__konsekvens-text">
					<strong>{{ valdTyp.gallringsbeslut }}</strong>
					<span v-if="!valdTyp.ringa" class="gallrings-grind__tvingande">
						{{ t('hubs_start', 'Handlingen är inte av ringa betydelse — den ska registreras utan ärende, inte gallras.') }}
					</span>
					<span v-else class="gallrings-grind__ringa">
						{{ t('hubs_start', 'Av ringa betydelse — får gallras vid inaktualitet enligt beslutet ovan.') }}
					</span>
				</div>
			</div>
		</div>

		<div class="gallrings-grind__footer">
			<NcButton type="tertiary" @click="onClose">
				{{ t('hubs_start', 'Avbryt') }}
			</NcButton>

			<!-- Registrera: tvingande väg när handlingen ej är ringa, annars alltid tillåten -->
			<NcButton
				type="secondary"
				:disabled="!valdTyp || planSaknas"
				@click="onRegistrera">
				<template #icon>
					<ArchiveArrowDownOutlineIcon :size="20" />
				</template>
				{{ t('hubs_start', 'Registrera utan ärende') }}
			</NcButton>

			<!-- Gallra: aktiv ENBART när vald typ är av ringa betydelse -->
			<NcButton
				type="error"
				:disabled="!gallraTillaten"
				@click="onGallra">
				<template #icon>
					<DeleteOutlineIcon :size="20" />
				</template>
				{{ t('hubs_start', 'Gallra utan ärende') }}
			</NcButton>
		</div>
	</NcModal>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'

import FileDocumentOutlineIcon from 'vue-material-design-icons/FileDocumentOutline.vue'
import DeleteOutlineIcon from 'vue-material-design-icons/DeleteOutline.vue'
import ArchiveArrowDownOutlineIcon from 'vue-material-design-icons/ArchiveArrowDownOutline.vue'
import AlertOctagonOutlineIcon from 'vue-material-design-icons/AlertOctagonOutline.vue'
import CheckCircleOutlineIcon from 'vue-material-design-icons/CheckCircleOutline.vue'

import { translate as t } from '@nextcloud/l10n'

import { isDemo } from '../../services/demoData.js'

export default {
	name: 'GallringsGrind',

	components: {
		NcButton,
		NcModal,
		NcSelect,
		FileDocumentOutlineIcon,
		DeleteOutlineIcon,
		ArchiveArrowDownOutlineIcon,
		AlertOctagonOutlineIcon,
		CheckCircleOutlineIcon,
	},

	props: {
		/** InflodeRad-shape — raden som ska gallras eller registreras. */
		rad: {
			type: Object,
			required: true,
		},
		/**
		 * Handlingstyper ur kommunens dokumenthanteringsplan. I demoläge faller
		 * grinden tillbaka på en inbäddad demo-lista om tom; i skarp drift utan
		 * konfigurerad plan spärras gallring helt (planSaknas).
		 */
		handlingstyper: {
			type: Array,
			default: () => [],
		},
	},

	data() {
		return {
			valdTyp: null,
		}
	},

	computed: {
		/**
		 * Effektiva handlingstyper — kommunens plan om angiven. Den inbäddade
		 * fixture-listan får ALDRIG användas i skarp drift: ett formellt
		 * gallringsbeslut måste vila på kommunens faktiska plan.
		 */
		typer() {
			if (this.handlingstyper && this.handlingstyper.length) {
				return this.handlingstyper
			}
			if (!isDemo()) {
				return []
			}
			return [
				{ id: 'reklam', label: this.t('hubs_start', 'Reklam/spam'), gallringsbeslut: this.t('hubs_start', 'Får gallras vid inaktualitet'), ringa: true },
				{ id: 'autosvar', label: this.t('hubs_start', 'Automatiskt svar'), gallringsbeslut: this.t('hubs_start', 'Får gallras vid inaktualitet'), ringa: true },
				{ id: 'fraga_ringa', label: this.t('hubs_start', 'Fråga av ringa betydelse'), gallringsbeslut: this.t('hubs_start', 'Får gallras vid inaktualitet'), ringa: true },
				{ id: 'handling', label: this.t('hubs_start', 'Allmän handling (ej ringa)'), gallringsbeslut: this.t('hubs_start', 'Får EJ gallras — ska registreras'), ringa: false },
			]
		},

		/** Spärrande tomt-läge: i skarp drift utan konfigurerad dokumenthanteringsplan. */
		planSaknas() {
			return this.typer.length === 0
		},

		/** Gallra-knappen är aktiv bara när planen finns, en typ är vald OCH den är av ringa betydelse. */
		gallraTillaten() {
			return !this.planSaknas && !!(this.valdTyp && this.valdTyp.ringa)
		},

		/** Referens till raden — aldrig klartext-PII, bara ärende-/triagereferens. */
		radReferens() {
			const r = this.rad || {}
			return r.titel || (r.koppling && r.koppling.triageRef) || this.t('hubs_start', 'Ej ärendekopplat inflöde')
		},

		konsekvensClass() {
			return this.gallraTillaten
				? 'gallrings-grind__konsekvens--ringa'
				: 'gallrings-grind__konsekvens--tvingande'
		},

		konsekvensIcon() {
			return this.gallraTillaten ? 'CheckCircleOutlineIcon' : 'AlertOctagonOutlineIcon'
		},
	},

	methods: {
		t,

		onGallra() {
			if (!this.gallraTillaten) {
				return
			}
			this.$emit('gallra', { rad: this.rad, handlingstyp: this.valdTyp })
		},

		onRegistrera() {
			if (!this.valdTyp || this.planSaknas) {
				return
			}
			this.$emit('registrera', { rad: this.rad, handlingstyp: this.valdTyp })
		},

		onClose() {
			this.$emit('close')
		},
	},
}
</script>

<style scoped lang="scss">
.gallrings-grind {
	&__body {
		display: flex;
		flex-direction: column;
		gap: 12px;
		padding: 4px 0 8px;
	}

	&__footer {
		display: flex;
		justify-content: flex-end;
		flex-wrap: wrap;
		gap: 8px;
		padding-top: 12px;
	}

	&__rad {
		display: flex;
		align-items: center;
		gap: 6px;
		margin: 0;
		padding: 8px 10px;
		border-radius: var(--border-radius, 8px);
		background: var(--color-background-dark);
		font-weight: 600;
		color: var(--color-main-text);
	}

	&__princip {
		margin: 0;
		font-size: 0.88rem;
		color: var(--color-text-maxcontrast);
	}

	// Spärrande tomt-läge: skarp drift utan konfigurerad dokumenthanteringsplan.
	&__plan-saknas {
		display: flex;
		align-items: flex-start;
		gap: 8px;
		padding: 10px 12px;
		border-radius: var(--border-radius-large, 12px);
		border: 1px solid var(--hs-status-error);
		background: color-mix(in srgb, var(--hs-status-error) 12%, var(--color-main-background));
		color: var(--hs-status-error);
		font-weight: 600;
	}

	&__label {
		font-weight: 600;
		font-size: 0.9rem;
		color: var(--color-main-text);
	}

	&__select {
		width: 100%;
		max-width: 100%;
	}

	&__konsekvens {
		display: flex;
		align-items: flex-start;
		gap: 8px;
		padding: 10px 12px;
		border-radius: var(--border-radius-large, 12px);
		border: 1px solid var(--gg-color, var(--color-border));
		background: color-mix(in srgb, var(--gg-color, var(--color-border)) 12%, var(--color-main-background));
		color: var(--gg-color, var(--color-main-text));

		&--ringa { --gg-color: var(--hs-status-warning); }
		&--tvingande { --gg-color: var(--hs-status-error); }
	}

	&__konsekvens-text {
		display: flex;
		flex-direction: column;
		gap: 4px;
		color: var(--color-main-text);

		strong { color: var(--gg-color, var(--color-main-text)); }
	}

	&__tvingande,
	&__ringa {
		font-size: 0.85rem;
		color: var(--color-text-maxcontrast);
	}

	// Reflow: stapla konsekvensblocket på smala ytor.
	@media (max-width: 720px) {
		&__konsekvens {
			flex-wrap: wrap;
		}
	}
}
</style>
