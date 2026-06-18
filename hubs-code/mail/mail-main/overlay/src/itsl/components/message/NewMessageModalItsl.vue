<!--
  - SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<Modal v-if="showMessageComposer"
		:size="modalSize"
		:name="modalTitle ? modalTitle : ' '"
		:label-id="'modal-label'"
		:additional-trap-elements="additionalTrapElements"
		@close="$event.type === 'click' ? onClose() : onMinimize()">
		<div class="modal-content">
			<h2 id="modal-label" class="visually-hidden">
				{{ modalTitle || t('mail', 'Message Composer') }}
			</h2>
			<div class="left-pane">
				<NcButton class="maximize-button"
					variant="tertiary-no-background"
					:aria-label="t('mail', 'Maximize composer')"
					:title="largerModal ? t('mail', 'Collapse composer') : t('mail', 'Maximize composer')"
					@click="onMaximize">
					<template #icon>
						<MaximizeIcon v-if="!largerModal" :size="20" />
						<DefaultComposerIcon v-else :size="20" />
					</template>
				</NcButton>
				<NcButton class="minimize-button"
					variant="tertiary-no-background"
					:aria-label="t('mail', 'Minimize composer')"
					:title="t('mail', 'Minimize composer')"
					@click="onMinimize">
					<template #icon>
						<MinimizeIcon :size="20" />
					</template>
				</NcButton>

				<KeepAlive>
					<EmptyContent v-if="error"
						:name="t('mail', 'Error sending your message')"
						class="empty-content"
						role="alert">
						<p>{{ error }}</p>
						<template #action>
							<NcButton variant="tertiary" :aria-label="t('mail', 'Go back')" @click="error = undefined">
								{{ t('mail', 'Go back') }}
							</NcButton>
							<NcButton variant="tertiary" :aria-label="t('mail', 'Retry')" @click="onSend">
								{{ t('mail', 'Retry') }}
							</NcButton>
						</template>
					</EmptyContent>
					<Loading v-else-if="uploadingAttachments" :hint="t('mail', 'Uploading attachments …')" role="alert" />
					<Loading v-else-if="sending" :hint="t('mail', 'Sending …')" role="alert" />
					<EmptyContent v-else-if="warning"
						:name="t('mail', 'Warning sending your message')"
						class="empty-content"
						role="alert">
						<template #description>
							{{ warning }}
						</template>
						<template #action>
							<NcButton variant="tertiary" :aria-label="t('mail', 'Go back')" @click="warning = undefined">
								{{ t('mail', 'Go back') }}
							</NcButton>
							<NcButton variant="tertiary" :aria-label="t('mail', 'Send anyway')" @click="onForceSend">
								{{ t('mail', 'Send anyway') }}
							</NcButton>
						</template>
					</EmptyContent>

					<ComposerItsl ref="composer"
						:from-account="composerData.accountId"
						:from-alias="composerData.aliasId"
						:to="composerData.to"
						:cc="composerData.cc"
						:bcc="composerData.bcc"
						:subject="composerData.subject"
						:attachments-data="composerData.attachments"
						:body="composerDataBodyAsTextInstance"
						:editor-body="convertEditorBody(composerData)"
						:in-reply-to-message-id="composerData.inReplyToMessageId"
						:reply-to="composerData.replyTo"
						:forward-from="composerData.forwardFrom"
						:send-at="composerData.sendAt * 1000"
						:forwarded-messages="forwardedMessages"
						:smart-reply="smartReply"
						:can-save-draft="canSaveDraft"
						:saving-draft="savingDraft"
						:draft-saved="draftSaved"
						:smime-sign="composerData.smimeSign"
						:smime-encrypt="composerData.smimeEncrypt"
						:is-first-open="modalFirstOpen"
						:request-mdn="composerData.requestMdn"
						:accounts="accounts"
						:itsl="composerData.itsl"
						@update:itsl="patchComposerData({ itsl: $event })"
						@update:from-account="patchComposerData({ accountId: $event })"
						@update:from-alias="patchComposerData({ aliasId: $event })"
						@update:to="patchComposerData({ to: $event })"
						@update:cc="patchComposerData({ cc: $event })"
						@update:bcc="patchComposerData({ bcc: $event })"
						@update:subject="patchComposerData({ subject: $event })"
						@update:attachments-data="patchComposerData({ attachments: $event })"
						@update:editor-body="patchEditorBody"
						@update:send-at="patchComposerData({ sendAt: $event / 1000 })"
						@update:smime-sign="patchComposerData({ smimeSign: $event })"
						@update:smime-encrypt="patchComposerData({ smimeEncrypt: $event })"
						@update:request-mdn="patchComposerData({ requestMdn: $event })"
						@ready="onComposerReady"
						@draft="onDraft"
						@discard-draft="discardDraft"
						@upload-attachment="onAttachmentUploading"
						@upload-additional-attachment="onAdditionalAttachmentUploading"
						@send="onSend"
						@show-toolbar="handleShow" />
				</KeepAlive>
			</div>

			<!-- <div v-show="showRecipientPane && !warning && !error" class="right-pane">
				<RecipientInfo />
			</div> -->
		</div>
	</Modal>
