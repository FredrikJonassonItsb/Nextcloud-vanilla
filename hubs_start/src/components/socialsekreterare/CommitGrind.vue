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
					{{ t('hubs_start', 'Överföringen stannade vid Skickat. Inget registrerades — försök igen.') }}
				</p>
			</section>

			<!-- Konsekvenstext. Gap 31: gallras-/retention-meningen renderas ENDAST
			     post-commit (v-if="committed") så gallrasDatum aldrig fabriceras
			     pre-commit (datumet är känt först ur API-svaret/provenance efter
			     bekräftad överföring). -->
			<p class="commit-grind__consequence">
				<InfoIcon :size="16" />
				<span>
					{{ t('hubs_start', 'Handlingen blir allmän handling i akten.') }}
					<template v-if="committed">
						{{ t('hubs_start', 'Hubs-rummet gallras {datum} efter bekräftad överföring.', { datum: gallrasDatum }) }}
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
					:disabled="isRunning"
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
		NcModal, NcButton, NcLoadingIcon,
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
		}
	},

	computed: {
		dialogTitle() {
			return t('hubs_start', 'För över till Treserva')
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
		gallrasDatum() {
			return (this.result && this.result.gallrasDatum)
				|| (this.arende.provenance && this.arende.provenance.gallrasDatum)
				|| t('hubs_start', '3 mån efter överföring')
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
		 * Drive the simulated three-step Frends progress, then emit @committed.
		 * Spinner → bock per step. In demo this always succeeds.
		 */
		async onCommit() {
			if (this.isRunning || this.committed) {
				return
			}
			this.failed = false
			this.isRunning = true
			this.activeIndex = 0

			try {
				// Step 0 → 1: Skickat → Bekräftat (call the Frends connector here).
				const r = await api.commitToTreserva({ ...this.payload, arende: this.arende })
				if (!r || r.ok === false) {
					throw new Error('frends-rejected')
				}
				this.result = r

				// Demo: stage the three-step indicator with setTimeout.
				await this.advanceTo(1) // Bekräftat (API-svar)
				await this.advanceTo(2) // Registrerat

				this.committed = true
				this.isRunning = false
				this.$emit('committed', this.result)
			} catch (e) {
				// Failure: stay at "Skickat", show fel-tone. No move. (Demo: never.)
				this.clearTimers()
				this.activeIndex = 0
				this.failed = true
				this.isRunning = false
			}
		},

		advanceTo(index) {
			return new Promise((resolve) => {
				const id = setTimeout(() => {
					this.activeIndex = index
					resolve()
				}, 800)
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
		align-items: center;
		gap: 8px;
		margin: 0;
		padding: 8px 12px;
		border-radius: var(--border-radius, 8px);
		color: var(--hs-status-error);
		background: var(--color-background-hover);
		font-weight: 500;
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
