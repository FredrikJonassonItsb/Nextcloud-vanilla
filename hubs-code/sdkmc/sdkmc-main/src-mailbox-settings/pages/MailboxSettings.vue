<template>
	<div class="settings">
		<NcAppSidebar :active.sync="activeTab" :name="t('sdkmc', 'Mailbox Settings')" aria-labelledby="mailbox-settings-title">
			<h2 id="mailbox-settings-title" class="hidden-visually">
				{{ t('sdkmc', 'Mailbox Settings') }}
			</h2>
			<template v-if="loaded">
				<NcAppSidebarTab id="sdk-tab" :name="t('sdkmc', 'Sdk mailbox')" :order="1">
					<MailboxTypeTabContent
						:accounts="messageBoxData.sdk"
						:users="users"
						:groups="groups"
						mailbox-type="sdk"
						@reload-accounts="reloadAccounts"
						@remove-account="requestRemoveAccount"
						@add-user="(account, userId) => requestAddUser(account, userId, 'sdk')"
						@remove-user="requestRemoveUser"
						@add-group="(account, groupId) => requestAddGroup(account, groupId, 'sdk')"
						@remove-group="requestRemoveGroup" />
				</NcAppSidebarTab>

				<NcAppSidebarTab id="fax-tab" :name="t('sdkmc', 'Fax mailbox')" :order="2">
					<MailboxTypeTabContent
						:accounts="messageBoxData.fax"
						:users="users"
						:groups="groups"
						mailbox-type="fax"
						@reload-accounts="reloadAccounts"
						@remove-account="requestRemoveAccount"
						@add-user="(account, userId) => requestAddUser(account, userId, 'fax')"
						@remove-user="requestRemoveUser"
						@add-group="(account, groupId) => requestAddGroup(account, groupId, 'fax')"
						@remove-group="requestRemoveGroup" />
				</NcAppSidebarTab>

				<NcAppSidebarTab id="gruppbox-tab" :name="t('sdkmc', 'Gruppbox mailbox')" :order="3">
					<MailboxTypeTabContent
						:accounts="messageBoxData.gruppbox"
						:users="users"
						:groups="groups"
						mailbox-type="gruppbox"
						@reload-accounts="reloadAccounts"
						@remove-account="requestRemoveAccount"
						@add-user="(account, userId) => requestAddUser(account, userId, 'gruppbox')"
						@remove-user="requestRemoveUser"
						@add-group="(account, groupId) => requestAddGroup(account, groupId, 'gruppbox')"
						@remove-group="requestRemoveGroup" />
				</NcAppSidebarTab>

				<NcAppSidebarTab id="personlig-tab" :name="t('sdkmc', 'Personlig mailbox')" :order="4">
					<MailboxTypeTabContent
						:accounts="messageBoxData.personlig"
						:users="users"
						:groups="groups"
						mailbox-type="personlig"
						@reload-accounts="reloadAccounts"
						@remove-account="requestRemoveAccount"
						@add-user="(account, userId) => requestAddUser(account, userId, 'personlig')"
						@remove-user="requestRemoveUser"
						@provision-mailboxes="requestProvisionMailboxes"
						@add-group="(account, groupId) => requestAddGroup(account, groupId, 'personlig')"
						@remove-group="requestRemoveGroup" />
				</NcAppSidebarTab>

				<NcAppSidebarTab id="sms-tab" :name="t('sdkmc', 'Sms mailbox')" :order="5">
					<MailboxTypeTabContent
						:accounts="messageBoxData.sms"
						:users="users"
						:groups="groups"
						mailbox-type="sms"
						@reload-accounts="reloadAccounts"
						@remove-account="requestRemoveAccount"
						@add-user="(account, userId) => requestAddUser(account, userId, 'sms')"
						@remove-user="requestRemoveUser"
						@add-group="(account, groupId) => requestAddGroup(account, groupId, 'sms')"
						@remove-group="requestRemoveGroup" />
				</NcAppSidebarTab>
			</template>

			<template v-else>
				<div class="loading">
					<NcLoadingIcon :size="64" appearance="dark" />
				</div>
			</template>
		</NcAppSidebar>
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { NcAppSidebar, NcAppSidebarTab, NcLoadingIcon } from '@nextcloud/vue'
import MailboxTypeTabContent from '../components/MailboxTypeTabContent.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showInfo } from '@nextcloud/dialogs'
import { getRequestToken } from '@nextcloud/auth'

