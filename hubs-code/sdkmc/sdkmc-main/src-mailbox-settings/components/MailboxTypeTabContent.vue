<template>
	<div>
		<div class="controls">
			<NcButton class="add-button" @click="showAddModal = true">
				{{ t('sdkmc', 'Add new mailbox') }}
			</NcButton>
			<NcTextField
				v-if="hasMessageBoxes"
				v-model="localFilter"
				:label="t('sdkmc', 'Search mailboxes')"
				type="search"
				:placeholder="t('sdkmc', 'Search...')"
				class="search-input" />
			<div class="controls-aligned">
				<NcButton
					v-if="('personlig').includes(mailboxType)"
					class="provision-button"
					variant="secondary"
					@click="showProvisionModal = true">
					{{ t('sdkmc', 'Provision mailboxes') }}
				</NcButton>
			</div>
		</div>

		<MailboxDetailModal
			v-if="showAddModal || editAccount"
			:mailbox-type="mailboxType"
			:existing-accounts="accounts"
			:edit-account="editAccount"
			@added="handleAdded"
			@updated="handleUpdated"
			@close="closeModal" />

		<NcModal
			v-if="confirmRemoveAccount !== null"
			class="account-removal-modal"
			:title="t('sdkmc', 'Confirm removal')"
			label-id="confirm-remove-title"
			@close="confirmRemoveAccount = null">
			<h2 id="confirm-remove-title" class="hidden-visually">
				{{ t('sdkmc', 'Confirm removal') }}
			</h2>
			<div class="modal__content">
				<p class="modal-container-heading">
					{{ t('sdkmc', 'Are you sure you want to remove this mailbox?') }}
				</p>
				<div class="modal-footer">
					<NcButton @click="confirmRemoveAccount = null">
						{{ t('sdkmc', 'Cancel') }}
					</NcButton>
					<NcButton @click="confirmRemove">
						{{ t('sdkmc', 'Remove') }}
					</NcButton>
				</div>
			</div>
		</NcModal>

		<ProvisionMailboxesModal
			:show="showProvisionModal"
			:groups="groups"
			@close="showProvisionModal = false"
			@provision-mailboxes="confirmProvision" />

		<AddUserToMailboxModal
			:show="showAddUserModal !== null"
			:account="showAddUserModal"
			:users="users"
			@close="closeUserModal"
			@add-user="handleAddUser" />

		<AddGroupToMailboxModal
			:show="showAddGroupModal !== null"
			:account="showAddGroupModal"
			:groups="groups"
			@close="closeGroupModal"
			@add-group="handleAddGroup" />

		<DataTableWithPagination
			:items="filteredAccounts"
			:columns="tableColumns"
			:items-per-page="pageSize"
			:has-actions="true"
			:actions-title="t('sdkmc', 'Actions')"
			:custom-key-function="getAccountKey"
			:empty-message="t('sdkmc', 'No mailboxes found')"
			:pagination-texts="paginationTexts">
			<template #cell-users="{ item }">
				<div v-if="item.users && item.users.length">
					<div
						v-for="userId in item.users"
						:key="userId"
						class="user-row">
						<NcChip @close="$emit('remove-user', item, userId)">
							{{ users[userId] && users[userId].displayName || userId }}
						</NcChip>
					</div>
				</div>
				<div v-else>
					{{ t('sdkmc', 'No users added') }}
				</div>
			</template>
			<template #cell-groups="{ item }">
				<div v-if="item.groups && item.groups.length">
					<div
						v-for="groupId in item.groups"
						:key="groupId"
						class="user-row">
						<NcChip @close="$emit('remove-group', mailboxType, item, groupId)">
							{{ getGroupLabel(groupId) }}
						</NcChip>
					</div>
				</div>
				<div v-else>
					{{ t('sdkmc', 'No groups added') }}
				</div>
			</template>

			<template #actions="{ item }">
				<div class="cursor-pointer">
					<NcPopover
						:shown="openPopover === getAccountKey(item)"
						:triggers="[]"
						placement="bottom-end"
						class="table-popover"
						@update:shown="handlePopoverToggle(item, $event)">
						<template #trigger="{ attrs }">
							<DotsVertical
								v-bind="attrs"
								class="cursor-pointer"
								@click.stop="togglePopover(item)" />
						</template>
						<template #default>
							<div
								class="popover-content"
								@mousedown.stop>
								<NcButton
									class="add-user-button"
									@click="openUserModal(item)">
									{{ t('sdkmc', 'Assign user') }}
								</NcButton>
								<NcButton
									class="add-group-button"
									@click="openGroupModal(item)">
									{{ t('sdkmc', 'Assign group') }}
								</NcButton>
								<NcButton
									class="edit-mailbox-button"
									@click="openEditModal(item)">
									{{ t('sdkmc', 'Edit mailbox') }}
								</NcButton>
								<NcButton
									class="remove-account-button"
									variant="secondary"
									:disabled="item.users && item.users.length > 0"
									:title="item.users && item.users.length > 0 ? t('sdkmc', 'Cannot remove mailbox with assigned users') : ''"
									@click="confirmRemoveAccount = item">
									{{ t('sdkmc', 'Remove mailbox') }}
								</NcButton>
							</div>
						</template>
					</NcPopover>
				</div>
			</template>

			<template #empty-state>
				<NcEmptyContent>
					<template #description>
						<div>
							{{ t('sdkmc', 'No mailboxes added') }}
						</div>
					</template>
				</NcEmptyContent>
			</template>
		</DataTableWithPagination>
	</div>
