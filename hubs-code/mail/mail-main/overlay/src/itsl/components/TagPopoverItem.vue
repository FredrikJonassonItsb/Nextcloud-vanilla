<!--
  - SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="tag-popover-item" @click="onRowClick">
		<!-- View mode -->
		<template v-if="!editing">
			<!-- Checkbox (leftmost) - wrapped to stop propagation -->
			<span class="tag-popover-item__checkbox-wrapper" @click.stop>
				<NcCheckboxRadioSwitch :checked="effectiveState"
					class="tag-popover-item__checkbox"
					@update:checked="onCheckboxChange" />
			</span>

			<!-- Avatar badge with initials for assignment tags -->
			<div v-if="isAssignmentTag"
				class="tag-popover-item__avatar"
				:style="{ backgroundColor: tag.color || '#6b6b6b' }">
				{{ getInitials(tag.displayName) }}
			</div>
			<!-- Color dot for regular tags -->
			<div v-else class="tag-popover-item__color">
				<div class="color-dot" :style="{ backgroundColor: tag.color }" />
			</div>

			<!-- Tag name (with search highlighting) -->
			<span class="tag-popover-item__name"
				:title="translateTagDisplayName(tag)"
				v-html="highlightedName" /> <!-- eslint-disable-line vue/no-v-html -- Safe: HTML-escaped -->

			<!-- Pen icon (rightmost) - only shown if user can manage tags -->
			<NcButton v-if="canManageTags"
				variant="tertiary"
				class="tag-popover-item__edit"
				:aria-label="t('mail', 'Edit tag')"
				@click.stop="startEdit">
				<template #icon>
					<Pencil :size="20" />
				</template>
			</NcButton>
		</template>

		<!-- Edit mode -->
		<template v-else>
			<!-- Delete button (leftmost - destructive action) - only shown if user can manage tags AND not assignment tag -->
			<NcButton v-if="canManageTags && !isAssignmentTag"
				type="error"
				class="tag-popover-item__btn"
				:aria-label="t('mail', 'Delete tag')"
				@click="confirmDelete">
				<template #icon>
					<Delete :size="20" />
				</template>
			</NcButton>

			<!-- Color picker with avatar badge for assignment tags -->
			<NcColorPicker v-if="isAssignmentTag"
				:key="editColorPickerKey"
				:value="editColor"
				container="body"
				class="tag-popover-item__avatar-picker"
				@input="editColor = $event"
				@submit="onEditColorSubmit">
				<div class="tag-popover-item__avatar"
					:style="{ backgroundColor: editColor || '#6b6b6b' }">
					{{ getInitials(editName || tag.displayName) }}
				</div>
			</NcColorPicker>
			<!-- Color picker with color dot for regular tags -->
			<NcColorPicker v-else
				:key="editColorPickerKey"
				:value="editColor"
				container="body"
				class="tag-popover-item__color tag-popover-item__color-picker"
				@input="editColor = $event"
				@submit="onEditColorSubmit">
				<div class="color-dot" :style="{ backgroundColor: editColor }" />
			</NcColorPicker>

			<!-- Name input -->
			<input ref="editInput"
				v-model="editName"
				class="tag-popover-item__input"
				type="text"
				:placeholder="t('mail', 'Tag name')"
				@keyup.enter="saveEdit"
				@keyup.escape="cancelEdit">

			<!-- Cancel button -->
			<NcButton variant="tertiary"
				class="tag-popover-item__btn"
				:aria-label="t('mail', 'Cancel')"
				@click="cancelEdit">
				<template #icon>
					<Close :size="20" />
				</template>
			</NcButton>

			<!-- Save button (rightmost - primary action) -->
			<NcButton variant="primary"
				class="tag-popover-item__btn"
				:aria-label="t('mail', 'Save')"
				@click="saveEdit">
				<template #icon>
					<Check :size="20" />
				</template>
			</NcButton>
		</template>
	</div>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcColorPicker } from '@nextcloud/vue'
import { showInfo, showError } from '@nextcloud/dialogs'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Check from 'vue-material-design-icons/Check.vue'
import Close from 'vue-material-design-icons/Close.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import { translateTagDisplayName } from '../../util/tag.js'
import { mapStores } from 'pinia'
import useMainStore from '../../store/mainStore.js'
import useItslStore from '../store/itslStore.js'