</template>
<script>
import {
	NcButton,
	NcEmptyContent as EmptyContent,
	NcModal as Modal,
} from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'

import logger from '../../../logger.js'
import ComposerItsl from './ComposerItsl.vue'
import { UNDO_DELAY } from '../../../store/constants.js'
import { matchError } from '../../../errors/match.js'
import NoSentMailboxConfiguredError from '../../../errors/NoSentMailboxConfiguredError.js'
import ManyRecipientsError from '../../../errors/ManyRecipientsError.js'
import AttachmentMissingError from '../../../errors/AttachmentMissingError.js'
import SubjectMissingError from '../../../errors/SubjectMissingError.js'
import Loading from '../../../components/Loading.vue'
import MinimizeIcon from 'vue-material-design-icons/Minus.vue'
import MaximizeIcon from 'vue-material-design-icons/ArrowExpand.vue'
import DefaultComposerIcon from 'vue-material-design-icons/ArrowCollapse.vue'
import { deleteDraft, saveDraft, updateDraft } from '../../../service/DraftService.js'
import useOutboxStore from '../../../store/outboxStore.js'
import { mapStores, mapState, mapActions } from 'pinia'
import RecipientInfo from '../../../components/RecipientInfo.vue'
import useMainStore from '../../../store/mainStore.js'
import { messageBodyToTextInstance } from '../../../util/message.js'
import { toPlain } from '../../../util/text.js'

