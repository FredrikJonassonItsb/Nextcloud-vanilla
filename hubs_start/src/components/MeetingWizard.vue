<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<NcModal
		size="normal"
		:name="modalTitle"
		@close="onClose">
		<div class="meeting-wizard">
			<h2 class="meeting-wizard__title">
				<CalendarPlus :size="20" />
				{{ modalTitle }}
			</h2>

			<!-- Step indicator (not interactive past current step) -->
			<ol v-if="!result" class="meeting-wizard__steps" aria-hidden="true">
				<li
					v-for="(s, i) in steps"
					:key="s.id"
					class="meeting-wizard__step"
					:class="{
						'meeting-wizard__step--active': i === stepIndex,
						'meeting-wizard__step--done': i < stepIndex,
					}">
					<span class="meeting-wizard__step-num">{{ i + 1 }}</span>
					{{ s.label }}
				</li>
			</ol>

			<!-- ============ CONFIRMATION ============ -->
			<div v-if="result" class="meeting-wizard__confirm" aria-live="polite">
				<div class="meeting-wizard__confirm-icon">
					<CheckCircle :size="44" />
				</div>
				<p class="meeting-wizard__confirm-lead">
					{{ t('hubs_start', 'Mötet är bokat') }}
				</p>
				<dl class="meeting-wizard__summary">
					<div class="meeting-wizard__summary-row">
						<dt>{{ t('hubs_start', 'Tid') }}</dt>
						<dd>{{ confirmTime }}</dd>
					</div>
					<div class="meeting-wizard__summary-row">
						<dt>{{ t('hubs_start', 'Deltagare') }}</dt>
						<dd>{{ confirmParticipants }}</dd>
					</div>
					<div class="meeting-wizard__summary-row">
						<dt>{{ t('hubs_start', 'Skyddsnivå') }}</dt>
						<dd>{{ confirmProtection }}</dd>
					</div>
					<div class="meeting-wizard__summary-row">
						<dt>{{ t('hubs_start', 'SMS-status') }}</dt>
						<dd>{{ confirmSmsStatus }}</dd>
					</div>
				</dl>
				<div class="meeting-wizard__actions">
					<NcButton type="primary" @click="onClose">
						{{ t('hubs_start', 'Klar') }}
					</NcButton>
				</div>
			</div>

			<!-- ============ STEP A — VEM ============ -->
			<form
				v-else
				class="meeting-wizard__body"
				@submit.prevent="onPrimary">
				<fieldset v-show="stepIndex === 0" class="meeting-wizard__fieldset">
					<legend class="meeting-wizard__legend">
						{{ t('hubs_start', 'Vem ska delta?') }}
					</legend>

					<NcTextField
						:value.sync="form.name"
						:label="t('hubs_start', 'Namn på medborgare')"
						:error="!!errors.name"
						:helper-text="errors.name || ''"
						autocomplete="off"
						required />

					<NcTextField
						:value.sync="form.ssn"
						:label="t('hubs_start', 'Personnummer (för BankID)')"
						:helper-text="t('hubs_start', 'Krävs när BankID med personnummer ska bindas till mötet.')"
						autocomplete="off"
						inputmode="numeric" />

					<NcTextField
						:value.sync="form.mobile"
						:label="t('hubs_start', 'Mobilnummer (för SMS)')"
						:helper-text="t('hubs_start', 'Dit skickas inbjudan och påminnelse via SMS.')"
						autocomplete="off"
						inputmode="tel" />

					<NcTextField
						:value.sync="form.secureEmail"
						:label="t('hubs_start', 'Säker e-postadress (valfritt)')"
						:error="!!errors.secureEmail"
						:helper-text="errors.secureEmail || t('hubs_start', 'För inbjudan via Säker E-post.')"
						autocomplete="off"
						inputmode="email" />

					<label class="meeting-wizard__select-label">
						{{ t('hubs_start', 'Intern kollega (valfritt)') }}
						<NcSelect
							v-model="form.colleague"
							:options="colleagueOptions"
							:placeholder="t('hubs_start', 'Sök kollega')"
							label="displayName"
							:input-label="t('hubs_start', 'Intern kollega (valfritt)')"
							:clearable="true" />
					</label>
				</fieldset>

				<!-- ============ STEP B — NÄR ============ -->
				<fieldset v-show="stepIndex === 1" class="meeting-wizard__fieldset">
					<legend class="meeting-wizard__legend">
						{{ t('hubs_start', 'När ska mötet hållas?') }}
					</legend>

					<NcTextField
						:value.sync="form.title"
						:label="t('hubs_start', 'Rubrik')"
						autocomplete="off" />

					<label class="meeting-wizard__field-label">
						{{ t('hubs_start', 'Starttid') }}
						<NcDateTimePickerNative
							v-if="hasNativePicker"
							id="hs-meeting-start"
							:value="startDate"
							type="datetime-local"
							:label="t('hubs_start', 'Starttid')"
							@input="onStartInput" />
						<input
							v-else
							id="hs-meeting-start-text"
							class="meeting-wizard__text-fallback"
							type="datetime-local"
							:value="startLocalValue"
							@input="onStartTextInput">
					</label>

					<label class="meeting-wizard__field-label">
						{{ t('hubs_start', 'Längd') }}
						<select
							v-model.number="form.durationMin"
							class="meeting-wizard__duration">
							<option :value="15">{{ t('hubs_start', '15 minuter') }}</option>
							<option :value="30">{{ t('hubs_start', '30 minuter') }}</option>
							<option :value="45">{{ t('hubs_start', '45 minuter') }}</option>
							<option :value="60">{{ t('hubs_start', '60 minuter') }}</option>
						</select>
					</label>

					<p v-if="errors.start" class="meeting-wizard__error" aria-live="polite">
						{{ errors.start }}
					</p>

					<button
						type="button"
						class="meeting-wizard__hint hs-target"
						@click="useNearestSlot">
						<Clock :size="16" />
						{{ nearestSlotHint }}
					</button>
				</fieldset>

				<!-- ============ STEP C — SKYDD ============ -->
				<fieldset v-show="stepIndex === 2" class="meeting-wizard__fieldset">
					<legend class="meeting-wizard__legend">
						{{ t('hubs_start', 'Skydd och inbjudan') }}
					</legend>

					<div class="meeting-wizard__protect">
						<NcCheckboxRadioSwitch
							type="switch"
							:checked.sync="form.requireBankId">
							{{ t('hubs_start', 'BankID krävs för att delta') }}
						</NcCheckboxRadioSwitch>
						<p class="meeting-wizard__helper">
							{{ t('hubs_start', 'Medborgaren legitimerar sig med BankID innan hen släpps in i mötet. Rekommenderas för externa deltagare.') }}
						</p>
					</div>

					<div class="meeting-wizard__protect">
						<NcCheckboxRadioSwitch
							type="switch"
							:checked.sync="form.sendSms"
							:disabled="!form.mobile">
							{{ t('hubs_start', 'Skicka inbjudan via SMS') }}
						</NcCheckboxRadioSwitch>
						<p class="meeting-wizard__helper">
							{{ smsHelper }}
						</p>
					</div>

					<div class="meeting-wizard__protect">
						<NcCheckboxRadioSwitch
							type="switch"
							:checked.sync="form.sendSecureEmailInvite"
							:disabled="!form.secureEmail">
							{{ t('hubs_start', 'Skicka inbjudan via Säker E-post') }}
						</NcCheckboxRadioSwitch>
						<p class="meeting-wizard__helper">
							{{ secureEmailHelper }}
						</p>
					</div>
				</fieldset>

				<p v-if="submitError" class="meeting-wizard__error" aria-live="polite">
					{{ submitError }}
				</p>

				<!-- ============ NAV ============ -->
				<div class="meeting-wizard__actions">
					<NcButton
						v-if="stepIndex > 0"
						type="tertiary"
						:disabled="submitting"
						@click="prevStep">
						{{ t('hubs_start', 'Tillbaka') }}
					</NcButton>
					<span class="meeting-wizard__actions-spacer" />
					<NcButton
						v-if="!isLastStep"
						type="primary"
						@click="nextStep">
						{{ t('hubs_start', 'Nästa') }}
					</NcButton>
					<NcButton
						v-else
						type="primary"
						:disabled="submitting"
						@click="book">
						<template #icon>
							<NcLoadingIcon v-if="submitting" :size="20" />
							<CalendarCheck v-else :size="20" />
						</template>
						{{ t('hubs_start', 'Boka') }}
					</NcButton>
				</div>
			</form>
		</div>
	</NcModal>
