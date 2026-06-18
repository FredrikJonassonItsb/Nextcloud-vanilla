<!--
  - SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
  - SPDX-FileCopyrightText: 2026 ITSL AB
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<EnvelopeSkeleton v-draggable-envelope="{
			accountId: data.accountId ? data.accountId : mailbox.accountId,
			mailboxId: data.mailboxId,
			databaseId: data.databaseId,
			threadRootId: data.threadRootId,
			draggableLabel,
			selectedEnvelopes,
			isDraggable,
		}"
		class="list-item-style envelope"
		:class="{seen: data.flags.seen, draft, selected: selected}"
		:to="link"
		:exact="true"
		:data-envelope-id="data.databaseId"
		:name="addresses"
		:details="formatted()"
		:one-line="oneLineLayout"
		:has-attachment="data.flags.hasAttachments === true"
		:message-type="data.itsl.messageType"
		@click.exact="onClick"
		@update:menuOpen="closeMoreAndSnoozeOptions">
		<!-- ITSL: Selection handlers disabled for Roomy density redesign. Re-enable with:
		@click.ctrl.exact.prevent="toggleSelected"
		@click.shift.exact.prevent="onSelectMultiple"
		-->
		<template #icon>
			<div class="avatar-wrapper">
				<template v-if="mailbox.isUnified || mailbox.isItslVirtual">
					<AvatarMessageTypeItsl :message-type="data.itsl?.messageType" :email="avatarEmail" :size="32" />
				</template>
				<template v-else>
					<Avatar :display-name="addresses" :email="avatarEmail" :size="32" />
				</template>
			</div>
		</template>
		<!-- ITSL: Custom #name slot with sender text and line-1 icons as flex siblings -->
		<template #name>
			<span v-if="draft" class="draft-label">[{{ t('mail', 'Draft') }}]</span>
			<span class="envelope-sender-text">{{ addresses }}</span>
			<!-- Line 1 icons - flex sibling of sender text, takes only needed space -->
			<div class="envelope-line1-icons">
				<IconAttachment v-if="data.flags.hasAttachments === true"
					class="line1-icon"
					:size="20" />
				<Reply v-if="data.flags.answered"
					class="line1-icon"
					:size="20" />
				<!-- Important badge: v-if="isImportant" (computed from tag with imapLabel === '$label1') -->
				<div v-if="isImportant"
					class="line1-icon line1-icon--important"
					:data-starred="isImportant ? 'true' : 'false'"
					@click.prevent="hasWriteAcl ? onToggleImportant() : false"
					v-html="importantSvg" /> <!-- eslint-disable-line vue/no-v-html -- Safe: static SVG -->
				<Star v-if="data.flags.flagged"
					fill-color="#f9cf3d"
					:size="20"
					class="line1-icon line1-icon--star"
					:data-starred="data.flags.flagged ? 'true' : 'false'"
					@click.prevent="hasWriteAcl ? onToggleFlagged() : false" />
			</div>
		</template>
		<template #subname>
			<div class="line-two"
				:class="{ 'one-line': oneLineLayout }">
				<div class="envelope__subtitle">
					<!-- ITSL: Reply and Attachment icons moved to line 1 (in #icon slot) -->
					<!-- ITSL: Draft label moved to line 1 (#name slot) - see Step 7 redesign -->
					<span class="envelope__subtitle__subject"
						:class="{'one-line': oneLineLayout }"
						dir="auto">
						<span class="envelope__subtitle__subject__text" :class="{'one-line': oneLineLayout }">
							{{ subjectForSubtitle }}
						</span>
					</span>
				</div>
				<div v-if="data.encrypted || data.previewText"
					class="envelope__preview-text"
					:title="data.summary ? t('mail', 'This summary was AI generated') : null">
					<SparkleIcon v-if="data.summary" :size="15" />
					{{ isEncrypted ? t('mail', 'Encrypted message') : data.summary ? data.summary.trim() : data.previewText.trim() }}
				</div>
			</div>
		</template>
		<!-- ITSL: Removed unread indicator dot (IconBullet) - replaced with blue bar styling -->
		<template #actions>
			<EnvelopePrimaryActions v-if="!moreActionsOpen && !snoozeOptions">
				<ActionButton v-if="hasWriteAcl"
					class="action--primary"
					:close-after-click="true"
					@click.prevent="onToggleFlagged">
					<template #icon>
						<StarOutline v-if="showFavoriteIconVariant"
							:size="24" />
						<Star v-else
							:size="24" />
					</template>
					{{
						data.flags.flagged ? t('mail', 'Unfavorite') : t('mail', 'Favorite')
					}}
				</ActionButton>
				<ActionButton v-if="hasSeenAcl"
					class="action--primary"
					:close-after-click="true"
					@click.prevent="onToggleSeen">
					<template #icon>
						<EmailUnread v-if="showImportantIconVariant"
							:size="24" />
						<EmailRead v-else
							:size="24" />
					</template>
					{{
						data.flags.seen ? t('mail', 'Unread') : t('mail', 'Read')
					}}
				</ActionButton>
				<ActionButton v-if="hasWriteAcl"
					class="action--primary"
					:close-after-click="true"
					@click.prevent="onToggleImportant">
					<template #icon>
						<ImportantIcon :size="24" />
					</template>
					{{
						isImportant ? t('mail', 'Unimportant') : t('mail', 'Important')
					}}
				</ActionButton>
			</EnvelopePrimaryActions>
			<template v-if="!moreActionsOpen && !snoozeOptions">
				<ActionText>
					<template #icon>
						<ClockOutlineIcon :size="20" />
					</template>
					{{
						messageLongDate
					}}
				</ActionText>
				<NcActionSeparator />
				<ActionButton v-if="hasWriteAcl"
					:close-after-click="true"
					@click.prevent="onToggleJunk">
					<template #icon>
						<AlertOctagonIcon :size="20" />
					</template>
					{{
						data.flags.$junk ? t('mail', 'Mark not spam') : t('mail', 'Mark as spam')
					}}
				</ActionButton>
				<ActionButton v-if="hasWriteAcl"
					:close-after-click="true"
					@click.prevent="onOpenTagModal">
					<template #icon>
						<TagIcon :size="20" />
					</template>
					{{ t('mail', 'Edit tags') }}
				</ActionButton>
				<ActionButton v-if="!isSnoozeDisabled && !isSnoozedMailbox"
					:close-after-click="false"
					@click="showSnoozeOptions">
					<template #icon>
						<AlarmIcon :title="t('mail', 'Snooze')"
							:size="20" />
					</template>
					{{
						t('mail', 'Snooze')
					}}
				</ActionButton>
				<ActionButton v-if="!isSnoozeDisabled && isSnoozedMailbox"
					:close-after-click="true"
					@click="onUnSnooze">
					<template #icon>
						<AlarmIcon :title="t('mail', 'Unsnooze')"
							:size="20" />
					</template>
					{{ t('mail', 'Unsnooze') }}
				</ActionButton>
				<ActionButton v-if="hasDeleteAcl"
					:close-after-click="true"
					@click.prevent="onOpenMoveModal">
					<template #icon>
						<OpenInNewIcon :size="20" />
					</template>
					{{ t('mail', 'Move thread') }}
				</ActionButton>
				<ActionButton v-if="showArchiveButton && hasArchiveAcl"
					:close-after-click="true"
					:disabled="disableArchiveButton"
					@click.prevent="onArchive">
					<template #icon>
						<ArchiveIcon :size="20" />
					</template>
					{{ t('mail', 'Archive thread') }}
				</ActionButton>
				<ActionButton v-if="hasDeleteAcl"
					:close-after-click="true"
					@click.prevent="onDelete">
					<template #icon>
						<DeleteIcon :size="20" />
					</template>
					{{ t('mail', 'Delete thread') }}
				</ActionButton>
				<ActionButton :close-after-click="false"
					@click="showMoreActionOptions">
					<template #icon>
						<DotsHorizontalIcon :size="20" />
					</template>
					{{ t('mail', 'More actions') }}
				</ActionButton>
			</template>
			<template v-if="snoozeOptions">
				<ActionButton :close-after-click="false"
					@click="snoozeOptions = false">
					<template #icon>
						<ChevronLeft :size="20" />
					</template>
					{{
						t('mail', 'Back')
					}}
				</ActionButton>

				<NcActionSeparator />

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

				<ActionButton :aria-label="t('mail', 'Set custom snooze')"
					close-after-click
					@click.stop="setCustomSnooze(customSnoozeDateTime)">
					<template #icon>
						<CheckIcon :size="20" />
					</template>
					{{ t('mail', 'Set custom snooze') }}
				</ActionButton>
			</template>
			<template v-if="moreActionsOpen">
				<ActionButton :close-after-click="false"
					@click="moreActionsOpen=false">
					<template #icon>
						<ChevronLeft :size="20" />
					</template>
					{{ t('mail', 'More actions') }}
				</ActionButton>
				<ActionButton :close-after-click="true"
					@click.prevent="onOpenEditAsNew">
					<template #icon>
						<PlusIcon :size="20" />
					</template>
					{{ t('mail', 'Edit as new message') }}
				</ActionButton>
				<ActionButton :close-after-click="true"
					@click.prevent="showEventModal = true">
					<template #icon>
						<IconCreateEvent :size="20" />
					</template>
					{{ t('mail', 'Create event') }}
				</ActionButton>
				<ActionButton :close-after-click="true"
					@click.prevent="showTaskModal = true">
					<template #icon>
						<TaskIcon :size="20" />
					</template>
					{{ t('mail', 'Create task') }}
				</ActionButton>
				<ActionLink :close-after-click="true"
					:href="exportMessageLink">
					<template #icon>
						<DownloadIcon :size="20" />
					</template>
					{{ t('mail', 'Download message') }}
				</ActionLink>
			</template>
		</template>
		<template #tags>
			<!-- ITSL: Tag sorting - assignment tags LEFT, other tags RIGHT -->
			<div class="envelope-tags-container">
				<!-- Left group: Assignment tags (with person icon) -->
				<div class="envelope-tags-left">
					<div v-for="tag in assignmentTags"
						:key="tag.id"
						class="tag-group">
						<AccountIcon :size="12"
							class="tag-group__person-icon"
							:style="{color: tag.color}" />
						<div class="tag-group__bg"
							:style="{'background-color': tag.color}" />
						<span class="tag-group__label"
							:style="{color: tag.color}">
							{{ translateTagDisplayName(tag) }}
						</span>
					</div>
				</div>
				<!-- Right group: Other tags (categories, labels) -->
				<div class="envelope-tags-right">
					<div v-for="tag in otherTags"
						:key="tag.id"
						class="tag-group">
						<div class="tag-group__bg"
							:style="{'background-color': tag.color}" />
						<span class="tag-group__label"
							:style="{color: tag.color}">
							{{ translateTagDisplayName(tag) }}
						</span>
					</div>
				</div>
			</div>
			<MoveModal v-if="showMoveModal"
				:account="account"
				:envelopes="[data]"
				:move-thread="true"
				@move="onMove"
				@close="onCloseMoveModal" />
			<EventModal v-if="showEventModal"
				:envelope="data"
				@close="showEventModal = false" />
			<TaskModal v-if="showTaskModal"
				:envelope="data"
				@close="showTaskModal = false" />
			<TagModal v-if="showTagModal"
				:account="account"
				:envelopes="[data]"
				@close="onCloseTagModal" />
		</template>
	</EnvelopeSkeleton>
</template>
<script>
import {
	NcActionButton as ActionButton,
	NcActionLink as ActionLink,
	NcActionSeparator,
	NcActionInput,
	NcActionText as ActionText,
} from '@nextcloud/vue'
import EnvelopeSkeleton from '../../components/EnvelopeSkeleton.vue'
import AccountIcon from 'vue-material-design-icons/Account.vue'
import AlertOctagonIcon from 'vue-material-design-icons/AlertOctagon.vue'
import Avatar from '../../components/Avatar.vue'
import IconCreateEvent from 'vue-material-design-icons/Calendar.vue'
import SparkleIcon from 'vue-material-design-icons/Creation.vue'
import ClockOutlineIcon from 'vue-material-design-icons/ClockOutline.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import ArchiveIcon from 'vue-material-design-icons/PackageDown.vue'
import TaskIcon from 'vue-material-design-icons/CheckboxMarkedCirclePlusOutline.vue'
import DotsHorizontalIcon from 'vue-material-design-icons/DotsHorizontal.vue'
import importantSvg from '../../../img/important.svg'
import { DraggableEnvelopeDirective } from '../../directives/drag-and-drop/draggable-envelope/index.js'
import { buildRecipients as buildReplyRecipients } from '../../ReplyBuilder.js'
import { shortRelativeDatetime, messageDateTime } from '../../util/shortRelativeDatetime.js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NoTrashMailboxConfiguredError
	from '../../errors/NoTrashMailboxConfiguredError.js'
import logger from '../../logger.js'
import { matchError } from '../../errors/match.js'
import MoveModal from '../../components/MoveModal.vue'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import StarOutline from 'vue-material-design-icons/StarOutline.vue'
import Star from 'vue-material-design-icons/Star.vue'
import Reply from 'vue-material-design-icons/Reply.vue'
import EmailRead from 'vue-material-design-icons/EmailOpen.vue'
import EmailUnread from 'vue-material-design-icons/Email.vue'
import IconAttachment from 'vue-material-design-icons/Paperclip.vue'
import ImportantIcon from './icons/ImportantIcon.vue'
// IconBullet removed - unread indicator dot replaced with blue bar styling
// JunkIcon removed - part of Roomy density redesign
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import TagIcon from 'vue-material-design-icons/Tag.vue'
import TagModal from '../../components/TagModal.vue'
import { getEnvelopeTags as getItslEnvelopeTags } from '../utils/tagHelpers.js' // itsl
import EventModal from '../../components/EventModal.vue'
import TaskModal from '../../components/TaskModal.vue'
import EnvelopePrimaryActions from '../../components/EnvelopePrimaryActions.vue'
import { generateUrl } from '@nextcloud/router'
import { isPgpText } from '../../crypto/pgp.js'
import { mailboxHasRights } from '../../util/acl.js'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import CalendarClock from 'vue-material-design-icons/CalendarClock.vue'
import AlarmIcon from 'vue-material-design-icons/Alarm.vue'
import moment from '@nextcloud/moment'
import { mapState, mapStores } from 'pinia'
import useMainStore from '../../store/mainStore.js'
import { translateTagDisplayName } from '../../util/tag.js'
import AvatarMessageTypeItsl from './message/AvatarMessageTypeItsl.vue' // itsl
import { MESSAGE_TYPES, MESSAGE_DIRECTION } from '../store/constants.js' // itsl
import { resolveMessageDisplayName } from '../utils/participantUtils.js' // itsl
import useItslStore from '../store/itslStore.js' // itsl

export default {
	// eslint-disable-next-line vue/match-component-file-name -- Intentionally named 'Envelope' to override upstream component
	name: 'Envelope',
	components: {
		AccountIcon,
		AlertOctagonIcon,
		Avatar,
		AvatarMessageTypeItsl, // itsl
		IconCreateEvent,
		CheckIcon,
		ChevronLeft,
		DeleteIcon,
		ArchiveIcon,
		TaskIcon,
		DotsHorizontalIcon,
		EnvelopePrimaryActions,
		EventModal,
		TaskModal,
		EnvelopeSkeleton,
		ImportantIcon,
		// JunkIcon removed - part of Roomy density redesign
		ActionButton,
		MoveModal,
		OpenInNewIcon,
		PlusIcon,
		TagIcon,
		TagModal,
		SparkleIcon,
		Star,
		StarOutline,
		EmailRead,
		EmailUnread,
		IconAttachment,
		Reply,
		ActionLink,
		ActionText,
		DownloadIcon,
		ClockOutlineIcon,
		NcActionSeparator,
		NcActionInput,
		CalendarClock,
		AlarmIcon,
	},
	directives: {
		draggableEnvelope: DraggableEnvelopeDirective,
	},
	props: {
		withReply: {
			// "Reply" action should only appear in envelopes from the envelope list
			// (Because in thread envelopes, this action is already set as primary button of this menu)
			type: Boolean,
			default: true,
		},
		data: {
			type: Object,
			required: true,
		},
		mailbox: {
			type: Object,
			required: true,
		},
		selectMode: {
			type: Boolean,
			default: false,
		},
		selected: {
			type: Boolean,
			default: false,
		},
		selectedEnvelopes: {
			type: Array,
			required: false,
			default: () => [],
		},
		hasMultipleAccounts: {
			type: Boolean,
			default: false,
		},
	},
	data() {
		return {
			importantSvg,
			showMoveModal: false,
			showEventModal: false,
			showTaskModal: false,
			showTagModal: false,
			moreActionsOpen: false,
			snoozeOptions: false,
			customSnoozeDateTime: new Date(moment().add(2, 'hours').minute(0).second(0).valueOf()),
			overwriteOneLineMobile: false,
			// hoveringAvatar removed - selection UI disabled for Roomy density redesign
		}
	},
	computed: {
		...mapStores(useMainStore, useItslStore),
		...mapState(useMainStore, [
			'isSnoozeDisabled',
		]),
		messageLongDate() {
			return messageDateTime(new Date(this.data.dateInt))
		},
		oneLineLayout() {
			return this.overwriteOneLineMobile ? false : this.mainStore.getPreference('layout-mode', 'vertical-split') === 'no-split'
		},
		hasMultipleRecipients() {
			if (!this.account) {
				console.error('account is undefined', {
					accountId: this.data.accountId,
				})
			}
			const recipients = buildReplyRecipients(this.envelope, {
				label: this.account.name,
				email: this.account.emailAddress,
			})
			return recipients.to.concat(recipients.cc).length > 1
		},
		draft() {
			return this.data.flags.draft
		},
		account() {
			const accountId = this.data.accountId
			return this.mainStore.getAccount(accountId)
		},
		link() {
			if (this.draft) {
				return undefined
			}
			return {
				name: 'message',
				params: {
					mailboxId: this.$route.params.mailboxId,
					...(this.$route.params.filter && { filter: this.$route.params.filter }),
					threadId: this.data.databaseId,
				},
				query: this.$route.query,
			}
		},
		addresses() {
			const messageType = this.data.itsl?.messageType
			const direction = this.data.itsl?.messageDirection
			const sdkHeader = this.data.itsl?.sdk?.messageHeader

			// SDK: pick counterparty by direction
			if (messageType === MESSAGE_TYPES.SDK.id) {
				const sdkParty = direction === MESSAGE_DIRECTION.INCOMING
					? sdkHeader?.sender
					: sdkHeader?.recipient
				const result = resolveMessageDisplayName(messageType, {
					email: this.data.from[0]?.email || '',
					label: this.data.from[0]?.label || '',
					sdkParty,
				})
				return result.raw || '?'
			}

			// SECURE: pick counterparty by direction
			if (messageType === MESSAGE_TYPES.SECURE.id) {
				const contact = direction === MESSAGE_DIRECTION.OUTGOING
					? this.data.to?.[0]
					: this.data.from?.[0]
				const result = resolveMessageDisplayName(messageType, {
					email: contact?.email || '',
					label: contact?.label || '',
				})
				return result.raw || '?'
			}

			// Sent mailbox: show recipients
			if (this.mailbox.specialRole === 'sent' || this.account.sentMailboxId === this.mailbox.databaseId) {
				const recipients = [this.data.to, this.data.cc].flat().filter(Boolean).map((recipient) => {
					const result = resolveMessageDisplayName(messageType, {
						email: recipient.email,
						label: recipient.label,
						internalMailboxName: this.itslStore.getInternalMailboxName(recipient.email),
					})
					return result.raw
				}).filter(Boolean)
				return recipients.length > 0 ? recipients.join(', ') : t('mail', 'Blind copy recipients only')
			}

			// Default (inbox): show sender
			const from = this.data.from?.[0]
			const result = resolveMessageDisplayName(messageType, {
				email: from?.email || '',
				label: from?.label || '',
				internalMailboxName: this.itslStore.getInternalMailboxName(from?.email),
			})
			return result.raw || '?'
		},
		avatarEmail() {
			// Show first recipients' avatar in a sent mailbox (or undefined when sent to Bcc only)
			if (this.mailbox.specialRole === 'sent') {
				const recipients = [this.data.to, this.data.cc].flat().map(function(recipient) {
					return recipient.email
				})
				return recipients.length > 0 ? recipients[0] : ''
			}

			// Show sender avatar in other mailbox types
			if (this.data.from.length > 0) {
				return this.data.from[0].email
			} else {
				return ''
			}
		},
		showArchiveButton() {
			return this.account.archiveMailboxId !== null
		},
		disableArchiveButton() {
			return this.account.archiveMailboxId !== null
				&& this.account.archiveMailboxId === this.mailbox.databaseId
		},
		showFavoriteIconVariant() {
			return this.data.flags.flagged
		},
		showImportantIconVariant() {
			return this.data.flags.seen
		},
		isEncrypted() {
			return this.data.encrypted // S/MIME
				|| (this.data.previewText && isPgpText(this.data.previewText)) // PGP/Mailvelope
		},
		isImportant() {
			return this.mainStore
				.getEnvelopeTags(this.data.databaseId)
				.some((tag) => tag.imapLabel === '$label1')
		},
		tags() {
			// itsl: Use account-specific tags for correct SDKMC colors
			return getItslEnvelopeTags(this.data.accountId, this.data.databaseId, this.mailbox.isUnified || this.mailbox.isItslVirtual)
		},
		/**
		 * Assignment tags (with person icon) - displayed LEFT
		 * Tags where isAssignmentTag is true (SDKMC user assignments)
		 *
		 * @return {Array}
		 */
		assignmentTags() {
			return this.tags.filter(tag => tag.isAssignmentTag)
		},
		/**
		 * Other tags (categories, labels) - displayed RIGHT
		 * Tags where isAssignmentTag is false or undefined
		 *
		 * @return {Array}
		 */
		otherTags() {
			return this.tags.filter(tag => !tag.isAssignmentTag)
		},
		draggableLabel() {
			let label = this.data.subject
			const sender = this.data.from[0]?.label ?? this.data.from[0]?.email
			if (sender) {
				label += ` (${sender})`
			}
			return label
		},
		isDraggable() {
			if (this.draft) {
				return false
			}
			return mailboxHasRights(this.mailbox, 'te')
		},
		/**
		 * Subject of envelope or "No Subject".
		 *
		 * @return {string}
		 */
		subjectForSubtitle() {
			// We have to use || here (instead of ??) because the subject might be '', null
			// or undefined.
			if (this.data.itsl.messageType === MESSAGE_TYPES.FAX.id) {
				return ''
			} else {
				return this.data.subject || this.t('mail', 'No subject')
			}
		},
		/**
		 * Link to download the whole message (.eml).
		 *
		 * @return {string}
		 */
		exportMessageLink() {
			return generateUrl('/apps/mail/api/messages/{id}/export', {
				id: this.data.databaseId,
			})
		},
		hasSeenAcl() {
			return mailboxHasRights(this.mailbox, 's')
		},
		hasArchiveAcl() {
			const hasDeleteSourceAcl = () => {
				return mailboxHasRights(this.mailbox, 'te')
			}
			const hasCreateDestinationAcl = () => {
				return mailboxHasRights(this.archiveMailbox, 'i')

			}
			return hasDeleteSourceAcl() && hasCreateDestinationAcl()
		},
		hasDeleteAcl() {
			return mailboxHasRights(this.mailbox, 'te')
		},
		hasWriteAcl() {
			return mailboxHasRights(this.mailbox, 'w')
		},
		archiveMailbox() {
			return this.mainStore.getMailbox(this.account.archiveMailboxId)
		},
		isSnoozedMailbox() {
			return this.mailbox.databaseId === this.account.snoozeMailboxId
		},
		reminderOptions() {
			const currentDateTime = moment()

			// Same day 18:00 PM (hidden if after 17:00 PM now)
			const laterTodayTime = (currentDateTime.hour() < 17)
				? moment().hour(18)
				: null

			// Tomorrow 08:00 AM
			const tomorrowTime = moment().add(1, 'days').hour(8)

			// Saturday 08:00 AM (hidden if Friday, Saturday or Sunday now)
			const thisWeekendTime = (currentDateTime.day() > 0 && currentDateTime.day() < 5)
				? moment().day(6).hour(8)
				: null

			// Next Monday 08:00 AM (hidden if Sunday now)
			const nextWeekTime = (currentDateTime.day() !== 0)
				? moment().add(1, 'weeks').day(1).hour(8)
				: null

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
	mounted() {
		this.onWindowResize()

		window.addEventListener('resize', this.onWindowResize)
	},
	methods: {
		translateTagDisplayName,
		setSelected(value) {
			if (this.selected !== value) {
				this.$emit('update:selected', value)
			}
		},
		formatted() {
			return shortRelativeDatetime(new Date(this.data.dateInt * 1000))
		},
		unselect() {
			if (this.selected) {
				this.$emit('update:selected', false)
			}
		},
		toggleSelected() {
			this.$emit('update:selected', !this.selected)
		},
		async onClick(event) {
			if (!event.ctrlKey && this.draft && !event.defaultPrevented) {
				try {
					await this.mainStore.startComposerSession({
						data: {
							...this.data,
							draftId: this.data.databaseId,
						},
						templateMessageId: this.data.databaseId,
					})
				} catch (error) {
					logger.error('Could not open draft', { error })
					this.mainStore.removeEnvelopeMutation({ id: this.data.databaseId })
					showError(t('mail', 'Draft no longer exists'))
				}
				// Sync Drafts folder to refresh stale entries (both success and error paths)
				const account = this.mainStore.getAccount(this.data.accountId)
				if (account?.draftsMailboxId) {
					this.mainStore.syncEnvelopes({
						mailboxId: account.draftsMailboxId, query: '', init: false,
					})
				}
			}
		},
		onSelectMultiple() {
			this.$emit('select-multiple')
		},
		onToggleImportant() {
			this.mainStore.toggleEnvelopeImportant(this.data)
		},
		onToggleFlagged() {
			this.mainStore.toggleEnvelopeFlagged(this.data)
		},
		onToggleSeen() {
			this.mainStore.toggleEnvelopeSeen({ envelope: this.data })
		},
		async onToggleJunk() {
			const removeEnvelope = await this.mainStore.moveEnvelopeToJunk(this.data)

			if (this.isImportant) {
				await this.mainStore.toggleEnvelopeImportant(this.data)
			}

			if (!this.data.flags.seen) {
				await this.mainStore.toggleEnvelopeSeen({ envelope: this.data })
			}

			/**
			 * moveEnvelopeToJunk returns true if the envelope should be moved to a different mailbox.
			 *
			 * Our backend (MessageMapper.move) implemented move as copy and delete.
			 * The message is copied to another mailbox and gets a new UID; the message in the current folder is deleted.
			 *
			 * Trigger the delete event here to open the next envelope and remove the current envelope from the list.
			 * The delete event bubbles up to Mailbox.onDelete to the actual implementation.
			 *
			 * In Mailbox.onDelete, fetchNextEnvelopes requires the current envelope to find the next envelope.
			 * Therefore, it must run before removing the envelope.
			 */

			if (removeEnvelope) {
				await this.$emit('delete', this.data.databaseId)
			}

			await this.mainStore.toggleEnvelopeJunk({
				envelope: this.data,
				removeEnvelope,
			})
		},
		async onDelete() {
			// Remove from selection first
			this.setSelected(false)
			// Delete
			this.$emit('delete', this.data.databaseId)

			try {
				await this.mainStore.deleteThread({
					envelope: this.data,
				})
			} catch (error) {
				showError(await matchError(error, {
					[NoTrashMailboxConfiguredError.getName()]() {
						return t('mail', 'No trash mailbox configured')
					},
					default(error) {
						logger.error('could not delete message', error)
						return t('mail', 'Could not delete message')
					},
				}))
			}
		},
		showMoreActionOptions() {
			this.snoozeOptions = false
			this.moreActionsOpen = true
		},
		showSnoozeOptions() {
			this.snoozeOptions = true
			this.moreActionsOpen = false
		},
		closeMoreAndSnoozeOptions() {
			this.snoozeOptions = false
			this.moreActionsOpen = false
		},
		async onArchive() {
			// Remove from selection first
			this.setSelected(false)
			// Archive
			this.$emit('archive', this.data.databaseId)

			try {
				await this.mainStore.moveThread({
					envelope: this.data,
					destMailboxId: this.account.archiveMailboxId,
				})
			} catch (error) {
				logger.error('could not archive message', error)
				showError(t('mail', 'Could not archive message'))
			}
		},
		async onSnooze(timestamp) {
			// Remove from selection first
			this.setSelected(false)

			if (!this.account.snoozeMailboxId) {
				await this.mainStore.createAndSetSnoozeMailbox(this.account)
			}

			try {
				await this.mainStore.snoozeThread({
					envelope: this.data,
					unixTimestamp: timestamp / 1000,
					destMailboxId: this.account.snoozeMailboxId,
				})
				showSuccess(t('mail', 'Thread was snoozed'))
			} catch (error) {
				logger.error('could not snooze thread', error)
				showError(t('mail', 'Could not snooze thread'))
			}
		},
		async onUnSnooze() {
			// Remove from selection first
			this.setSelected(false)

			// Store envelope reference before unsnoozing (needed for sync)
			const envelope = this.data

			try {
				await this.mainStore.unSnoozeThread({
					envelope,
				})
				// Sync handled by $onAction in initITSL.js
				showSuccess(t('mail', 'Thread was unsnoozed'))
			} catch (error) {
				logger.error('Could not unsnooze thread', error)
				showError(t('mail', 'Could not unsnooze thread'))
			}
		},
		async onOpenEditAsNew() {
			await this.mainStore.startComposerSession({
				templateMessageId: this.data.databaseId,
				data: this.data,
			})
		},
		onOpenMoveModal() {
			this.showMoveModal = true
		},
		onOpenEventModal() {
			this.showEventModal = true
		},
		onMove() {
			this.$emit('move')
		},
		onCloseMoveModal() {
			this.showMoveModal = false
		},
		onOpenTagModal() {
			this.showTagModal = true
		},
		onCloseTagModal() {
			this.showTagModal = false
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
		onWindowResize() {
			const widthOutput = window.innerWidth

			if (widthOutput <= 700) {
				this.overwriteOneLineMobile = true
			} else {
				this.overwriteOneLineMobile = false
			}
		},
	},
}
</script>
<style lang="scss" scoped>
.mail-message-account-color {
	position: absolute;
	left: 0px;
	width: 2px;
	height: 69px;
	z-index: 1;
}

.envelope {
	.app-content-list-item-icon {
		height: 32px; // ITSL: Match Roomy density mode avatar size
	}

	&__subtitle {
		display: flex;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
		align-items: center;
		&__subject {
			flex: 1;
			overflow: hidden;
			text-overflow: ellipsis;
			white-space: nowrap;
			line-height: var(--default-line-height);
		}
	}
	&__preview-text {
		color: var(--color-text-maxcontrast);
		overflow: hidden;
		font-weight: initial;
		max-height: calc(var(--default-font-size) * var(--default-line-height) * 2);

		/* Weird CSS hacks to make text ellipsize without white-space: nowrap */
		display: -webkit-box;
		-webkit-line-clamp: 2;
		-webkit-box-orient: vertical;

		.material-design-icon {
			display: inline;

			position: relative;
			top: 2px;
		}
	}
}

.icon-important {
	:deep(path) {
		fill: #ffcc00;
		stroke: var(--color-main-background);
	}
	.list-item:hover &,
	.list-item:focus &,
	.list-item.active & {
		:deep(path) {
			stroke: var(--color-background-dark);
		}
	}

	// In message list, but not the one in the action menu
	&.app-content-list-item-star {
		background-image: none;
		left: 1px;
		top: 8px;
		opacity: 1;

		&:hover,
		&:focus {
			opacity: 0.5;
		}
	}
}
.important-one-line.app-content-list-item-star:deep() {
	top: 4px !important;
	left: 2px;
}

.app-content-list-item-select-checkbox {
	display: inline-block;
	vertical-align: middle;
	position: absolute;
	left: 33px;
	top: 35px;
	z-index: 50; // same as icon-starred
}

.list-item-style:not(.seen) {
	font-weight: bold;
}

.list-item-style {
	.draft {
		line-height: 130%;

		em {
			font-style: italic;
		}
	}
}
/* .junk-icon-style removed - JunkIcon component removed in Roomy density redesign */

.icon-attachment {
	-ms-filter: 'progid:DXImageTransform.Microsoft.Alpha(Opacity=25)';
	opacity: 0.25;
}

:deep(.action--primary) {
	.material-design-icon {
		margin-bottom: -14px;
	}
}
.tag-group__label {
	margin: 0 7px;
	z-index: 2;
	font-size: calc(var(--default-font-size) * 0.8);
	font-weight: bold;
	padding-inline-start: 2px;
	padding-inline-end: 2px;
	white-space: nowrap;
}
.tag-group__bg {
	position: absolute;
	width: 100%;
	height: 100%;
	top: 0;
	left: 0;
	opacity: 15%;
}
.tag-group {
	display: inline-flex;
	align-items: center;
	border-radius: var(--border-radius-pill);
	position: relative;
	margin-inline-end: 1px;
	overflow: hidden;
	text-overflow: ellipsis;
}
.tag-group__person-icon {
	z-index: 2;
	flex-shrink: 0;
	opacity: 0.8;
	padding-inline-start: 6px;
}
.list-item__wrapper:deep() {
	list-style: none;
}
/* ITSL: Star and Important icon styling moved to _envelope-overrides.scss (.envelope-line1-icons) */
:deep(.svg svg) {
	height: 16px;
	width: 16px;
}
/* ITSL: .seen-icon-style and .attachment-icon-style removed - icons moved to line 1 */
:deep(.list-item__anchor) {
	margin-top: 6px;
	margin-bottom: 6px;
}
:deep(.line-two__subtitle) {
	display: flex;
	flex-basis: 100%;
	padding-inline-start: 40px;
	width: 450px;
}

:deep(.line-one__title) {
	flex-direction: row;
	display: flex;
	width: 200px;
}
.line-two.one-line {
	display: flex;
	overflow: hidden;
	align-items: center;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.line-two {
	margin-inline-end: 35px;
}
.list-item-style:not(.seen) .line-two {
	margin-inline-end: 42px;
}

.envelope__subtitle__subject.one-line {
	display: flex;
	align-items: center;
	height: calc(var(--default-font-size) * var(--default-line-height));

	&::after {
		content: '\00B7';
		margin: 12px;
	}
}

.envelope__subtitle__subject__text.one-line {
	max-width: 300px;
	display: inline-block;
	text-overflow: ellipsis;
	overflow: hidden;
}
/* .app-content-list-item-avatar-selected and .hover-active removed - selection UI disabled in Roomy density redesign */

.envelope__subtitle {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.envelope__preview-text {
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	max-width: 100%;
	display: block;
}
</style>
