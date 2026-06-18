<!--
  - SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<!-- Standard Actions menu for Envelopes -->
<template>
	<div>
		<template v-if="!localMoreActionsOpen && (!snoozeActionsOpen && !forwardActionsOpen)">
			<ActionButton :is-menu="true" @click="forwardActionsOpen = true">
				<template #icon>
					<ShareIcon :size="20" />
				</template>
				{{ t('mail', 'Forward') }}
			</ActionButton>
			<ActionButton v-if="isTranslationEnabled ?? false"
				:close-after-click="true"
				@click.prevent="$emit('open-translation-modal')">
				<template #icon>
					<TranslationIcon :title="t('mail', 'Translate')"
						:size="20" />
				</template>
				{{ t('mail', 'Translate') }}
			</ActionButton>
			<ActionButton :close-after-click="true"
				@click="$emit('toggle-seen')">
				<template #icon>
					<EmailRead v-if="envelope.flags.seen" :size="20" />
					<EmailUnread v-else :size="20" />
				</template>
				{{ envelope.flags.seen ? t('mail', 'Mark as unread') : t('mail', 'Mark as read') }}
			</ActionButton>
			<ActionButton :close-after-click="true"
				@click="$emit('print')">
				<template #icon>
					<PrinterIcon :title="t('mail', 'Download as PDF')" :size="20" />
				</template>
				{{ t('mail', 'Download as PDF') }}
			</ActionButton>
			<ActionButton :close-after-click="true"
				@click="$emit('save-to-files')">
				<template #icon>
					<IconFolderDownload :title="t('mail', 'Save to Files')" :size="20" />
				</template>
				{{ t('mail', 'Save to Files') }}
			</ActionButton>
			<ActionButton :close-after-click="false"
				@click="localMoreActionsOpen=true">
				<template #icon>
					<DotsHorizontalIcon :title="t('mail', 'More actions')"
						:size="20" />
				</template>
				{{ t('mail', 'More actions') }}
			</ActionButton>
		</template>
		<template v-if="localMoreActionsOpen">
			<ActionButton :close-after-click="false"
				@click="localMoreActionsOpen=false">
				<template #icon>
					<ChevronLeft :title="t('mail', 'More actions')"
						:size="20" />
					{{ t('mail', 'More actions') }}
				</template>
			</ActionButton>
			<ActionButton v-if="withShowSource"
				:close-after-click="true"
				@click.prevent="$emit('show-source-modal')">
				<template #icon>
					<InformationIcon :title="t('mail', 'View source')"
						:size="20" />
				</template>
				{{ t('mail', 'View source') }}
			</ActionButton>
		</template>
		<template v-if="forwardActionsOpen">
			<ActionButton :close-after-click="false"
				@click="forwardActionsOpen = false">
				<template #icon>
					<ChevronLeft :size="20" />
				</template>
				{{
					t('mail', 'Back')
				}}
			</ActionButton>

			<ActionButton :close-after-click="true"
				@click="onForward">
				<template #icon>
					<ShareIcon :title="t('mail', 'Forward')"
						:size="20" />
				</template>
				{{ t('mail', 'Forward as') + ' ' + messageTypeToLabelKey(messageType) }}
			</ActionButton>
			<!-- TODO: Implement -->
			<ActionButton v-if="messageType === MESSAGE_TYPES.INTERNAL.id || messageType === MESSAGE_TYPES.SECURE.id"
				:close-after-click="true"
				@click="onForward(MESSAGE_TYPES.SDK.id)">
				<template #icon>
					<ShareIcon :title="t('mail', 'Forward as') + ' ' + t('mail', MESSAGE_TYPES.SDK.labelKey)"
						:size="20" />
				</template>
				{{ t('mail', 'Forward as') + ' ' + t('mail', MESSAGE_TYPES.SDK.labelKey) }}
			</ActionButton>
			<ActionButton v-if="hasInternalMailbox"
				:close-after-click="true"
				@click="$emit('forward-internal-pdf')">
				<template #icon>
					<ForwardAsPDF :title="t('mail', 'Forward to Internal as PDF')"
						:size="20" />
				</template>
				{{ t('mail', 'Forward to Internal as PDF') }}
			</ActionButton>
			<ActionButton v-if="hasInternalMailbox"
				:close-after-click="true"
				@click="$emit('forward-internal-message')">
				<template #icon>
					<ForwardToInternal :title="t('mail', 'Forward to Internal as Message')"
						:size="20" />
				</template>
				{{ t('mail', 'Forward to Internal as Message') }}
			</ActionButton>
		</template>
		<template v-if="snoozeActionsOpen">
			<ActionButton :close-after-click="false"
				@click="snoozeActionsOpen = false">
				<template #icon>
					<ChevronLeft :size="20" />
				</template>
				{{
					t('mail', 'Back')
				}}
			</ActionButton>

			<ActionButton v-for="option in reminderOptions"
				:key="option.key"
				:aria-label="option.ariaLabel"
				close-after-click
				@click.stop="onSnooze(option.timestamp)">
				{{ option.label }}
			</ActionButton>

			<NcActionSeparator />

			<NcActionInput type="datetime-local"
				is-native-picker
				:value="customSnoozeDateTime"
				:min="new Date()"
				@change="setCustomSnoozeDateTime">
				<template #icon>
					<CalendarClock :size="20" />
				</template>
			</NcActionInput>

			<NcActionButton :aria-label="t('mail', 'Set custom snooze')"
				close-after-click
				@click.stop="setCustomSnooze(customSnoozeDateTime)">
				<template #icon>
					<CheckIcon :size="20" />
				</template>
				{{ t('mail', 'Set custom snooze') }}
			</NcActionButton>
		</template>
	</div>