</template>

<script>
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'
import NcDateTimePickerNative from '@nextcloud/vue/dist/Components/NcDateTimePickerNative.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import { translate as t } from '@nextcloud/l10n'

import CalendarPlus from 'vue-material-design-icons/CalendarPlus.vue'
import CalendarCheck from 'vue-material-design-icons/CalendarCheck.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import Clock from 'vue-material-design-icons/ClockOutline.vue'

import api from '../services/api.js'

/** Round a Date up to the next quarter hour (nearest bookable slot). */
function nextQuarterHour(from) {
	const d = new Date(from.getTime())
	d.setSeconds(0, 0)
	const rem = d.getMinutes() % 15
	if (rem !== 0) {
		d.setMinutes(d.getMinutes() + (15 - rem))
	}
	return d
}

export default {
	name: 'MeetingWizard',

	components: {
		NcModal,
		NcButton,
		NcTextField,
		NcSelect,
		NcCheckboxRadioSwitch,
		NcDateTimePickerNative,
		NcLoadingIcon,
		CalendarPlus,
		CalendarCheck,
		CheckCircle,
		Clock,
	},

	props: {
		/** "start now" mode: prefill start = now and jump intent to instant. */
		startNow: {
			type: Boolean,
			default: false,
		},
		/** Optional function mailbox the meeting is sent from. */
		fromMailboxId: {
			type: [String, Number],
			default: null,
		},
		/** gap17 — ärendet bokningen hör till: dnr/hubsCaseId märks in i kalender-
		 * objektet (X-HUBS-DNR/CATEGORIES) så mötet syns i kortets Möten-flik. */
		arende: {
			type: Object,
			default: null,
		},
	},

	data() {
		const base = this.startNow ? new Date() : nextQuarterHour(new Date())
		return {
			stepIndex: 0,
			submitting: false,
			submitError: null,
			result: null,
			startDate: base,
			hasNativePicker: typeof NcDateTimePickerNative !== 'undefined',
			// Stubbed colleague directory (NcSelect of users). Real list arrives
			// from the directory endpoint in a later phase.
			colleagueOptions: [],
			form: {
				name: '',
				ssn: '',
				mobile: '',
				secureEmail: '',
				colleague: null,
				title: this.startNow
					? t('hubs_start', 'Säkert möte (direkt)')
					: t('hubs_start', 'Säkert möte'),
				durationMin: 30,
				// External by default → BankID on.
				requireBankId: true,
				sendSms: false,
				sendSecureEmailInvite: false,
			},
			errors: {
				name: '',
				secureEmail: '',
				start: '',
			},
		}
	},

	computed: {
		steps() {
			return [
				{ id: 'vem', label: t('hubs_start', 'Vem') },
				{ id: 'nar', label: t('hubs_start', 'När') },
				{ id: 'skydd', label: t('hubs_start', 'Skydd') },
			]
		},

		modalTitle() {
			return this.startNow
				? t('hubs_start', 'Starta säkert möte nu')
				: t('hubs_start', 'Boka säkert möte')
		},

		isLastStep() {
			return this.stepIndex === this.steps.length - 1
		},

		/** Value bound to the text fallback (<input type=datetime-local>). */
		startLocalValue() {
			return this.toLocalInput(this.startDate)
		},

		nearestSlotHint() {
			const slot = nextQuarterHour(new Date())
			return t('hubs_start', 'Närmaste lediga tid: {time} — använd den', {
				time: this.formatDateTime(slot),
			})
		},

		smsHelper() {
			if (!this.form.mobile) {
				return t('hubs_start', 'Ange ett mobilnummer i steg "Vem" för att kunna skicka SMS.')
			}
			return t('hubs_start', 'Medborgaren får ett SMS med tid och en länk till mötet.')
		},

		secureEmailHelper() {
			if (!this.form.secureEmail) {
				return t('hubs_start', 'Ange en säker e-postadress i steg "Vem" för att kunna skicka inbjudan.')
			}
			return t('hubs_start', 'Medborgaren får en inbjudan via Säker E-post med kvittens.')
		},

		// --- Confirmation rendering (from createSecureMeeting result) ---
		confirmTime() {
			const start = this.result?.start || this.computedStart().toISOString()
			const end = this.result?.end || this.computedEnd().toISOString()
			return this.formatDateTime(new Date(start)) + ' – ' + this.formatTime(new Date(end))
		},

		confirmParticipants() {
			const parts = []
			if (this.form.name) {
				parts.push(this.form.name)
			}
			if (this.form.colleague?.displayName) {
				parts.push(this.form.colleague.displayName)
			}
			return parts.length
				? parts.join(', ')
				: t('hubs_start', 'Inga namngivna deltagare')
		},

		confirmProtection() {
			const protection = this.result?.protection
			const bankId = (protection && typeof protection.requireBankId === 'boolean')
				? protection.requireBankId
				: this.form.requireBankId
			return bankId
				? t('hubs_start', 'BankID krävs')
				: t('hubs_start', 'Inget BankID-krav')
		},

		confirmSmsStatus() {
			const status = this.result?.smsStatus
			switch (status) {
			case 'sent':
				return t('hubs_start', 'SMS skickat')
			case 'queued':
				return t('hubs_start', 'SMS köat')
			case 'failed':
				return t('hubs_start', 'SMS kunde inte skickas')
			case 'skipped':
			case null:
			case undefined:
				return this.form.sendSms
					? t('hubs_start', 'SMS skickat')
					: t('hubs_start', 'Inget SMS skickat')
			default:
				return String(status)
			}
		},
	},

	methods: {
		t,

		// --- step navigation ---
		nextStep() {
			if (!this.validateStep(this.stepIndex)) {
				return
			}
			if (this.stepIndex < this.steps.length - 1) {
				this.stepIndex += 1
			}
		},

		prevStep() {
			if (this.stepIndex > 0) {
				this.stepIndex -= 1
			}
		},

		onPrimary() {
			// Enter on the form → advance or book.
			if (this.isLastStep) {
				this.book()
			} else {
				this.nextStep()
			}
		},

		// --- validation ---
		validateStep(index) {
			this.errors.name = ''
			this.errors.secureEmail = ''
			this.errors.start = ''
			if (index === 0) {
				if (!this.form.name.trim()) {
					this.errors.name = t('hubs_start', 'Ange medborgarens namn.')
					return false
				}
				if (this.form.secureEmail && !this.form.secureEmail.includes('@')) {
					this.errors.secureEmail = t('hubs_start', 'Ange en giltig e-postadress.')
					return false
				}
			}
			if (index === 1) {
				if (!this.startDate || isNaN(this.startDate.getTime())) {
					this.errors.start = t('hubs_start', 'Välj en giltig starttid.')
					return false
				}
			}
			return true
		},

		// --- time handling ---
		onStartInput(value) {
			// NcDateTimePickerNative emits a Date.
			if (value instanceof Date && !isNaN(value.getTime())) {
				this.startDate = value
			} else if (value) {
				const d = new Date(value)
				if (!isNaN(d.getTime())) {
					this.startDate = d
				}
			}
		},

		onStartTextInput(event) {
			const d = new Date(event.target.value)
			if (!isNaN(d.getTime())) {
				this.startDate = d
			}
		},

		useNearestSlot() {
			this.startDate = nextQuarterHour(new Date())
		},

		computedStart() {
			return this.startDate
		},

		computedEnd() {
			return new Date(this.startDate.getTime() + this.form.durationMin * 60000)
		},

		// --- formatting ---
		toLocalInput(d) {
			if (!d || isNaN(d.getTime())) {
				return ''
			}
			const pad = (n) => String(n).padStart(2, '0')
			return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
				+ 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes())
		},

		formatDateTime(d) {
			if (!d || isNaN(d.getTime())) {
				return ''
			}
			return d.toLocaleString(undefined, {
				weekday: 'short',
				day: 'numeric',
				month: 'short',
				hour: '2-digit',
				minute: '2-digit',
			})
		},

		formatTime(d) {
			if (!d || isNaN(d.getTime())) {
				return ''
			}
			return d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
		},

		// --- booking ---
		buildPayload() {
			const citizen = { name: this.form.name.trim() }
			if (this.form.ssn.trim()) {
				citizen.ssn = this.form.ssn.trim()
			}
			if (this.form.mobile.trim()) {
				citizen.mobile = this.form.mobile.trim()
			}
			if (this.form.secureEmail.trim()) {
				citizen.secureEmail = this.form.secureEmail.trim()
			}

			const payload = {
				citizen,
				start: this.computedStart().toISOString(),
				end: this.computedEnd().toISOString(),
				title: this.form.title.trim() || t('hubs_start', 'Säkert möte'),
				requireBankId: !!this.form.requireBankId,
				sendSms: !!(this.form.sendSms && this.form.mobile.trim()),
				sendSecureEmailInvite: !!(this.form.sendSecureEmailInvite && this.form.secureEmail.trim()),
			}
			if (this.form.colleague?.id) {
				payload.colleagueUserId = this.form.colleague.id
			}
			// gap17 — ärende-bindning: dnr (eller pseudonymt hubsCaseId) följer med
			// bokningen så kalenderobjektet märks och mötet landar i kortets Möten-flik.
			if (this.arende && (this.arende.dnr || this.arende.hubsCaseId)) {
				payload.dnr = this.arende.dnr || this.arende.hubsCaseId
			}
			if (this.fromMailboxId !== null && this.fromMailboxId !== '') {
				payload.fromMailboxId = this.fromMailboxId
			}
			return payload
		},

		async book() {
			// Validate every step before submit.
			for (let i = 0; i < this.steps.length; i++) {
				if (!this.validateStep(i)) {
					this.stepIndex = i
					return
				}
			}
			this.submitting = true
			this.submitError = null
			try {
				const result = await api.createSecureMeeting(this.buildPayload())
				this.result = result || {}
				this.$emit('booked', this.result)
			} catch (e) {
				this.submitError = t('hubs_start', 'Mötet kunde inte bokas. Försök igen.')
			} finally {
				this.submitting = false
			}
		},

		onClose() {
			this.$emit('close')
		},
	},
}
</script>

