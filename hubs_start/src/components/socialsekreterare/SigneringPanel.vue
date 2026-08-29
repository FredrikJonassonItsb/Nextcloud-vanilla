<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Signeringsstatus för ärendets begärda e-underskrifter (K-SIGN-5/6/7/9).
  - Statuskedjan Skickad → Signerad X av Y → Klar, per-part-rader (roll + status
  - + tidpunkt), Påminn/Uppdatera samt Förnya/Avbryt för avvisad/utgången.
  - Auto-pollar motorns refresh (idempotent, K-SIGN-22) var 10:e sekund SÅ LÄNGE
  - någon post är pending/partially_signed och fliken är synlig — panelen lever
  - bara medan beslutsfliken är öppen (v-else-if i ArendeKort) och intervallet
  - städas i beforeDestroy; ticken hoppar över dolda webbläsarflikar.
-->
<template>
	<div class="signering-panel">
		<p class="signering-panel__rubrik">
			<DrawPenIcon :size="16" />
			{{ t('hubs_start', 'E-underskrift') }}
		</p>

		<p v-if="laddar" class="signering-panel__muted">
			<NcLoadingIcon :size="14" /> {{ t('hubs_start', 'Hämtar signeringsstatus…') }}
		</p>
		<p v-else-if="!sorteradePoster.length" class="signering-panel__muted">
			{{ t('hubs_start', 'Ingen e-underskrift är begärd i ärendet.') }}
		</p>

		<div
			v-for="post in sorteradePoster"
			:key="post.signRequestId"
			class="signering-panel__post"
			:class="{ 'signering-panel__post--negativ': arNegativ(post) }">
			<!-- Rad 1: handling + nivå + status-chip -->
			<p class="signering-panel__post-rad1">
				<FileDocumentIcon :size="15" />
				<strong class="signering-panel__filnamn">{{ post.filename }}</strong>
				<NivaBadge :niva="post.niva || 'ades'" />
				<span class="signering-panel__chip" :class="chipKlass(post.status)">{{ statusLabel(post) }}</span>
			</p>

			<!-- Statuskedjan (endast för det löpande flödet; negativa lägen får en egen rad) -->
			<ol v-if="!arNegativ(post)" class="signering-panel__kedja" :aria-label="t('hubs_start', 'Signeringsstatus')">
				<li
					v-for="(steg, i) in kedjeSteg(post)"
					:key="steg.id"
					class="signering-panel__steg"
					:class="{
						'signering-panel__steg--klar': i < aktivtSteg(post) || post.status === 'signed',
						'signering-panel__steg--aktiv': i === aktivtSteg(post) && post.status !== 'signed',
					}">
					<CheckIcon v-if="i < aktivtSteg(post) || post.status === 'signed'" :size="14" />
					<ClockOutlineIcon v-else :size="14" />
					{{ steg.label }}
				</li>
			</ol>

			<!-- Negativa lägen (K-SIGN-7) — åtgärdbara, aldrig en tyst återvändsgränd. -->
			<p v-else class="signering-panel__negativ" role="status">
				<AlertIcon :size="15" />
				<span>{{ negativText(post) }}</span>
			</p>

			<!-- Per-part-status (U4/K-SIGN-9): roll + status + tidpunkt. -->
			<ul v-if="(post.signers || []).length" class="signering-panel__parter">
				<li v-for="(s, i) in post.signers" :key="post.signRequestId + '-s' + i" class="signering-panel__part">
					<span class="signering-panel__part-namn">{{ s.uid }}</span>
					<span class="signering-panel__muted">({{ partRoll(s.role) }})</span>
					<span
						class="signering-panel__chip"
						:class="s.status === 'signerad' ? 'signering-panel__chip--gron' : 'signering-panel__chip--neutral'">
						{{ s.status === 'signerad' ? t('hubs_start', 'Signerad') : t('hubs_start', 'Väntar') }}
					</span>
					<span v-if="s.tidpunkt" class="signering-panel__muted">{{ fmtTime(s.tidpunkt) }}</span>
				</li>
			</ul>

			<!-- Klar-bekräftelsen: verklig status, med faktisk uppnådd PAdES-nivå (U7). -->
			<p v-if="post.status === 'signed'" class="signering-panel__klar" role="status">
				<CheckCircleIcon :size="15" />
				{{ klarText(post) }}
			</p>

			<!-- Åtgärder per läge -->
			<div class="signering-panel__atgarder">
				<template v-if="arPagaende(post)">
					<NcButton type="secondary" :disabled="arUpptagen(post)" @click="uppdatera(post)">
						<template #icon><RefreshIcon :size="16" /></template>
						{{ t('hubs_start', 'Uppdatera') }}
					</NcButton>
					<NcButton type="tertiary" :disabled="arUpptagen(post)" @click="paminn(post)">
						<template #icon><BellRingIcon :size="16" /></template>
						{{ t('hubs_start', 'Påminn') }}
					</NcButton>
				</template>
				<NcButton
					v-if="arNegativ(post) || post.status === 'avbruten'"
					type="secondary"
					:disabled="arUpptagen(post)"
					@click="fornya(post)">
					<template #icon><SendIcon :size="16" /></template>
					{{ t('hubs_start', 'Förnya begäran') }}
				</NcButton>
				<NcButton
					v-if="(arPagaende(post) || arNegativ(post)) && avbrytFor !== post.signRequestId"
					type="tertiary"
					:disabled="arUpptagen(post)"
					@click="oppnaAvbryt(post)">
					<template #icon><CloseCircleIcon :size="16" /></template>
					{{ t('hubs_start', 'Avbryt') }}
				</NcButton>
			</div>

			<!-- Avbryt-skälet (journalförs, enum — aldrig fri text/PII). -->
			<fieldset v-if="avbrytFor === post.signRequestId" class="signering-panel__avbryt">
				<legend class="signering-panel__avbryt-legend">{{ t('hubs_start', 'Skäl för att avbryta (journalförs)') }}</legend>
				<NcCheckboxRadioSwitch
					v-for="s in avbrytSkalVal"
					:key="s.value"
					:checked.sync="avbrytSkal"
					:value="s.value"
					name="signering-avbryt-skal"
					type="radio"
					:disabled="arUpptagen(post)">
					{{ s.label }}
				</NcCheckboxRadioSwitch>
				<div class="signering-panel__avbryt-knappar">
					<NcButton type="tertiary" :disabled="arUpptagen(post)" @click="avbrytFor = null">
						{{ t('hubs_start', 'Ångra') }}
					</NcButton>
					<NcButton type="secondary" :disabled="arUpptagen(post) || !avbrytSkal" @click="avbryt(post)">
						{{ t('hubs_start', 'Avbryt begäran') }}
					</NcButton>
				</div>
			</fieldset>
		</div>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'