const activeTab = ref('sdk-tab')
const loaded = ref(false)

const requestToken = getRequestToken()
axios.defaults.headers.common.requesttoken = requestToken
axios.defaults.withCredentials = true

const messageBoxData = ref({
	sdk: [],
	fax: [],
	gruppbox: [],
	personlig: [],
	sms: [],
})

const users = reactive({})
const groups = ref([])

async function loadMailboxes() {
	try {
		const res = await axios.get(generateUrl('/apps/sdkmc/api/v2/sdkmc/allMailboxes'))
		const rawMap = res.data

		messageBoxData.value = {
			sdk: Object.values(rawMap.accounts?.sdk || {}).sort((a, b) => {
				const valA = (a.sdkaddress || '').toLowerCase()
				const valB = (b.sdkaddress || '').toLowerCase()
				return valA.localeCompare(valB)
			}),
			fax: Object.values(rawMap.accounts?.fax || {}).sort((a, b) => {
				const valA = (a.email || '').toLowerCase()
				const valB = (b.email || '').toLowerCase()
				return valA.localeCompare(valB)
			}),
			gruppbox: Object.values(rawMap.accounts?.gruppbox || {}).sort((a, b) => {
				const valA = (a.email || '').toLowerCase()
				const valB = (b.email || '').toLowerCase()
				return valA.localeCompare(valB)
			}),
			personlig: Object.values(rawMap.accounts?.personlig || {}).sort((a, b) => {
				const valA = (a.email || '').toLowerCase()
				const valB = (b.email || '').toLowerCase()
				return valA.localeCompare(valB)
			}),
			sms: Object.values(rawMap.accounts?.sms || {}).sort((a, b) => {
				const valA = (a.email || '').toLowerCase()
				const valB = (b.email || '').toLowerCase()
				return valA.localeCompare(valB)
			}),
		}
	} catch (error) {
		console.error('Failed to load mailbox data:', error)
		showError(t('sdkmc', 'Failed to load mailbox data. Please try again.'))
	}
}

async function loadUsers() {
	try {
		const res = await axios.get(generateUrl('/apps/sdkmc/api/v2/sdkmc/allUsers'))
		const rawMap = res.data

		if (rawMap.users) {
			Object.assign(users, rawMap.users)
		}
	} catch (error) {
		console.error('Failed to load user data:', error)
		showError(t('sdkmc', 'Failed to load users data. Please try again.'))
	}
}

async function loadGroups() {
	try {
		const response = await axios.get(
			'/ocs/v2.php/cloud/groups/details',
			{ headers: { 'OCS-APIRequest': 'true' } },
		)

		const rawGroups = response.data?.ocs?.data?.groups || []
		const loadedGroups = rawGroups.map(group => ({
			groupId: group.id,
			label: group.displayname || group.id,
		}))

		groups.value = loadedGroups
	} catch (error) {
		console.error('Failed to load groups:', error)
		showError(t('sdkmc', 'Failed to load groups.'))
	}
}

onMounted(async () => {
	await loadMailboxes()
	await loadUsers()
	await loadGroups()
	loaded.value = true
})

async function reloadAccounts() {
	await loadMailboxes()
}

async function requestRemoveAccount(account, mailboxType) {
	await removeAccount(account, mailboxType)
}

