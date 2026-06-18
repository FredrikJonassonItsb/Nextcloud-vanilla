<template>
	<div class="metadata-attachments-itsl">
		<!-- Sender IDs -->
		<div class="metadata-section">
			<div class="metadata-section-label">
				{{ t('mail', 'SenderIDs') }}
			</div>
			<div class="chip-container">
				<span v-if="senderPersonIDs.length === 0 && senderReferenceIDs.length === 0" class="no-ids-label"> {{ t('mail', 'Empty') }}</span>
				<MetadataIDChipItsl v-for="(id, index) in senderPersonIDs"
					:key="'sender-person-' + index"
					type="person"
					:label="id.personId.extension"
					:info="id.personId.extension"
					:popover-row1-value="id.personId.root"
					:popover-row2-value="id.personId.extension"
					:popover-row3-value="id.label"
					:deletable="deletable"
					@delete="() => emitRemove('senderPersonIDs', index)" />

				<MetadataIDChipItsl v-for="(id, index) in senderReferenceIDs"
					:key="'sender-reference-' + index"
					type="reference"
					:label="id.referenceId.extension"
					:info="id.referenceId.extension"
					:popover-row1-value="id.referenceId.root"
					:popover-row2-value="id.referenceId.extension"
					:popover-row3-value="id.label"
					:deletable="deletable"
					@delete="() => emitRemove('senderReferenceIDs', index)" />
			</div>
		</div>

		<!-- Recipient IDs -->
		<div class="metadata-section">
			<div class="metadata-section-label">
				{{ t('mail', 'RecipientIDs') }}
			</div>
			<div class="chip-container">
				<span v-if="recipientPersonIDs.length === 0 && recipientReferenceIDs.length === 0" class="no-ids-label"> {{ t('mail', 'Empty') }}</span>
				<MetadataIDChipItsl v-for="(id, index) in recipientPersonIDs"
					:key="'recipient-person-' + index"
					type="person"
					:label="id.personId.extension"
					:info="id.personId.extension"
					:popover-row1-value="id.personId.root"
					:popover-row2-value="id.personId.extension"
					:popover-row3-value="id.label"
					:deletable="deletable"
					@delete="() => emitRemove('recipientPersonIDs', index)" />

				<MetadataIDChipItsl v-for="(id, index) in recipientReferenceIDs"
					:key="'recipient-reference-' + index"
					type="reference"
					:label="id.referenceId.extension"
					:info="id.referenceId.extension"
					:popover-row1-value="id.referenceId.root"
					:popover-row2-value="id.referenceId.extension"
					:popover-row3-value="id.label"
					:deletable="deletable"
					@delete="() => emitRemove('recipientReferenceIDs', index)" />
			</div>
		</div>
	</div>
</template>

<script>
import MetadataIDChipItsl from './MetadataIDChipItsl.vue'

export default {
	name: 'MetadataAttachmentsItsl',
	components: {
		MetadataIDChipItsl,
	},
	props: {
		senderPersonIDs: {
			type: Array,
			required: true,
			default: () => [],
		},
		senderReferenceIDs: {
			type: Array,
			required: true,
			default: () => [],
		},
		recipientPersonIDs: {
			type: Array,
			required: true,
			default: () => [],
		},
		recipientReferenceIDs: {
			type: Array,
			required: true,
			default: () => [],
		},
		deletable: {
			type: Boolean,
			required: false,
			default: false,
		},
	},
	setup(props, { emit }) {
		const emitRemove = (group, index) => {
			emit('remove-chip', { group, index })
		}

		return {
			emitRemove,
		}
	},
}
</script>

<style scoped>
.metadata-attachments-itsl {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
	font-size: 14px;
	line-height: 1.2;
	padding: 0 10px;
	margin: 10px;
}

.metadata-section {
	display: flex;
	flex-direction: row;
	align-items: center;
	flex-wrap: wrap;
}

.metadata-section-label {
	margin-inline-end: 0.5rem;
	white-space: nowrap;
}

.chip-container {
	display: flex;
	flex-wrap: wrap;
	gap: 0.25rem;
}
.no-ids-label {
	color: #6c757d
}
</style>
