<template>
	<div class="settings-input-wrapper">
		<label v-if="labelAbove" class="label-above" :for="inputId">
			{{ labelAbove }}
		</label>

		<div class="input-with-tooltip">
			<component
				:is="inputComponent"
				:id="!isSelectComponent ? inputId : undefined"
				:input-id="isSelectComponent ? inputId : undefined"
				:model-value="modelValue"
				:label="label"
				:placeholder="placeholder"
				:error="hasError"
				:disabled="disabled"
				v-bind="componentProps"
				:input-label="isSelectComponent ? (componentProps.inputLabel || label) : undefined"
				:label-outside="isSelectComponent ? (componentProps.labelOutside ?? true) : undefined"
				:type="isSwitchCOmponent ? (componentProps.type ) : type"
				@update:model-value="handleInput" />

			<NcPopover
				v-if="tooltip"
				class="input-tooltip"
				placement="top"
				:triggers="['hover', 'focus']"
				:focus-trap="false">
				<template #trigger="{ attrs }">
					<button
						v-bind="attrs"
						type="button"
						class="tooltip-btn"
						:aria-label="`Info: ${tooltip}`">
						<component :is="icon" class="tooltip-icon" />
					</button>
				</template>
				<template #default>
					<div class="tooltip-content" tabindex="0" v-text="tooltip" />
				</template>
			</NcPopover>
		</div>

		<p v-if="validationError" class="settings-error-msg" role="alert">
			{{ validationError }}
		</p>
	</div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { NcInputField, NcTextArea, NcPopover, NcSelect, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import IconInformation from 'vue-material-design-icons/InformationSlabCircleOutline.vue'

let uniqueIdCounter = 0

const props = defineProps({
	modelValue: {
		type: [String, Number, Object, Boolean],
		required: true,
	},
	label: {
		type: String,
		default: '',
	},
	tooltip: {
		type: String,
		default: '',
	},
	icon: {
		type: Object,
		default: () => IconInformation,
	},
	placeholder: {
		type: String,
		default: '',
	},
	validator: {
		type: Function,
		default: null,
	},
	isTextarea: {
		type: Boolean,
		default: false,
	},
	disabled: {
		type: Boolean,
		default: false,
	},
	component: {
		type: [Object, Function, String],
		default: null,
	},
	componentProps: {
		type: Object,
		default: () => ({}),
	},
	labelAbove: {
		type: String,
		default: '',
	},
	type: {
		type: String,
		default: 'text',
	},
})

const emit = defineEmits(['update:modelValue'])

const touched = ref(false)
const validationError = ref('')

const inputComponent = computed(() => {
	if (props.component) return props.component
	return props.isTextarea ? NcTextArea : NcInputField
})

const instanceUniqueId = ref(++uniqueIdCounter)

const inputId = computed(() => {
	let baseId = props.label || 'default-label'

	if (typeof props.modelValue === 'object' && props.modelValue?.address) {
		baseId += '-' + props.modelValue.address
	} else if (props.modelValue != null && props.modelValue !== '') {
		baseId += '-' + props.modelValue
	}

	return `input-${baseId.replace(/[^a-zA-Z0-9]/g, '-')}-${instanceUniqueId.value}`
})

const isSelectComponent = computed(() => {
	return props.component === NcSelect
		|| props.component === 'NcSelect'
})
const isSwitchCOmponent = computed(() => {
	return props.component === NcCheckboxRadioSwitch
		|| props.component === 'NcCheckboxRadioSwitch'
})

const hasError = computed(() => Boolean(validationError.value))

function validateValue(value) {
	if (!props.validator) return ''
	return props.validator(value) || ''
}

function handleInput(value) {
	touched.value = true
	validationError.value = validateValue(value)
	emit('update:modelValue', value)
}

// Watch for external changes to modelValue
watch(() => props.modelValue, (newValue) => {
	if (touched.value) {
		validationError.value = validateValue(newValue)
	}
}, { immediate: false })
</script>

<style scoped>
.settings-input-wrapper {
	position: relative;
	width: 100%;
}

.label-above {
	display: block;
	margin-bottom: 0.5rem;
	font-weight: 500;
}

.input-with-tooltip {
	display: flex;
	align-items: center;
	gap: 0.5rem;
}

.input-with-tooltip > :first-child {
	flex: 1;
}

.input-tooltip {
	flex-shrink: 0;
	margin-top: 0.375rem;
}

.tooltip-btn {
	background: none;
	border: none;
	padding: 0;
	cursor: pointer;
	border-radius: 50%;
	transition: background-color 0.2s ease;
}

.tooltip-btn:hover {
	background-color: var(--color-background-hover, rgba(0, 0, 0, 0.05));
}

.tooltip-btn:focus {
	outline: 2px solid var(--color-primary);
	outline-offset: 2px;
}

.tooltip-icon {
	width: 1.5rem;
	height: 1.5rem;
	color: var(--color-text-maxcontrast, #999);
}

.tooltip-content {
	padding: 0.75rem;
	width: auto;
	word-wrap: break-word;
	white-space: pre-line;
}

.settings-error-msg {
	position: absolute;
	bottom: -1.25rem;
	left: 0;
	font-size: 0.8125rem;
	color: var(--color-error);
	margin: 0;
}

/* Improve visibility of disabled/read-only input fields */
.input-with-tooltip :deep(.input-field:has(input:disabled)) {
	opacity: 1;
	filter: none;
}

.input-with-tooltip :deep(input:disabled) {
	opacity: 1;
	color: var(--color-main-text);
	background-color: var(--color-background-dark);
	cursor: not-allowed;
}
</style>
