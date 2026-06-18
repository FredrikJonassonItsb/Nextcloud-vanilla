<template>
	<div class="header">
		<template v-if="messageTypes.length > 1">
			<NcActions class="flex-container-column"
				:menu-name="t('mail', 'New message')">
				<template #icon>
					<IconAdd :size="20" />
				</template>
				{{ t('mail', 'New message') }}
				<NcActionButton v-for="(type, index) in messageTypes"
					:key="index"
					close-after-click
					:aria-label="type.label"
					class="select-message-type-option"
					@click="openNewMessageDropdown(type)">
					<template #icon>
						<component :is="type.icon" class="select-message-type-option-icon" />
					</template>
					{{ t('mail', type.labelKey) }}
				</NcActionButton>
			</NcActions>
		</template>
		<template v-else>
			<ButtonVue :aria-label="t('mail', messageTypes[0].labelKey)"
				variant="secondary"
				button-id="mail_new_message"
				role="complementary"
				:wide="true"
				class="select-message-type-option"
				@click="openNewMessageDropdown(messageTypes[0])">
				<template #icon>
					<component :is="messageTypes[0].icon" class="select-message-type-option-icon select-message-type-option-icon--single" />
				</template>
				{{ t('mail', 'New message') }}
			</ButtonVue>
		</template>
		<ButtonVue v-if="currentMailbox"
			:aria-label="t('mail', 'Refresh')"
			variant="tertiary-no-background"
			class="refresh__button"
			:disabled="refreshing"
			@click="refreshMailbox">
			<template #icon>
				<IconRefresh v-if="!refreshing"
					:size="20" />
				<IconLoading v-if="refreshing"
					:size="20" />
			</template>
		</ButtonVue>
	</div>
</template>

<script>
import { NcButton as ButtonVue, NcActions, NcActionButton } from '@nextcloud/vue'
import { mapStores } from 'pinia'
import useMainStore from '../../../store/mainStore.js'
import { UNIFIED_INBOX_ID, PRIORITY_INBOX_ID, FOLLOW_UP_MAILBOX_ID } from '../../../store/constants.js'
import { MESSAGE_TYPES, MY_MESSAGES_MAILBOX_ID, UNASSIGNED_MAILBOX_ID } from '../../store/constants.js'
import { messageTypeToIcon } from '../../utils/messageTypeUtils.js'
import useItslStore from '../../store/itslStore.js'
import IconAdd from 'vue-material-design-icons/Plus.vue'
import IconRefresh from 'vue-material-design-icons/Refresh.vue'
import IconLoading from '@nextcloud/vue/components/NcLoadingIcon'
import logger from '../../../logger.js'

export default {
	name: 'NewMessageButtonHeaderItsl',
	components: {
		ButtonVue,
		NcActions,
		NcActionButton,
		IconAdd,
		IconRefresh,
		IconLoading,
	},
	data() {
		return {
			refreshing: false,
			messageType: null,
			itslStore: useItslStore(),
		}
	},
	computed: {
		...mapStores(useMainStore),
		currentMailbox() {
			if (this.$route.name === 'message' || this.$route.name === 'mailbox') {
				return this.mainStore.getMailbox(this.$route.params.mailboxId)
			}
			return undefined
		},
		messageTypes() {
			const accounts = this.mainStore.getAccounts.filter(a => !a.isUnified)
			const emailAddresses = accounts.map(a => a.emailAddress.toLowerCase())

			const includedTypes = new Set()

			// Check for each suffix and add the appropriate message types
			for (const email of emailAddresses) {
				if (email.endsWith('@sdk')) {
					includedTypes.add('SDK')
				}
				if (email.endsWith('@gruppbox') || email.endsWith('@personlig')) {
					includedTypes.add('INTERNAL')
					includedTypes.add('SECURE')
				}
				if (email.endsWith('@fax')) {
					includedTypes.add('FAX')
				}
				if (email.endsWith('@sms')) {
					includedTypes.add('SMS')
				}
			}

			return [...includedTypes].map(typeKey => {
				const type = MESSAGE_TYPES[typeKey]
				return {
					...type,
					label: t('mail', type.labelKey),
					icon: messageTypeToIcon(type.id),
				}
			})
		},
		selectedThreadAccountId() {
			// Check if we're in a unified/virtual inbox view
			const mailboxId = this.$route.params.mailboxId
			const isUnifiedView = [
				UNIFIED_INBOX_ID,
				PRIORITY_INBOX_ID,
				FOLLOW_UP_MAILBOX_ID,
				MY_MESSAGES_MAILBOX_ID,
				UNASSIGNED_MAILBOX_ID,
			].includes(mailboxId)

			if (!isUnifiedView) {
				return undefined // Let composer use its default logic
			}

			// Check if there's a selected thread
			const threadId = this.$route.params.threadId
			if (!threadId) {
				return undefined
			}

			// Get envelope and its mailbox to find the accountId
			const envelope = this.mainStore.getEnvelope(parseInt(threadId, 10))
			if (!envelope) {
				return undefined
			}

			const mailbox = this.mainStore.getMailbox(envelope.mailboxId)
			return mailbox?.accountId
		},
	},
	methods: {
		async refreshMailbox() {
			if (this.refreshing === true) {
				return
			}
			this.refreshing = true
			try {
				await this.mainStore.syncEnvelopes({ mailboxId: this.currentMailbox.databaseId })
			} catch (error) {
				logger.error('could not sync current mailbox', { error })
			} finally {
				this.refreshing = false
			}
		},
		openNewMessageDropdown(type) {
			this.messageType = {
				...type,
				label: t('mail', type.labelKey),
			}
			this.itslStore.setMessageType(type.id)
			this.onNewMessage(type)
		},

		async onNewMessage(type) {
			const data = {}
			if (this.selectedThreadAccountId) {
				data.accountId = this.selectedThreadAccountId
			}
			if (type) {
				data.itsl = { messageType: type.id }
			}
			await this.mainStore.startComposerSession({
				isBlankMessage: true,
				data,
			})
		},
	},
}
</script>

<style lang="scss" scoped>
.header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: calc(var(--default-grid-baseline, 4px) * 2);
	gap: 4px;
	> .select-message-type-option {
		flex: 1;
		min-width: 0;
	}
}
.refresh__button {
	flex-shrink: 0;
	background-color: transparent;
}
.flex-container-column {
	flex: 1;
	min-width: 0;
	padding: 8px 0;
	:deep(button.action-item__menutoggle) {
		width: 100%;
	}
}
.select-message-type-option {
	display: flex;
	&.active {
		background-color: var(--color-primary-element-light)!important;
	}
	:deep(.action-button) {
		display: flex;
		gap: 10px;
		font-weight: 400;
		align-items: center;
		justify-content: flex-start;
		padding: 1px 5px;
	}
	.select-message-type-option-icon {
		max-width: 16px;
		margin: 0 0 0 5px;
		&--single {
			margin: 0;
		}
	}
	.action-button > span[data-v-903e8d3b] {
		cursor: pointer;
		white-space: nowrap;
		font-weight: 500;
	}
}

@media only screen and (max-width: 768px) {
	.flex-container-column.action-item {
			padding: 0;
	}
}
</style>