import DrawPenIcon from 'vue-material-design-icons/DrawPen.vue'
import FileDocumentIcon from 'vue-material-design-icons/FileDocument.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import CheckCircleIcon from 'vue-material-design-icons/CheckCircle.vue'
import ClockOutlineIcon from 'vue-material-design-icons/ClockOutline.vue'
import AlertIcon from 'vue-material-design-icons/AlertCircleOutline.vue'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import BellRingIcon from 'vue-material-design-icons/BellRing.vue'
import SendIcon from 'vue-material-design-icons/Send.vue'
import CloseCircleIcon from 'vue-material-design-icons/CloseCircle.vue'

import { translate as t } from '@nextcloud/l10n'
import { showSuccess, showError } from '@nextcloud/dialogs'

import { signeringList, signeringRefresh, signeringFornya, signeringAvbryt, signeringPaminn } from '../../services/api.js'
import NivaBadge from './NivaBadge.vue'

/** Auto-poll-intervallet (K-SIGN-6): var 10:e sekund medan en begäran pågår. */
const POLL_MS = 10000

/** Signer-roll → läsbar etikett (kontraktets role-fält + ledger-rollerna). */
const PART_ROLL_LABEL = {
	beslutsfattare: t('hubs_start', 'beslutsfattare'),
	foredragande: t('hubs_start', 'föredragande'),
	handlaggare: t('hubs_start', 'handläggare'),
	co_handlaggare: t('hubs_start', 'medhandläggare'),
	observator: t('hubs_start', 'observatör'),
}