<style scoped lang="scss">
.meeting-wizard {
	padding: 20px 24px 24px;
	display: flex;
	flex-direction: column;
	gap: 16px;

	&__title {
		display: flex;
		align-items: center;
		gap: 8px;
		font-size: 1.15rem;
		font-weight: 600;
		margin: 0;
	}

	&__steps {
		list-style: none;
		display: flex;
		gap: 8px;
		margin: 0;
		padding: 0;
	}

	&__step {
		display: flex;
		align-items: center;
		gap: 6px;
		flex: 1;
		font-size: 0.85rem;
		color: var(--color-text-maxcontrast);
		padding-bottom: 6px;
		border-bottom: 2px solid var(--color-border);

		&--active {
			color: var(--color-main-text);
			font-weight: 600;
			border-bottom-color: var(--color-primary-element);
		}

		&--done {
			color: var(--color-main-text);
			border-bottom-color: var(--hs-status-success);
		}
	}

	&__step-num {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		width: 22px;
		height: 22px;
		border-radius: 50%;
		background: var(--color-background-dark);
		font-size: 0.8rem;
	}

	&__body {
		display: flex;
		flex-direction: column;
		gap: 16px;
	}

	&__fieldset {
		border: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 14px;
	}

	&__legend {
		font-weight: 600;
		font-size: 1rem;
		padding: 0;
		margin-bottom: 2px;
	}

	&__field-label,
	&__select-label {
		display: flex;
		flex-direction: column;
		gap: 4px;
		font-size: 0.9rem;
		font-weight: 600;
	}

	&__text-fallback,
	&__duration {
		min-height: 44px;
		padding: 0 8px;
		border: 2px solid var(--color-border-maxcontrast, var(--color-border));
		border-radius: var(--border-radius-large, 8px);
		background: var(--color-main-background);
		color: var(--color-main-text);

		&:focus-visible {
			outline: 2px solid var(--color-primary-element);
			outline-offset: 1px;
		}
	}

	&__hint {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		align-self: flex-start;
		min-height: var(--hs-min-target);
		padding: 4px 8px;
		background: transparent;
		border: 1px dashed var(--color-border-maxcontrast, var(--color-border));
		border-radius: var(--border-radius, 6px);
		color: var(--color-primary-element);
		cursor: pointer;
		font-size: 0.9rem;

		&:hover {
			background: var(--color-background-hover);
		}

		&:focus-visible {
			outline: 2px solid var(--color-primary-element);
			outline-offset: 2px;
		}
	}

	&__protect {
		padding: 12px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large, 8px);
		background: var(--color-background-hover);
	}

	&__helper {
		margin: 6px 0 0;
		font-size: 0.85rem;
		color: var(--color-text-maxcontrast);
	}

	&__error {
		margin: 0;
		color: var(--hs-status-error);
		font-size: 0.9rem;
		font-weight: 600;
	}

	&__actions {
		display: flex;
		align-items: center;
		gap: 8px;
		margin-top: 4px;
	}

	&__actions-spacer {
		flex: 1;
	}

	// --- confirmation ---
	&__confirm {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 12px;
		text-align: center;
		padding: 8px 0;
	}

	&__confirm-icon {
		color: var(--hs-status-success);
	}

	&__confirm-lead {
		font-size: 1.1rem;
		font-weight: 600;
		margin: 0;
	}

	&__summary {
		width: 100%;
		max-width: 420px;
		margin: 0;
		display: flex;
		flex-direction: column;
		gap: 8px;
	}

	&__summary-row {
		display: flex;
		justify-content: space-between;
		gap: 16px;
		padding: 8px 0;
		border-bottom: 1px solid var(--color-border);
		text-align: left;

		dt {
			color: var(--color-text-maxcontrast);
			font-size: 0.9rem;
		}

		dd {
			margin: 0;
			font-weight: 600;
		}
	}
}
</style>
