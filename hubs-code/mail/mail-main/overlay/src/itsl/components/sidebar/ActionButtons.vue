<!--
  - SPDX-FileCopyrightText: 2026 ITSL AB <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="action-buttons" role="group" :aria-label="t('mail', 'Actions')">
		<NcButton variant="secondary"
			class="action-buttons__btn"
			:disabled="disableProcessed"
			@click="$emit('processed')">
			<template #icon>
				<PackageDown :size="20" />
			</template>
			{{ t('mail', 'Mark as processed') }}
		</NcButton>

		<NcButton variant="secondary"
			class="action-buttons__btn action-buttons__btn--delete"
			@click="confirmDelete">
			<template #icon>
				<Delete :size="20" />
			</template>
			{{ t('mail', 'Delete thread') }}
		</NcButton>

		<!-- Delete confirmation dialog -->
		<NcDialog v-if="showDeleteConfirm"
			:name="t('mail', 'Delete thread?')"
			@close="closeDialog">
			<p>{{ t('mail', 'Are you sure you want to delete this entire thread? This action cannot be undone.') }}</p>
			<template #actions>
				<NcButton variant="tertiary" @click="showDeleteConfirm = false">
					{{ t('mail', 'Cancel') }}
				</NcButton>
				<NcButton type="error" @click="doDelete">
					{{ t('mail', 'Delete') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'
import PackageDown from 'vue-material-design-icons/PackageDown.vue'
import Delete from 'vue-material-design-icons/Delete.vue'

export default {
	name: 'ActionButtons',
	components: {
		NcButton,
		NcDialog,
		PackageDown,
		Delete,
	},
	props: {
		envelope: {
			type: Object,
			required: true,
		},
		thread: {
			type: Array,
			required: true,
		},
		disableProcessed: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['processed', 'delete'],
	data() {
		return {
			showDeleteConfirm: false,
			isClosing: false,
		}
	},
	methods: {
		confirmDelete() {
			// Guard against click-through when dialog is closing
			if (this.isClosing) return
			this.showDeleteConfirm = true
		},
		closeDialog() {
			this.isClosing = true
			this.showDeleteConfirm = false
			// Reset guard after click event finishes processing
			setTimeout(() => {
				this.isClosing = false
			}, 100)
		},
		doDelete() {
			this.showDeleteConfirm = false
			this.$emit('delete')
		},
	},
}
</script>

<style lang="scss" scoped>
.action-buttons {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px;
}

.action-buttons__btn {
	width: 100%;
	justify-content: flex-start;

	:deep(.button-vue__wrapper) {
		justify-content: flex-start;
	}

	:deep(.button-vue__text) {
		font-weight: 400;
		font-size: 13px;
		white-space: normal;
		text-align: start;
	}

	// Delete button: red-tinted secondary button
	&--delete {
		background-color: color-mix(in srgb, var(--color-error) 8%, transparent) !important;

		&:hover {
			background-color: color-mix(in srgb, var(--color-error) 15%, transparent) !important;
		}

		:deep(.button-vue__text),
		:deep(.button-vue__icon) {
			color: var(--color-error);
		}
	}
}
</style>
