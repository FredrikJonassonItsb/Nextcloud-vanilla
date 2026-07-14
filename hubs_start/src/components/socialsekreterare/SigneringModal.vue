<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Beslutsstegets signeringsdialog (K-SIGN-1–4/6): öppnas från action=signera.
  - Tvånivåmodellen avgör vägen per handlingstyp (motorns niva_matris):
  -  · 'godkann' → EN Godkänn-knapp → journalförd bekräftelse "Godkänt av <roll>"
  -    (K-SIGN-2 — ALDRIG ordet underskrift/signatur för denna nivå).
  -  · 'ades'    → välj påskrivare (default: ärendets tilldelade handläggare som
  -    beslutsfattare) → signeringBegar → motorns SigneringPort (stub i fas 1).
  - Statuskedjan efter skickad begäran bor i SigneringPanel (Historik & beslut).
-->
<template>
	<NcModal
		:show="true"
		:name="dialogTitle"
		size="normal"
		:can-close="!kor"
		@close="onClose">
		<div class="signering-modal">
			<p v-if="laddar" class="signering-modal__muted">
				<NcLoadingIcon :size="16" /> {{ t('hubs_start', 'Hämtar signeringsläge…') }}
			</p>

			<!-- Kvitto-läget: dialogen stängs inte tyst — den bekräftar vad som hänt. -->
			<template v-else-if="resultat">
				<p class="signering-modal__kvitto" role="status">
					<CheckCircleIcon :size="20" />
					<span>{{ kvittoText }}</span>
				</p>
				<p v-if="resultat.typ !== 'godkand' && resultat.status !== 'signed'" class="signering-modal__muted">
					{{ t('hubs_start', 'Följ statusen under fliken Historik & beslut på ärendekortet.') }}
				</p>
				<div class="signering-modal__footer">
					<NcButton type="primary" @click="onClose">
						{{ t('hubs_start', 'Stäng') }}
					</NcButton>
				</div>
			</template>

			<template v-else>
				<!-- Handlingen som ska godkännas/skrivas under -->
				<section class="signering-modal__section">
					<h3 class="signering-modal__heading">
						<FileDocumentIcon :size="18" />
						{{ t('hubs_start', 'Handling') }}
					</h3>
					<p v-if="!dokument.length" class="signering-modal__muted">
						{{ t('hubs_start', 'Ärendet saknar handlingar i akten — skapa beslutshandlingen först (Gör annat → Skapa handling från mall).') }}
					</p>
					<!-- Ett dokument: visa det. Flera: radioval (default: beslutshandlingen). -->
					<template v-else>
						<div v-if="dokument.length === 1" class="signering-modal__dok">
							<span class="signering-modal__dok-namn">{{ dokument[0].namn }}</span>
							<span v-if="hashPrefix" class="signering-modal__muted">· {{ hashPrefix }}</span>
							<NivaBadge :niva="niva" />
						</div>
						<template v-else>
							<NcCheckboxRadioSwitch
								v-for="(d, i) in dokument"
								:key="'dok' + i"
								:checked.sync="valdIndexStr"
								:value="String(i)"
								name="signering-dok"
								type="radio"
								:disabled="kor">
								{{ d.namn }}
							</NcCheckboxRadioSwitch>
							<p class="signering-modal__dok signering-modal__dok--vald">
								<span v-if="hashPrefix" class="signering-modal__muted">{{ hashPrefix }}</span>
								<NivaBadge :niva="niva" />
							</p>
						</template>
					</template>
				</section>

				<!-- Godkänn-vägen (K-SIGN-2): digitalt godkännande, journalförs. -->
				<section v-if="!arAdes" class="signering-modal__section">
					<p class="signering-modal__lead">
						{{ t('hubs_start', 'Den här handlingstypen kräver digitalt godkännande. Godkännandet journalförs med dokumenthash, tidpunkt och din roll.') }}
					</p>
				</section>

				<!-- e-underskriftsvägen (K-SIGN-3/9): välj påskrivare. -->
				<section v-else class="signering-modal__section">
					<h3 class="signering-modal__heading">
						<AccountMultipleIcon :size="18" />
						{{ t('hubs_start', 'Påskrivare') }}
					</h3>
					<p class="signering-modal__lead">
						{{ t('hubs_start', 'Handlingen skickas för e-underskrift till de valda påskrivarna. Begäran journalförs.') }}
					</p>
					<p v-if="!medlemmar.length" class="signering-modal__muted">
						{{ t('hubs_start', 'Ärendet saknar registrerade medlemmar att skicka till.') }}
					</p>
					<NcCheckboxRadioSwitch
						v-for="m in medlemmar"
						:key="m.uid"
						:checked="valdaUids.includes(m.uid)"
						:disabled="kor"
						@update:checked="toggleSigner(m.uid, $event)">
						{{ m.displayName || m.uid }}
						<span class="signering-modal__muted">({{ signerRollLabel(m) }})</span>
					</NcCheckboxRadioSwitch>
				</section>

				<p v-if="fel" class="signering-modal__fel" role="alert">
					<AlertIcon :size="16" />
					<span>{{ fel }}</span>
				</p>

				<div class="signering-modal__footer">
					<NcButton :disabled="kor" @click="onClose">
						{{ t('hubs_start', 'Avbryt') }}
					</NcButton>
					<NcButton
						v-if="!arAdes"
						type="primary"
						:disabled="kor || !valdDok"
						@click="onGodkann">
						<template #icon>
							<NcLoadingIcon v-if="kor" :size="18" />
							<CheckIcon v-else :size="18" />
						</template>
						{{ t('hubs_start', 'Godkänn') }}
					</NcButton>
					<NcButton
						v-else
						type="primary"
						:disabled="kor || !valdDok || !valdaUids.length"
						@click="onSkicka">
						<template #icon>
							<NcLoadingIcon v-if="kor" :size="18" />
							<SendIcon v-else :size="18" />
						</template>
						{{ t('hubs_start', 'Skicka för e-underskrift') }}
					</NcButton>
				</div>
			</template>
		</div>
	</NcModal>
