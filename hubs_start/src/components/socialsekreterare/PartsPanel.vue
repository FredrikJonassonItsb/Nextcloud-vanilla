<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Partsregistret: ärendets parter (barn, vårdnadshavare, anmälare …) ur motorn
  - (hubs_arende v0.9.0). PII-visning för behörig handläggare är AVSEDD — motorn
  - authz-grindar; invarianten är behörighetsgränsen, inte döljning. Skydds-UI:t
  - (K-NAV-5.2/5.3) är däremot KRITISKT: skyddad folkbokföring ⇒ ingen verklig
  - adress finns här (förmedlingstjänst-text + ev. särskild postadress);
  - sekretessmarkering ⇒ varningssignal men adressen visas (posten levereras hel).
-->
<template>
	<div class="parts-panel">
		<!-- Huvudåtgärder -->
		<div class="parts-panel__atgarder">
			<NcButton type="primary" :disabled="modalKor" @click="uppslagOpen = true">
				<template #icon>
					<DatabaseSyncIcon :size="18" />
				</template>
				{{ t('hubs_start', 'Hämta från folkbokföringen') }}
			</NcButton>
			<NcButton type="secondary" :disabled="modalKor" @click="manuellOpen = true">
				<template #icon>
					<AccountMultiplePlusIcon :size="18" />
				</template>
				{{ t('hubs_start', 'Lägg till manuellt') }}
			</NcButton>
		</div>

		<!-- Ladd-/fel-/tomstate -->
		<p v-if="laddar" class="parts-panel__muted parts-panel__status">
			<NcLoadingIcon :size="16" /> {{ t('hubs_start', 'Hämtar parter …') }}
		</p>
		<p v-else-if="felVidLaddning" class="parts-panel__muted parts-panel__status">
			{{ t('hubs_start', 'Parterna kunde inte hämtas just nu.') }}
		</p>
		<p v-else-if="!parter.length" class="parts-panel__muted parts-panel__status">
			{{ t('hubs_start', 'Inga parter registrerade ännu — hämta från folkbokföringen eller lägg till manuellt.') }}
		</p>

		<!-- Partslistan -->
		<ul v-else class="parts-panel__list">
			<li v-for="part in parter" :key="part.id" class="parts-panel__part">
				<!-- Rad 1: namn + roll + skydd + fbf-status + radåtgärder -->
				<div class="parts-panel__rad1">
					<strong class="parts-panel__namn">{{ part.namn || '—' }}</strong>
					<span class="parts-panel__chip parts-panel__chip--roll">{{ rollLabel(part.roll) }}</span>

					<span
						v-if="part.skydd === 'skyddad_folkbokforing'"
						class="parts-panel__badge parts-panel__badge--skyddad">
						<ShieldLockIcon :size="13" />
						{{ t('hubs_start', 'Skyddad folkbokföring') }}
					</span>
					<span
						v-else-if="part.skydd === 'sekretessmarkering'"
						class="parts-panel__badge parts-panel__badge--sekretess"
						:title="t('hubs_start', 'Varningssignal — skadeprövning krävs före utlämnande')">
						<AlertOutlineIcon :size="13" />
						{{ t('hubs_start', 'Sekretessmarkerad') }}
					</span>

					<span v-if="part.fbfStatus === 'avliden'" class="parts-panel__chip parts-panel__chip--fbf">
						{{ t('hubs_start', 'Avliden') }}
					</span>
					<span v-else-if="part.fbfStatus === 'utvandrad'" class="parts-panel__chip parts-panel__chip--fbf">
						{{ t('hubs_start', 'Utvandrad') }}
					</span>

					<span class="parts-panel__radatgarder">
						<button
							v-if="part.personnummer"
							class="parts-panel__radknapp"
							type="button"
							:disabled="muterar"
							@click="oppnaAndamal(part)">
							<DatabaseSyncIcon :size="14" />
							{{ t('hubs_start', 'Uppdatera från folkbokföringen') }}
						</button>
						<button
							class="parts-panel__radknapp parts-panel__radknapp--fara"
							type="button"
							:disabled="muterar"
							@click="taBort(part)">
							<DeleteOutlineIcon :size="14" />
							{{ t('hubs_start', 'Ta bort') }}
						</button>
					</span>
				</div>

				<!-- Rad 2: personnummer + adress (eller förmedlingstjänst-texten) + särskild postadress -->
				<div class="parts-panel__rad2">
					<span v-if="part.personnummer" class="parts-panel__pnr">{{ fmtPnr(part.personnummer) }}</span>
					<span v-if="part.skydd === 'skyddad_folkbokforing'" class="parts-panel__adress parts-panel__adress--skyddad">
						{{ t('hubs_start', 'Adress hanteras via Skatteverkets förmedlingstjänst') }}
					</span>
					<span v-else-if="part.adress" class="parts-panel__adress">{{ part.adress }}</span>
					<span v-if="part.sarskildPostadress" class="parts-panel__sarskild">
						<strong>{{ t('hubs_start', 'Särskild postadress — enda utskicksväg') }}:</strong>
						{{ part.sarskildPostadress }}
					</span>
					<span v-if="part.kontakt" class="parts-panel__kontakt">{{ part.kontakt }}</span>
				</div>

				<!-- Rad 3: källa + verifierad -->
				<p class="parts-panel__muted parts-panel__rad3">
					{{ kallaLabel(part.kalla) }}<template v-if="part.verifierad"> · {{ t('hubs_start', 'verifierad {datum}', { datum: fmtDatum(part.verifierad) }) }}</template>
				</p>

				<!-- Inline ändamåls-rad för re-uppslag (ändamålet journalförs, K-NAV-4.2) -->
				<div v-if="andamalForId === part.id" class="parts-panel__andamal">
					<input
						v-model="andamalText"
						class="parts-panel__andamal-input"
						type="text"
						:placeholder="t('hubs_start', 'Ändamål (journalförs)')"
						:disabled="muterar"
						:aria-label="t('hubs_start', 'Ändamål för uppslag i folkbokföringen (journalförs)')"
						@keyup.enter="bekraftaUppdatering(part)">
					<NcButton type="primary" :disabled="!andamalText.trim() || muterar" @click="bekraftaUppdatering(part)">
						<template #icon>
							<NcLoadingIcon v-if="muterar" :size="16" />
						</template>
						{{ t('hubs_start', 'Bekräfta') }}
					</NcButton>
					<NcButton :disabled="muterar" @click="stangAndamal">
						{{ t('hubs_start', 'Avbryt') }}
					</NcButton>
				</div>
			</li>
		</ul>

		<!-- Modaler (byggs parallellt — kontrakten antagna) -->
		<PartUppslagModal
			v-if="uppslagOpen"
			:is-running="modalKor"
			@uppslag="onUppslag"
			@close="uppslagOpen = false" />
		<PartManuellModal
			v-if="manuellOpen"
			:is-running="modalKor"
			@skapa="onSkapaManuell"
			@close="manuellOpen = false" />
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'

