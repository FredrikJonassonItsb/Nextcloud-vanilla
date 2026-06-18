<!--
  - SPDX-FileCopyrightText: 2026 ITSL AB <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="thread-info-sidebar">
		<!-- 1. Assigned To Section -->
		<SidebarSection :title="t('mail', 'Assigned to')"
			:add-button="hasWriteAcl"
			:count="assignees.length || null"
			:reduced-header-gap="assignees.length > 0"
			:popover-open.sync="assignmentsPopoverOpen">
			<AssigneeList v-if="assignees.length > 0"
				:assignees="assignees"
				:editable="hasWriteAcl"
				@remove="removeAssignee"
				@search="searchByTag" />
			<span v-else class="thread-info-sidebar__empty">{{ t('mail', 'No one assigned') }}</span>
			<template #popover>
				<TagPopover :key="assignmentsPopoverKey"
					:envelopes="thread"
					filter-mode="assignments"
					@close="assignmentsPopoverOpen = false" />
			</template>
		</SidebarSection>

		<!-- 3. Tags Section -->
		<SidebarSection :title="t('mail', 'Tags')"
			:add-button="hasWriteAcl"
			:count="labelTags.length || null"
			:popover-open.sync="labelsPopoverOpen">
			<TagList v-if="labelTags.length > 0"
				:tags="labelTags"
				:editable="hasWriteAcl"
				@remove="removeTag"
				@search="searchByTag" />
			<span v-else class="thread-info-sidebar__empty">{{ t('mail', 'No tags') }}</span>
			<template #popover>
				<TagPopover :key="labelsPopoverKey"
					:envelopes="thread"
					filter-mode="labels"
					@close="labelsPopoverOpen = false" />
			</template>
		</SidebarSection>

		<!-- 4. Mailbox Section -->
		<SidebarSection :title="t('mail', 'Mailbox')">
			<template v-if="messageType" #header-badge>
				<MessageTypeBadge :message-type="messageType" compact />
			</template>
			<MailboxInfo :envelope="envelope"
				:thread="thread"
				:show-move-action="hasWriteAcl"
				@move="openMoveDialog" />
		</SidebarSection>

		<!-- 5. Status Section -->
		<SidebarSection :title="t('mail', 'Status')" reduced-header-gap>
			<StatusRow :label="t('mail', 'Favorite')"
				:active="isFavorite"
				icon="star"
				active-color="var(--itsl-starred-active)"
				@toggle="toggleFavorite" />
			<StatusRow :label="t('mail', 'ImportantLabel')"
				:active="isImportant"
				icon="important"
				active-color="var(--itsl-important-flag)"
				@toggle="toggleImportant" />
			<SnoozeStatusRow v-if="!isSnoozeDisabled"
				:snoozed-until="snoozedUntil"
				@snooze="handleSnooze"
				@clear="handleClearSnooze" />
		</SidebarSection>

		<!-- 6. Thread Participants Section -->
		<SidebarSection :title="t('mail', 'Thread Participants')">
			<ThreadParticipantList :thread="thread" :message-type="messageType" />
		</SidebarSection>

		<!-- 7. Actions -->
		<ActionButtons :envelope="envelope"
			:thread="thread"
			:disable-processed="allInArchive"
			@processed="markAsProcessed"
			@delete="deleteThread" />

		<!-- Move Modal -->
		<MoveModal v-if="showMoveModal"
			:account="account"
			:envelopes="[envelope]"
			:move-thread="true"
			@move="onMove"
			@close="showMoveModal = false" />
	</div>
</template>

<script>
import { set as vueSet } from 'vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { mapStores, mapState } from 'pinia'
import useMainStore from '../../store/mainStore.js'
import useItslStore from '../store/itslStore.js'
import { hiddenTags } from '../../components/tags.js'
import { translateTagDisplayName } from '../../util/tag.js'
import { snoozeThread, unSnoozeThread } from '../../service/ThreadService.js'
import { setThreadTag, removeThreadTag, setThreadFlags } from '../services/ThreadTagService.js'

