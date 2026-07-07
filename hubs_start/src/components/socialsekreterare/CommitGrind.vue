<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<NcModal
		:show="true"
		:name="dialogTitle"
		size="normal"
		:can-close="!isRunning"
		@close="onClose">
		<div class="commit-grind">
			<!-- #6 — signerings-bekräftelse, INBÄDDAD här (inte en egen staplad NcModal).
			     Två NcModaler som monteras/avmonteras i samma tick deadlockar focus-trap +
			     scroll-lock → UI:t hänger. En enda modal löser det. Visas bara för
			     signerat-beslut (payload.kraverSignering); "För över" gateas tills ikryssad. -->
			<section v-if="kraverSignering" class="commit-grind__section commit-grind__sign">
				<p class="commit-grind__sign-lead">
					<DrawPenIcon :size="18" />
					<span>{{ t('hubs_start', 'Har du signerat dokumentet i underskriftstjänsten? Bekräfta nedan så går vi vidare till överföringen.') }}</span>
				</p>
				<NcCheckboxRadioSwitch
					:checked.sync="signeradBekraftad"
					:disabled="isRunning || committed">
					{{ t('hubs_start', 'Jag har signerat dokumentet') }}
				</NcCheckboxRadioSwitch>
			</section>

			<!-- Vad som förs över -->
			<section class="commit-grind__section">
				<h3 class="commit-grind__heading">
					<FileExportIcon :size="18" />
					{{ t('hubs_start', 'Det här förs över') }}
				</h3>
				<dl class="commit-grind__facts">
					<div class="commit-grind__fact">
						<dt>{{ t('hubs_start', 'Handling') }}</dt>
						<dd>{{ handlingLabel }}</dd>
					</div>
					<div v-if="signaturLabel" class="commit-grind__fact">
						<dt>{{ t('hubs_start', 'Underskrift / kvittens') }}</dt>
						<dd>
							<DrawPenIcon :size="16" class="commit-grind__inline-icon" />
							{{ signaturLabel }}
						</dd>
					</div>
					<div class="commit-grind__fact">
						<dt>{{ t('hubs_start', 'Ursprung') }}</dt>
						<dd>{{ provenansLabel }}</dd>
					</div>
					<div class="commit-grind__fact">
						<dt>{{ t('hubs_start', 'Destination') }}</dt>
						<dd>
							<DatabaseIcon :size="16" class="commit-grind__inline-icon" />
							{{ destinationLabel }}
						</dd>
					</div>
				</dl>
			</section>

			<!-- #5 — Dokument som förs över. Alla förvalda; handläggaren bockar AV det
			     som ska undantas (t.ex. utkast). Obligatorisk granskning innan commit. -->
			<section class="commit-grind__section">
				<h3 class="commit-grind__heading">
					<FileExportIcon :size="18" />
					{{ t('hubs_start', 'Dokument som förs över') }}
				</h3>
				<ul v-if="valda.length" class="commit-grind__docs">
					<li v-for="(d, i) in valda" :key="i" class="commit-grind__doc">
						<NcCheckboxRadioSwitch
							:checked.sync="d.vald"
							:disabled="isRunning || committed">
							{{ d.namn }}
						</NcCheckboxRadioSwitch>
					</li>
				</ul>
				<p v-else class="commit-grind__docs-empty">
					{{ t('hubs_start', 'Inga dokument i ärenderummet.') }}
				</p>
				<p v-if="valda.length" class="commit-grind__docs-hint">
					{{ t('hubs_start', 'Avmarkera dokument du inte vill föra över (t.ex. utkast).') }}
				</p>
			</section>

			<!-- Frends tre-stegs progress -->
			<section class="commit-grind__section">
				<h3 class="commit-grind__heading">
					<TransferIcon :size="18" />
					{{ t('hubs_start', 'Överföring via Frends') }}
				</h3>
				<ol class="commit-grind__steps" aria-label="Överföringsstatus">
					<li
						v-for="(s, i) in steps"
						:key="s.id"
						class="commit-grind__step"
						:class="stepClass(i)"
						:aria-current="i === activeIndex && !committed ? 'step' : null">
						<span class="commit-grind__step-icon" :style="stepIconStyle(i)">
							<NcLoadingIcon
								v-if="stepState(i) === 'running'"
								:size="20" />
							<CheckIcon
								v-else-if="stepState(i) === 'done'"
								:size="20" />
							<AlertIcon
								v-else-if="stepState(i) === 'error'"
								:size="20" />
							<component
								:is="s.icon"
								v-else
								:size="20" />
						</span>
						<span class="commit-grind__step-body">
							<span class="commit-grind__step-label">{{ s.label }}</span>
							<span class="commit-grind__step-state">{{ stepStateLabel(i) }}</span>
						</span>
					</li>
				</ol>
				<p
					v-if="failed"
					class="commit-grind__error"
					role="alert">
					<AlertIcon :size="16" />
					<span>
						{{ t('hubs_start', 'Överföringen stannade vid Skickat. Inget registrerades — försök igen.') }}
						<template v-if="felOrsak">
							{{ t('hubs_start', 'Orsak: {orsak}', { orsak: felOrsak }) }}
						</template>
					</span>
				</p>
			</section>

			<!-- Konsekvenstext. Gap 31: gallras-/retention-meningen renderas ENDAST
			     post-commit (v-if="committed") och ENDAST med motorns faktiska datum
			     (kvitto/provenance) — aldrig ett fabricerat. Saknar kvittot datum
			     visas i stället att facksystemet sätter det vid verifierad
			     registrering. -->
			<p class="commit-grind__consequence">
				<InfoIcon :size="16" />
				<span>
					{{ t('hubs_start', 'Handlingen blir allmän handling i akten.') }}
					<template v-if="committed">
						<template v-if="gallrasDatum">
							{{ t('hubs_start', 'Hubs-rummet gallras {datum} efter bekräftad överföring.', { datum: gallrasDatum }) }}
						</template>
						<span v-else class="commit-grind__consequence-pending">
							{{ t('hubs_start', 'Gallringsdatum: sätts av facksystemet vid verifierad registrering.') }}
						</span>
					</template>
				</span>
			</p>

			<!-- Åtgärder. NB: NcModal-footer (egen div), INTE NcDialog #actions-slot —
			     i @nextcloud/vue 8.39 renderas NcDialogs #actions-slot inte (knapparna
			     föll bort helt → gick ej att committa). NcModal + egen footer är samma
			     mönster som de fungerande KopplaValjare/MeetingWizard-dialogerna. -->
			<div class="commit-grind__footer">
				<NcButton
					:disabled="isRunning"
					@click="onClose">
					{{ committed ? t('hubs_start', 'Stäng') : t('hubs_start', 'Avbryt') }}
				</NcButton>
				<NcButton
					v-if="!committed"
					type="primary"
					:disabled="isRunning || (kraverSignering && !signeradBekraftad)"
					class="commit-grind__commit"
					@click="onCommit">
					<template #icon>
						<NcLoadingIcon v-if="isRunning" :size="20" />
						<TransferIcon v-else :size="20" />
					</template>
					{{ isRunning ? t('hubs_start', 'För över…') : t('hubs_start', 'För över') }}
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