</template>

<script>
import { ref, computed, nextTick } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import {
	NcModal,
	NcTextField,
	NcButton,
	NcEmptyContent,
	NcPopover,
} from '@nextcloud/vue'
import NcChip from '@nextcloud/vue/dist/Components/NcChip.js'
import DotsVertical from 'vue-material-design-icons/DotsVertical.vue'
import MailboxDetailModal from '../modals/MailboxDetailModal.vue'
import AddUserToMailboxModal from '../modals/AddUserToMailboxModal.vue'
import AddGroupToMailboxModal from '../modals/AddGroupToMailboxModal.vue'
import ProvisionMailboxesModal from '../modals/ProvisionMailboxesModal.vue'
import DataTableWithPagination from './shareable/DataTableWithPagination.vue'

export default {
	name: 'MailboxTypeTabContent',
	components: {
		NcModal,
		NcTextField,
		NcButton,
		NcEmptyContent,
		NcPopover,
		NcChip,
		DotsVertical,
		MailboxDetailModal,
		AddUserToMailboxModal,
		AddGroupToMailboxModal,
		ProvisionMailboxesModal,
		DataTableWithPagination,
	},
	props: {
		accounts: {
			type: Array,
			default: () => [],
		},
		users: {
			type: Object,
			default: () => ({}),
		},
		groups: {
			type: Array,
			default: () => [],
		},
		filter: {
			type: String,
			default: '',
		},
		mailboxType: {
			type: String,
			required: true,
		},
	},
	emits: [
		'remove-user',
		'add-user',
		'add-group',
		'remove-group',
		'reload-accounts',
		'remove-account',
		'page-change',
	],
	setup(props, { emit }) {
		const localFilter = ref('')
		const openPopover = ref(null)
		const showAddModal = ref(false)
		const editAccount = ref(null)
		const showAddUserModal = ref(null)
		const showAddGroupModal = ref(null)
		const confirmRemoveAccount = ref(null)
		const showProvisionModal = ref(false)
		const pageSize = ref(10)

		const hasMessageBoxes = computed(() => props.accounts.length > 0)

		const headersMap = computed(() => ({
			sdk: [
				{ key: 'sdkaddress', title: t('sdkmc', 'Address') },
				{ key: 'alias', title: t('sdkmc', 'Alias') },
				{ key: 'users', title: t('sdkmc', 'Users') },
				{ key: 'groups', title: t('sdkmc', 'Groups') },
			],
			fax: [
				{ key: 'alias', title: t('sdkmc', 'Alias') },
				{ key: 'name', title: t('sdkmc', 'Name') },
				{ key: 'description', title: t('sdkmc', 'Description') },
				{ key: 'number', title: t('sdkmc', 'Fax Number') },
				{ key: 'users', title: t('sdkmc', 'Users') },
				{ key: 'groups', title: t('sdkmc', 'Groups') },
			],
			gruppbox: [
				{ key: 'alias', title: t('sdkmc', 'Alias') },
				{ key: 'name', title: t('sdkmc', 'Name') },
				{ key: 'description', title: t('sdkmc', 'Description') },
				{ key: 'users', title: t('sdkmc', 'Users') },
				{ key: 'groups', title: t('sdkmc', 'Groups') },
			],
			personlig: [
				{ key: 'alias', title: t('sdkmc', 'Alias') },
				{ key: 'name', title: t('sdkmc', 'Name') },
				{ key: 'description', title: t('sdkmc', 'Description') },
				{ key: 'users', title: t('sdkmc', 'Users') },
				{ key: 'groups', title: t('sdkmc', 'Groups') },
			],
			sms: [
				{ key: 'alias', title: t('sdkmc', 'Alias') },
				{ key: 'name', title: t('sdkmc', 'Name') },
				{ key: 'description', title: t('sdkmc', 'Description') },
				{ key: 'number', title: t('sdkmc', 'Phone Number') },
				{ key: 'users', title: t('sdkmc', 'Users') },
				{ key: 'groups', title: t('sdkmc', 'Groups') },
			],
		}))

		const tableColumns = computed(() => {
			return headersMap.value[props.mailboxType] || [
				{ key: 'name', title: t('sdkmc', 'Name') },
				{ key: 'users', title: t('sdkmc', 'Users') },
			]
		})

		const filteredAccounts = computed(() => {
			if (!localFilter.value) {
				return props.accounts
			}
			const lowerFilter = localFilter.value.toLowerCase()
			return props.accounts.filter(acc => {
				const usersString = acc.users ? acc.users.join(' ') : ''
				const groupString = acc.groups ? acc.groups.join(' ') : ''
				const haystack = [
					acc.sdkaddress,
					acc.name,
					acc.alias,
					acc.description,
					acc.number,
					acc.phoneNumber,
					usersString,
					groupString,
				]
					.filter(Boolean)
					.join(' ')
					.toLowerCase()

				return haystack.includes(lowerFilter)
			})
		})

		const paginationTexts = computed(() => ({
			count: t(
				'sdkmc',
				'Showing {from} to {to} of {count} mailboxes|{count} mailboxes|One mailbox',
			),
		}))

		function getAccountKey(acc) {
			return (
				acc.email
				|| acc.sdkaddress
				|| acc.name
				|| acc.alias
				|| JSON.stringify(acc)
			)
		}

		function handlePopoverToggle(account, isOpen) {
			if (isOpen) {
				openPopover.value = getAccountKey(account)
			} else {
				openPopover.value = null
			}
		}

		function togglePopover(account) {
			const key = getAccountKey(account)
			openPopover.value = openPopover.value === key ? null : key
		}

		function closePopover() {
			openPopover.value = null
		}

		function handleAddUser(account, userId) {
			emit('add-user', account, userId)
			closePopover()
		}

		async function openUserModal(account) {
			openPopover.value = null
			await nextTick()
			showAddUserModal.value = account
		}

		function closeUserModal() {
			showAddUserModal.value = null
		}

		function openEditModal(account) {
			editAccount.value = account
			closePopover()
		}

		function closeModal() {
			showAddModal.value = false
			editAccount.value = null
		}

		function handleAdded() {
			closeModal()
			emit('reload-accounts')
		}

		function handleUpdated() {
			closeModal()
			emit('reload-accounts')
		}

		function confirmRemove() {
			if (!confirmRemoveAccount.value) return
			emit('remove-account', confirmRemoveAccount.value, props.mailboxType)
			confirmRemoveAccount.value = null
		}

		function confirmProvision(groupId) {
			emit('provision-mailboxes', groupId)
			showProvisionModal.value = false
		}

		function handleAddGroup(account, groupId) {
			emit('add-group', account, groupId)
			closePopover()
		}

		async function openGroupModal(account) {
			openPopover.value = null
			await nextTick()
			showAddGroupModal.value = account
		}

		function closeGroupModal() {
			showAddGroupModal.value = null
		}

		function getGroupLabel(groupId) {
			const group = props.groups.find(g => g.groupId === groupId)
			return group?.label || groupId
		}

		return {
			localFilter,
			openPopover,
			showAddModal,
			editAccount,
			showAddUserModal,
			showAddGroupModal,
			confirmRemoveAccount,
			showProvisionModal,
			pageSize,
			hasMessageBoxes,
			headersMap,
			tableColumns,
			filteredAccounts,
			paginationTexts,
			getAccountKey,
			handlePopoverToggle,
			togglePopover,
			closePopover,
			handleAddUser,
			openUserModal,
			closeUserModal,
			openEditModal,
			closeModal,
			handleAdded,
			handleUpdated,
			confirmRemove,
			confirmProvision,
			handleAddGroup,
			openGroupModal,
			closeGroupModal,
			getGroupLabel,
		}
	},
}
</script>