// Sub-components
import SidebarSection from './sidebar/SidebarSection.vue'
import MessageTypeBadge from './sidebar/MessageTypeBadge.vue'
import AssigneeList from './sidebar/AssigneeList.vue'
import TagList from './sidebar/TagList.vue'
import StatusRow from './sidebar/StatusRow.vue'
import SnoozeStatusRow from './sidebar/SnoozeStatusRow.vue'
import MailboxInfo from './sidebar/MailboxInfo.vue'
import ThreadParticipantList from './sidebar/ThreadParticipantList.vue'
import ActionButtons from './sidebar/ActionButtons.vue'
import TagPopover from './TagPopover.vue'
import MoveModal from '../../components/MoveModal.vue'

export default {
	name: 'ThreadInfoSidebar',
	components: {
		SidebarSection,
		MessageTypeBadge,
		AssigneeList,
		TagList,
		StatusRow,
		SnoozeStatusRow,
		MailboxInfo,
		ThreadParticipantList,
		ActionButtons,
		TagPopover,
		MoveModal,
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
	},
	data() {
		return {
			assignmentsPopoverOpen: false,
			assignmentsPopoverKey: 0,
			labelsPopoverOpen: false,
			labelsPopoverKey: 0,
			showMoveModal: false,
			favoriteLoading: false,
			importantLoading: false,
		}
	},
	computed: {
		...mapStores(useMainStore, useItslStore),
		...mapState(useMainStore, ['isSnoozeDisabled']),

		account() {
			return this.mainStore.getAccount(this.envelope.accountId)
		},

		messageType() {
			return this.envelope?.itsl?.messageType
		},

		// Tags from all envelopes in thread
		allTags() {
			const pendingRemovals = this.itslStore.pendingTagRemovals
			const pendingAdditions = this.itslStore.pendingTagAdditions
			const tagMap = new Map()

			this.thread.forEach(env => {
				const accountTags = this.itslStore.getTagsForAccount(env.accountId)
				const envPendingRemovals = pendingRemovals[env.databaseId] || {}
				const envTags = this.mainStore.getEnvelopeTags(env.databaseId)
					.filter(t => t && !(t.imapLabel in envPendingRemovals))

				envTags.forEach(tag => {
					if (!tagMap.has(tag.imapLabel)) {
						const sdkmcTag = accountTags.find(t => t.imapLabel === tag.imapLabel)
						tagMap.set(tag.imapLabel, sdkmcTag || tag)
					}
				})

				// Add pending additions
				const envPendingAdditions = pendingAdditions[env.databaseId] || {}
				for (const imapLabel of Object.keys(envPendingAdditions)) {
					if (!tagMap.has(imapLabel)) {
						const sdkmcTag = accountTags.find(t => t.imapLabel === imapLabel)
						if (sdkmcTag) {
							tagMap.set(imapLabel, sdkmcTag)
						}
					}
				}
			})

			return Array.from(tagMap.values())
				.filter(tag => tag.imapLabel !== '$label1' && !(tag.displayName.toLowerCase() in hiddenTags))
		},

		assignees() {
			return this.allTags
				.filter(tag => tag.isAssignmentTag === true)
				.sort((a, b) => a.displayName.localeCompare(b.displayName))
		},

		labelTags() {
			return this.allTags
				.filter(tag => tag.isAssignmentTag !== true)
				.sort((a, b) => a.displayName.localeCompare(b.displayName))
		},

		isFavorite() {
			return this.thread.some(env => env.flags?.flagged)
		},

		isImportant() {
			return this.thread.some(env =>
				this.mainStore.getEnvelopeTags(env.databaseId)
					.some(tag => tag.imapLabel === '$label1'),
			)
		},

		snoozedUntil() {
			// Look for snoozedUntil on any envelope in thread
			for (const env of this.thread) {
				if (env.itsl?.snoozedUntil) {
					return env.itsl.snoozedUntil
				}
			}
			return null
		},

		participantCount() {
			const participants = new Set()
			this.thread.forEach(env => {
				if (env.from?.[0]?.email) participants.add(env.from[0].email)
				env.to?.forEach(r => r.email && participants.add(r.email))
				env.cc?.forEach(r => r.email && participants.add(r.email))
			})
			return participants.size
		},

		hasWriteAcl() {
			return this.thread.some(env => {
				const mailbox = this.mainStore.getMailbox(env.mailboxId)
				return !mailbox?.myAcls || mailbox.myAcls.includes('w')
			})
		},

		allInArchive() {
			if (!this.account?.archiveMailboxId) return false
			return this.thread.every(env => env.mailboxId === this.account.archiveMailboxId)
		},
	},
	watch: {
		assignmentsPopoverOpen(newVal) {
			if (newVal) {
				this.assignmentsPopoverKey++ // Reset TagPopover state when opening
			}
		},
		labelsPopoverOpen(newVal) {
			if (newVal) {
				this.labelsPopoverKey++ // Reset TagPopover state when opening
			}
		},
	},
	methods: {
		translateTagDisplayName,

		async removeAssignee(tagId) {
			const tag = this.assignees.find(t => t.id === tagId)
			if (tag) {
				await this.removeTagFromThread(tag)
			}
		},

		async removeTag(tagId) {
			const tag = this.labelTags.find(t => t.id === tagId)
			if (tag) {
				await this.removeTagFromThread(tag)
			}
		},

		async removeTagFromThread(tag) {
			const timestamps = new Map()
			const originalTags = new Map()

			// STEP 1: Per-envelope optimistic updates
			for (const envelope of this.thread) {
				const timestamp = this.itslStore.addPendingTagRemoval(envelope.databaseId, tag.imapLabel)
				timestamps.set(envelope.databaseId, timestamp)

				// Find any tags with matching imapLabel (handles cross-account desync)
				const tagsToRemove = (envelope.tags || []).filter(tagId => {
					const t = this.mainStore.getTag(tagId)
					return t?.imapLabel === tag.imapLabel
				})

				if (tagsToRemove.length > 0) {
					originalTags.set(envelope.databaseId, [...envelope.tags])
					vueSet(envelope, 'tags', envelope.tags.filter(id => !tagsToRemove.includes(id)))
				}

				this.itslStore.removeFromTagSearchLists(this.mainStore, envelope.databaseId, tag.imapLabel)
			}

			// STEP 2: Single bulk API call - always send all thread IDs
			try {
				const ids = this.thread.map(env => env.databaseId)
				await removeThreadTag(ids, tag.imapLabel)

				// STEP 3: Post-API side effects - Priority inbox update if $label1
				if (tag.imapLabel === '$label1') {
					for (const envelope of this.thread) {
						this.itslStore.updateEnvelopePriorityList(this.mainStore, envelope, false)
					}
				}
				// Virtual mailbox update for assignment tag changes
				for (const envelope of this.thread) {
					this.itslStore.updateVirtualMailboxLists(this.mainStore, envelope, tag.imapLabel, false)
				}
			} catch (error) {
				// STEP 4: Rollback on error
				console.error('Failed to remove tag', error)
				for (const envelope of this.thread) {
					const original = originalTags.get(envelope.databaseId)
					if (original) {
						vueSet(envelope, 'tags', original)
					}
					const timestamp = timestamps.get(envelope.databaseId)
					this.itslStore.clearPendingTagRemoval(envelope.databaseId, tag.imapLabel, timestamp)
				}
			}
		},

		async addTagToThread(imapLabel) {
			const accountTags = this.itslStore.getTagsForAccount(this.envelope.accountId)
			const tag = accountTags.find(t => t.imapLabel === imapLabel)

			if (!tag) {
				console.error('[ITSL] addTagToThread: tag not found for account', {
					imapLabel,
					accountId: this.envelope.accountId,
					availableTags: accountTags.map(t => ({ id: t.id, imapLabel: t.imapLabel })),
				})
				return
			}

			const timestamps = new Map()
			const originalTags = new Map()

			// STEP 1: Optimistic UI update for ALL envelopes
			for (const envelope of this.thread) {
				const timestamp = this.itslStore.addPendingTagAddition(envelope.databaseId, imapLabel)
				timestamps.set(envelope.databaseId, timestamp)

				// Check if any tag with this imapLabel already exists (handles cross-account desync)
				const existingTagIds = (envelope.tags || []).filter(tagId => {
					const t = this.mainStore.getTag(tagId)
					return t?.imapLabel === imapLabel
				})

				originalTags.set(envelope.databaseId, [...(envelope.tags || [])])

				if (existingTagIds.length === 0) {
					// No existing tag - add correct one
					vueSet(envelope, 'tags', [...(envelope.tags || []), tag.id])
				} else if (!existingTagIds.includes(tag.id)) {
					// Has wrong account's tag - replace with correct one
					console.warn('[ITSL] addTagToThread: replacing wrong tag ID', {
						envelopeId: envelope.databaseId,
						wrongTagIds: existingTagIds,
						correctTagId: tag.id,
						imapLabel,
					})
					vueSet(envelope, 'tags', [
						...envelope.tags.filter(id => !existingTagIds.includes(id)),
						tag.id,
					])
				}
			}

			// STEP 2: Single bulk API call
			try {
				const ids = this.thread.map(env => env.databaseId)
				const returnedTag = await setThreadTag(ids, imapLabel)

				// STEP 3: Post-API side effects
				// Validate returned tag matches expected account (defensive)
				if (returnedTag.id !== tag.id) {
					console.error('[ITSL] addTagToThread: API returned unexpected tag', {
						expected: { id: tag.id, imapLabel: tag.imapLabel },
						received: { id: returnedTag.id, imapLabel: returnedTag.imapLabel, emailAddress: returnedTag.emailAddress },
					})
					// Don't add the wrong tag to mainStore
				} else if (!this.mainStore.getTag(returnedTag.id)) {
					this.mainStore.addTagMutation({ tag: returnedTag })
				}
				// Priority inbox update if $label1
				if (imapLabel === '$label1') {
					for (const envelope of this.thread) {
						this.itslStore.updateEnvelopePriorityList(this.mainStore, envelope, true)
					}
				}
				// Virtual mailbox update for assignment tag changes
				for (const envelope of this.thread) {
					this.itslStore.updateVirtualMailboxLists(this.mainStore, envelope, imapLabel, true)
				}
			} catch (error) {
				// STEP 4: Rollback on error
				console.error('Failed to add tag', error)
				for (const envelope of this.thread) {
					const original = originalTags.get(envelope.databaseId)
					if (original) {
						vueSet(envelope, 'tags', original)
					}
					const timestamp = timestamps.get(envelope.databaseId)
					this.itslStore.clearPendingTagAddition(envelope.databaseId, imapLabel, timestamp)
				}
			}
		},

		/**
		 * Search by tag via sidebar click.
		 * Signals the mixin via store, does NOT navigate.
		 * @param {string} imapLabel - Tag imapLabel
		 */
		searchByTag(imapLabel) {
			const tag = this.allTags.find(t => t.imapLabel === imapLabel)
			if (tag) {
				this.itslStore.triggerSidebarTagClick(tag.imapLabel, translateTagDisplayName(tag))
			}
			// NO NAVIGATION - sidebar never navigates
		},

		async toggleFavorite() {
			if (this.favoriteLoading) return

			this.favoriteLoading = true
			const newState = !this.isFavorite
			const originalStates = new Map()

			// STEP 1: Optimistic UI update for ALL envelopes
			for (const envelope of this.thread) {
				originalStates.set(envelope.databaseId, envelope.flags?.flagged)
				this.mainStore.flagEnvelopeMutation({ envelope, flag: 'flagged', value: newState })
			}

			// STEP 2: Single bulk API call
			try {
				const ids = this.thread.map(env => env.databaseId)
				await setThreadFlags(ids, { flagged: newState })
			} catch (error) {
				// STEP 3: Rollback on error
				console.error('Failed to toggle favorite', error)
				for (const envelope of this.thread) {
					const original = originalStates.get(envelope.databaseId)
					this.mainStore.flagEnvelopeMutation({ envelope, flag: 'flagged', value: original })
				}
			} finally {
				this.favoriteLoading = false
			}
		},

		async toggleImportant() {
			if (this.importantLoading) return

			this.importantLoading = true
			const importantLabel = '$label1'

			try {
				if (this.isImportant) {
					// Remove important - use existing removeTagFromThread
					const accountTags = this.itslStore.getTagsForAccount(this.envelope.accountId)
					const importantTag = accountTags.find(t => t.imapLabel === importantLabel)
					if (importantTag) {
						await this.removeTagFromThread(importantTag)
					} else {
						console.error('[ITSL] toggleImportant: cannot remove - tag not found for account', {
							imapLabel: importantLabel,
							accountId: this.envelope.accountId,
							isImportant: this.isImportant,
						})
					}
				} else {
					// Add important - use addTagToThread
					await this.addTagToThread(importantLabel)
				}
			} finally {
				this.importantLoading = false
			}
		},

		async handleSnooze(timestamp) {
			if (!this.account.snoozeMailboxId) {
				await this.mainStore.createAndSetSnoozeMailbox(this.account)
			}

			// Get current mailbox before snoozing
			const currentMailboxId = this.$route.params.mailboxId
			const snoozeMailboxId = this.account.snoozeMailboxId

			// Get all envelopes in the thread before removing
			const threadEnvelopes = this.mainStore.getEnvelopesByThreadRootId(
				this.envelope.accountId,
				this.envelope.threadRootId,
			)

			// Remove ALL thread envelopes from store to prevent duplicates after sync
			threadEnvelopes.forEach(env => {
				this.mainStore.removeEnvelopeMutation({ id: env.databaseId })
			})

			try {
				// Use thread-level snooze (snoozes entire thread at once)
				await snoozeThread(this.envelope.databaseId, timestamp / 1000, snoozeMailboxId)
				showSuccess(this.t('mail', 'Thread was snoozed'))

				// Sync the snooze mailbox to ensure the moved thread is visible
				await this.mainStore.syncEnvelopes({
					mailboxId: snoozeMailboxId,
					query: '',
					init: true,
				})

				// Stay in current mailbox
				this.$router.push({
					name: 'mailbox',
					params: { mailboxId: currentMailboxId || 'unified' },
				})
			} catch (error) {
				// Restore all envelopes on error
				this.mainStore.addEnvelopesMutation({ envelopes: threadEnvelopes })
				console.error('Could not snooze thread', error)
				showError(this.t('mail', 'Could not snooze thread'))
			}
		},

		async handleClearSnooze() {
			// snoozeSrcMailboxId is unreliable for syncing (sdkmc applies one value
			// to all thread messages) but valid as a navigation hint
			const srcMailboxId = this.envelope.itsl?.snoozeSrcMailboxId
			const threadRootId = this.envelope.threadRootId
			const accountId = this.envelope.accountId
			const savedEnvelope = { ...this.envelope }

			// Get all envelopes in the thread before removing
			const threadEnvelopes = this.mainStore.getEnvelopesByThreadRootId(accountId, threadRootId)

			// Remove ALL thread envelopes from store to prevent duplicates after sync
			threadEnvelopes.forEach(env => {
				this.mainStore.removeEnvelopeMutation({ id: env.databaseId })
			})

			try {
				await unSnoozeThread(this.envelope.databaseId)
				showSuccess(this.t('mail', 'Thread was unsnoozed'))

				// Sync ALL inbox + sent mailboxes (not just snoozeSrcMailboxId)
				await this.itslStore.syncAfterUnsnooze(this.mainStore, savedEnvelope)

				// Navigate: use snoozeSrcMailboxId if available (best hint for where
				// messages went), fall back to inbox
				const destMailboxId = srcMailboxId
					|| this.mainStore.getMailboxes(accountId)
						.find(mb => mb.specialRole === 'inbox')?.databaseId
					|| 'unified'
				await this.$router.push({
					name: 'mailbox',
					params: { mailboxId: destMailboxId },
				})

				// Navigate to the thread if found
				const envelopes = this.mainStore.getEnvelopesByThreadRootId(accountId, threadRootId)
				if (envelopes.length > 0) {
					const firstEnvelope = envelopes[0]
					await this.$router.push({
						name: 'message',
						params: {
							mailboxId: destMailboxId,
							threadId: firstEnvelope.databaseId,
						},
					})
				}
			} catch (error) {
				// Restore all envelopes on error
				this.mainStore.addEnvelopesMutation({ envelopes: threadEnvelopes })
				console.error('Could not unsnooze thread', error)
				showError(this.t('mail', 'Could not unsnooze thread'))
			}
		},

		openMoveDialog() {
			this.showMoveModal = true
		},

		async onMove() {
			this.showMoveModal = false

			// Get current mailbox before navigating
			const currentMailboxId = this.$route.params.mailboxId || 'unified'

			// Navigate back to mailbox after move
			await this.$router.push({
				name: 'mailbox',
				params: { mailboxId: currentMailboxId },
			})

			// Sync source mailbox to remove stale envelope data
			await this.mainStore.syncEnvelopes({
				mailboxId: currentMailboxId,
				query: '',
				init: true,
			})
		},

		navigateToInbox() {
			const currentMailboxId = this.$route.params.mailboxId
			let targetMailboxId

			// Stay in unified/priority inbox if that's where we are
			if (currentMailboxId === 'unified' || currentMailboxId === 'priority') {
				targetMailboxId = currentMailboxId
			} else {
				// Navigate to account's inbox
				const inbox = this.mainStore.getInbox(this.envelope.accountId)
				targetMailboxId = inbox?.databaseId || 'unified'
			}

			this.$router.push({
				name: 'mailbox',
				params: { mailboxId: targetMailboxId },
			})
		},

		async markAsProcessed() {
			// Move to archive
			if (!this.account.archiveMailboxId) {
				showError(this.t('mail', 'No archive mailbox configured'))
				return
			}

			try {
				for (const envelope of this.thread) {
					await this.mainStore.moveMessage({
						id: envelope.databaseId,
						destMailboxId: this.account.archiveMailboxId,
					})
				}
				showSuccess(this.t('mail', 'Thread archived'))
				this.navigateToInbox()
			} catch (error) {
				console.error('Could not archive thread', error)
				showError(this.t('mail', 'Could not archive thread'))
			}
		},

		async deleteThread() {
			try {
				for (const envelope of this.thread) {
					await this.mainStore.deleteMessage({ id: envelope.databaseId })
				}
				showSuccess(this.t('mail', 'Thread deleted'))
				this.navigateToInbox()
			} catch (error) {
				console.error('Could not delete thread', error)
				showError(this.t('mail', 'Could not delete thread'))
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.thread-info-sidebar {
	width: 185px;
	min-width: 185px;
	flex-shrink: 0;
	background: var(--color-main-background);
	border-left: 1px solid var(--color-border);
	border-radius: 8px;
	overflow: hidden; // Let parent scroll
	align-self: stretch; // Stretch to fill parent height
}

.thread-info-sidebar__empty {
	display: block;
	font-size: 13px;
	line-height: 1;
	color: var(--color-text-maxcontrast);
}
</style>