import CheckIcon from 'vue-material-design-icons/Check.vue'
import AlertIcon from 'vue-material-design-icons/AlertCircleOutline.vue'
import InfoIcon from 'vue-material-design-icons/InformationOutline.vue'
import FileExportIcon from 'vue-material-design-icons/FileExport.vue'
import DatabaseIcon from 'vue-material-design-icons/DatabaseArrowRight.vue'
import DrawPenIcon from 'vue-material-design-icons/DrawPen.vue'
import TransferIcon from 'vue-material-design-icons/Transfer.vue'
import SendIcon from 'vue-material-design-icons/Send.vue'
import ServerNetworkIcon from 'vue-material-design-icons/ServerNetwork.vue'
import ArchiveCheckIcon from 'vue-material-design-icons/ArchiveCheck.vue'

import { translate as t } from '@nextcloud/l10n'

import api from '../../services/api.js'
import { isDemo } from '../../services/demoData.js'
import { toneColor } from '../../services/tones.js'

// Human labels per payload.typ — what is actually being committed.
const TYP_LABEL = {
	skyddsbedomning: 'Skyddsbedömning (journalnotat)',
	beslut: 'Beslut',
	utredning: 'Utredning (slutversion, BBIC)',
	motesanteckning: 'Godkänd mötesanteckning',
	bevakning: 'Bevakning / uppföljning',
	'signerat-beslut': 'Signerat beslut',
}