</template>

<script>
import {
	NcActionButton,
	NcActionButton as ActionButton,
} from '@nextcloud/vue'
import { Base64 } from 'js-base64'
import { buildRecipients as buildReplyRecipients } from '../../../ReplyBuilder.js'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import DotsHorizontalIcon from 'vue-material-design-icons/DotsHorizontal.vue'
import PrinterIcon from 'vue-material-design-icons/Printer.vue'
import TranslationIcon from 'vue-material-design-icons/Translate.vue'
import { mailboxHasRights } from '../../../util/acl.js'
import { generateUrl } from '@nextcloud/router'
import InformationIcon from 'vue-material-design-icons/Information.vue'
import ShareIcon from 'vue-material-design-icons/Share.vue'
import { showError, showSuccess } from '@nextcloud/dialogs'

import CalendarClock from 'vue-material-design-icons/CalendarClock.vue'
import NcActionSeparator from '@nextcloud/vue/components/NcActionSeparator'
import NcActionInput from '@nextcloud/vue/components/NcActionInput'
import logger from '../../../logger.js'
import moment from '@nextcloud/moment'
import { mapStores, mapState } from 'pinia'
import useMainStore from '../../../store/mainStore.js'
import useItslStore from '../../store/itslStore.js'
import EmailRead from 'vue-material-design-icons/EmailOpen.vue'
import EmailUnread from 'vue-material-design-icons/Email.vue'
import IconFolderDownload from 'vue-material-design-icons/FolderDownload.vue'
import ForwardAsPDF from '../icons/ForwardAsPDF.vue'
import ForwardToInternal from '../icons/ForwardToInternal.vue'
import { MESSAGE_TYPES } from '../../store/constants.js'
import { messageTypeToLabelKey } from '../../utils/messageTypeUtils.js'
import { cloneDeep } from 'lodash/fp.js'