export default {
	name: 'NewMessageModalItsl',
	components: {
		NcButton,
		ComposerItsl,
		EmptyContent,
		Loading,
		Modal,
		MinimizeIcon,
		MaximizeIcon,
		DefaultComposerIcon,
		RecipientInfo,
	},
	props: {
		accounts: {
			type: Array,
			required: true,
		},
	},
	data() {
		return {
			additionalTrapElements: ['#reference-picker'],
			original: undefined,
			draftsPromise: Promise.resolve(),
			attachmentsPromise: Promise.resolve(),
			canSaveDraft: true,
			savingDraft: false,
			draftSaved: false,
			uploadingAttachments: false,
			sending: false,
			error: undefined,
			warning: undefined,
			modalFirstOpen: true,
			cookedComposerData: undefined,
			changed: false,
			largerModal: false,
			isLargeScreen: window.innerWidth >= 1024,
			isMaximized: false,
			recipient: {
				name: '',
				email: '',
			},
		}
	},
	computed: {
		...mapStores(useOutboxStore, useMainStore),
		...mapState(useMainStore, ['showMessageComposer']),
		...mapActions(useMainStore, ['getPreference']),
		composerDataBodyAsTextInstance() {
			return messageBodyToTextInstance(this.composerData)
		},
		modalTitle() {
			if (this.composerMessage.type === 'outbox') {
				return t('mail', 'Edit message')
			}
			if (this.composerData.draftId !== undefined) {
				return t('mail', 'Draft')
			}
			if (this.composerData.replyTo) {
				return t('mail', 'Reply')
			}
			if (this.composerData.forwardFrom) {
				return t('mail', 'Forward')
			}
			return null
		},
		hasContactDetailsApi() {
			return !!window.OCA?.Contacts?.mountContactDetails
		},
		showRecipientPane() {
			return this.hasContactDetailsApi
				&& this.composerData.to
				&& this.composerData.to.length > 0
				&& !this.isMaximized
		},
		composerMessage() {
			return this.mainStore.composerMessage
		},
		composerData() {
			return this.mainStore.composerMessage?.data ?? {}
		},
		forwardedMessages() {
			return this.composerMessage?.options?.forwardedMessages ?? []
		},
		smartReply() {
			return this.composerData?.smartReply ?? null
		},
		modalSize() {
			return this.isLargeScreen && this.hasContactDetailsApi && this.composerData.to && this.composerData.to.length > 0
				? 'large'
				: (this.largerModal ? 'large' : 'normal')
		},
	},
	created() {
		const id = this.composerData?.id
		if (id) {
			this.draftsPromise = Promise.resolve(id)
		}
		window.addEventListener('beforeunload', this.onBeforeUnload)
	},
	async mounted() {
		await this.$nextTick()
		this.updateCookedComposerData()
		await this.openModalSize()
		window.addEventListener('resize', this.checkScreenSize)
	},
	beforeDestroy() {
		window.removeEventListener('beforeunload', this.onBeforeUnload)
		window.removeEventListener('resize', this.checkScreenSize)
	},
	methods: {
		checkScreenSize() {
			this.isLargeScreen = window.innerWidth >= 1024
		},
		async onComposerReady() {
			// CKEditor is now fully initialized — save the IMAP draft to local DB
			// immediately so the old envelope is removed from the Drafts list right away,
			// instead of waiting for the 5-second auto-save debounce.
			if (this.composerData?.draftId && !this.composerData?.id) {
				this.updateCookedComposerData()
				if (this.cookedComposerData) {
					await this.onDraft(this.cookedComposerData)
				}
			}
		},
		async openModalSize() {
			try {
				const sizePreference = this.mainStore.getPreference('modalSize')
				this.largerModal = sizePreference === 'large'
			} catch (error) {
				console.error('Error getting modal size preference', error)
			}
		},
		async onMaximize() {
			this.isMaximized = !this.isMaximized
			this.largerModal = !this.largerModal
			try {
				await this.mainStore.savePreference({
					key: 'modalSize',
					value: this.largerModal ? 'large' : 'normal',
				})
			} catch (error) {
				console.error('Failed to save preference', error)
			}
		},
		async onMinimize() {
			this.isMaximized = false
			this.modalFirstOpen = false

			await this.mainStore.hideMessageComposerMutation()
			if (!this.mainStore.composerMessageIsSaved && this.changed) {
				await this.onDraft(this.cookedComposerData, { showToast: true })
			}

		},
		handleShow(element) {
			this.additionalTrapElements.push(element)
		},
		/**
		 * @param data Message data
		 * @param {object=} opts Options
		 * @param {boolean=} opts.showToast Show a toast after saving
		 * @return {Promise<number>} Draft id promise
		 */
		// TODO: when there's no draft is saved, Cloing wont move ie case 2 doesn't work
		onDraft(data, { showToast = false } = {}) {
			if (!this.composerMessage) {
				logger.info('Ignoring draft because there is no message anymore', { data })
				return this.draftsPromise
			}
			if (this.composerMessage.type === 'outbox') {
				// Outbox messages are already persisted server-side. Calling the
				// Draft API with an outbox ID returns 404, whose handler clears
				// composerData.id — causing duplicates on re-send.
				return this.draftsPromise
			}
			this.changed = true

			this.draftsPromise = this.draftsPromise.then(async (id) => {
				this.savingDraft = true
				this.draftSaved = false
				try {
					let idToReturn
					const dataForServer = this.getDataForServer(data, true)
					if (!id) {
						const { id } = await saveDraft(dataForServer)
						dataForServer.id = id
						// handleDraft just deleted the old IMAP draft — remove its stale envelope
						if (dataForServer.draftId) {
							this.mainStore.removeEnvelopeMutation({ id: dataForServer.draftId })
						}
						await this.mainStore.patchComposerData({ id, draftId: null, itsl: data.itsl })
						this.canSaveDraft = true
						this.draftSaved = true

						idToReturn = id
					} else {
						dataForServer.id = id
						await updateDraft(dataForServer)
						this.canSaveDraft = true
						this.draftSaved = true
						idToReturn = id
					}

					this.mainStore.setComposerMessageSavedMutation(true)

					if (showToast) {
						if (this.composerMessage.type === 'outbox') {
							showSuccess(t('mail', 'Message saved'))
						} else {
							showSuccess(t('mail', 'Draft saved'))
						}
					}

					if (idToReturn !== undefined) {
						return idToReturn
					}
				} catch (error) {
					logger.error('Could not save draft', { error })
					this.mainStore.setComposerIndicatorDisabledMutation(false)

					if (showToast) {
						if (this.composerMessage.type === 'outbox') {
							showError(t('mail', 'Failed to save message'))
						} else {
							showError(t('mail', 'Failed to save draft'))
						}
					}

					// If the local draft was deleted (e.g. by background flush to IMAP after 5min idle),
					// clear the stale id so the next auto-save creates a fresh local draft via POST.
					// This works safely because draftId is already null after the first successful save.
					if (error?.response?.status === 404 && this.composerData?.id) {
						await this.mainStore.patchComposerData({ id: undefined })
						this.canSaveDraft = true
						return undefined
					}

					// For non-404 errors, preserve the id to retry on the PUT path.
					this.canSaveDraft = false
					return this.composerData?.id
				} finally {
					this.savingDraft = false
				}
			})

			return this.draftsPromise
		},
		getDataForServer(data) {
			const dataForServer = {
				...data,
				id: data.id,
				accountId: data.accountId,
				to: data.to,
				cc: data.cc,
				bcc: data.bcc,
				attachments: data.attachments,
				aliasId: data.aliasId,
				inReplyToMessageId: data.inReplyToMessageId,
				sendAt: data.sendAt,
				draftId: this.composerData?.draftId,
			}

			if (data.isHtml) {
				delete dataForServer.bodyPlain
			} else {
				delete dataForServer.bodyHtml
			}

			return dataForServer
		},
		onAttachmentUploading(done, data) {
			this.attachmentsPromise = this.attachmentsPromise
				.then(done)
				.then(() => this.onDraft(data))
				.then(() => logger.debug('attachments uploaded'))
				.catch((error) => logger.error('could not upload attachments', { error }))
		},
		onAdditionalAttachmentUploading(done, data) {
			delete this.composerData.itsl.additionalAttachment
			delete this.composerData.itsl.additionalAttachmentName
			this.onAttachmentUploading(done, data)
		},
		async onSend(data, force = false) {
			logger.debug('sending message', { data })

			await this.attachmentsPromise
			this.uploadingAttachments = false
			this.sending = true
			try {
				const now = new Date().getTime()
				for (const attachment of data.attachments) {
					if (!attachment.type) {
						// todo move to backend: https://github.com/nextcloud/mail/issues/6227
						attachment.type = 'local'
					}
				}
				const dataForServer = this.getDataForServer({
					...data,
					id: await this.draftsPromise,
					sendAt: data.sendAt ? data.sendAt : Math.floor((now + UNDO_DELAY) / 1000),
				})
				if (dataForServer.sendAt < Math.floor((now + UNDO_DELAY) / 1000)) {
					dataForServer.sendAt = Math.floor((now + UNDO_DELAY) / 1000)
				}
				if (!force && !data.subject?.trim() && data.itsl?.messageType !== 'fax_message') {
					throw new SubjectMissingError()
				}

				if (!force && data.attachments.length === 0) {
					const lines = toPlain(messageBodyToTextInstance(data)).value.toLowerCase().split('\n')
					const wordAttachment = t('mail', 'attachment').toLowerCase()
					const wordAttached = t('mail', 'attached').toLowerCase()
					for (const line of lines) {
						if (line.startsWith('>') || line.startsWith('--')) {
							break
						}
						if (line.includes(wordAttachment) || line.includes(wordAttached)) {
							throw new AttachmentMissingError()
						}
					}
				}

				if (!this.composerData.id) {
					// This is a new message
					const { id } = await saveDraft(dataForServer)
					dataForServer.id = id
					await this.outboxStore.enqueueFromDraft({
						draftMessage: dataForServer,
						id,
					})
				} else if (this.composerMessage.type === 'outbox') {
					// This is an outbox message (reopened via Undo)
					dataForServer.id = this.composerData.id
					await this.outboxStore.updateMessage({
						message: dataForServer,
						id: this.composerData.id,
					})
				} else {
					// This is a draft
					await updateDraft(dataForServer)
					dataForServer.id = this.composerData.id
					await this.outboxStore.enqueueFromDraft({
						draftMessage: dataForServer,
						id: this.composerData.id,
					})
				}

				if (!data.sendAt || data.sendAt < Math.floor((now + UNDO_DELAY) / 1000)) {
					// Awaiting here would keep the modal open for a long time and thus block the user
					this.outboxStore.sendMessageWithUndo({ id: dataForServer.id, replyToDatabaseId: data.replyToDatabaseId, accountId: data.accountId, itsl: this.composerData.itsl }).catch((error) => {
						logger.debug('Could not send message', { error })
					})
				}
				if (dataForServer.id) {
					// Remove old draft envelope
					this.mainStore.removeMessageMutation({ id: dataForServer.id })
				}
				await this.mainStore.stopComposerSession()
				this.$emit('close')
			} catch (error) {
				this.error = await matchError(error, {
					[NoSentMailboxConfiguredError.getName()]() {
						logger.error('could not send message', { error })
						return t('mail', 'No sent mailbox configured. Please pick one in the account settings.')
					},
					[ManyRecipientsError.getName()]() {
						logger.error('could not send message', { error })
						return t('mail', 'You are trying to send to many recipients in To and/or Cc. Consider using Bcc to hide recipient addresses.')
					},
					// eslint-disable-next-line n/handle-callback-err
					default(error) {
						logger.error('could not send message', { error })
					},
				})
				this.warning = await matchError(error, {
					[SubjectMissingError.getName()]() {
						logger.info('showing the missing subject warning', { error })
						return t('mail', 'Your message has no subject. Do you want to send it anyway?')
					},
					[AttachmentMissingError.getName()]() {
						logger.info('showing the did you forgot an attachment warning', { error })
						return t('mail', 'You mentioned an attachment. Did you forget to add it?')
					},
					// eslint-disable-next-line n/handle-callback-err
					default(error) {
						logger.warn('could not send message', { error })
					},
				})
			} finally {
				this.sending = false
			}

			// Sync sent mailbox when it's currently open
			const account = this.mainStore.getAccount(data.accountId)
			if (account && parseInt(this.$route.params.mailboxId, 10) === account.sentMailboxId) {
				setTimeout(() => {
					this.mainStore.syncEnvelopes({
						mailboxId: account.sentMailboxId,
						query: '',
						init: false,
					})
				}, 500)
			}
			// Sync Drafts folder — the draft was consumed by sending
			if (account?.draftsMailboxId) {
				setTimeout(() => {
					this.mainStore.syncEnvelopes({
						mailboxId: account.draftsMailboxId,
						query: '',
						init: false,
					})
				}, 500)
			}
		},
		async onForceSend() {
			await this.onSend(this.cookedComposerData, true)
		},
		recipientToRfc822(recipient) {
			if (recipient.email === recipient.label) {
				// From mailto or sender without proper label
				return recipient.email
			} else if (recipient.label === '') {
				// Invalid label
				return recipient.email
			} else if (recipient.email.search(/^[a-zA-Z]+:/) === 0) {
				// Group integration
				return recipient.email
			} else {
				// Proper layout with label
				return `"${recipient.label}" <${recipient.email}>`
			}
		},
		async discardDraft() {
			let id = await this.draftsPromise
			const isOutbox = this.composerMessage.type === 'outbox'
			if (isOutbox) {
				id = this.composerMessage.data.id
			}

			// It's safe to stop the session and ultimately destroy this component as only data
			// local this this function is accessed afterwards
			await this.mainStore.stopComposerSession()

			try {
				if (isOutbox) {
					await this.outboxStore.deleteMessage({ id })
				} else {
					deleteDraft(id)
				}
				showSuccess(t('mail', 'Message discarded'))
			} catch (error) {
				logger.error('Could not discard draft', { error })
				showError(t('mail', 'Could not discard message'))
			}
		},
		convertEditorBody(composerData) {
			if (composerData.isHtml) {
				return composerData.bodyHtml
			}

			return composerData.bodyPlain
		},
		patchEditorBody(editorBody) {
			if (this.composerData.isHtml) {
				this.patchComposerData({ bodyHtml: editorBody })
			} else {
				this.patchComposerData({ bodyPlain: editorBody })
			}
		},
		updateCookedComposerData() {
			if (!this.$refs.composer) {
				// Composer is not rendered yet
				return
			}

			// Extract data to save drafts while the composer is not rendered.
			// This is hacky but there is no other way for now.
			this.cookedComposerData = this.$refs.composer.getMessageData()
		},
		async patchComposerData(data) {
			this.changed = true
			this.updateCookedComposerData()
			await this.mainStore.patchComposerData(data)
		},
		onBeforeUnload(e) {
			if (this.canSaveDraft && this.changed) {
				e.preventDefault()
				e.returnValue = true
				this.mainStore.showMessageComposerMutation()
			} else {
				console.info('No unsaved changes. See you!')
			}
		},
		async onClose() {
			this.mainStore.setComposerIndicatorDisabledMutation(true)
			try {
				await this.onMinimize()

				// End the session only if all unsaved changes have been saved
				if (this.canSaveDraft && ((this.changed && this.draftSaved) || !this.changed)) {
					logger.debug('Closing composer session due to close button click')
					const accountId = this.composerData.accountId
					await this.mainStore.stopComposerSession({
						restoreOriginalSendAt: true,
						moveToImap: this.changed,
						id: this.composerData.id,
					})
					// Sync Drafts folder so the new IMAP draft appears with the correct ID
					const account = this.mainStore.getAccount(accountId)
					if (account?.draftsMailboxId) {
						setTimeout(() => {
							this.mainStore.syncEnvelopes({
								mailboxId: account.draftsMailboxId,
								query: '',
								init: false,
							})
						}, 500)
					}
				} else {
					this.mainStore.setComposerIndicatorDisabledMutation(false)
				}
			} catch (error) {
				logger.error('Failed to close composer session', { error })
				if (this.composerMessage) {
					this.mainStore.setComposerIndicatorDisabledMutation(false)
				}
			}
		},
	},
}