async function removeAccount(account, mailboxType) {
	try {
		const params = new URLSearchParams()
		params.append('email', account.email)
		params.append('messageType', mailboxType)
		params.append('requesttoken', requestToken)

		const res = await axios.post(generateUrl('/apps/sdkmc/api/v2/admin/removeAccount'), params)

		if (!res.status || res.status >= 400) {
			throw new Error(res.statusText || 'Error removing account')
		}

		await loadMailboxes()
	} catch (err) {
		console.error('Failed to remove account:', err)
		showError(t('sdkmc', 'Failed to remove mailbox. Please try again.'))
	}
}

async function requestAddUser(account, userId, mailboxType) {
	if (account.users == null) {
		account.users = []
	}
	if (!userId || account?.users.includes(userId)) return

	try {
		const params = new URLSearchParams()
		params.append('email', account.email)
		params.append('userId', userId)
		params.append('messageType', mailboxType)
		params.append('requesttoken', requestToken)

		const res = await axios.post(generateUrl('/apps/sdkmc/api/v2/admin/addUserToMailBox'), params)

		if (!res.status || res.status >= 400) {
			throw new Error(res.statusText || 'Failed to add user')
		}

		account.users.push(userId)
	} catch (error) {
		console.error('Failed to add user:', error)
		showError(t('sdkmc', 'Failed to add user. Please try again.'))
	}
}

async function requestRemoveUser(account, userId) {
	try {
		await axios.post(generateUrl('/apps/sdkmc/api/v2/admin/removeUserFromMailBox'), {
			email: account.email,
			userId,
		})
		account.users = account.users.filter(u => u !== userId)
	} catch (error) {
		console.error('Failed to remove user:', error)
		showError(t('sdkmc', 'Failed to remove user. Please try again.'))
	}
}

async function requestProvisionMailboxes(groupId) {
	try {
		const params = new URLSearchParams()
		params.append('requesttoken', requestToken)

		if (groupId) {
			params.append('groupId', groupId)
		}

		const res = await axios.post(generateUrl('/apps/sdkmc/api/v2/admin/provisionPersonligAccounts'), params)

		if (!res.status || res.status >= 400) {
			throw new Error(res.statusText || 'Provisioning failed')
		}

		showInfo(t('sdkmc', 'Provision scheduled. Mailboxes will be set up in the next ~30 minutes in the background.'))
		await loadMailboxes()
	} catch (err) {
		console.error('Failed to provision mailboxes:', err)
		showError(t('sdkmc', 'Provisioning failed. Please try again.'))
	}
}

async function requestAddGroup(account, groupId, mailboxType) {
	if (!account.groups) {
		account.groups = []
	}
	if (!groupId || account.groups.includes(groupId)) return

	try {
		const params = new URLSearchParams()
		params.append('email', account.email)
		params.append('groupId', groupId)
		params.append('messageType', mailboxType)
		params.append('requesttoken', requestToken)

		const res = await axios.post(generateUrl('/apps/sdkmc/api/v2/admin/addGroupToMailBox'), params)

		if (!res.status || res.status >= 400) {
			throw new Error(res.statusText || 'Failed to add group')
		}

		await loadMailboxes()
	} catch (error) {
		console.error('Failed to add group:', error)
		showError(t('sdkmc', 'Failed to add group. Please try again.'))
	}
}

async function requestRemoveGroup(mailboxType, account, groupId) {
	try {
		await axios.post(generateUrl('/apps/sdkmc/api/v2/admin/removeGroupFromMailBox'), {
			messageType: mailboxType,
			email: account.email,
			groupId,
		})
		account.groups = account.groups.filter(g => g !== groupId)
	} catch (error) {
		console.error('Failed to remove group:', error)
		showError(t('sdkmc', 'Failed to remove group. Please try again.'))
	}
}
</script>

<style scoped>
.settings .app-sidebar {
	width: 100%;
	border: none;
}

.settings :deep(.app-sidebar-header) {
	display: none;
}

.settings :deep(.app-sidebar-tabs__nav) {
	margin-bottom: 20px;
}

