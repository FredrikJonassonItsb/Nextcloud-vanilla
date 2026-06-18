<template>
	<NcChip :no-close="!deletable" aria-label-close="Remove" @close="onDelete">
		<template #icon>
			<Account v-if="type==='person'" :size="20" />
			<Attachment v-else :size="20" />
		</template>
		<template #default>
			<div class="chip-content">
				{{ label }}
				<NcPopover v-if="hasPopoverContent">
					<template #trigger>
						<NcButton aria-label="Show information"
							class="icon-button"
							unstyled>
							<template #icon>
								<Information :size="20" />
							</template>
						</NcButton>
					</template>
					<template #default>
						<div class="popover-content" tabindex="0">
							<div v-if="popoverRow1Value">
								<strong>{{ row1Title }}:</strong> {{ popoverRow2Value }}
							</div>
							<div v-if="popoverRow2Value">
								{{ row2Title }}: {{ popoverRow1Value }}
							</div>
							<div v-if="popoverRow3Value">
								{{ row3Title }}: {{ popoverRow3Value }}
							</div>
						</div>
					</template>
				</NcPopover>
			</div>
		</template>
	</NcChip>
</template>
<script>
import { computed } from 'vue'
import { NcPopover, NcButton } from '@nextcloud/vue'
import NcChip from '@nextcloud/vue/components/NcChip'
import Information from 'vue-material-design-icons/Information.vue'
import Account from 'vue-material-design-icons/Account.vue'
import Attachment from 'vue-material-design-icons/Attachment.vue'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'MetadataIDChipItsl',
	components: {
		NcChip,
		NcPopover,
		NcButton,
		Information,
		Account,
		Attachment,
	},
	props: {
		label: {
			type: String,
			required: false,
			default: '',
		},
		info: {
			type: String,
			required: false,
			default: '',
		},
		deletable: {
			type: Boolean,
			required: false,
			default: false,
		},
		type: {
			type: String,
			validator: val => ['person', 'reference'].includes(val),
			required: true,
		},
		popoverRow1Value: {
			type: String,
			required: false,
			default: '',
		},
		popoverRow2Value: {
			type: String,
			required: false,
			default: '',
		},
		popoverRow3Value: {
			type: String,
			required: false,
			default: '',
		},
	},
	setup(props, { emit }) {
		const onDelete = () => emit('delete')

		const row1Title = computed(() =>
			props.type === 'person' ? t('mail', 'PersonID') : t('mail', 'ReferenceID'),
		)
		const row2Title = t('mail', 'Code')
		const row3Title = t('mail', 'Description')

		const hasPopoverContent = computed(() =>
			!!props.info || !!props.popoverRow1Value || !!props.popoverRow2Value || !!props.popoverRow3Value,
		)
		return {
			onDelete,
			row1Title,
			row2Title,
			row3Title,
			hasPopoverContent,
		}
	},
}
</script>

<style scoped>
.icon-wrapper {
	display: inline-flex;
	align-items: center;
	margin-inline-start: 8px;
	cursor: pointer;
}

.delete-icon-wrapper {
	display: inline-flex;
	align-items: center;
	margin-inline-start: 8px;
	cursor: pointer;
	opacity: 0.6;
	transition: opacity 0.2s ease;
}
.delete-icon-wrapper:hover {
	opacity: 1;
}
.icon-button {
	all: unset;
	display: inline-flex;
	align-items: center;
	cursor: pointer;
}
.icon-button:hover,
.icon-button:focus,
.icon-button:focus-visible {
	background-color: transparent !important;
	box-shadow: none !important;
}
.chip-content {
	display: inline-flex;
	align-items: center;
	gap: 4px;
}
.popover-content {
	padding: 10px
}
</style>