export default {
	name: 'MenuEnvelopeItsl',
	components: {
		NcActionButton,
		NcActionInput,
		NcActionSeparator,
		CalendarClock,
		ActionButton,
		ChevronLeft,
		CheckIcon,
		DotsHorizontalIcon,
		TranslationIcon,
		InformationIcon,
		ShareIcon,
		PrinterIcon,
		EmailRead,
		EmailUnread,
		IconFolderDownload,
		ForwardToInternal,
		ForwardAsPDF,
	},
	props: {
		envelope: {
			// The envelope on which this menu will act
			type: Object,
			required: true,
		},
		mailbox: {
			// Required for checking ACLs
			type: Object,
			required: true,
		},
		moreActionsOpen: {
			type: Boolean,
			required: false,
		},
		withSelect: {
			// "Select" action should only appear in envelopes from the envelope list
			type: Boolean,
			default: true,
		},
		withShowSource: {
			// "Show source" action should only appear in thread envelopes
			type: Boolean,
			default: true,
		},
		isTranslationAvailable: {
			type: Boolean,
			required: false,
			default: false,
		},
		inlineMenuSize: {
			type: Number,
			default: 4,
		},
		hasInternalMailbox: {
			type: Boolean,
			default: false,
		},
		hasArchiveAcl: {
			type: Boolean,
			default: false,
		},
		disableArchiveButton: {
			type: Boolean,
			default: false,
		},
		showArchiveButton: {
			type: Boolean,
			default: false,
		},
		messageType: {
			type: String,
			default: null,
		},
	},
	data() {
		return {
			debug: window?.OC?.debug || false,
			localMoreActionsOpen: false,
			snoozeActionsOpen: false,
			forwardMessages: this.envelope.databaseId,
			customSnoozeDateTime: new Date(moment().add(2, 'hours').minute(0).second(0).valueOf()),
			forwardActionsOpen: false,
			MESSAGE_TYPES,
			messageTypeToLabelKey,
		}
	},
	computed: {
		...mapStores(useMainStore, useItslStore),
		...mapState(useMainStore, [
			'isSnoozeDisabled',
			'isTranslationEnabled',
		]),
		account() {
			const accountId = this.envelope.accountId ?? this.mailbox.accountId
			return this.mainStore.getAccount(accountId)
		},
		hasMultipleRecipients() {
			if (!this.account) {
				console.error('account is undefined', {
					accountId: this.envelope.accountId,
				})
			}
			const recipients = buildReplyRecipients(this.envelope, {
				label: this.account.name,
				email: this.account.emailAddress,
			})
			return recipients.to.concat(recipients.cc).length > 1
		},
		threadingFile() {
			return `data:text/plain;base64,${Base64.encode(JSON.stringify({
				subject: this.envelope.subject,
				messageId: this.envelope.messageId,
				inReplyTo: this.envelope.inReplyTo,
				references: this.envelope.references,
				threadRootId: this.envelope.threadRootId,
			}, null, 2))}`
		},
		threadingFileName() {
			return `${this.envelope.databaseId}.json`
		},
		showFavoriteIconVariant() {
			return this.envelope.flags.flagged
		},
		showImportantIconVariant() {
			return this.envelope.flags.seen
		},
		isImportant() {
			return this.mainStore
				.getEnvelopeTags(this.envelope.databaseId)
				.some((tag) => tag.imapLabel === '$label1')
		},
		/**
		 * Link to download the whole message (.eml).
		 *
		 * @return {string}
		 */
		exportMessageLink() {
			return generateUrl('/apps/mail/api/messages/{id}/export', {
				id: this.envelope.databaseId,
			})
		},
		hasWriteAcl() {
			return mailboxHasRights(this.mailbox, 'w')
		},
		hasDeleteAcl() {
			return mailboxHasRights(this.mailbox, 'te')
		},
		isSnoozedMailbox() {
			return this.mailbox.databaseId === this.account.snoozeMailboxId
		},
		reminderOptions() {
			const currentDateTime = moment()

			// Same day 18:00 PM (or hidden)
			const laterTodayTime = (currentDateTime.hour() < 18)
				? moment().hour(18)
				: null

			// Tomorrow 08:00 AM
			const tomorrowTime = moment().add(1, 'days').hour(8)

			// Saturday 08:00 AM (or hidden)
			const thisWeekendTime = (currentDateTime.day() !== 6 && currentDateTime.day() !== 0)
				? moment().day(6).hour(8)
				: null

			// Next Monday 08:00 AM
			const nextWeekTime = moment().add(1, 'weeks').day(1).hour(8)

			return [
				{
					key: 'laterToday',
					timestamp: this.getTimestamp(laterTodayTime),
					label: t('mail', 'Later today – {timeLocale}', { timeLocale: laterTodayTime?.format('LT') }),
					ariaLabel: t('mail', 'Set reminder for later today'),
				},
				{
					key: 'tomorrow',
					timestamp: this.getTimestamp(tomorrowTime),
					label: t('mail', 'Tomorrow – {timeLocale}', { timeLocale: tomorrowTime?.format('ddd LT') }),
					ariaLabel: t('mail', 'Set reminder for tomorrow'),
				},
				{
					key: 'thisWeekend',
					timestamp: this.getTimestamp(thisWeekendTime),
					label: t('mail', 'This weekend – {timeLocale}', { timeLocale: thisWeekendTime?.format('ddd LT') }),
					ariaLabel: t('mail', 'Set reminder for this weekend'),
				},
				{
					key: 'nextWeek',
					timestamp: this.getTimestamp(nextWeekTime),
					label: t('mail', 'Next week – {timeLocale}', { timeLocale: nextWeekTime?.format('ddd LT') }),
					ariaLabel: t('mail', 'Set reminder for next week'),
				},
			].filter(option => option.timestamp !== null)
		},
	},
	watch: {
		localMoreActionsOpen(value) {
			this.$emit('update:moreActionsOpen', value)
		},
	},
	methods: {
		onForward(type) {
			let dataObject = this.envelope
			if (type === MESSAGE_TYPES.SDK.id) {
				dataObject = cloneDeep(this.envelope)
				dataObject.itsl.messageType = type
			}
			this.mainStore.startComposerSession({
				reply: {
					mode: 'forward',
					data: dataObject,
				},
			})
		},
		async onSnooze(timestamp) {
			// Remove from selection first
			if (this.withSelect) {
				this.$emit('unselect')
			}

			logger.info(`snoozing message ${this.envelope.databaseId}`)

			if (!this.account.snoozeMailboxId) {
				await this.mainStore.createAndSetSnoozeMailbox(this.account)
			}

			try {
				await this.mainStore.snoozeMessage({
					id: this.envelope.databaseId,
					unixTimestamp: timestamp / 1000,
					destMailboxId: this.account.snoozeMailboxId,
				})
				showSuccess(t('mail', 'Message was snoozed'))
			} catch (error) {
				logger.error('Could not snooze message', error)
				showError(t('mail', 'Could not snooze message'))
			}
		},
		async onUnSnooze() {
			// Remove from selection first
			if (this.withSelect) {
				this.$emit('unselect')
			}

			logger.info(`unSnoozing message ${this.envelope.databaseId}`)

			// Store envelope reference before unsnoozing (needed for sync)
			const envelope = this.envelope

			try {
				await this.mainStore.unSnoozeMessage({
					id: envelope.databaseId,
				})
				// Sync handled by $onAction in initITSL.js
				showSuccess(t('mail', 'Message was unsnoozed'))
			} catch (error) {
				logger.error('Could not unsnooze message', error)
				showError(t('mail', 'Could not unsnooze message'))
			}
		},
		onToggleFlagged() {
			this.mainStore.toggleEnvelopeFlagged(this.envelope)
		},
		onToggleImportant() {
			this.mainStore.toggleEnvelopeImportant(this.envelope)
		},
		onToggleSeen() {
			this.mainStore.toggleEnvelopeSeen({ envelope: this.envelope })
		},
		async onToggleJunk() {
			const removeEnvelope = await this.mainStore.moveEnvelopeToJunk(this.envelope)

			/**
			 * moveEnvelopeToJunk returns true if the envelope should be moved to a different mailbox.
			 *
			 * Our backend (MessageMapper.move) implemented move as copy and delete.
			 * The message is copied to another mailbox and gets a new UID; the message in the current folder is deleted.
			 *
			 * Trigger the delete event here to open the next envelope and remove the current envelope from the list.
			 * The delete event bubbles up to MailboxThread.deleteMessage and is forwarded to Mailbox.onDelete to the actual implementation.
			 *
			 * In Mailbox.onDelete, fetchNextEnvelopes requires the current envelope to find the next envelope.
			 * Therefore, it must run before removing the envelope.
			 */

			if (removeEnvelope) {
				await this.$emit('delete', this.envelope.databaseId)
			}

			await this.mainStore.toggleEnvelopeJunk({
				envelope: this.envelope,
				removeEnvelope,
			})
		},
		toggleSelected() {
			this.$emit('update:selected')
		},
		async forwardSelectedAsAttachment() {
			await this.mainStore.startComposerSession({
				forwardedMessages: [this.envelope.databaseId],
			})
		},
		onReply(replySenderOnly = false) {
			this.$emit('reply', '', false, replySenderOnly)
		},
		async onOpenEditAsNew() {
			await this.mainStore.startComposerSession({
				templateMessageId: this.envelope.databaseId,
				data: this.envelope,
			})
		},
		getTimestamp(momentObject) {
			return momentObject?.minute(0).second(0).millisecond(0).valueOf() || null
		},
		setCustomSnoozeDateTime(event) {
			this.customSnoozeDateTime = new Date(event.target.value)
		},
		setCustomSnooze() {
			this.onSnooze(this.customSnoozeDateTime.valueOf())
		},
		onPrint() {
			this.$emit('print')
		},
	},
}
</script>
<style lang="scss" scoped>
	.source-modal {
		:deep(.modal-container) {
			height: 800px;
		}

		.source-modal-content {
			width: 100%;
			height: 100%;
			overflow-y: scroll !important;
		}
	}

</style>
