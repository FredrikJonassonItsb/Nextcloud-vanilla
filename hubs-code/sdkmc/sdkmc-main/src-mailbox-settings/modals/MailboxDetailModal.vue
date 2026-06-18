<template>
	<div class="modal-overlay" @click.self="close">
		<div class="modal-content"
			role="dialog"
			aria-modal="true"
			aria-labelledby="modalTitle">
			<CloseIcon class="modal-button-close" :aria-label="t('sdkmc', 'Close')" @click="close" />
			<h3 id="modalTitle" class="modal-title">
				{{ isEditMode ? t('sdkmc', 'Edit mailbox') : t('sdkmc', 'Add new mailbox') }}
			</h3>
			<form @submit.prevent="submitForm">
				<input type="hidden" :value="form.messageType" name="messageType">

				<div v-if="form.messageType === 'sdk'">
					<label>
						{{ t('sdkmc', 'SDK Address') }}:
						<input
							v-model="form.sdkaddress"
							type="text"
							required
							:disabled="isEditMode"
							:class="{ 'input-error': sdkAddressError }"
							@input="validateSdkAddress">
						<div v-if="sdkAddressError" class="field-error">
							{{ sdkAddressError }}
						</div>
					</label>
				</div>

				<label>
					{{ t('sdkmc', 'Alias') }}:
					<input
						v-model="form.alias"
						type="text"
						required
						:disabled="isEditMode"
						:class="{ 'input-error': aliasError }"
						@input="validateAlias">
					<div v-if="aliasError" class="field-error">
						{{ aliasError }}
					</div>
				</label>

				<div v-if="['personlig', 'gruppbox', 'sms', 'fax'].includes(form.messageType)">
					<label>
						{{ t('sdkmc', 'Name') }}:
						<input v-model="form.name" type="text">
					</label>
					<label>
						{{ t('sdkmc', 'Description') }}:
						<input v-model="form.description" type="text">
					</label>
				</div>

				<div v-if="['personlig', 'gruppbox'].includes(form.messageType)">
					<div class="switch-field">
						<NcCheckboxRadioSwitch
							v-model="form.canBeRepliedTo"
							type="switch">
							{{ t('sdkmc', 'Allow replying to this mailbox') }}
						</NcCheckboxRadioSwitch>
					</div>

					<div class="switch-field">
						<NcCheckboxRadioSwitch
							v-model="form.canMessageBeSentTo"
							type="switch">
							{{ t('sdkmc', 'Allow sending messages to this mailbox') }}
						</NcCheckboxRadioSwitch>
					</div>
				</div>

				<div v-if="['sms', 'fax'].includes(form.messageType)">
					<label>
						{{ t('sdkmc', 'Phone Number') }}:
						<input
							v-model="form.number"
							type="text"
							:disabled="isEditMode">
					</label>
				</div>

				<div v-if="['sdk', 'gruppbox', 'fax'].includes(form.messageType)">
					<label>
						{{ t('sdkmc', 'Notification Email') }}:
						<input
							v-model="form.notificationEmail"
							type="email"
							placeholder="email@example.com">
					</label>
				</div>

				<details ref="retentionSectionRef" class="retention-section" @toggle="onRetentionToggle">
					<summary class="retention-summary">
						<ChevronDownIcon class="retention-chevron" :size="20" />
						{{ t('sdkmc', 'Retention Overrides') }}
						<span v-if="hasRetentionOverrides" class="retention-badge">{{ overrideCount }}</span>
					</summary>
					<div class="retention-content">
						<p class="retention-legend">
							{{ t('sdkmc', 'Empty = use global setting, 0 = keep forever, number = days') }}
						</p>
						<table class="retention-table">
							<thead>
								<tr>
									<th>{{ t('sdkmc', 'Folder') }}</th>
									<th>{{ t('sdkmc', 'Days') }}</th>
									<th>{{ t('sdkmc', 'Global') }}</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="folder in retentionFolders" :key="folder.key">
									<td>{{ folder.label }}</td>
									<td class="retention-input-cell">
										<input
											v-model="form.retention[folder.key]"
											type="number"
											min="0"
											class="retention-input"
											:placeholder="t('sdkmc', 'inherit')">
										<button
											v-if="form.retention[folder.key] !== ''"
											type="button"
											class="retention-clear-btn"
											:title="t('sdkmc', 'Reset to global')"
											@click="clearRetentionField(folder.key)">
											&times;
										</button>
									</td>
									<td class="retention-global">
										{{ folder.globalDisplay }}
									</td>
								</tr>
							</tbody>
						</table>
					</div>
				</details>

				<div v-if="error" class="error">
					{{ error }}
				</div>

				<NcButton
					type="submit"
					:disabled="loading || isSubmitDisabled"
					class="modal-button-submit">
					{{ loading ?
						(isEditMode ? t('sdkmc', 'Updating...') : t('sdkmc', 'Adding...')) :
						(isEditMode ? t('sdkmc', 'Update mailbox') : t('sdkmc', 'Add mailbox'))
					}}
				</NcButton>
			</form>
		</div>
	</div>
