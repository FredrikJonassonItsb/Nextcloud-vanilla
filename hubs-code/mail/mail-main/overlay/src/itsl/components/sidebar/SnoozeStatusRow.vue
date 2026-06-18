<!--
  - SPDX-FileCopyrightText: 2026 ITSL AB <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="snooze-status-row">
		<!-- Popover wraps the main row for proper positioning -->
		<NcPopover :shown.sync="popoverOpen"
			popup-role="dialog"
			class="snooze-status-row__popover">
			<template #trigger>
				<!-- Main row: icon - label - toggle -->
				<div class="snooze-status-row__main">
					<span class="snooze-status-row__icon" :style="iconStyle">
						<Alarm :size="18" />
					</span>
					<span class="snooze-status-row__label">{{ t('mail', 'Snooze') }}</span>
					<span @click="onSwitchClick">
						<NcCheckboxRadioSwitch :checked="!!snoozedUntil"
							type="switch"
							class="snooze-status-row__switch"
							@update:checked="handleToggle" />
					</span>
				</div>
			</template>

			<div class="snooze-status-row__picker">
				<div class="snooze-status-row__presets">
					<button v-for="option in reminderOptions"
						:key="option.key"
						class="snooze-status-row__preset"
						@click="onSelectPreset(option.timestamp)">
						{{ option.label }}
					</button>
				</div>

				<div class="snooze-status-row__divider" />

				<div class="snooze-status-row__custom">
					<input type="datetime-local"
						:value="customDateTimeValue"
						:min="minDateTime"
						class="snooze-status-row__datetime-input"
						@change="onCustomDateChange">
					<NcButton variant="primary"
						:disabled="!customDateTime"
						@click="onSetCustom">
						{{ t('mail', 'Set') }}
					</NcButton>
				</div>
			</div>
		</NcPopover>

		<!-- Snooze time shown when active -->
		<div v-if="snoozedUntil" class="snooze-status-row__time">
			{{ t('mail', 'Postponed until') }} {{ formattedTime }}
		</div>
	</div>
</template>

