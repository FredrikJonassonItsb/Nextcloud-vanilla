<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Fas F3 — Koppla-väljaren. Låter handläggaren VÄLJA vilket befintligt ärende ett
  - inflöde-meddelande ska kopplas till. På val körs den durabla, IDOR-säkra Väg A-
  - kopplingen (api.kopplaMeddelandeTillArende via store): synliga taggar i mail-
  - klienten (case:{id} → "Ärende {ref}", + "Behandlad") + referens-fil i ärendemappen.
  - "Kopplat" ska visas först när kvittot är verifierat — felkoppling är en
  - sekretessincident, så valet är ALLTID människo-bekräftat (ingen tyst auto-koppling).
-->
<template>
	<NcModal :show="true" size="normal" :name="t('hubs_start', 'Koppla meddelande till ärende')" @close="$emit('close')">
		<div class="koppla-valjare">
			<h3 class="koppla-valjare__rubrik">
				<LinkVariantIcon :size="20" />
				{{ t('hubs_start', 'Koppla till befintligt ärende') }}
			</h3>

			<p class="koppla-valjare__meddelande">
				{{ t('hubs_start', 'Meddelande:') }}
				<strong>{{ rad.titel || t('hubs_start', 'inkommande meddelande') }}</strong>
			</p>

			<NcTextField
				:value.sync="sok"
				:label="t('hubs_start', 'Sök ärende (referens, typ, steg)')"
				:label-visible="true"
				class="koppla-valjare__sok" />

			<ul class="koppla-valjare__lista" :aria-label="t('hubs_start', 'Välj målärende')">
				<li v-for="a in filtrerade" :key="a.hubsCaseId || a.triageRef">
					<button type="button" class="koppla-valjare__arende hs-target" @click="valj(a)">
						<span class="koppla-valjare__arende-ref">
							<FolderAccountOutlineIcon :size="18" />
							{{ a.triageRef || a.barnRef || a.hubsCaseId }}
						</span>
						<span class="koppla-valjare__arende-meta">
							<span class="koppla-valjare__arende-typ">{{ typLabel(a.arendeTyp) }}</span>
							<span class="koppla-valjare__arende-steg">{{ a.steg }}</span>
						</span>
					</button>
				</li>
				<li v-if="!filtrerade.length" class="koppla-valjare__tomt">
					{{ t('hubs_start', 'Inga ärenden matchar.') }}
				</li>
			</ul>

			<div class="koppla-valjare__footer">
				<NcButton type="tertiary" @click="$emit('close')">
					{{ t('hubs_start', 'Avbryt') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'

import LinkVariantIcon from 'vue-material-design-icons/LinkVariant.vue'
import FolderAccountOutlineIcon from 'vue-material-design-icons/FolderAccountOutline.vue'

import { translate as t } from '@nextcloud/l10n'

const TYP_LABEL = {
	orosanmalan: 'Orosanmälan',
	ansokan_bistand: 'Ansökan bistånd',
	ekonomi: 'Ekonomi',
	vard_samverkan: 'Vård/samverkan',
	verkstallighet: 'Verkställighet',
	familjeratt: 'Familjerätt',
	komplettering: 'Komplettering',
	rattsligt_tvang: 'Rättsligt tvång',
}

export default {
	name: 'KopplaValjare',

	components: {
		NcModal,
		NcTextField,
		NcButton,
		LinkVariantIcon,
		FolderAccountOutlineIcon,
	},

	props: {
		/** Inflöde-raden som ska kopplas (rad.id = meddelande-id, rad.titel). */
		rad: {
			type: Object,
			required: true,
		},
		/** Handläggarens ärenden (mapToCard-shape: hubsCaseId, triageRef, arendeTyp, steg…). */
		arenden: {
			type: Array,
			default: () => [],
		},
	},

	data() {
		return {
			sok: '',
		}
	},

	computed: {
		/** Ärenden filtrerade på sök-termen (referens/typ/steg). Endast de med hubsCaseId är kopplingsbara. */
		filtrerade() {
			const q = this.sok.trim().toLowerCase()
			const kopplingsbara = this.arenden.filter((a) => a && a.hubsCaseId)
			if (!q) {
				return kopplingsbara
			}
			return kopplingsbara.filter((a) => {
				const hay = [a.triageRef, a.barnRef, a.arendeTyp, this.typLabel(a.arendeTyp), a.steg, a.hubsCaseId]
					.filter(Boolean).join(' ').toLowerCase()
				return hay.includes(q)
			})
		},
	},

	methods: {
		t,

		typLabel(typ) {
			return TYP_LABEL[typ] || typ || ''
		},

		/** Bekräfta valet → den durabla Väg A-kopplingen körs i föräldern. */
		valj(a) {
			if (a && a.hubsCaseId) {
				this.$emit('valj', a.hubsCaseId)
			}
		},
	},
}
</script>

<style scoped lang="scss">
.koppla-valjare {
	padding: 20px;
	display: flex;
	flex-direction: column;
	gap: 12px;
	min-width: 0;

	&__rubrik {
		display: flex;
		align-items: center;
		gap: 8px;
		margin: 0;
		font-size: 1.1rem;
	}

	&__meddelande {
		margin: 0;
		color: var(--color-text-maxcontrast);
		font-size: 0.92rem;
	}

	&__sok {
		margin-top: 4px;
	}

	&__lista {
		display: flex;
		flex-direction: column;
		gap: 6px;
		margin: 0;
		padding: 0;
		list-style: none;
		max-height: 320px;
		overflow-y: auto;
	}

	&__arende {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
		width: 100%;
		padding: 10px 12px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large, 12px);
		background: var(--color-main-background);
		color: var(--color-main-text);
		cursor: pointer;
		text-align: start;

		&:hover {
			background: var(--color-background-hover);
			border-color: var(--color-primary-element);
		}
	}

	&__arende-ref {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		font-weight: 600;
	}

	&__arende-meta {
		display: inline-flex;
		align-items: center;
		gap: 10px;
		font-size: 0.82rem;
		color: var(--color-text-maxcontrast);
	}

	&__arende-typ {
		font-weight: 600;
	}

	&__tomt {
		padding: 12px;
		color: var(--color-text-maxcontrast);
		font-style: italic;
		list-style: none;
	}

	&__footer {
		display: flex;
		justify-content: flex-end;
		gap: 8px;
		margin-top: 4px;
	}
}
</style>