</template>

<script setup>
import { reactive, ref, onMounted, computed, watch, nextTick } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import { NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { getRequestToken } from '@nextcloud/auth'

const props = defineProps({
	mailboxType: { type: String, required: true },
	existingAccounts: { type: Array, default: () => [] },
	editAccount: { type: Object, default: null }, // account to edit, null for add mode
})

const emit = defineEmits(['added', 'updated', 'close'])

const isEditMode = computed(() => !!props.editAccount)
const dataModified = ref(false)

// Single source of truth for folder definitions - matches ExpungeService::FOLDER_MAP
const RETENTION_FOLDERS = [
	{ key: '*', label: t('sdkmc', 'Default (all folders)'), globalKey: 'mailRetentionDefault' },
	{ key: 'INBOX', label: t('sdkmc', 'INBOX'), globalKey: 'mailRetentionInbox' },
	{ key: 'Sent', label: t('sdkmc', 'Sent'), globalKey: 'mailRetentionSent' },
	{ key: 'Archive', label: t('sdkmc', 'Archive'), globalKey: 'mailRetentionArchive' },
	{ key: 'Trash', label: t('sdkmc', 'Trash'), globalKey: 'mailRetentionTrash' },
	{ key: 'Drafts', label: t('sdkmc', 'Drafts'), globalKey: 'mailRetentionDraft' },
]

const initRetention = () => Object.fromEntries(RETENTION_FOLDERS.map(f => [f.key, '']))

const form = reactive({
	messageType: props.mailboxType,
	sdkaddress: '',
	alias: '',
	name: '',
	description: '',
	number: '',
	canBeRepliedTo: true,
	canMessageBeSentTo: true,
	notificationEmail: '',
	retention: initRetention(),
})

const loading = ref(false)
const error = ref('')
const aliasError = ref('')
const sdkAddressError = ref('')
const originalFormData = ref({})
const globalRetention = ref({})
const retentionSectionRef = ref(null)

// Computed properties for retention
const retentionFolders = computed(() =>
	RETENTION_FOLDERS.map(f => ({
		...f,
		globalValue: globalRetention.value[f.globalKey] ?? 0,
		globalDisplay: formatGlobal(globalRetention.value[f.globalKey] ?? 0),
	})),
)

function formatGlobal(days) {
	return days === 0 ? t('sdkmc', 'forever') : `${days}d`
}

function onRetentionToggle(event) {
	if (event.target.open) {
		nextTick(() => {
			retentionSectionRef.value?.scrollIntoView({ behavior: 'smooth', block: 'end' })
		})
	}
}

function clearRetentionField(folderKey) {
	form.retention[folderKey] = ''
}

const hasRetentionOverrides = computed(() =>
	Object.values(form.retention).some(v => v !== ''),
)

const overrideCount = computed(() =>
	Object.values(form.retention).filter(v => v !== '').length,
)

const existingAliases = computed(() => {
	return props.existingAccounts
		.filter(account => {
			if (isEditMode.value && props.editAccount) {
				return account.alias !== props.editAccount.alias
			}
			return true
		})
		.map(account => account.alias?.toLowerCase())
		.filter(Boolean)
})

const existingSdkAddresses = computed(() => {
	return props.existingAccounts
		.filter(account => {
			if (isEditMode.value && props.editAccount) {
				return account.sdkaddress !== props.editAccount.sdkaddress
			}
			return true
		})
		.map(account => account.sdkaddress?.toLowerCase())
		.filter(Boolean)
})

const isSubmitDisabled = computed(() => {
	if (isEditMode.value) {
		return !!aliasError.value || !!sdkAddressError.value || !dataModified.value
	} else {
		return !!aliasError.value || !!sdkAddressError.value || !form.alias.trim()
	}
})

// Helper to load retention overrides from editAccount
function loadRetention(overrides) {
	const result = initRetention()
	if (overrides) {
		for (const folder of Object.keys(result)) {
			if (overrides[folder] !== undefined) {
				result[folder] = String(overrides[folder])
			}
		}
	}
	return result
}

watch(() => props.editAccount, (editAccount) => {
	if (editAccount) {
		const retention = loadRetention(editAccount.retentionOverrides)
		const formData = {
			sdkaddress: editAccount.sdkaddress || '',
			alias: editAccount.alias || '',
			name: editAccount.name || '',
			description: editAccount.description || '',
			number: editAccount.number || '',
			canBeRepliedTo: !!editAccount.canBeRepliedTo,
			canMessageBeSentTo: !!editAccount.canMessageBeSentTo,
			notificationEmail: editAccount.notificationEmail || '',
			retention,
		}
		Object.assign(form, formData)
		originalFormData.value = { ...formData, retention: { ...retention } }

		dataModified.value = false
	} else {
		Object.assign(form, {
			sdkaddress: '',
			alias: '',
			name: '',
			description: '',
			number: '',
			notificationEmail: '',
			retention: initRetention(),
		})
		originalFormData.value = {}
		dataModified.value = false
	}
}, { immediate: true })

watch(form, (newForm) => {
	if (isEditMode.value && originalFormData.value) {
		dataModified.value = Object.keys(originalFormData.value).some(key => {
			return newForm[key] !== originalFormData.value[key]
		})
	}
}, { deep: true })

function validateAlias() {
	if (isEditMode.value) {
		aliasError.value = ''
		return
	}

	const trimmedAlias = form.alias.trim()

	if (!trimmedAlias) {
		aliasError.value = ''
		return
	}

	// RFC 5321: Max 64 characters for local-part
	if (trimmedAlias.length > 64) {
		aliasError.value = t('sdkmc', 'Alias cannot exceed 64 characters')
		return
	}

	// RFC 5321 compliant validation for email local-part
	// Pattern: atom(.atom)* where atom = [a-z0-9_-]+
	// This inherently prevents leading/trailing/consecutive dots
	const aliasRegex = /^[a-z0-9_-]+(\.[a-z0-9_-]+)*$/

	if (!aliasRegex.test(trimmedAlias)) {
		aliasError.value = t('sdkmc', 'Alias may only contain lowercase letters (a-z), digits (0-9), underscore (_), hyphen (-), and dots (not at start/end or consecutive)')
		return
	}

	if (existingAliases.value.includes(trimmedAlias.toLowerCase())) {
		aliasError.value = t('sdkmc', 'This alias is already in use')
	} else {
		aliasError.value = ''
	}
}

function validateSdkAddress() {
	if (isEditMode.value) {
		sdkAddressError.value = ''
		return
	}

	const trimmedSdkAddress = form.sdkaddress.trim()

	if (!trimmedSdkAddress) {
		sdkAddressError.value = ''
		return
	}

	if (existingSdkAddresses.value.includes(trimmedSdkAddress.toLowerCase())) {
		sdkAddressError.value = t('sdkmc', 'This SDK address is already in use')
	} else {
		sdkAddressError.value = ''
	}
}

function close() {
	if (!loading.value) {
		emit('close')
	}
}

const requestToken = getRequestToken()
onMounted(async () => {
	axios.defaults.headers.common.requesttoken = requestToken

	// Fetch global retention settings for display
	try {
		const res = await axios.get('/index.php/apps/sdkmc/api/v2/admin/serversettings')
		if (res.data) {
			globalRetention.value = res.data
		}
	} catch (err) {
		console.error('Failed to load global retention settings:', err)
	}
})

async function submitForm() {
	if (!isEditMode.value) {
		validateAlias()
		if (form.messageType === 'sdk') {
			validateSdkAddress()
		}
		if (aliasError.value || sdkAddressError.value) {
			return
		}
	}

	loading.value = true

	const trimmedForm = {
		messageType: form.messageType?.trim(),
		sdkaddress: form.sdkaddress?.trim(),
		alias: form.alias?.trim(),
		name: form.name?.trim(),
		description: form.description?.trim(),
		number: form.number?.trim(),
		notificationEmail: form.notificationEmail?.trim(),
	}

	if (!isEditMode.value) {
		if (trimmedForm.messageType === 'sdk' && !trimmedForm.sdkaddress) {
			showError(t('sdkmc', 'SDK address is required for SDK type'))
			loading.value = false
			return
		}
		if (!trimmedForm.alias) {
			showError(t('sdkmc', 'Alias is required'))
			loading.value = false
			return
		}
	}

	// Build JSON data object - preserves boolean types correctly
	const data = {
		messageType: trimmedForm.messageType,
		alias: trimmedForm.alias,
		name: trimmedForm.name,
		description: trimmedForm.description,
		canBeRepliedTo: form.canBeRepliedTo,
		canMessageBeSentTo: form.canMessageBeSentTo,
		notificationEmail: trimmedForm.notificationEmail,
	}

	if (!isEditMode.value) {
		data.number = trimmedForm.number
		data.sdkaddress = trimmedForm.sdkaddress
	}

	// Build retention overrides object (only non-empty, non-negative values)
	const overrides = {}
	for (const [folder, value] of Object.entries(form.retention)) {
		if (value !== '' && value !== null && value !== undefined) {
			const days = parseInt(value, 10)
			if (!isNaN(days) && days >= 0) {
				overrides[folder] = days
			}
		}
	}
	// Only send if there are overrides
	if (Object.keys(overrides).length > 0) {
		data.retentionOverrides = overrides
	}

	try {
		const endpoint = isEditMode.value
			? generateUrl('/apps/sdkmc/api/v2/admin/updateAccount')
			: generateUrl('/apps/sdkmc/api/v2/admin/addAccount')

		const httpMethod = isEditMode.value ? 'patch' : 'post'
		const response = await axios[httpMethod](endpoint, data, {
			headers: {
				'Content-Type': 'application/json',
				requesttoken: requestToken,
			},
			withCredentials: true,
		})

		if (response.status === 200 || response.status === 303) {
			if (isEditMode.value) {
				showSuccess(t('sdkmc', 'Mailbox updated successfully!'))
				emit('updated')
			} else {
				showSuccess(t('sdkmc', 'Mailbox added successfully!'))
				emit('added')
			}
			emit('close')
		} else {
			showError(t('sdkmc', isEditMode.value ? 'Failed to update mailbox. Please try again.' : 'Failed to add mailbox. Please try again.'))
		}
	} catch (err) {
		const backendError = err.response?.data?.error
		showError(backendError || t('sdkmc', 'Network or server error. Please try again.'))
	} finally {
		loading.value = false
	}
}
</script>

<style scoped>
.modal-overlay {
	position: fixed;
	top: 0; left: 0; right: 0; bottom: 0;
	background: rgba(0,0,0,0.5);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 1500;
}

.modal-content {
	position: relative;
	background: var(--color-main-background);
	border-radius: 6px;
	padding: 20px 40px;
	width: 600px;
	max-width: 90vw;
	max-height: 90vh;
	overflow-y: auto;
}

.modal-title {
	margin-top: 0;
	margin-bottom: 30px;
}

.modal-button-submit {
	margin-top: 30px;
	float: right;
}

.modal-button-close {
	position: absolute;
	top: 20px;
	right: 40px;
	width: 36px;
	height: 36px;
	color: var(--color-main-text);
	cursor: pointer;
}

label {
	display: block;
	margin-bottom: 10px;
}

input[type='email'],
input[type='text'],
select {
	width: 100%;
	padding: 6px;
	margin-top: 4px;
	box-sizing: border-box;
}

input[type='email']:focus,
input[type='text']:focus,
select:focus {
	outline: none;
	border-color: var(--color-primary);
}

input[type='text']:disabled {
	background-color: var(--color-background-hover);
	color: var(--color-text-maxcontrast);
	cursor: not-allowed;
}

.input-error {
	border-color: var(--color-error) !important;
}

.error {
	color: var(--color-error);
	margin-top: 12px;
}

.field-error {
	color: var(--color-error);
	font-size: 12px;
	margin-top: 4px;
	margin-bottom: 10px;
}

.switch-field {
	margin: 10px 0;
}

.retention-section {
	margin-top: 16px;
	border-top: 1px solid var(--color-border);
	padding-top: 12px;
}

.retention-section summary {
	list-style: none;
}

.retention-section summary::-webkit-details-marker {
	display: none;
}

.retention-summary {
	cursor: pointer;
	font-weight: 500;
	display: flex;
	align-items: center;
	gap: 8px;
}

.retention-chevron {
	transition: transform 0.2s ease;
}

.retention-section[open] .retention-chevron {
	transform: rotate(180deg);
}

.retention-badge {
	background: var(--color-primary);
	color: white;
	border-radius: 10px;
	padding: 2px 8px;
	font-size: 11px;
}

.retention-content {
	margin-top: 12px;
}

.retention-legend {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-bottom: 8px;
}

.retention-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 13px;
}

.retention-table th,
.retention-table td {
	padding: 4px 8px;
	text-align: left;
}

.retention-table th {
	font-weight: 500;
	border-bottom: 1px solid var(--color-border);
}

.retention-input-cell {
	display: flex;
	align-items: center;
	gap: 4px;
}

.retention-input {
	width: 60px;
	padding: 4px;
	border: 1px solid var(--color-border);
	border-radius: 4px;
}

.retention-clear-btn {
	background: none;
	border: none;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	font-size: 16px;
	padding: 2px 6px;
	line-height: 1;
	border-radius: 4px;
}

.retention-clear-btn:hover {
	background: var(--color-background-hover);
	color: var(--color-error);
}

.retention-global {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}
</style>
