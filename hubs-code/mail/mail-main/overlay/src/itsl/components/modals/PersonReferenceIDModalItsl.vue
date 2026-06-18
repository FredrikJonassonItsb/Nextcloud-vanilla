<template>
	<NcModal v-if="visible"
		name="Custom Modal"
		@close="handleClose">
		<div class="modal__content">
			<h2>{{ modalTitle }}</h2>

			<div class="form-group">
				<label for="first-row">{{ t('mail', 'Codework') }}</label>
				<NcSelect v-model="firstRow"
					:aria-label-combobox="t('mail', 'Codework')"
					input-id="first-row"
					:options="firstRowOptions"
					class="width-100" />
			</div>

			<div class="form-group">
				<label for="second-row">{{ secondRowTitle }}</label>

				<NcTextField id="second-row"
					v-model="secondRow"
					:placeholder="t('mail', secondRowTitle)" />
			</div>

			<div class="form-group">
				<label for="third-row">{{ t('mail', 'Description') }}</label>

				<NcTextField id="third-row"
					v-model="thirdRow"
					:placeholder="t('mail', 'Description')"
					@keydown.enter="submitForm" />
			</div>

			<NcButton :disabled="isSubmitDisabled"
				variant="primary"
				@click="submitForm">
				{{ t('mail', 'Submit') }}
			</NcButton>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcModal, NcSelect, NcTextField } from '@nextcloud/vue'
import { ref, computed, defineComponent, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'

export default defineComponent({
	name: 'PersonReferenceIDModalItsl',
	components: {
		NcButton,
		NcModal,
		NcSelect,
		NcTextField,
	},
	props: {
		visible: {
			type: Boolean,
			required: true,
		},
		type: {
			type: String,
			validator: val => ['', 'person', 'reference'].includes(val),
			required: true,
		},
		side: {
			type: String,
			validator: val => ['', 'sender', 'recipient'].includes(val),
			required: true,
		},
	},

	setup(props, { emit }) {
		const firstRow = ref('')
		const secondRow = ref('')
		const thirdRow = ref('')

		const modalTitle = computed(() => {
			return t('mail', `${props.side}${props.type}modalTitle`)
		})
		const secondRowTitle = computed(() => {
			return props.type === 'person' ? t('mail', 'PersonID') : t('mail', 'ReferenceID')
		})
		// Computed options for firstRow depending on type
		const firstRowOptions = computed(() => {
			if (props.type === 'person') {
				return [{
					value: '1.2.752.129.2.1.3.1',
					label: `${t('mail', 'personPersonnummer')} (1.2.752.129.2.1.3.1)`,
				},
				{
					value: '1.2.752.29.6.2.1',
					label: `${t('mail', 'personHsaid')} (1.2.752.29.6.2.1)`,
				},
				{
					value: '0.9.2342.19200300.100.1.3',
					label: `${t('mail', 'personEmail')} (0.9.2342.19200300.100.1.3)`,
				},
				{
					value: '1.2.752.129.2.1.2.1',
					label: `${t('mail', 'personOther')} (1.2.752.129.2.1.2.1)`,
				}]
			} else if (props.type === 'reference') {
				return [{
					value: 'dnr',
					label: `${t('mail', 'referenceDnr')} (dnr)`,
				},
				{
					value: '1.3.88',
					label: `${t('mail', 'referenceGln')} (1.3.88)`,
				},
				{
					value: '1.2.752.129.2.1.3.1',
					label: `${t('mail', 'referencePnr')} (1.2.752.129.2.1.3.1)`,
				},
				{
					value: '1.2.752.129.2.1.3.3',
					label: `${t('mail', 'referenceSnr')} (1.2.752.129.2.1.3.3)`,
				},
				{
					value: '1.2.752.74.9.1',
					label: `${t('mail', 'referenceNrid')} (1.2.752.74.9.1)`,
				},
				{
					value: 'unregistred',
					label: `${t('mail', 'referenceOther')} (unregistred)`,
				}]
			} else {
				return []
			}
		})

		// Reset form when opening
		watch(
			() => props.visible,
			(val) => {
				if (val) {
					resetForm()
				}
			},
		)

		const resetForm = () => {
			firstRow.value = ''
			secondRow.value = ''
			thirdRow.value = ''
		}

		const handleClose = () => {
			emit('update:visible', false)
		}

		const submitForm = () => {
			const payload = {
				firstRow: firstRow.value,
				secondRow: secondRow.value,
				thirdRow: thirdRow.value,
				side: props.side,
				type: props.type,
			}

			emit('submit', { ...payload })
			handleClose()
		}

		const isSubmitDisabled = computed(() => {
			return !firstRow.value || !secondRow.value
		})

		return {
			firstRow,
			firstRowOptions,
			secondRow,
			secondRowTitle,
			thirdRow,
			isSubmitDisabled,
			modalTitle,
			handleClose,
			submitForm,
		}
	},
})
</script>

<style scoped>
.modal__content {
	margin: 50px;
}
.form-group {
	margin-bottom: 1rem;
	display: flex;
	flex-direction: column;
	align-items: flex-start;
}
.form-group > * {
	width: 100%
}
@media only screen and (max-width: 768px) {
	.modal__content {
		margin: 60px 20px;
	}
	.modal-wrapper .modal-container__close {
		top: 15px;
		right: 10px;
	}
}
</style>