export default {
	name: 'TagPopoverItem',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcColorPicker,
		Pencil,
		Check,
		Close,
		Delete,
	},
	props: {
		tag: {
			type: Object,
			required: true,
		},
		envelopes: {
			type: Array,
			required: true,
		},
		pendingState: {
			type: Boolean,
			default: undefined,
		},
		canManageTags: {
			type: Boolean,
			default: false,
		},
		isAssignmentTag: {
			type: Boolean,
			default: false,
		},
		searchQuery: {
			type: String,
			default: '',
		},
	},
	emits: ['delete', 'toggle'],
	data() {
		return {
			editing: false,
			editName: '',
			editColor: '',
			editColorPickerKey: 0,
		}
	},
	computed: {
		...mapStores(useMainStore, useItslStore),
		isApplied() {
			// Access pending state directly for Vue reactivity tracking
			const pendingRemovals = this.itslStore.pendingTagRemovals
			const pendingAdditions = this.itslStore.pendingTagAdditions

			return this.envelopes.some((envelope) => {
				const envPendingAdditions = pendingAdditions[envelope.databaseId] || {}
				const envPendingRemovals = pendingRemovals[envelope.databaseId] || {}

				// Check pending states first (optimistic UI)
				if (this.tag.imapLabel in envPendingAdditions) {
					return true
				}
				if (this.tag.imapLabel in envPendingRemovals) {
					return false
				}

				// Fall back to actual state from store
				return this.mainStore.getEnvelopeTags(envelope.databaseId).some(
					tag => tag.imapLabel === this.tag.imapLabel,
				)
			})
		},
		effectiveState() {
			// If there's a pending state, use it; otherwise use actual state
			if (this.pendingState !== undefined) {
				return this.pendingState
			}
			return this.isApplied
		},
		highlightedName() {
			const name = translateTagDisplayName(this.tag)
			// Escape HTML entities to prevent XSS
			const escapedName = name
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;')
			if (!this.searchQuery.trim()) return escapedName
			const escaped = this.searchQuery.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
			const regex = new RegExp(`(${escaped})`, 'gi')
			return escapedName.replace(regex, '<strong>$1</strong>')
		},
	},
	methods: {
		translateTagDisplayName,
		getInitials(name) {
			if (!name) return '?'
			const parts = name.trim().split(/\s+/)
			if (parts.length === 1) {
				return parts[0].substring(0, 2).toUpperCase()
			}
			return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
		},
		startEdit() {
			this.editName = this.tag.displayName
			this.editColor = this.tag.color
			this.editing = true
		},
		cancelEdit() {
			this.editing = false
		},
		async saveEdit() {
			if (!this.editName.trim()) {
				showError(this.t('mail', 'Tag name cannot be empty'))
				return
			}

			try {
				await this.itslStore.updateTag(this.mainStore, {
					tag: this.tag,
					displayName: this.editName.trim(),
					color: this.editColor,
					accountId: this.envelopes[0].accountId,
				})
				this.editing = false
			} catch (error) {
				showInfo(this.t('mail', 'An error occurred, unable to rename the tag.'))
				console.error(error)
			}
		},
		onEditColorSubmit(newColor) {
			this.editColor = newColor
			this.editColorPickerKey++ // Force close by remounting
		},
		onCheckboxChange() {
			// Emit toggle event - parent manages pending state
			this.$emit('toggle', this.tag)
		},
		onRowClick() {
			// Only toggle in view mode (not editing)
			if (!this.editing) {
				this.$emit('toggle', this.tag)
			}
		},
		confirmDelete() {
			this.$emit('delete', this.tag)
		},
	},
}
</script>

<style lang="scss" scoped>
.tag-popover-item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px 8px;
	min-height: 36px;
	position: relative;
	cursor: pointer;

	&:hover {
		background-color: var(--color-background-hover);
	}
}

.tag-popover-item__checkbox {
	flex-shrink: 0;

	:deep(.checkbox-radio-switch__content),
	:deep(.checkbox-radio-switch__input) {
		background-color: transparent !important;
	}
}

.tag-popover-item__avatar {
	width: 22px;
	height: 22px;
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
	color: white;
	font-size: 10px;
	font-weight: 600;
	flex-shrink: 0;
}

// Avatar in color picker needs pointer cursor
.tag-popover-item__avatar-picker .tag-popover-item__avatar {
	cursor: pointer;
}

.tag-popover-item__color {
	flex-shrink: 0;

	.color-dot {
		width: 16px;
		height: 16px;
		border-radius: 50%;
		border: 1px solid var(--color-border-dark);
	}
}

// Color picker trigger in edit mode needs pointer cursor
.tag-popover-item__color-picker .color-dot {
	cursor: pointer;
}

.tag-popover-item__name {
	flex: 1;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	cursor: pointer;
}

.tag-popover-item__edit {
	position: absolute;
	right: 8px;
	opacity: 0;
	transition: opacity 0.1s ease;
	background: inherit;

	.tag-popover-item:hover & {
		opacity: 1;
	}

	&:deep(.button-vue) {
		background-color: inherit !important;
	}
}

.tag-popover-item__input {
	flex: 1;
	min-width: 80px;
	padding: 4px 8px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	background: var(--color-main-background);

	&:focus {
		border-color: var(--color-primary-element);
		outline: none;
	}
}

.tag-popover-item__btn {
	flex-shrink: 0;
	// Make buttons smaller in edit mode
	min-width: 32px !important;
	min-height: 32px !important;
	padding: 0 !important;

	&:deep(.button-vue--vue-tertiary) {
		background-color: var(--color-background-darker, #dbdbdb) !important;
	}
}
</style>