</template>

<script>
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'

import CheckIcon from 'vue-material-design-icons/Check.vue'
import CheckCircleIcon from 'vue-material-design-icons/CheckCircle.vue'
import AlertIcon from 'vue-material-design-icons/AlertCircleOutline.vue'
import FileDocumentIcon from 'vue-material-design-icons/FileDocument.vue'
import AccountMultipleIcon from 'vue-material-design-icons/AccountMultiple.vue'
import SendIcon from 'vue-material-design-icons/Send.vue'

import { translate as t } from '@nextcloud/l10n'
import { getCurrentUser } from '@nextcloud/auth'

import store from '../../store/index.js'
import { signeringList, signeringGodkann, signeringBegar, fetchArendeMedlemmar } from '../../services/api.js'
import NivaBadge from './NivaBadge.vue'

/** Ledger-roll → läsbar etikett (samma vokabulär som ArendeKort/medlemspanelen). */
const ROLL_LABEL = {
	mottagningskrets: t('hubs_start', 'mottagningskrets'),
	handlaggare: t('hubs_start', 'handläggare'),
	co_handlaggare: t('hubs_start', 'medhandläggare'),
	observator: t('hubs_start', 'observatör'),
}

export default {
	name: 'SigneringModal',

	components: {
		NcModal, NcButton, NcLoadingIcon, NcCheckboxRadioSwitch,
		CheckIcon, CheckCircleIcon, AlertIcon, FileDocumentIcon, AccountMultipleIcon, SendIcon,
		NivaBadge,
	},

	props: {
		/** Ärendet vars beslut ska godkännas/skrivas under. */
		arende: { type: Object, required: true },
	},

	data() {
		return {
			laddar: true,
			/** K-SIGN-1 — motorns nivåmatris {handlingstyp: 'godkann'|'ades'}. */
			matris: {},
			/** Aktens dokument, normaliserade {fileid, namn, hash?, dokumenttyp?}. */
			dokument: [],
			valdIndex: 0,
			/** Medlemsledgern {uid, roll, displayName} — påskrivar-kandidaterna. */
			medlemmar: [],
			valdaUids: [],
			kor: false,
			fel: null,
			/** Kvittot: {typ:'godkand', tidpunkt, roll} ELLER SigneringDTO. */
			resultat: null,
		}
	},

	computed: {
		arendeRef() {
			const a = this.arende
			return a && (a.hubsCaseId || a.dnr || a.triageRef)
		},
		/** Stabil cache-nyckel för store.loadArende (samma regel som ArendeKort). */
		cacheKey() {
			return this.arende.triageRef || this.arende.dnr
		},
		dialogTitle() {
			return this.arAdes
				? t('hubs_start', 'Skicka för e-underskrift')
				: t('hubs_start', 'Godkänn handling')
		},
		/** NcCheckboxRadioSwitch radio binder strängar — bro till valdIndex. */
		valdIndexStr: {
			get() {
				return String(this.valdIndex)
			},
			set(v) {
				this.valdIndex = parseInt(v, 10) || 0
			},
		},
		valdDok() {
			return this.dokument[this.valdIndex] || null
		},
		/**
		 * Handlingstypen som slår i nivåmatrisen. Dokument stämplade av motorn bär
		 * dokumenttyp; annars 'beslut' — dialogen öppnas från beslutssteget
		 * (action=signera) så beslutshandlingen är default-klassen.
		 */
		handlingstyp() {
			return (this.valdDok && this.valdDok.dokumenttyp) || 'beslut'
		},
		/**
		 * Kravnivån ur matrisen (K-SIGN-1). Saknas typen i matrisen faller vi till
		 * 'ades' — beslut som expedieras externt är default-AdES enligt kravet,
		 * och en för hög nivå är alltid säkrare än en för låg.
		 */
		niva() {
			return this.matris[this.handlingstyp] || 'ades'
		},
		arAdes() {
			return this.niva === 'ades'
		},
		/** Kort hash-prefix för visning (aldrig hela hashen i UI:t). */
		hashPrefix() {
			const h = this.valdDok && (this.valdDok.hash || this.valdDok.sha256 || this.valdDok.dokumentHash)
			return h ? String(h).slice(0, 10) + '…' : null
		},
		/** Ärendets tilldelade handläggare — default-beslutsfattaren (K-SIGN-3). */
		beslutsfattareUid() {
			const agare = this.arende.tilldelning && this.arende.tilldelning.agareUid
			if (agare) {
				return agare
			}
			const hl = this.medlemmar.find((m) => m.roll === 'handlaggare')
			return (hl && hl.uid) || null
		},
		kvittoText() {
			if (this.resultat && this.resultat.typ === 'godkand') {
				// K-SIGN-2 — ärlig etikettering: "Godkänt av <roll>", aldrig "signerat".
				return t('hubs_start', 'Godkänt av {roll} — journalfört med dokumenthash och tidpunkt.', { roll: this.resultat.roll })
			}
			if (this.resultat && this.resultat.status === 'signed') {
				return t('hubs_start', 'E-underskrift klar (PAdES).')
			}
			return t('hubs_start', 'Begäran om e-underskrift är skickad och journalförd.')
		},
	},

	created() {
		this.ladda()
	},

	methods: {
		t,

		/** Hämta signeringsläge + akt + medlemsledger parallellt (best-effort per källa). */
		async ladda() {
			this.laddar = true
			try {
				const [sign, full, medlemmar] = await Promise.all([
					this.arendeRef ? signeringList(this.arendeRef).catch(() => null) : null,
					this.cacheKey ? store.loadArende(this.cacheKey) : null,
					this.arendeRef ? fetchArendeMedlemmar(this.arendeRef).catch(() => []) : [],
				])
				this.matris = (sign && sign.niva_matris) || {}
				this.medlemmar = Array.isArray(medlemmar) ? medlemmar : []
				this.dokument = this.normaliseraDok((full && full.rum && full.rum.dokument) || [])
				// Default-handling: beslutshandlingen om den går att känna igen.
				const beslutIdx = this.dokument.findIndex((d) => /beslut/i.test(d.namn) || d.dokumenttyp === 'beslut')
				this.valdIndex = beslutIdx >= 0 ? beslutIdx : 0
				// Default-påskrivare: den tilldelade handläggaren (beslutsfattaren).
				if (this.beslutsfattareUid && this.medlemmar.some((m) => m.uid === this.beslutsfattareUid)) {
					this.valdaUids = [this.beslutsfattareUid]
				} else if (this.beslutsfattareUid) {
					// Handläggaren finns inte i den hämtade ledgern (t.ex. tom lista) —
					// visa hen ändå som valbar rad så defaulten inte tyst försvinner.
					this.medlemmar = this.medlemmar.concat([{ uid: this.beslutsfattareUid, roll: 'handlaggare', displayName: null }])
					this.valdaUids = [this.beslutsfattareUid]
				}
			} finally {
				this.laddar = false
			}
		},

		/** Samma normalisering som CommitGrind/ArendeKort: {fileid, namn} + ev. hash,
		 * meddelandepekare (msg-*.url) utesluts — de är referenser, inte handlingar. */
		normaliseraDok(docs) {
			return docs
				.map((d) => (d && typeof d === 'object')
					? { ...d, fileid: (d.fileid !== undefined && d.fileid !== null) ? d.fileid : null, namn: d.namn || d.name || '' }
					: { fileid: null, namn: String(d) })
				.filter((d) => !/^msg-[0-9a-f]+\.url$/i.test(d.namn))
		},

		toggleSigner(uid, checked) {
			if (checked && !this.valdaUids.includes(uid)) {
				this.valdaUids = this.valdaUids.concat([uid])
			} else if (!checked) {
				this.valdaUids = this.valdaUids.filter((u) => u !== uid)
			}
		},

		/** Radetikett: beslutsfattar-rollen för defaulten, annars ledger-rollen. */
		signerRollLabel(m) {
			if (m.uid === this.beslutsfattareUid) {
				return t('hubs_start', 'beslutsfattare')
			}
			return ROLL_LABEL[m.roll] || m.roll || ''
		},

		/** Kontraktets handlings-payload: {handlingRef, filename, dokumentHash}.
		 * handlingRef skickas som STRÄNG och dokumentHash som '' (inte null) —
		 * NC-dispatchern är strict_types och kastar på JSON-Number/null mot
		 * string-parametrar. Tom hash ⇒ motorn beräknar den kanoniska SHA-256:an
		 * server-side ur fileid (U2). */
		handlingPayload() {
			const d = this.valdDok
			return {
				handlingRef: (d.fileid !== null && d.fileid !== undefined) ? String(d.fileid) : d.namn,
				filename: d.namn,
				dokumentHash: d.hash || d.sha256 || d.dokumentHash || '',
			}
		},

		/** Godkänn-vägen (K-SIGN-2): journalförd bekräftelse, aldrig "signering". */
		async onGodkann() {
			if (this.kor || !this.valdDok || !this.arendeRef) {
				return
			}
			this.kor = true
			this.fel = null
			try {
				const r = await signeringGodkann(this.arendeRef, this.handlingPayload())
				if (r && r.journalfort) {
					this.resultat = { typ: 'godkand', tidpunkt: r.tidpunkt || null, roll: this.minRoll() }
					this.$emit('klar', { ...r, typ: 'godkand' })
				} else {
					this.fel = (r && r.error) || t('hubs_start', 'Godkännandet journalfördes inte — försök igen.')
				}
			} catch (e) {
				this.fel = this.motorFel(e, t('hubs_start', 'Kunde inte godkänna. Försök igen.'))
			} finally {
				this.kor = false
			}
		},

		/** e-underskriftsvägen (K-SIGN-3): begäran via motorn → SigneringDTO. */
		async onSkicka() {
			if (this.kor || !this.valdDok || !this.valdaUids.length || !this.arendeRef) {
				return
			}
			this.kor = true
			this.fel = null
			// signers-kontraktet: {uid, role} — defaulthandläggaren är beslutsfattare,
			// övriga bär sin ledger-roll (sekventiell ordning är fas 2, K-SIGN-9).
			const signers = this.valdaUids.map((uid) => {
				const m = this.medlemmar.find((x) => x.uid === uid) || {}
				return { uid, role: uid === this.beslutsfattareUid ? 'beslutsfattare' : (m.roll || 'co_handlaggare') }
			})
			try {
				const dto = await signeringBegar(this.arendeRef, { ...this.handlingPayload(), signers })
				if (dto && dto.signRequestId) {
					this.resultat = dto
					this.$emit('klar', dto)
				} else {
					this.fel = (dto && dto.error) || t('hubs_start', 'Begäran kunde inte skickas — försök igen.')
				}
			} catch (e) {
				this.fel = this.motorFel(e, t('hubs_start', 'Kunde inte skicka begäran. Försök igen.'))
			} finally {
				this.kor = false
			}
		},

		/** Min roll i ärendet (för "Godkänt av <roll>") — ledger-uppslag på min uid. */
		minRoll() {
			const cu = getCurrentUser()
			const jag = cu && this.medlemmar.find((m) => m.uid === cu.uid)
			return (jag && (ROLL_LABEL[jag.roll] || jag.roll)) || t('hubs_start', 'handläggare')
		},

		/** Motorns felorsak ur ett OCS-fel (samma mönster som ArendeKort.motorFel). */
		motorFel(e, fallback) {
			const d = e && e.response && e.response.data
			const orsak = (d && d.ocs && d.ocs.data && d.ocs.data.error) || (d && d.error) || null
			return orsak || fallback
		},

		onClose() {
			if (this.kor) {
				return
			}
			this.$emit('close')
		},
	},
}
</script>

