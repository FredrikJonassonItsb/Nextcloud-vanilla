<template>
	<div v-if="show" class="custom-modal-backdrop" @click.self="handleClose">
		<div class="custom-modal"
			role="dialog"
			aria-modal="true"
			:aria-label="t('sdkmc', 'Add group to mailbox')">
			<div class="custom-modal__header">
				<h2 class="custom-modal__title">
					{{ t('sdkmc', 'Add group to mailbox') }}
				</h2>
				<button class="custom-modal__close" :aria-label="t('sdkmc', 'Close')" @click="handleClose">
					<span aria-hidden="true">&times;</span>
				</button>
			</div>
			<div class="custom-modal__content">
				<div v-if="availableGroups.length">
					<p class="modal-container-heading">
						{{ t('sdkmc', 'Select the group to add') }}
					</p>
					<label for="add-group-select" class="hidden-visually">
						{{ t('sdkmc', 'Select group') }}
					</label>
					<NcSelect
						v-model="selectedGroupId"
						:options="groupOptions"
						:placeholder="t('sdkmc', 'Select group')"
						:append-to-body="false"
						:reduce="option => option.value"
						class="modal-selector"
						input-id="add-group-select"
						:label-outside="true" />
					<div class="modal-footer">
						<NcButton @click="handleClose">
							{{ t('sdkmc', 'Cancel') }}
						</NcButton>
						<NcButton
							:disabled="!selectedGroupId"
							@click="handleAddGroup">
							{{ t('sdkmc', 'Add group') }}
						</NcButton>
					</div>
				</div>

				<div v-else>
					<p class="modal-container-heading">
						{{ t('sdkmc', 'All groups are already added.') }}
					</p>
					<div class="modal-footer">
						<NcButton @click="handleClose">
							{{ t('sdkmc', 'OK') }}
						</NcButton>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import {
	NcButton,
	NcSelect,
} from '@nextcloud/vue'

export default {
	name: 'AddGroupToMailboxModal',
	components: {
		NcButton,
		NcSelect,
	},
	props: {
		show: {
			type: Boolean,
			default: false,
		},
		account: {
			type: Object,
			default: () => ({}),
		},
		groups: {
			type: Array,
			default: () => [],
		},
	},
	emits: [
		'close',
		'add-group',
	],
	setup(props, { emit }) {
		const selectedGroupId = ref('')

		const availableGroups = computed(() => {
			const assigned = props.account.groups || []
			return props.groups.filter(g => !assigned.includes(g.groupId))
		})

		const groupOptions = computed(() => {
			return availableGroups.value.map(group => ({
				label: group.label || group.groupId,
				value: group.groupId,
			}))
		})

		function handleAddGroup() {
			if (!selectedGroupId.value) return
			emit('add-group', props.account, selectedGroupId.value)
			emit('close')
		}

		function handleClose() {
			emit('close')
		}

		function handleEscapeKey(event) {
			if (event.key === 'Escape' && props.show) {
				handleClose()
			}
		}

		// Handle escape key
		onMounted(() => {
			document.addEventListener('keydown', handleEscapeKey)
		})

		onUnmounted(() => {
			document.removeEventListener('keydown', handleEscapeKey)
		})

		// Reset selected group when modal is closed or account changes
		watch(() => props.show, (newShow) => {
			if (!newShow) {
				selectedGroupId.value = ''
			}
		})

		watch(() => props.account, () => {
			selectedGroupId.value = ''
		})

		return {
			selectedGroupId,
			availableGroups,
			groupOptions,
			handleAddGroup,
			handleClose,
		}
	},
}
</script>

<style scoped>
.custom-modal-backdrop {
	position: fixed;
	top: 0;
	left: 0;
	width: 100%;
	height: 100%;
	background-color: rgba(0, 0, 0, 0.5);
	display: flex;
	justify-content: center;
	align-items: center;
	z-index: 10000;
}

.custom-modal {
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	box-shadow: 0 0 40px rgba(0, 0, 0, 0.2);
	min-width: 400px;
	max-width: 90vw;
	max-height: 90vh;
	overflow: visible;
	position: relative;
}

.custom-modal__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 16px 20px;
}

.custom-modal__title {
	margin: 0;
	font-size: 18px;
	font-weight: 600;
	color: var(--color-main-text);
}

.custom-modal__close {
	background: none;
	border: none;
	font-size: 24px;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	padding: 0;
	width: 32px;
	height: 32px;
	display: flex;
	align-items: center;
	justify-content: center;
	border-radius: var(--border-radius);
}

.custom-modal__close:hover {
	background-color: var(--color-background-hover);
	color: var(--color-main-text);
}

.custom-modal__content {
	padding: 20px;
}

.modal-container-heading {
	font-size: 16px;
	font-weight: 600;
	margin: 0 0 20px 0;
	color: var(--color-main-text);
}

.modal-footer {
	display: flex;
	margin: 30px 0 0;
	justify-content: space-between;
	gap: 12px;
}

.modal-selector {
	margin-top: 12px;
}
</style>
