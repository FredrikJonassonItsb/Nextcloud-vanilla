<template>
	<div v-if="show" class="custom-modal-backdrop" @click.self="handleClose">
		<div class="custom-modal"
			role="dialog"
			aria-modal="true"
			:aria-label="t('sdkmc', 'Add user to mailbox')">
			<div class="custom-modal__header">
				<h2 class="custom-modal__title">
					{{ t('sdkmc', 'Add user to mailbox') }}
				</h2>
				<button class="custom-modal__close" :aria-label="t('sdkmc', 'Close')" @click="handleClose">
					<span aria-hidden="true">&times;</span>
				</button>
			</div>
			<div class="custom-modal__content">
				<div v-if="availableUsers.length">
					<p class="modal-container-heading">
						{{ t('sdkmc', 'Select the user to add') }}
					</p>
					<label for="add-user-select" class="hidden-visually">
						{{ t('sdkmc', 'Select user') }}
					</label>
					<NcSelect
						v-model="selectedUserId"
						:options="userOptions"
						:placeholder="t('sdkmc', 'Select user')"
						:append-to-body="false"
						:reduce="option => option.value"
						class="modal-selector"
						input-id="add-user-select"
						:label-outside="true" />
					<div class="modal-footer">
						<NcButton @click="handleClose">
							{{ t('sdkmc', 'Cancel') }}
						</NcButton>
						<NcButton
							:disabled="!selectedUserId"
							@click="handleAddUser">
							{{ t('sdkmc', 'Add user') }}
						</NcButton>
					</div>
				</div>

				<div v-else>
					<p class="modal-container-heading">
						{{ t('sdkmc', 'All users are already added.') }}
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
	name: 'AddUserToMailboxModal',
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
		users: {
			type: Object,
			default: () => ({}),
		},
	},
	emits: [
		'close',
		'add-user',
	],
	setup(props, { emit }) {
		const selectedUserId = ref('')

		const availableUsers = computed(() => {
			if (!props.account || !props.account.users) {
				return Object.values(props.users)
			}
			const assigned = props.account.users || []
			return Object.values(props.users).filter(
				user => !assigned.includes(user.userId),
			)
		})

		const userOptions = computed(() => {
			return availableUsers.value.map(user => ({
				label: user.displayName || user.userId,
				value: user.userId,
			}))
		})

		function handleAddUser() {
			if (!selectedUserId.value) return
			emit('add-user', props.account, selectedUserId.value)
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

		// Reset selected user when modal is closed or account changes
		watch(() => props.show, (newShow) => {
			if (!newShow) {
				selectedUserId.value = ''
			}
		})

		watch(() => props.account, () => {
			selectedUserId.value = ''
		})

		return {
			selectedUserId,
			availableUsers,
			userOptions,
			handleAddUser,
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