<style scoped lang="scss">
.signering-modal {
	display: flex;
	flex-direction: column;
	gap: 20px;
	padding: 4px 4px 8px;

	&__section {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}

	&__heading {
		display: flex;
		align-items: center;
		gap: 8px;
		margin: 0;
		font-size: 1rem;
		font-weight: 600;
	}

	&__lead {
		margin: 0;
		color: var(--color-text-maxcontrast);
		font-size: 0.9rem;
	}

	&__dok {
		display: flex;
		align-items: center;
		gap: 8px;
		margin: 0;

		&--vald {
			padding-top: 2px;
		}
	}

	&__dok-namn {
		font-weight: 500;
	}

	&__muted {
		margin: 0;
		color: var(--color-text-maxcontrast);
		font-size: 0.88rem;
	}

	&__kvitto {
		display: flex;
		align-items: flex-start;
		gap: 8px;
		margin: 0;
		padding: 12px;
		border-radius: var(--border-radius, 8px);
		background: var(--color-background-hover);
		color: var(--hs-status-success);
		font-weight: 600;

		svg {
			flex-shrink: 0;
			margin-top: 1px;
		}
	}

	&__fel {
		display: flex;
		align-items: flex-start;
		gap: 8px;
		margin: 0;
		padding: 8px 12px;
		border-radius: var(--border-radius, 8px);
		color: var(--hs-status-error);
		background: var(--color-background-hover);
		font-weight: 500;

		svg {
			flex-shrink: 0;
			margin-top: 2px;
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
