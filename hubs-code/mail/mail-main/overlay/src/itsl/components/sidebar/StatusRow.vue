<!--
  - SPDX-FileCopyrightText: 2026 ITSL AB <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="status-row" @click="handleToggle">
		<span class="status-row__icon" :style="iconStyle">
			<Star v-if="icon === 'star'" :size="18" />
			<Alert v-else-if="icon === 'important'" :size="18" />
		</span>
		<span class="status-row__label">{{ label }}</span>
		<span @click.stop>
			<NcCheckboxRadioSwitch :checked="active"
				type="switch"
				class="status-row__switch"
				@update:checked="handleToggle" />
		</span>
	</div>
</template>

<script>
import { NcCheckboxRadioSwitch } from '@nextcloud/vue'
import Star from 'vue-material-design-icons/Star.vue'
import Alert from 'vue-material-design-icons/Alert.vue'

export default {
	name: 'StatusRow',
	components: {
		NcCheckboxRadioSwitch,
		Star,
		Alert,
	},
	props: {
		label: {
			type: String,
			required: true,
		},
		active: {
			type: Boolean,
			default: false,
		},
		icon: {
			type: String,
			required: true,
			validator: (v) => ['star', 'important'].includes(v),
		},
		activeColor: {
			type: String,
			default: 'var(--itsl-starred-active)',
		},
	},
	emits: ['toggle'],
	data() {
		return {
			lastToggleTime: 0,
		}
	},
	computed: {
		iconStyle() {
			return {
				color: this.active ? this.activeColor : 'var(--color-text-maxcontrast)',
			}
		},
	},
	methods: {
		handleToggle() {
			// Debounce: NcCheckboxRadioSwitch emits update:checked twice (~1ms apart)
			// from both its hidden input and content wrapper. Block duplicate calls.
			const now = Date.now()
			if (now - this.lastToggleTime < 50) return
			this.lastToggleTime = now
			this.$emit('toggle')
		},
	},
}
</script>

<style lang="scss" scoped>
.status-row {
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

.status-row__icon {
	display: flex;
	align-items: center;
	flex-shrink: 0;
	cursor: pointer;

	:deep(svg) {
		cursor: pointer;
	}
}

.status-row__label {
	flex: 1;
	font-size: 13px;
	color: var(--color-main-text);
	cursor: pointer;
}

.status-row__switch {
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
</style>