export default {
	name: 'SigneringPanel',

	components: {
		NcButton, NcLoadingIcon, NcCheckboxRadioSwitch,
		DrawPenIcon, FileDocumentIcon, CheckIcon, CheckCircleIcon, ClockOutlineIcon,
		AlertIcon, RefreshIcon, BellRingIcon, SendIcon, CloseCircleIcon,
		NivaBadge,
	},

	props: {
		arende: { type: Object, required: true },
	},

	data() {
		return {
			laddar: true,
			poster: [],
			pollTimer: null,
			/** Pågående refresh-tick (dedup — ticken får aldrig stapla anrop). */
			pollKor: false,
			/** signRequestId:n med en åtgärd i luften (Uppdatera/Förnya/Avbryt/Påminn). */
			upptagna: [],
			/** Post vars avbryt-skäl-form är öppen (null = stängd). */
			avbrytFor: null,
			avbrytSkal: '',
		}
	},

	computed: {
		arendeRef() {
			const a = this.arende
			return a && (a.hubsCaseId || a.dnr || a.triageRef)
		},
		/** Nyaste först — den senaste begäran är den relevanta. */
		sorteradePoster() {
			return this.poster.slice().sort((a, b) => String(b.createdAt || '') < String(a.createdAt || '') ? -1 : 1)
		},
		/** Något att polla? (pending/partially_signed — K-SIGN-6). */
		harPagaende() {
			return this.poster.some((p) => this.arPagaende(p))
		},
		avbrytSkalVal() {
			return [
				{ value: 'fel_handling', label: t('hubs_start', 'Fel handling skickades') },
				{ value: 'ny_version', label: t('hubs_start', 'Handlingen ska ändras — ny version krävs') },
				{ value: 'annat', label: t('hubs_start', 'Annat skäl') },
			]
		},
	},

	watch: {
		// Starta/stoppa pollen på VERKLIGT behov — inte en evig timer.
		harPagaende: {
			immediate: true,
			handler(v) {
				if (v) {
					this.startPoll()
				} else {
					this.stopPoll()
				}
			},
		},
	},

	created() {
		this.ladda()
	},

	beforeDestroy() {
		this.stopPoll()
	},

	methods: {
		t,

		async ladda() {
			if (!this.arendeRef) {
				this.laddar = false
				return
			}
			this.laddar = true
			try {
				const r = await signeringList(this.arendeRef)
				this.poster = (r && r.poster) || []
			} catch (e) {
				// Ärligt tom panel i stället för evig spinner; posterna kommer på nästa expand.
			} finally {
				this.laddar = false
			}
		},

		// --- Auto-poll (K-SIGN-6/22) -----------------------------------------
		startPoll() {
			if (this.pollTimer) {
				return
			}
			this.pollTimer = setInterval(() => this.tick(), POLL_MS)
		},
		stopPoll() {
			if (this.pollTimer) {
				clearInterval(this.pollTimer)
				this.pollTimer = null
			}
		},
		/** En poll-tick: hoppa över dolda webbläsarflikar; refresha pågående poster. */
		async tick() {
			if (this.pollKor || document.hidden) {
				return
			}
			this.pollKor = true
			try {
				await Promise.all(this.poster.filter((p) => this.arPagaende(p)).map((p) => this.refreshPost(p)))
			} finally {
				this.pollKor = false
			}
		},
		/** Idempotent refresh av EN post; ersätter DTO:n och signalerar signed uppåt. */
		async refreshPost(post) {
			try {
				const dto = await signeringRefresh(this.arendeRef, post.signRequestId)
				if (dto && dto.signRequestId) {
					this.ersattPost(dto)
					if (dto.status === 'signed' && post.status !== 'signed') {
						// Verklig status driver nastaAtgard-kedjan (K-SIGN-8) via föräldern.
						this.$emit('signed', dto)
					}
				}
			} catch (e) {
				// Tyst vid poll-fel (nätglapp) — nästa tick försöker igen.
			}
		},
		ersattPost(dto) {
			const i = this.poster.findIndex((p) => p.signRequestId === dto.signRequestId)
			if (i >= 0) {
				this.$set(this.poster, i, dto)
			} else {
				this.poster = this.poster.concat([dto])
			}
		},

		// --- Åtgärder (K-SIGN-7) ----------------------------------------------
		arUpptagen(post) {
			return this.upptagna.includes(post.signRequestId)
		},
		async medUpptagen(post, fn) {
			if (this.arUpptagen(post)) {
				return
			}
			this.upptagna = this.upptagna.concat([post.signRequestId])
			try {
				await fn()
			} finally {
				this.upptagna = this.upptagna.filter((id) => id !== post.signRequestId)
			}
		},
		uppdatera(post) {
			return this.medUpptagen(post, () => this.refreshPost(post))
		},
		fornya(post) {
			return this.medUpptagen(post, async () => {
				try {
					const dto = await signeringFornya(this.arendeRef, post.signRequestId)
					if (dto && dto.signRequestId) {
						this.ersattPost(dto)
						showSuccess(t('hubs_start', 'Ny begäran om e-underskrift är skickad — kedjan är journalförd.'))
					} else {
						showError(t('hubs_start', 'Kunde inte förnya begäran: {orsak}', { orsak: (dto && dto.error) || t('hubs_start', 'okänt fel') }))
					}
				} catch (e) {
					showError(t('hubs_start', 'Kunde inte förnya begäran. Försök igen.'))
				}
			})
		},
		oppnaAvbryt(post) {
			this.avbrytFor = post.signRequestId
			// INGET förval (samma princip som grindarna): skälet väljs aktivt.
			this.avbrytSkal = ''
		},
		avbryt(post) {
			return this.medUpptagen(post, async () => {
				try {
					const dto = await signeringAvbryt(this.arendeRef, post.signRequestId, this.avbrytSkal)
					if (dto && dto.signRequestId) {
						this.ersattPost(dto)
						this.avbrytFor = null
						showSuccess(t('hubs_start', 'Begäran är avbruten — skälet är journalfört.'))
					} else {
						showError(t('hubs_start', 'Kunde inte avbryta begäran: {orsak}', { orsak: (dto && dto.error) || t('hubs_start', 'okänt fel') }))
					}
				} catch (e) {
					showError(t('hubs_start', 'Kunde inte avbryta begäran. Försök igen.'))
				}
			})
		},
		paminn(post) {
			return this.medUpptagen(post, async () => {
				try {
					const r = await signeringPaminn(this.arendeRef, post.signRequestId)
					if (r && r.paminnelse) {
						showSuccess(t('hubs_start', 'Påminnelsen är journalförd.'))
					} else {
						showError(t('hubs_start', 'Kunde inte skicka påminnelsen: {orsak}', { orsak: (r && r.error) || t('hubs_start', 'okänt fel') }))
					}
				} catch (e) {
					showError(t('hubs_start', 'Kunde inte skicka påminnelsen. Försök igen.'))
				}
			})
		},

		// --- Status-presentation ----------------------------------------------
		arPagaende(post) {
			return post.status === 'pending' || post.status === 'partially_signed'
		},
		arNegativ(post) {
			return post.status === 'rejected' || post.status === 'expired'
		},
		signeradeAntal(post) {
			return (post.signers || []).filter((s) => s.status === 'signerad').length
		},
		/** Statuskedjans tre steg (K-SIGN-6): Skickad → Signerad X av Y → Klar. */
		kedjeSteg(post) {
			const y = (post.signers || []).length
			return [
				{ id: 'skickad', label: t('hubs_start', 'Skickad') },
				{ id: 'signerad', label: t('hubs_start', 'Signerad {x} av {y}', { x: this.signeradeAntal(post), y }) },
				{ id: 'klar', label: t('hubs_start', 'Klar') },
			]
		},
		/** Aktivt kedjesteg: pending → 0, partially_signed → 1, signed → förbi allt. */
		aktivtSteg(post) {
			if (post.status === 'signed') {
				return 3
			}
			return post.status === 'partially_signed' ? 1 : 0
		},
		statusLabel(post) {
			const map = {
				pending: t('hubs_start', 'Skickad'),
				partially_signed: t('hubs_start', 'Delvis signerad'),
				signed: t('hubs_start', 'Klar'),
				rejected: t('hubs_start', 'Avvisad'),
				expired: t('hubs_start', 'Utgången'),
				avbruten: t('hubs_start', 'Avbruten'),
			}
			return map[post.status] || post.status
		},
		chipKlass(status) {
			const map = {
				signed: 'signering-panel__chip--gron',
				rejected: 'signering-panel__chip--rod',
				expired: 'signering-panel__chip--rod',
				avbruten: 'signering-panel__chip--gra',
			}
			return map[status] || 'signering-panel__chip--neutral'
		},
		negativText(post) {
			if (post.status === 'rejected') {
				return post.avvisadSkal
					? t('hubs_start', 'Underskriften avvisades: {skal}. Förnya begäran eller avbryt.', { skal: post.avvisadSkal })
					: t('hubs_start', 'Underskriften avvisades. Förnya begäran eller avbryt.')
			}
			return t('hubs_start', 'Begäran gick ut utan att alla skrivit under. Förnya begäran eller avbryt.')
		},
		/** Klar-texten bär den FAKTISKT uppnådda PAdES-nivån när motorn stämplat en. */
		klarText(post) {
			const niva = post.padesLevel ? String(post.padesLevel) : 'PAdES'
			return t('hubs_start', 'E-underskrift klar ({niva})', { niva })
		},
		partRoll(role) {
			return PART_ROLL_LABEL[role] || role || ''
		},
		fmtTime(s) {
			try {
				return new Date(s).toLocaleString('sv-SE', { hour: '2-digit', minute: '2-digit', day: 'numeric', month: 'short' })
			} catch (e) {
				return s
			}
		},
	},
}
</script>