<script>
import { NcPopover, NcButton, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import Alarm from 'vue-material-design-icons/Alarm.vue'
import moment from '@nextcloud/moment'

export default {
	name: 'SnoozeStatusRow',
	components: {
		NcPopover,
		NcButton,
		NcCheckboxRadioSwitch,
		Alarm,
	},
	props: {
		snoozedUntil: {
			type: Number,
			default: null,
		},
	},
	emits: ['snooze', 'clear'],
	data() {
		return {
			popoverOpen: false,
			customDateTime: null,
		}
	},
	computed: {
		iconStyle() {
			return {
				color: this.snoozedUntil ? 'var(--itsl-snooze-active)' : 'var(--color-text-maxcontrast)',
			}
		},
		formattedTime() {
			if (!this.snoozedUntil) return ''
			// Use llll format - fully locale-aware abbreviated date/time with weekday
			// e.g., "Wed, Jan 7, 2026 6:00 PM" (English) or "ons 7 jan 2026 18:00" (Swedish)
			return moment.unix(this.snoozedUntil).format('llll')
		},
		reminderOptions() {
			const currentDateTime = moment()

			const laterTodayTime = (currentDateTime.hour() < 18)
				? moment().hour(18)
				: null

			const tomorrowTime = moment().add(1, 'days').hour(8)

			const thisWeekendTime = (currentDateTime.day() !== 6 && currentDateTime.day() !== 0)
				? moment().day(6).hour(8)
				: null

			const nextWeekTime = moment().add(1, 'weeks').day(1).hour(8)

			return [
				{
					key: 'laterToday',
					timestamp: this.getTimestamp(laterTodayTime),
					label: laterTodayTime ? this.t('mail', 'Later today') + ` – ${laterTodayTime.format('LT')}` : null,
				},
				{
					key: 'tomorrow',
					timestamp: this.getTimestamp(tomorrowTime),
					label: this.t('mail', 'Tomorrow') + ` – ${tomorrowTime.format('ddd LT')}`,
				},
				{
					key: 'thisWeekend',
					timestamp: this.getTimestamp(thisWeekendTime),
					label: thisWeekendTime ? this.t('mail', 'This weekend') + ` – ${thisWeekendTime.format('ddd LT')}` : null,
				},
				{
					key: 'nextWeek',
					timestamp: this.getTimestamp(nextWeekTime),
					label: this.t('mail', 'Next week') + ` – ${nextWeekTime.format('ddd LT')}`,
				},
			].filter(option => option.timestamp !== null)
		},
		customDateTimeValue() {
			if (!this.customDateTime) return ''
			return moment(this.customDateTime).format('YYYY-MM-DDTHH:mm')
		},
		minDateTime() {
			return moment().format('YYYY-MM-DDTHH:mm')
		},
	},
	watch: {
		popoverOpen(isOpen) {
			if (isOpen) {
				// Set up listeners when popover opens (iframe exists by then)
				this.$nextTick(() => {
					this.setupIframeClickListeners()
				})
			} else {
				this.cleanupIframeClickListeners()
			}
		},
	},
	mounted() {
		document.addEventListener('click', this.handleOutsideClick, true)
	},
	beforeDestroy() {
		document.removeEventListener('click', this.handleOutsideClick, true)
		this.cleanupIframeClickListeners()
	},
	methods: {
		setupIframeClickListeners() {
			this.iframeCleanupFns = []
			const iframes = document.querySelectorAll('iframe.message-frame')
			iframes.forEach(iframe => {
				try {
					const iframeDoc = iframe.contentDocument || iframe.contentWindow?.document
					if (iframeDoc) {
						const handler = () => { this.popoverOpen = false }
						iframeDoc.addEventListener('click', handler, true)
						this.iframeCleanupFns.push(() => {
							iframeDoc.removeEventListener('click', handler, true)
						})
					}
				} catch (e) {
					// Cross-origin iframe, can't add listener
				}
			})
		},
		cleanupIframeClickListeners() {
			if (this.iframeCleanupFns) {
				this.iframeCleanupFns.forEach(fn => fn())
				this.iframeCleanupFns = []
			}
		},
		handleOutsideClick(event) {
			if (!this.popoverOpen) return

			// Check if click is inside the popover content (teleported to body)
			if (event.target.closest('.v-popper__popper')) {
				return // Click inside popover - don't close
			}

			// Check if click is on the trigger (the snooze row itself)
			if (event.target.closest('.snooze-status-row__main')) {
				return // Click on trigger - let NcPopover handle
			}

			// Click is outside - close popover
			this.popoverOpen = false
		},
		getTimestamp(momentObject) {
			return momentObject?.minute(0).second(0).millisecond(0).valueOf() || null
		},
		onSwitchClick(event) {
			// Stop propagation when disabling snooze to prevent popover from opening
			if (this.snoozedUntil) {
				event.stopPropagation()
			}
		},
		handleToggle(checked) {
			if (checked) {
				// Opening snooze - show popover
				this.popoverOpen = true
			} else {
				// Clearing snooze
				this.$emit('clear')
			}
		},
		onSelectPreset(timestamp) {
			this.$emit('snooze', timestamp)
			this.popoverOpen = false
		},
		onCustomDateChange(event) {
			this.customDateTime = new Date(event.target.value)
		},
		onSetCustom() {
			if (this.customDateTime) {
				this.$emit('snooze', this.customDateTime.valueOf())
				this.popoverOpen = false
				this.customDateTime = null
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.snooze-status-row__main {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 2px 8px;
	margin: 0 -8px;
	border-radius: 6px;
	cursor: pointer;

	&:hover {
		background: var(--color-background-hover);
	}
}

.snooze-status-row__icon {
	display: flex;
	align-items: center;
	flex-shrink: 0;
	cursor: pointer;
}

.snooze-status-row__label {
	flex: 1;
	font-size: 13px;
	color: var(--color-main-text);
}

.snooze-status-row__switch {
	margin: 0;
	margin-inline-end: -2px;
	cursor: pointer;

	:deep(.checkbox-content) {
		padding-top: 0;
		padding-bottom: 0;
	}

	// Disable individual hover on switch - row handles hover
	:deep(.checkbox-radio-switch__label),
	:deep(.checkbox-radio-switch__label:hover),
	:deep(.checkbox-radio-switch--checked .checkbox-radio-switch__label),
	:deep(.checkbox-radio-switch--checked .checkbox-radio-switch__label:hover) {
		background: transparent !important;
		background-color: transparent !important;
	}

	:deep(.checkbox-radio-switch__content) {
		background-color: transparent !important;
		padding-inline-end: 0 !important;
		margin-inline-end: -1px !important;
	}

	:deep(.checkbox-radio-switch__input) {
		background-color: transparent !important;
	}
}

.snooze-status-row__time {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	padding-inline-start: 0;
	margin-top: -2px;
}

.snooze-status-row__picker {
	padding: 8px;
	min-width: 220px;
}

.snooze-status-row__presets {
	display: flex;
	flex-direction: column;
}

.snooze-status-row__preset {
	display: block;
	width: 100%;
	text-align: start;
	background: none;
	border: none;
	padding: 8px 12px;
	cursor: pointer;
	font-size: 13px;
	color: var(--color-main-text);
	border-radius: var(--border-radius);

	&:hover {
		background: var(--color-background-hover);
	}
}

.snooze-status-row__divider {
	height: 1px;
	background: var(--color-border);
	margin: 8px 0;
}

.snooze-status-row__custom {
	display: flex;
	align-items: center;
	gap: 8px;
}

.snooze-status-row__datetime-input {
	flex: 1;
	padding: 6px;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	font-size: 12px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	color-scheme: light dark;
}
</style>
