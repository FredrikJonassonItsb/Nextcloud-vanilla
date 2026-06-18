<template>
	<NcModal
		class="log-details-modal"
		name="log-details-modal"
		:title="`Log Details - ID ${log.id}`"
		size="large"
		@close="$emit('close')">
		<div class="modal__content">
			<table class="log-details-table">
				<tbody>
					<tr v-for="(value, key) in formattedLog" :key="key">
						<td class="log-key">
							{{ key }}
						</td>
						<td class="log-value">
							{{ value }}
						</td>
					</tr>
				</tbody>
			</table>
		</div>
	</NcModal>
</template>

<script>
import { NcModal } from '@nextcloud/vue'
export default {
	name: 'CustomLogDetailsModal',
	components: {
		NcModal,
	},
	props: {
		log: {
			type: Object,
			required: true,
		},
	},
	computed: {
		formattedLog() {
			const log = this.log

			// Map internal field names to translated labels
			const keyMap = {
				id: t('sdkmc', 'ID'),
				message_type: t('sdkmc', 'Message Type'),
				ap_id: t('sdkmc', 'AP ID'),
				creation_date_time: t('sdkmc', 'Creation Time'),
				from_client: t('sdkmc', 'From Message Client'),
				to_client: t('sdkmc', 'To Message Client'),
				from_ap: t('sdkmc', 'From AP'),
				to_ap: t('sdkmc', 'To AP'),
				sender: t('sdkmc', 'Sender'),
				sender_attention: t('sdkmc', 'Sender Attention'),
				recipient: t('sdkmc', 'Recipient'),
				recipient_attention: t('sdkmc', 'Recipient Attention'),
				message_id_as4: t('sdkmc', 'Message ID AS4'),
				message_id: t('sdkmc', 'Message ID'),
				conversation_id: t('sdkmc', 'Conversation ID'),
				address_book_copy: t('sdkmc', 'Address Book Copy'),

				mtsId: t('sdkmc', 'MTS ID'),
				apAddress: t('sdkmc', 'AP Address'),
				documentType: t('sdkmc', 'Document Type'),
				clientAddress: t('sdkmc', 'Client Address'),
				messageStatus: t('sdkmc', 'Status'),
				confidentiality: t('sdkmc', 'Confidentiality'),
				functionalAddress: t('sdkmc', 'Functional Address'),
				handlingServiceId: t('sdkmc', 'Handling Service ID'),
				accessPointPartyId: t('sdkmc', 'Access Point Party ID'),
				generatingSystemRoot: t('sdkmc', 'Generating System Root'),
				generatingSystemExtension: t('sdkmc', 'Generating System Extension'),
			}

			const flat = {
				[keyMap.id]: log.id,
				[keyMap.message_type]: log.message_type,
				[keyMap.ap_id]: log.ap_id,
				[keyMap.creation_date_time]: log.creation_date_time?.date,
				[keyMap.from_client]: log.from_client?.date,
				[keyMap.to_client]: log.to_client?.date,
				[keyMap.from_ap]: log.from_ap?.date ?? '—',
				[keyMap.to_ap]: log.to_ap?.date ?? '—',
				[keyMap.sender]: log.sender,
				[keyMap.sender_attention]: log.sender_attention,
				[keyMap.recipient]: log.recipient,
				[keyMap.recipient_attention]: log.recipient_attention,
				[keyMap.message_id_as4]: log.message_id_as4,
				[keyMap.message_id]: log.message_id,
				[keyMap.conversation_id]: log.conversation_id,
				[keyMap.address_book_copy]: log.address_book_copy,

				// From nested log_data
				[keyMap.mtsId]: log.log_data?.mtsId,
				[keyMap.apAddress]: log.log_data?.apAddress,
				[keyMap.documentType]: log.log_data?.documentType,
				[keyMap.clientAddress]: log.log_data?.clientAddress,
				[keyMap.messageStatus]: log.log_data?.messageStatus,
				[keyMap.confidentiality]: log.log_data?.confidentiality,
				[keyMap.functionalAddress]: log.log_data?.functionalAddress,
				[keyMap.handlingServiceId]: log.log_data?.handlingServiceId,
				[keyMap.accessPointPartyId]: log.log_data?.accessPointPartyId,
				[keyMap.generatingSystemRoot]: log.log_data?.generatingSystemRoot,
				[keyMap.generatingSystemExtension]: log.log_data?.generatingSystemExtension,
			}
			return flat
		},
	},
}
</script>

<style scoped>
.log-details-modal .modal-header__name {
	display: none;
}

.log-details-modal :deep(.modal-container.modal--large) {
	max-width: 90vw;
}

.log-details-modal :deep(.modal-wrapper) {
	max-width: none;
}

.modal__content {
	padding: 1rem;
	overflow-x: auto;
	background-color: var(--color-background-dark);
	color: var(--color-main-text);
}

.log-details-table {
	width: 100%;
	border-collapse: collapse;
	table-layout: auto;
}

.log-key {
	font-weight: bold;
	padding: 6px 12px;
	width: 200px;
	min-width: 200px;
	background-color: var(--color-background-hover);
	color: var(--color-main-text);
	vertical-align: top;
	white-space: nowrap;
	border-bottom: 1px solid var(--color-border-dark);
}

.log-value {
	padding: 6px 12px;
	overflow-wrap: anywhere;
	word-break: break-word;
	max-width: unset;
}

.log-value:hover {
	white-space: normal;
}

</style>