<style scoped>
.controls {
	display: flex;
	gap: 8px;
	margin-bottom: 12px;
	align-items: center;
	justify-content: flex-start;
}

.controls-aligned {
	justify-content: flex-end;
	display: flex;
	flex: 1;
}

.search-input {
	max-width: 200px;
	margin: 0;
}

.add-button {
	padding: 6px 16px;
}

.user-row {
	display: flex;
	align-items: center;
	margin-bottom: 4px;
	gap: 8px;
}

.popover-content {
	display: flex;
	flex-direction: column;
	gap: 0;
	min-width: 200px;
}

.button-vue.remove-account-button,
.button-vue.add-user-button,
.button-vue.add-group-button,
.button-vue.edit-mailbox-button {
	background-color: transparent;
	min-height: 45px;
	align-items: center;
	justify-content: flex-start;
	border-radius: 0;
	padding: 0;
	width: 100%;
}

.button-vue.remove-account-button:hover,
.button-vue.add-user-button:hover,
.button-vue.add-group-button:hover,
.button-vue.edit-mailbox-button:hover {
	background-color: var(--color-primary-element-light);
}

.button-vue.remove-account-button:focus-visible,
.button-vue.add-user-button:focus-visible,
.button-vue.add-group-button:focus-visible,
.button-vue.edit-mailbox-button:focus-visible {
	outline: none !important;
	box-shadow: none !important;
}

.button-vue >>> .button-vue__wrapper {
	display: inline-flex;
	align-items: center;
	justify-content: flex-start;
	width: 100%;
	padding: 0 8px;
	font-weight: normal;
}

.button-vue--text-only >>> .button-vue__text {
	font-weight: 500;
}

.v-popper--theme-dropdown.v-popper__popper {
	z-index: 1500;
}

.modal-container__content .modal__content {
	padding: 30px 40px;
}

.modal-container__content .modal-container-heading {
	font-size: 18px;
	font-weight: 600;
}

.modal-container__close {
	top: 15px!important;
	margin-right: 20px;
}

.modal-footer {
	display: flex;
	margin: 30px 0 0;
	justify-content: space-between;
}

.modal-mask--opaque {
	z-index: 100001!important;
	background-color: rgba(var(--backdrop-color), 0.5)!important;
}

.cursor-pointer {
	cursor: pointer;
}
</style>