// Which payload-types carry a signature/receipt artefact to show.
const TYP_SIGNATUR = {
	'signerat-beslut': 'AES via Inera Underskriftstjänst (PAdES · PDF/A · LTV)',
	beslut: 'Loggat beslut — kvittens i akten',
	motesanteckning: 'Godkänd av handläggare (human-in-the-loop, loggat)',
}

export default {
	name: 'CommitGrind',

	components: {
		NcModal, NcButton, NcLoadingIcon, NcCheckboxRadioSwitch,
		CheckIcon, AlertIcon, InfoIcon, FileExportIcon, DatabaseIcon,
		DrawPenIcon, TransferIcon,
	},

	props: {
		/** The ärende being committed. */
		arende: {
			type: Object,
			required: true,
		},
		/** { typ, artefakter } — what + the artefacts to transfer. */
		payload: {
			type: Object,
			required: true,
		},
	},

	data() {
		return {
			steps: [
				{ id: 'skickat', label: t('hubs_start', 'Skickat'), icon: SendIcon },
				{ id: 'bekraftat', label: t('hubs_start', 'Bekräftat (API-svar)'), icon: ServerNetworkIcon },
				{ id: 'registrerat', label: t('hubs_start', 'Registrerat'), icon: ArchiveCheckIcon },
			],
			// -1 idle · 0..2 progressing · committed=true when step 3 done
			activeIndex: -1,
			committed: false,
			failed: false,
			isRunning: false,
			result: null,
			timers: [],
			// Motorns felorsak (OCS error) vid misslyckad överföring — visas i felraden.
			felOrsak: null,
			// Demo-läge: styr om steg-indikatorn får teater-fördröjning (800 ms/steg).
			// I live drivs stegen av det faktiska API-svaret (kort stegring för läsbarhet).
			demoLage: isDemo(),
			// #6 — signerings-bekräftelse (gateas "För över" när kraverSignering).
			signeradBekraftad: false,
			// #5 — granskbar dokumentlista (alla förvalda). Normaliserar både
			// {namn,fileid}-objekt (riktig data) och strängar (demo).
			// TODO[per-arendetyp-dokumentpolicy]: framtid — härled initial `vald`
			// per payload.typ/arendeTyp (t.ex. exkludera utkast). Nu, beslut #5: alla förvalda.
			valda: [],
		}
	},

	created() {
		const docs = (this.payload && this.payload.dokument) || []
		this.valda = docs.map((d) => (d && typeof d === 'object')
			? { fileid: (d.fileid !== undefined && d.fileid !== null) ? d.fileid : null, namn: d.namn || d.name || '', vald: true }
			: { fileid: null, namn: String(d), vald: true })
	},

	computed: {
		dialogTitle() {
			return t('hubs_start', 'För över till Treserva')
		},
		/** #6 — kräver detta commit en signerings-bekräftelse först (signerat-beslut)? */
		kraverSignering() {
			return !!(this.payload && this.payload.kraverSignering)
		},
		handlingLabel() {
			return t('hubs_start', TYP_LABEL[this.payload.typ] || 'Handling')
		},
		signaturLabel() {
			const s = TYP_SIGNATUR[this.payload.typ]
			return s ? t('hubs_start', s) : null
		},
		provenansLabel() {
			const ref = this.arende.barnRef || this.arende.triageRef || ''
			return t('hubs_start', 'Säkert ärenderum · {ref}', { ref })
		},
		destinationLabel() {
			const dnr = this.arende.dnr || t('hubs_start', 'nytt')
			return t('hubs_start', 'Treserva-akt · dnr {dnr}', { dnr })
		},
		/**
		 * Gap 31: ALDRIG fabricerat — endast motorns kvitto (result) eller redan
		 * verifierad provenance. null när motorn inte satt datumet; UI:t visar då
		 * "sätts av facksystemet …" i stället för ett påhittat datum.
		 */
		gallrasDatum() {
			return (this.result && this.result.gallrasDatum)
				|| (this.arende.provenance && this.arende.provenance.gallrasDatum)
				|| null
		},
	},

	beforeDestroy() {
		this.clearTimers()
	},

	methods: {
		t,

		clearTimers() {
			this.timers.forEach((id) => clearTimeout(id))
			this.timers = []
		},

		/** State of step i: 'pending' | 'running' | 'done' | 'error'. */
		stepState(i) {
			if (this.committed) {
				return 'done'
			}
			if (this.failed) {
				// On failure we stay at "Skickat": step 0 errors, rest pending.
				return i === 0 ? 'error' : 'pending'
			}
			if (i < this.activeIndex) {
				return 'done'
			}
			if (i === this.activeIndex) {
				return 'running'
			}
			return 'pending'
		},

		stepStateLabel(i) {
			switch (this.stepState(i)) {
			case 'done':
				return t('hubs_start', 'Klart')
			case 'running':
				return t('hubs_start', 'Pågår…')
			case 'error':
				return t('hubs_start', 'Avbröts')
			default:
				return t('hubs_start', 'Väntar')
			}
		},

		stepClass(i) {
			const st = this.stepState(i)
			return {
				'commit-grind__step--done': st === 'done',
				'commit-grind__step--running': st === 'running',
				'commit-grind__step--error': st === 'error',
				'commit-grind__step--pending': st === 'pending',
			}
		},

		// Icon never relies on colour alone (spinner/check/alert glyph differ),
		// but we tint to reinforce: tone success for done, error for error.
		stepIconStyle(i) {
			const st = this.stepState(i)
			if (st === 'done') {
				return { color: toneColor('success'), borderColor: toneColor('success') }
			}
			if (st === 'error') {
				return { color: toneColor('error'), borderColor: toneColor('error') }
			}
			if (st === 'running') {
				return { color: toneColor('info'), borderColor: toneColor('info') }
			}
			return {}
		},

		/**
		 * Drive the three-step Frends progress, then emit @committed.
		 * Spinner → bock per step. LIVE: stegen drivs av det faktiska API-svaret —
		 * när anropet returnerat markeras de i följd med kort visuell stegring
		 * (≤150 ms, endast för läsbarhet). DEMO: simulerad teater (800 ms/steg).
		 */
		async onCommit() {
			if (this.isRunning || this.committed) {
				return
			}
			// #6 — gate: kräver signering men ej bekräftad → gör inget (knappen är
			// redan disablad; detta är en defensiv spärr även i logiken).
			if (this.kraverSignering && !this.signeradBekraftad) {
				return
			}
			this.failed = false
			this.felOrsak = null
			this.isRunning = true
			this.activeIndex = 0

			// #5 — bara de valda dokumenten förs över (vald-flaggan strippas).
			const valdaDokument = this.valda.filter((d) => d.vald).map(({ fileid, namn }) => ({ fileid, namn }))

			try {
				// Step 0 → 1: Skickat → Bekräftat (call the Frends connector here).
				const r = await api.commitToTreserva({ ...this.payload, valdaDokument, arende: this.arende })
				if (!r || r.ok === false) {
					const err = new Error('frends-rejected')
					err.motorOrsak = (r && r.error) || null
					throw err
				}
				this.result = r

				// Svaret är redan bekräftat här — i LIVE markeras stegen direkt i
				// följd (kort stegring för läsbarhet, ingen konstgjord väntan).
				// I DEMO behålls teatern med 800 ms/steg.
				const stegDelay = this.demoLage ? 800 : 150
				await this.advanceTo(1, stegDelay) // Bekräftat (API-svar)
				await this.advanceTo(2, stegDelay) // Registrerat

				this.committed = true
				this.isRunning = false
				// Andra argumentet låter föräldern tråda valdaDokument vidare till sitt
				// (idempotenta) andra store-commit, så urvalet är identiskt i båda.
				this.$emit('committed', this.result, valdaDokument)
			} catch (e) {
				// Failure: stay at "Skickat", show fel-tone. No move. (Demo: never.)
				// Motorns orsak sväljs ALDRIG tyst — OCS-felet (eller ok:false-svarets
				// error) plockas upp och visas i felraden.
				this.clearTimers()
				this.activeIndex = 0
				this.failed = true
				this.felOrsak = e?.response?.data?.ocs?.data?.error || e?.motorOrsak || null
				this.isRunning = false
			}
		},

		advanceTo(index, delay) {
			return new Promise((resolve) => {
				const id = setTimeout(() => {
					this.activeIndex = index
					resolve()
				}, delay)
				this.timers.push(id)
			})
		},

		onClose() {
			if (this.isRunning) {
				return
			}
			this.clearTimers()
			this.$emit('close')
		},
	},
}
</script>