import DatabaseSyncIcon from 'vue-material-design-icons/DatabaseSync.vue'
import AccountMultiplePlusIcon from 'vue-material-design-icons/AccountMultiplePlus.vue'
import ShieldLockIcon from 'vue-material-design-icons/ShieldLock.vue'
import AlertOutlineIcon from 'vue-material-design-icons/AlertOutline.vue'
import DeleteOutlineIcon from 'vue-material-design-icons/DeleteOutline.vue'

import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { showSuccess, showError } from '@nextcloud/dialogs'

import { fetchArendeParter, uppslagPart, skapaPartManuell, uppdateraPartFranNavet, taBortPart } from '../../services/api.js'
import PartUppslagModal from './PartUppslagModal.vue'
import PartManuellModal from './PartManuellModal.vue'

export default {
	name: 'PartsPanel',

	components: {
		NcButton,
		NcLoadingIcon,
		DatabaseSyncIcon,
		AccountMultiplePlusIcon,
		ShieldLockIcon,
		AlertOutlineIcon,
		DeleteOutlineIcon,
		PartUppslagModal,
		PartManuellModal,
	},

	props: {
		arende: { type: Object, required: true },
	},

	data() {
		return {
			parter: [],
			laddar: false,
			felVidLaddning: false,
			// Modaler
			uppslagOpen: false,
			manuellOpen: false,
			modalKor: false,
			// Inline ändamåls-rad (re-uppslag) — id:t för parten vars rad är öppen.
			andamalForId: null,
			andamalText: '',
			// Pågående radmutation (uppdatera/ta bort) — spärrar dubbeltryck.
			muterar: false,
		}
	},

	computed: {
		/** Husets referens-mönster: hubsCaseId ?? dnr ?? triageRef. */
		ref() {
			return this.arende.hubsCaseId || this.arende.dnr || this.arende.triageRef
		},
	},

	mounted() {
		this.laddaParter()
	},

	methods: {
		t,
		n,

		/** Hämta parterna ur motorn + uppdatera flik-räknaren (emit 'antal'). */
		async laddaParter() {
			if (!this.ref) {
				return
			}
			this.laddar = true
			this.felVidLaddning = false
			try {
				this.parter = await fetchArendeParter(this.ref)
			} catch (e) {
				this.felVidLaddning = true
				showError(this.motorFel(e, t('hubs_start', 'Kunde inte hämta parterna. Försök igen.')))
			} finally {
				this.laddar = false
				this.$emit('antal', this.parter.length)
			}
		},

		/** Uppslag via folkbokföringen (Navet): skapa part + ev. vårdnadshavare. */
		async onUppslag(payload) {
			if (this.modalKor) {
				return
			}
			this.modalKor = true
			try {
				const r = await uppslagPart(this.ref, payload)
				if (r && r.ok !== false) {
					const antalVh = (r.vardnadshavare && r.vardnadshavare.length) || 0
					if (antalVh > 0) {
						showSuccess(n('hubs_start', 'Part och %n vårdnadshavare hämtad', 'Part och %n vårdnadshavare hämtade', antalVh))
					} else {
						showSuccess(t('hubs_start', 'Part hämtad från folkbokföringen.'))
					}
					this.uppslagOpen = false
					await this.laddaParter()
				} else {
					showError(t('hubs_start', 'Uppslaget misslyckades: {orsak}', { orsak: (r && r.error) || t('hubs_start', 'okänt fel') }))
				}
			} catch (e) {
				showError(this.motorFel(e, t('hubs_start', 'Uppslaget misslyckades. Försök igen.')))
			} finally {
				this.modalKor = false
			}
		},

		/** Manuell part (utan folkbokföringsuppslag) — skydd obligatoriskt i motorn. */
		async onSkapaManuell(data) {
			if (this.modalKor) {
				return
			}
			this.modalKor = true
			try {
				const r = await skapaPartManuell(this.ref, data)
				if (r && r.ok !== false) {
					showSuccess(t('hubs_start', 'Parten är tillagd.'))
					this.manuellOpen = false
					await this.laddaParter()
				} else {
					showError(t('hubs_start', 'Parten kunde inte läggas till: {orsak}', { orsak: (r && r.error) || t('hubs_start', 'okänt fel') }))
				}
			} catch (e) {
				showError(this.motorFel(e, t('hubs_start', 'Parten kunde inte läggas till. Försök igen.')))
			} finally {
				this.modalKor = false
			}
		},

		/** Öppna inline-ändamålsraden för re-uppslag (kräver lagrat personnummer). */
		oppnaAndamal(part) {
			this.andamalForId = part.id
			this.andamalText = ''
		},

		stangAndamal() {
			this.andamalForId = null
			this.andamalText = ''
		},

		/** Re-uppslag mot folkbokföringen (rättelse-garantin K-NAV-4.4). */
		async bekraftaUppdatering(part) {
			const andamal = this.andamalText.trim()
			if (!andamal || this.muterar) {
				return
			}
			this.muterar = true
			try {
				const r = await uppdateraPartFranNavet(this.ref, part.id, andamal)
				if (r && r.ok !== false) {
					showSuccess(t('hubs_start', 'Parten är uppdaterad från folkbokföringen.'))
					this.stangAndamal()
					await this.laddaParter()
				} else {
					showError(t('hubs_start', 'Uppdateringen misslyckades: {orsak}', { orsak: (r && r.error) || t('hubs_start', 'okänt fel') }))
				}
			} catch (e) {
				showError(this.motorFel(e, t('hubs_start', 'Uppdateringen misslyckades. Försök igen.')))
			} finally {
				this.muterar = false
			}
		},

		/** Ta bort parten (journalförs i motorn). */
		async taBort(part) {
			if (this.muterar) {
				return
			}
			// eslint-disable-next-line no-alert
			if (!window.confirm(t('hubs_start', 'Ta bort parten ur partsregistret? Åtgärden journalförs.'))) {
				return
			}
			this.muterar = true
			try {
				const r = await taBortPart(this.ref, part.id)
				if (r && r.ok !== false) {
					showSuccess(t('hubs_start', 'Parten är borttagen.'))
					if (this.andamalForId === part.id) {
						this.stangAndamal()
					}
					await this.laddaParter()
				} else {
					showError(t('hubs_start', 'Parten kunde inte tas bort: {orsak}', { orsak: (r && r.error) || t('hubs_start', 'okänt fel') }))
				}
			} catch (e) {
				showError(this.motorFel(e, t('hubs_start', 'Parten kunde inte tas bort. Försök igen.')))
			} finally {
				this.muterar = false
			}
		},

		/** Motorns felorsak ur ett OCS-fel (husets mönster). */
		motorFel(e, fallback) {
			const d = e && e.response && e.response.data
			const orsak = (d && d.ocs && d.ocs.data && d.ocs.data.error) || (d && d.error) || null
			return orsak || fallback
		},

		rollLabel(roll) {
			const map = {
				barn: t('hubs_start', 'Barn'),
				vardnadshavare: t('hubs_start', 'Vårdnadshavare'),
				anmalare: t('hubs_start', 'Anmälare'),
				motpart: t('hubs_start', 'Motpart'),
				samverkanspart: t('hubs_start', 'Samverkanspart'),
				annan: t('hubs_start', 'Annan'),
			}
			return map[roll] || roll
		},

		kallaLabel(kalla) {
			const map = {
				navet: t('hubs_start', 'Folkbokföringen'),
				manuell: t('hubs_start', 'Manuell'),
				anmalan: t('hubs_start', 'Anmälan'),
				treserva: t('hubs_start', 'Facksystem'),
			}
			return map[kalla] || kalla || ''
		},

		/** 12 siffror ⇒ ÅÅÅÅMMDD-XXXX; annat visas som det är. */
		fmtPnr(pnr) {
			const s = String(pnr || '').replace(/\D/g, '')
			return s.length === 12 ? s.slice(0, 8) + '-' + s.slice(8) : String(pnr || '')
		},

		/** Bara datumdelen ur ett ISO-datum ("2026-07-06"). */
		fmtDatum(iso) {
			return String(iso || '').slice(0, 10)
		},
	},
}
</script>

