<!--
  - SPDX-FileCopyrightText: 2026 ITSL AB <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="mailbox-info">
		<div class="mailbox-info__name">
			{{ mailboxDisplayName }}
		</div>
		<div v-if="folderCounts.length > 0" class="mailbox-info__folders">
			<div class="mailbox-info__folders-header">
				<span class="mailbox-info__label">{{ t('mail', 'Messages in:') }}</span>
				<button v-if="showMoveAction"
					class="mailbox-info__move-action"
					@click.stop="$emit('move')">
					{{ t('mail', 'Move') }}
				</button>
			</div>
			<template v-for="(folder, index) in folderCounts">
				<span v-if="index > 0" :key="'sep-' + folder.name" class="mailbox-info__separator">/</span>
				<a :key="folder.name"
					class="mailbox-info__folder"
					:href="getFolderLink(folder)"
					@click.prevent="navigateToFolder(folder)">
					<component :is="getFolderIcon(folder.specialRole)" :size="14" class="mailbox-info__folder-icon" />
					<span class="mailbox-info__folder-name">{{ folder.name }}</span>
					<span class="mailbox-info__folder-count">{{ folder.count }}</span>
				</a>
			</template>
		</div>
	</div>
</template>

<script>
import { mapStores } from 'pinia'
import useMainStore from '../../../store/mainStore.js'
import useItslStore from '../../store/itslStore.js'
import { formatPhoneNumber } from '../../utils/phoneUtils.js'

// Folder icons
import Home from 'vue-material-design-icons/Home.vue'
import Send from 'vue-material-design-icons/Send.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import PackageDown from 'vue-material-design-icons/PackageDown.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Alarm from 'vue-material-design-icons/Alarm.vue'
import Folder from 'vue-material-design-icons/Folder.vue'

