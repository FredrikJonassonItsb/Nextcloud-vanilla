<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - #12 — "Egna anteckningar": handläggarens PRIVATA anteckningar. Per-användare,
  - krypterat, aldrig delat med teamet (backas av ett privat 1:1-rum). Append-only
  - lista (nyast först) + ett skrivfält. Brand-säkert: inga produktnamn i UI.
-->
<template>
	<NcModal
		:show="true"
		:name="dialogTitle"
		size="small"
		@close="$emit('close')">
		<div class="mina-anteckningar">
			<p class="mina-anteckningar__lead">
				{{ t('hubs_start', 'Privata anteckningar — bara du ser dem. De delas aldrig med teamet eller ärendet.') }}
			</p>

			<!-- Skrivfält -->
			<form class="mina-anteckningar__skriv" @submit.prevent="onSpara">
				<label class="mina-anteckningar__sr-only" for="mina-anteckningar-utkast">
					{{ t('hubs_start', 'Skriv en anteckning') }}
				</label>
				<textarea
					id="mina-anteckningar-utkast"
					v-model="utkast"
					class="mina-anteckningar__textarea"
					rows="2"
					:disabled="saving"
					:placeholder="t('hubs_start', 'Skriv en anteckning…')" />
				<NcButton
					type="primary"
					native-type="submit"
					:disabled="!kanSpara">
					<template #icon>
						<NcLoadingIcon v-if="saving" :size="18" />
						<ContentSaveIcon v-else :size="18" />
					</template>
					{{ t('hubs_start', 'Spara') }}
				</NcButton>
			</form>

			<!-- Lista -->
			<NcLoadingIcon v-if="loading" :size="32" class="mina-anteckningar__loading" />
			<ol v-else-if="notes.length" class="mina-anteckningar__lista">
				<li v-for="note in notes" :key="note.id" class="mina-anteckningar__note">
					<p class="mina-anteckningar__text">{{ note.text }}</p>
					<span class="mina-anteckningar__tid">{{ kortTid(note.createdAt) }}</span>
				</li>
			</ol>
			<p v-else class="mina-anteckningar__tom">
				{{ t('hubs_start', 'Inga anteckningar än.') }}
			</p>
		</div>
	</NcModal>
</template>

<script>
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'

import ContentSaveIcon from 'vue-material-design-icons/ContentSave.vue'

import { translate as t } from '@nextcloud/l10n'
import { showError } from '@nextcloud/dialogs'

import api from '../../services/api.js'

export default {
	name: 'MinaAnteckningar',

	components: { NcModal, NcButton, NcLoadingIcon, ContentSaveIcon },

	props: {
		/** Ärendet modalen öppnades från (kontext; anteckningarna är dock per-användare). */
		arende: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		return {
			notes: [],
			utkast: '',
			loading: true,
			saving: false,
		}
	},

	computed: {
		dialogTitle() {
			return t('hubs_start', 'Egna anteckningar')
		},
		kanSpara() {
			return !this.saving && this.utkast.trim().length > 0
		},
	},

	created() {
		this.load()
	},

	methods: {
		t,

		async load() {
			this.loading = true
			try {
				const res = await api.fetchNotes()
				this.notes = (res && res.notes) || []
			} catch (e) {
				this.notes = []
			} finally {
				this.loading = false
			}
		},

		async onSpara() {
			const text = this.utkast.trim()
			if (!text || this.saving) {
				return
			}
			this.saving = true
			try {
				const res = await api.addNote(text)
				if (res && res.note) {
					this.notes.unshift(res.note)
					this.utkast = ''
				} else {
					showError(t('hubs_start', 'Kunde inte spara anteckningen. Försök igen.'))
				}
			} catch (e) {
				showError(t('hubs_start', 'Kunde inte spara anteckningen. Försök igen.'))
			} finally {
				this.saving = false
			}
		},

		/** Kort sv-SE-tid; tål trasig ISO. */
		kortTid(iso) {
			if (!iso) {
				return ''
			}
			const d = new Date(iso)
			if (isNaN(d.getTime())) {
				return String(iso)
			}
			return d.toLocaleString('sv-SE', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
		},
	},
}
</script>

<style scoped lang="scss">
.mina-anteckningar {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 4px;

	&__lead {
		margin: 0;
		color: var(--color-text-maxcontrast);
		font-size: 0.88rem;
	}

	&__skriv {
		display: flex;
		align-items: flex-end;
		gap: 8px;
	}

	&__textarea {
		flex: 1 1 auto;
		min-width: 0;
		min-height: 40px;
		padding: 6px 10px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large, 12px);
		background: var(--color-main-background);
		color: var(--color-main-text);
		font: inherit;
		resize: vertical;
	}

	&__loading {
		margin: 16px auto;
	}

	&__lista {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 8px;
		max-height: 320px;
		overflow-y: auto;
	}

	&__note {
		padding: 8px 10px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large, 12px);
		background: var(--color-main-background);
	}

	&__text {
		margin: 0;
		font-size: 0.9rem;
		color: var(--color-main-text);
		white-space: pre-wrap;
		overflow-wrap: anywhere;
	}

	&__tid {
		display: block;
		margin-top: 2px;
		font-size: 0.78rem;
		color: var(--color-text-maxcontrast);
	}

	&__tom {
		margin: 8px 0;
		color: var(--color-text-maxcontrast);
		font-size: 0.88rem;
	}

	&__sr-only {
		position: absolute;
		width: 1px;
		height: 1px;
		padding: 0;
		margin: -1px;
		overflow: hidden;
		clip: rect(0, 0, 0, 0);
		white-space: nowrap;
		border: 0;
	}
}
</style>