<style scoped lang="scss">
.parts-panel {
	display: flex;
	flex-direction: column;
	gap: 10px;

	&__atgarder {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
	}

	&__muted {
		color: var(--color-text-maxcontrast);
		font-size: 0.82rem;
	}

	&__status {
		display: flex;
		align-items: center;
		gap: 6px;
		margin: 0;
	}

	&__list {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 10px;
		font-size: 0.88rem;
	}

	&__part {
		display: flex;
		flex-direction: column;
		gap: 3px;
		padding-bottom: 8px;
		border-bottom: 1px solid var(--color-border);

		&:last-child {
			border-bottom: none;
			padding-bottom: 0;
		}
	}

	&__rad1 {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 6px;
	}

	&__namn {
		font-weight: 700;
	}

	&__chip {
		font-size: 0.72rem;
		font-weight: 600;
		padding: 1px 8px;
		border-radius: var(--border-radius-pill, 16px);
		background: var(--color-background-dark);
		color: var(--color-text-maxcontrast);
		white-space: nowrap;

		&--fbf {
			background: var(--hs-status-warning-bg, var(--color-warning-hover, #fdf3e3));
			color: var(--hs-status-warning, var(--color-warning-text, #9a5b00));
		}
	}

	&__badge {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		font-size: 0.72rem;
		font-weight: 700;
		padding: 1px 8px;
		border-radius: var(--border-radius-pill, 16px);
		white-space: nowrap;

		&--skyddad {
			background: var(--hs-status-error, var(--color-error, #b3251e));
			color: #fff;
		}

		&--sekretess {
			background: var(--hs-status-warning-bg, var(--color-warning-hover, #fdf3e3));
			color: var(--hs-status-warning, var(--color-warning-text, #9a5b00));
			border: 1px solid var(--hs-status-warning, var(--color-warning-text, #9a5b00));
			cursor: help;
		}
	}

	&__radatgarder {
		display: inline-flex;
		align-items: center;
		gap: 10px;
		margin-left: auto;
	}

	&__radknapp {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		background: none;
		border: none;
		padding: 0;
		font: inherit;
		font-size: 0.8rem;
		cursor: pointer;
		color: var(--color-primary-element);
		font-weight: 600;

		&:hover,
		&:focus-visible {
			text-decoration: underline;
		}

		&:disabled {
			opacity: 0.6;
			cursor: default;
			text-decoration: none;
		}

		&--fara {
			color: var(--hs-status-error, var(--color-error, #b3251e));
		}
	}

	&__rad2 {
		display: flex;
		flex-wrap: wrap;
		align-items: baseline;
		gap: 4px 12px;
	}

	&__pnr {
		font-variant-numeric: tabular-nums;
	}

	&__adress {
		// Fleradig adress (\n) renderas radbruten.
		white-space: pre-line;

		&--skyddad {
			font-style: italic;
			color: var(--color-text-maxcontrast);
		}
	}

	&__sarskild {
		white-space: pre-line;
	}

	&__kontakt {
		color: var(--color-text-maxcontrast);
	}

	&__rad3 {
		margin: 0;
	}

	&__andamal {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 6px;
		margin-top: 4px;
	}

	&__andamal-input {
		flex: 1 1 220px;
		min-width: 180px;
	}
}
</style>