<style scoped lang="scss">
.signering-panel {
	display: flex;
	flex-direction: column;
	gap: 10px;
	padding: 10px 12px;
	margin-bottom: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 10px);
	background: var(--color-background-hover);

	&__rubrik {
		display: flex;
		align-items: center;
		gap: 6px;
		margin: 0;
		font-size: 0.92rem;
		font-weight: 700;
	}

	&__muted {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		margin: 0;
		color: var(--color-text-maxcontrast);
		font-size: 0.85rem;
	}

	&__post {
		display: flex;
		flex-direction: column;
		gap: 8px;
		padding: 8px 10px;
		border-radius: var(--border-radius, 8px);
		background: var(--color-main-background);

		&--negativ {
			border: 1px solid var(--hs-status-error);
		}
	}

	&__post-rad1 {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 6px;
		margin: 0;
	}

	&__filnamn {
		font-size: 0.9rem;
	}

	&__kedja {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 12px;
		list-style: none;
		margin: 0;
		padding: 0;
	}

	&__steg {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		font-size: 0.85rem;
		color: var(--color-text-maxcontrast);

		&--klar {
			color: var(--hs-status-success);
			font-weight: 600;
		}

		&--aktiv {
			color: var(--color-main-text);
			font-weight: 600;
		}
	}

	&__negativ {
		display: flex;
		align-items: flex-start;
		gap: 6px;
		margin: 0;
		color: var(--hs-status-error);
		font-size: 0.88rem;
		font-weight: 500;

		svg {
			flex-shrink: 0;
			margin-top: 1px;
		}
	}

	&__klar {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		margin: 0;
		color: var(--hs-status-success);
		font-size: 0.88rem;
		font-weight: 600;
	}

	&__parter {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 2px;
	}

	&__part {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 6px;
		font-size: 0.85rem;
	}

	&__part-namn {
		font-weight: 500;
	}

	&__chip {
		padding: 0 8px;
		border-radius: var(--border-radius-pill, 16px);
		font-size: 0.75rem;
		font-weight: 600;

		&--neutral {
			background: var(--color-background-dark);
			color: var(--color-text-maxcontrast);
		}

		&--gron {
			background: var(--hs-status-success);
			color: #fff;
		}

		&--rod {
			background: var(--hs-status-error);
			color: #fff;
		}

		&--gra {
			background: var(--color-background-dark);
			color: var(--color-text-maxcontrast);
			font-style: italic;
		}
	}

	&__atgarder {
		display: flex;
		flex-wrap: wrap;
		gap: 6px;
	}

	&__avbryt {
		display: flex;
		flex-direction: column;
		gap: 4px;
		margin: 0;
		padding: 8px 0 0;
		border: none;
		border-top: 1px solid var(--color-border);
	}

	&__avbryt-legend {
		margin: 0 0 4px;
		padding: 0;
		font-size: 0.85rem;
		font-weight: 600;
		color: var(--color-text-maxcontrast);
	}

	&__avbryt-knappar {
		display: flex;
		gap: 8px;
		padding-top: 4px;
	}
}
</style>