export default {
	name: 'MailboxInfo',
	components: {
		Home,
		Send,
		Pencil,
		PackageDown,
		AlertCircle,
		Delete,
		Alarm,
		Folder,
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
		showMoveAction: {
			type: Boolean,
			default: false,
		},
	},
	computed: {
		...mapStores(useMainStore, useItslStore),
		mailboxDisplayName() {
			// Get the account that owns this mailbox
			const account = this.mainStore.getAccount(this.envelope.accountId)
			const email = account?.emailAddress || ''

			// SDK: Look up function name from cached address book data
			if (email.endsWith('@sdk')) {
				// Step 1: Find function address in validFromData (map is keyed by function address, value is email)
				const foundEntry = [...this.itslStore.validFromData.entries()].find(([, value]) => value === email)
				if (foundEntry) {
					const functionAddress = foundEntry[0]
					// Step 2: Search all orgs for a function with this exact address
					for (const org of this.itslStore.addressBookOrgs) {
						const func = org.functionAddresses.find(f => f.address === functionAddress)
						if (func) {
							return func.name
						}
					}
				}
				// Fallback: extract prefix from email
				return email.split('@')[0]
			}

			// Internal (@gruppbox/@personlig): Look up in cached internal mailboxes
			if (email.endsWith('@gruppbox') || email.endsWith('@personlig')) {
				const name = this.itslStore.getInternalMailboxName(email)
				return name || email
			}

			// Fax/SMS mailbox: Format phone number (strips @fax/@sms suffix, falls back to local part for non-phone input)
			if (email.endsWith('@fax') || email.endsWith('@sms')) {
				return formatPhoneNumber(email)
			}

			return email
		},
		folderCounts() {
			// Group messages by mailbox folder and count them
			// Key: folderName, Value: { count, specialRole, mailboxId, lastEnvelopeId }
			const folderMap = new Map()

			this.thread.forEach(env => {
				const mailbox = this.mainStore.getMailbox(env.mailboxId)
				if (mailbox) {
					const folderName = this.getMailboxTitle(mailbox)
					const specialRole = this.getMailboxSpecialRole(mailbox)
					const existing = folderMap.get(folderName)
					if (existing) {
						existing.count++
						// Update lastEnvelopeId if this envelope is newer (higher dateInt)
						if (env.dateInt > existing.lastDateInt) {
							existing.lastEnvelopeId = env.databaseId
							existing.lastDateInt = env.dateInt
						}
					} else {
						folderMap.set(folderName, {
							count: 1,
							specialRole,
							mailboxId: mailbox.databaseId,
							lastEnvelopeId: env.databaseId,
							lastDateInt: env.dateInt,
						})
					}
				}
			})

			// Convert to array and sort by standard folder order
			const folderOrder = ['Inbox', 'Sent', 'Archive', 'Snooze', 'Drafts', 'Trash']
			const result = []

			// First add folders in standard order
			folderOrder.forEach(name => {
				const translatedName = this.translateFolderName(name)
				if (folderMap.has(translatedName)) {
					const { count, specialRole, mailboxId, lastEnvelopeId } = folderMap.get(translatedName)
					result.push({ name: translatedName, count, specialRole, mailboxId, lastEnvelopeId })
					folderMap.delete(translatedName)
				}
			})

			// Then add any remaining folders alphabetically
			Array.from(folderMap.entries())
				.sort((a, b) => a[0].localeCompare(b[0]))
				.forEach(([name, { count, specialRole, mailboxId, lastEnvelopeId }]) => {
					result.push({ name, count, specialRole, mailboxId, lastEnvelopeId })
				})

			return result
		},
	},
	methods: {
		getMailboxTitle(mailbox) {
			// Try to get localized name based on special role
			if (mailbox.specialRole) {
				switch (mailbox.specialRole) {
				case 'inbox':
					return this.t('mail', 'Inbox')
				case 'sent':
					return this.t('mail', 'Sent')
				case 'drafts':
					return this.t('mail', 'Drafts')
				case 'archive':
					return this.t('mail', 'Archive')
				case 'junk':
					return this.t('mail', 'Junk')
				case 'trash':
					return this.t('mail', 'Trash')
				}
			}

			// Check for snooze mailbox
			const account = this.mainStore.getAccount(mailbox.accountId)
			if (account && mailbox.databaseId === account.snoozeMailboxId) {
				return this.t('mail', 'Snooze')
			}

			return mailbox.name || mailbox.displayName || 'Unknown'
		},
		translateFolderName(name) {
			// Map English names to translated versions for comparison
			const translations = {
				Inbox: this.t('mail', 'Inbox'),
				Sent: this.t('mail', 'Sent'),
				Drafts: this.t('mail', 'Drafts'),
				Archive: this.t('mail', 'Archive'),
				Snooze: this.t('mail', 'Snooze'),
				Trash: this.t('mail', 'Trash'),
			}
			return translations[name] || name
		},
		getMailboxSpecialRole(mailbox) {
			if (mailbox.specialRole) {
				return mailbox.specialRole
			}
			// Check for snooze mailbox
			const account = this.mainStore.getAccount(mailbox.accountId)
			if (account && mailbox.databaseId === account.snoozeMailboxId) {
				return 'snooze'
			}
			return null
		},
		getFolderIcon(specialRole) {
			const iconMap = {
				inbox: 'Home',
				sent: 'Send',
				drafts: 'Pencil',
				archive: 'PackageDown',
				junk: 'AlertCircle',
				trash: 'Delete',
				snooze: 'Alarm',
			}
			return iconMap[specialRole] || 'Folder'
		},
		getFolderLink(folder) {
			return this.$router.resolve({
				name: 'message',
				params: {
					mailboxId: folder.mailboxId,
					threadId: folder.lastEnvelopeId,
				},
			}).href
		},
		navigateToFolder(folder) {
			this.$router.push({
				name: 'message',
				params: {
					mailboxId: folder.mailboxId,
					threadId: folder.lastEnvelopeId,
				},
			})
		},
	},
}
</script>

<style lang="scss" scoped>
.mailbox-info {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.mailbox-info__name {
	font-size: 14px;
	font-weight: 600;
	color: var(--color-main-text);
	word-break: break-word;
}

.mailbox-info__folders {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 2px;
	font-size: 12px;
	color: var(--color-main-text);
	margin-top: 4px;
}

.mailbox-info__folders-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	width: 100%;
	flex-basis: 100%;
	margin-bottom: 2px;
}

.mailbox-info__label {
	color: var(--color-text-maxcontrast);
}

.mailbox-info__move-action {
	font-size: 11px;
	color: var(--color-primary-element);
	background: none;
	border: none;
	cursor: pointer;
	padding: 2px 6px;
	padding-inline-end: 0;
	border-radius: 4px;
	margin: 0;
	margin-inline-end: 0;
	min-height: unset;
	line-height: 1;

	&:hover {
		background: var(--color-background-hover);
	}
}

.mailbox-info__separator {
	color: var(--color-main-text);
	margin: 0;
}

.mailbox-info__folder {
	display: inline-flex;
	align-items: center;
	gap: 2px;
	cursor: pointer;
	text-decoration: none;
	color: inherit;
	white-space: nowrap;

	&:hover {
		text-decoration: underline;
	}
}

.mailbox-info__folder-icon {
	flex-shrink: 0;
}

.mailbox-info__folder-name {
	color: var(--color-main-text);
}

.mailbox-info__folder-count {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	font-size: 11px;
	font-weight: 700;
	padding: 0 5px;
	height: 18px;
	line-height: 18px;
	border-radius: 100px;
	min-width: 14px;
}
</style>
