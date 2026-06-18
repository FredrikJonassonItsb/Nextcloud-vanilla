<template>
	<NcModal
		:name="t('sdkmc', 'SDK Receipt')"
		size="large"
		:show="show"
		@close="$emit('close')">
		<div class="modal-content">
			<div v-if="!messageId" class="no-message-state">
				<p>{{ t('sdkmc', 'No message selected') }}</p>
			</div>
			<div v-else-if="isLoading" class="loading-state">
				<NcLoadingIcon :size="32" />
				<p>{{ t('sdkmc', 'Loading receipt...') }}</p>
			</div>
			<div v-else-if="error" class="error-state">
				<NcNoteCard type="error">
					{{ error }}
				</NcNoteCard>
			</div>
			<div v-else class="receipt-content">
				<JsonPretty :data="receiptData" :show-icon="true" />
			</div>
		</div>
	</NcModal>
</template>

<script>
import { ref, watch } from 'vue'
import axios from '@nextcloud/axios'
import JsonPretty from 'vue-json-pretty'
import 'vue-json-pretty/lib/styles.css'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'

export default {
	name: 'SDKReceiptModal',
	components: {
		JsonPretty,
		NcModal,
		NcLoadingIcon,
		NcNoteCard,
	},
	props: {
		messageId: {
			type: [String, Number],
			required: false,
			default: null,
		},
		show: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['close'],
	setup(props, { emit }) {
		const receiptData = ref(null)
		const isLoading = ref(false)
		const error = ref(null)

		const fetchReceipt = async () => {
			if (!props.show || !props.messageId) {
				// Reset state when no messageId or modal is hidden
				if (!props.messageId) {
					receiptData.value = null
					error.value = null
					isLoading.value = false
				}
				return
			}

			isLoading.value = true
			error.value = null
			receiptData.value = null

			try {
				const response = await axios.get(
					generateUrl('/apps/sdkmc/api/v2/sdkmw/messageReceipt/' + props.messageId),
					{
						headers: {
							Accept: 'application/json',
						},
						withCredentials: true,
					},
				)
				receiptData.value = response.data
			} catch (err) {
				error.value = t('sdkmc', 'No receipt data found for message id: ') + props.messageId
				console.error('Receipt fetch error:', err)
			} finally {
				isLoading.value = false
			}
		}

		watch(() => props.messageId, () => {
			if (props.show) {
				fetchReceipt()
			}
		})

		return {
			receiptData,
			isLoading,
			error,
			t,
		}
	},
}
</script>

<style scoped>
.modal-content {
	padding: 20px;
	min-height: 100px;
	max-height: 80vh;
	overflow-y: auto;
}

.no-message-state {
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 40px;
}

.no-message-state p {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.loading-state {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 16px;
	padding: 40px;
}

.loading-state p {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.error-state {
	padding: 20px 0;
}

.receipt-content {
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	overflow: hidden;
}

:deep(.vjs-tree) {
	font-family: var(--font-face);
	font-size: var(--default-font-size);
	background-color: var(--color-main-background) !important;
	color: var(--color-main-text) !important;
	padding: 1rem;
	line-height: 1.5;
	margin: 0;
}

:deep(.vjs-tree .vjs-tree__content) {
	border-left: 1px dotted var(--color-border-dark);
}

:deep(.vjs-tree .vjs-key) {
	color: var(--color-primary-element) !important;
	font-weight: 600;
}

:deep(.vjs-tree .vjs-value__string) {
	color: var(--color-success) !important;
}

:deep(.vjs-tree .vjs-value__number) {
	color: var(--color-warning) !important;
	font-weight: 500;
}

:deep(.vjs-tree .vjs-value__boolean) {
	color: var(--color-info) !important;
	font-weight: 500;
}

:deep(.vjs-tree .vjs-value__null) {
	color: var(--color-text-maxcontrast) !important;
	font-style: italic;
	opacity: 0.7;
}

:deep(.vjs-tree .vjs-tree__brackets) {
	color: var(--color-text-maxcontrast) !important;
	font-weight: bold;
}

:deep(.vjs-tree .vjs-tree__node:hover) {
	background-color: var(--color-background-hover) !important;
}

:deep(.vjs-tree .vjs-tree__node--highlight) {
	background-color: var(--color-primary-light) !important;
}

:deep(.vjs-tree *) {
	border-color: var(--color-border) !important;
}
</style>