.settings :deep(.app-sidebar__tab),
.settings :deep(.app-sidebar__tab:focus) {
	box-shadow: none;
}

.loading {
	padding: 50px;
	text-align: center;
	font-size: 1.1rem;
	color: var(--color-text-secondary);
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	margin-top: 25px;
}

@media screen and (max-width: 768px) {
	.settings :deep(.app-sidebar-tabs__nav) {
		display: none !important;
	}

	.settings :deep(.app-sidebar__tab) {
		display: flex !important;
		flex-direction: column;
		padding-bottom: 25px;
		margin-bottom: 15px;
		height: auto !important;
		flex: 1 1 auto;
		max-height: 780px;
		min-height: 150px;
		border-bottom: 1px solid var(--color-border);
	}

	.settings :deep(.app-sidebar__tab .app-sidebar-tab__name) {
		display: block;
		font-size: 1.2rem;
		font-weight: bold;
		margin-bottom: 1rem;
		color: var(--color-text);
	}

	.settings :deep(.controls) {
		align-items: flex-start;
		flex-direction: column-reverse;
	}

	.settings :deep(.hidden-visually) {
		margin-top: 0;
		display: block;
		position: relative;
		height: auto;
		width: auto;
		top: 0;
		left: 0;
		overflow: visible;
		font-size: 20px;
	}

	/* Table styles */

	.settings :deep(table) {
		display: block;
		overflow-x: auto;
		width: 100%;
		border: 0 !important;
	}

	.settings :deep(thead) {
		display: none;
	}

	.settings :deep(tbody),
	.settings :deep(tr) {
		display: block;
		width: 100%;
	}

	.settings :deep(tr) {
		border: 1px solid var(--color-primary, #0082c9);
		border-radius: 6px;
		margin-bottom: 1rem;
		padding: 0;
		overflow: hidden;
	}

	.settings :deep(td) {
		display: flex;
		align-items: center;
		width: 100%;
		padding: 0;
		padding-right: 7px;
		border: none;
		border-bottom: 1px solid var(--color-primary, #0082c9);
		min-height: 45px;
		font-size: 0.95rem;
		white-space: normal;
		word-break: break-word;
		justify-content: space-between;
		text-align: right;
	}

	.settings :deep(td::before) {
		content: attr(data-label);
		flex: 0 0 40%;
		text-align: left;
		background-color: var(--color-primary, #0082c9);
		color: var(--color-primary-text, white);
		min-height: 45px;
		display: flex;
		align-items: center;
		justify-content: flex-start;
		padding-left: 10px;
		border-bottom: .1px solid;
		height: 100%;
		height: -webkit-fill-available;
		height: -moz-available;
		margin-right: 7px;
		font-size: 14px;
		font-weight: 400;
	}

	.settings :deep(td > div) {
		max-width: 54%;
	}

	.settings :deep(.user-row) {
		justify-content: end;
	}

	.settings :deep(td.actions-cell) {
		border-bottom: 0;
	}

	.settings :deep(.nc-chip) {
		height: 100%;
		display: flex;
		max-width: 100%;
		margin: 5px 0 0 0;
	}
}
@media screen and (max-width: 512px) {
	.settings .app-sidebar {
		padding: 25px 20px;
		z-index: 1;
	}
}
@media screen and (max-width: 425px) {
	.settings :deep(.custom-modal) {
		display: flex;
		min-width: 90vw;
		flex-direction: column;
		max-height: 90vh;
		margin-top: 50px;
		width: 90vw;
	}
	.settings :deep(.select) {
		min-width: 227px;
	}
	.settings :deep(.modal-title) {
		font-size: 18px;
	}

	.settings :deep(.modal-content) {
		padding: 20px;
	}

	.settings :deep(.modal-button-close) {
		right: 20px;
		top: 15px;
	}
	.settings :deep(.pagination) {
		margin-top: 0;
	}

}
</style>