</script>

<style lang="scss" scoped>
@media only screen and (max-width: 600px) {
	:deep(.modal-container) {
		max-width: 80%;
	}
}

:deep(.modal-wrapper .modal-container) {
	overflow-y: auto !important;
	overflow-x: hidden !important;
	// from original Modal max-height
	height: 700px;
	// Max editor + modal height
	max-height: 90% !important;
}

.minimize-button {
	float: right;
	position: absolute;
	top: 4px;
	inset-inline-end: 63px;
	z-index: 2;
}

.maximize-button {
	float: right;
	position: absolute;
	top: 4px;
	inset-inline-end: 33px;
	z-index: 2;
}
.empty-content{
	height: 100%;
	display: flex;
}
:deep(.wrapper) {
	height: 100%;
}
.modal-content {
	display: flex;
	height: 100%;
	flex-direction: row;
	width: 100%;
}

.left-pane {
	flex: 1;
	overflow-y: auto;
}

.right-pane {
	flex: 0 0 400px;
	overflow-y: auto;
	padding-inline-start: 5px;
	border-left: 1px solid #ccc;
	@media (max-width: 1023px) {
		display: none;
	}
}

.modal-content.with-recipient .left-pane {
	flex: 1;
}
.modal-content .left-pane {
	width: 100%;
}
:deep(.visually-hidden) {
	position: absolute !important;
	width: 1px !important;
	height: 1px !important;
	padding: 0 !important;
	margin: -1px !important;
	overflow: hidden !important;
	clip: rect(0, 0, 0, 0) !important;
	white-space: nowrap !important;
	border: 0 !important;
}
</style>