<style scoped lang="scss">
.commit-grind {
	display: flex;
	flex-direction: column;
	gap: 20px;
	padding: 4px 4px 8px;

	&__section {
		display: flex;
		flex-direction: column;
		gap: 10px;
	}

	&__heading {
		display: flex;
		align-items: center;
		gap: 8px;
		margin: 0;
		font-size: 1rem;
		font-weight: 600;
	}

	&__facts {
		margin: 0;
		display: flex;
		flex-direction: column;
		gap: 8px;
	}

	&__fact {
		display: grid;
		grid-template-columns: 160px 1fr;
		gap: 12px;
		align-items: baseline;

		dt {
			color: var(--color-text-maxcontrast);
			font-size: 0.9rem;
		}

		dd {
			margin: 0;
			font-weight: 500;
			display: flex;
			align-items: center;
			gap: 6px;
		}
	}

	&__inline-icon {
		flex-shrink: 0;
		color: var(--color-text-maxcontrast);
	}

	&__docs {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 2px;
	}

	&__docs-empty {
		margin: 0;
		color: var(--color-text-maxcontrast);
		font-size: 0.9rem;
	}

	&__docs-hint {
		margin: 2px 0 0;
		color: var(--color-text-maxcontrast);
		font-size: 0.82rem;
	}

	&__sign-lead {
		display: flex;
		align-items: flex-start;
		gap: 8px;
		margin: 0 0 8px;

		svg {
			flex-shrink: 0;
			margin-top: 1px;
			color: var(--color-text-maxcontrast);
		}
	}

	&__steps {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 4px;
	}

	&__step {
		display: flex;
		align-items: center;
		gap: 12px;
		padding: 8px 4px;
		min-height: 44px; // WCAG 2.2 target / legibility
		border-radius: var(--border-radius, 8px);

		&--running {
			background: var(--color-background-hover);
		}

		&--error {
			background: var(--color-background-hover);
		}
	}

	&__step-icon {
		flex-shrink: 0;
		width: 32px;
		height: 32px;
		display: inline-flex;
		align-items: center;
		justify-content: center;
		border: 2px solid var(--color-border-dark);
		border-radius: 50%;
		color: var(--color-text-maxcontrast);
	}

	&__step-body {
		display: flex;
		flex-direction: column;
		line-height: 1.25;
	}

	&__step-label {
		font-weight: 600;
	}

	&__step-state {
		font-size: 0.85rem;
		color: var(--color-text-maxcontrast);
	}

	&__step--done &__step-state {
		color: var(--hs-status-success);
	}

	&__step--error &__step-state {
		color: var(--hs-status-error);
	}

	&__error {
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

	&__consequence {
		display: flex;
		align-items: flex-start;
		gap: 8px;
		margin: 0;
		padding: 12px;
		border-radius: var(--border-radius, 8px);
		background: var(--color-background-hover);
		color: var(--color-text-maxcontrast);
		font-size: 0.9rem;

		svg {
			flex-shrink: 0;
			margin-top: 1px;
		}
	}

	// Gap 31 — datum saknas i kvittot: dämpad, icke-auktoritativ formulering.
	&__consequence-pending {
		font-style: italic;
	}

	&__commit {
		min-height: 44px;
	}

	&__footer {
		display: flex;
		justify-content: flex-end;
		gap: 8px;
		padding-top: 4px;
	}
}

@media (max-width: 500px) {
	.commit-grind__fact {
		grid-template-columns: 1fr;
		gap: 2px;
	}
}
</style>
