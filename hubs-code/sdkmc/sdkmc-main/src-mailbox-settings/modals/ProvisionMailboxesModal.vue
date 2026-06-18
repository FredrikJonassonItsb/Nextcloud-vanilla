<template>
	<div v-if="show" class="custom-modal-backdrop" @click.self="handleClose">
		<div class="custom-modal provision-modal"
			role="dialog"
			aria-modal="true"
			:aria-label="t('sdkmc', 'Confirm provisioning')">
			<div class="custom-modal__header">
				<h2 class="custom-modal__title">
					{{ t('sdkmc', 'Confirm provisioning') }}
				</h2>
				<button class="custom-modal__close" :aria-label="t('sdkmc', 'Close')" @click="handleClose">
					<span aria-hidden="true">&times;</span>
				</button>
			</div>
			<div class="custom-modal__content">
				<p class="modal-container-heading">
					{{ t('sdkmc', 'This will create a personlig mailbox for every user that doesn’t have one. This task may take up to 30 minutes.') }}
				</p>
				<p class="modal-container-heading">
					{{ t('sdkmc', 'Select a group to provision only users in that group.') }}
				</p>
				<label for="provision-group-select" class="hidden-visually">
					{{ t('sdkmc', 'Select group') }}
				</label>
				<NcSelect
					v-model="selectedGroupId"
					:options="groupOptions"
					:placeholder="t('sdkmc', 'Select group')"
					:append-to-body="false"
					:reduce="option => option.value"
					class="modal-selector"
					input-id="provision-group-select"
					:label-outside="true" />
				<div class="modal-footer">
					<NcButton @click="handleClose">
						{{ t('sdkmc', 'Cancel') }}
					</NcButton>
					<NcButton
						@click="confirmProvision">
						{{ t('sdkmc', 'Provision mailboxes') }}
					</NcButton>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { NcButton, NcSelect } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'ProvisionMailboxesModal',
	components: {
		NcButton,
		NcSelect,
	},
	props: {
		show: {
			type: Boolean,
			default: false,
		},
		groups: {
			type: Array,
			default: () => [],
		},
	},
	emits: [
		'close',
		'provision-mailboxes',
	],
	setup(props, { emit }) {
		const selectedGroupId = ref('')

		const groupOptions = computed(() => [
			{
				label: t('sdkmc', 'All groups'),
				value: '',
			},
			...props.groups.map(group => ({
				label: group.label || group.groupId,
				value: group.groupId,
			})),
		])

		function confirmProvision() {
			if (!props.show) return
			emit('provision-mailboxes', selectedGroupId.value || null)
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

		watch(() => props.show, (newShow) => {
			if (!newShow) selectedGroupId.value = ''
		})

		return {
			t,
			selectedGroupId,
			groupOptions,
			confirmProvision,
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

.custom-modal.provision-modal {
	max-width: 600px;
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
	padding: 16px 30px;
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
	padding: 10px 30px 20px;
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
