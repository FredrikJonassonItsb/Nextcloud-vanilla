<!--
  - SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="message-composer">
		<div v-if="messageTypeInfo" class="message-composer__header">
			<component :is="messageTypeInfo.icon" class="message-composer__icon" />
			<span class="message-composer__label">{{ messageTypeInfo.label }}</span>
		</div>

		<NcReferencePickerModal v-if="isPickerAvailable && isPickerOpen"
			id="reference-picker"
			@submit="onPicked"
			@cancel="closePicker" />
		<div class="composer-fields composer-fields__from mail-account">
			<label class="label" for="from">
				{{ t('mail', 'From') }}
			</label>
			<div class="composer-fields--custom">
				<NcSelect id="from"
					input-id="from"
					:value="selectedAlias"
					:options="aliases"
					label="name"
					:get-option-key="(option)=>option.selectId"
					:searchable="false"
					:placeholder="t('mail', 'Select account')"
					:aria-label-combobox="t('mail', 'Select account')"
					:clear-on-select="false"
					:append-to-body="false"
					:selectable="(option)=> {
						return option.selectable}"
					@option:selected="onAliasChange">
					<template #option="option">
						<div v-if="option.description" class="option-container">
							<span class="option-name">{{ option.name }}</span>
							<span class="option-description">{{ option.description }}</span>
						</div>
						<template v-else>{{ option.name }}</template>
					</template>

					<template #selected-option="option">
						<div class="selected-ellipsis">
							{{ option.description && !option.emailAddress.endsWith('@sdk') && !option.emailAddress.endsWith('@personlig') && !option.emailAddress.endsWith('@gruppbox') ? `${option.name} (${option.description})` : option.name }}
						</div>
					</template>
				</NcSelect>
			</div>
		</div>

		<!-- New SDK message START -->
		<template v-if="selectedMessageType === MESSAGE_TYPES.SDK.id">
			<div class="composer-fields">
				<div class="composer-fields--custom">
					<NcSelect v-model="organizationAddress"
						:options="organizationAddressesAvailable"
						:searchable="true"
						:input-label="t('mail', 'Recipient Organization')"
						:placeholder="t('mail', 'Recipient Organization')"
						label="name"
						:filter-by="addressFilter"
						:loading="orgSelectionAvailable"
						@update:modelValue="updateOrgSelect">
						<template #option="option">
							<div class="option-container">
								<span class="option-name">{{ option.name }}</span>
								<span class="option-description">{{ option.address }}</span>
							</div>
						</template>
					</NcSelect>
				</div>
			</div>
			<div class="composer-fields">
				<div class="composer-fields--custom">
					<NcSelect ref="functionAddressRef"
						v-model="functionAddress"
						:options="functionAddressesAvailable"
						:searchable="true"
						:input-label="t('mail', 'Function Address')"
						label="name"
						:placeholder="t('mail', 'Function Address')"
						:filter-by="addressFilter"
						@update:modelValue="updateFuncAddrSelect">
						<template #option="option">
							<div class="option-container">
								<span class="option-name">{{ option.name }}</span>
								<span class="option-description">{{ option.address }}</span>
							</div>
						</template>
						<template #no-options>
							{{ t('mail', 'No available addresses') }}
						</template>
					</NcSelect>
				</div>
			</div>
		</template>
		<!-- New SDK message END -->

		<!-- New Internal message START -->
		<template v-if="selectedMessageType === MESSAGE_TYPES.INTERNAL.id">
			<div class="composer-fields">
				<div class="composer-fields__label">
					<label class="label" for="email">
						{{ t('mail', 'Local recipient') }}
					</label>
				</div>
				<div class="composer-fields--custom">
					<NcSelect ref="personalAddresses"
						v-model="email"
						:options="emailOptions"
						:searchable="true"
						:placeholder="t('mail', 'Local recipient')"
						:reduce="option => option.value"
						label="name"
						:filter-by="emailFilter"
						@update:modelValue="updateEmailSelect">
						<template #option="option">
							<div v-if="option" class="option-container">
								<span class="option-name">{{ option.name }}</span>
								<span class="option-description">{{ option.description }}</span>
							</div>
						</template>

						<template #selected-option="option">
							<div class="selected-ellipsis">
								{{ option.name }}
							</div>
						</template>
					</NcSelect>
				</div>
			</div>
		</template>
		<!-- New Internal message END -->

		<!-- New Secure Email START -->
		<template v-if="selectedMessageType === MESSAGE_TYPES.SECURE.id">
			<div class="composer-fields">
				<div class="composer-fields__label">
					<label class="label" for="notification">
						{{ t('mail', 'E-mail to notify') }}
					</label>
				</div>
				<div class="composer-fields--custom">
					<input id="notification"
						v-model="notification"
						type="text"
						name="notification"
						autocomplete="off"
						:placeholder="t('mail', 'user@example.com')"
						@input="saveDraftDebounced">
				</div>
			</div>
			<div class="composer-fields">
				<div class="composer-fields__label">
					<label class="label">{{ t('mail', 'Security level') }}</label>
				</div>
				<div class="composer-fields--custom loa-radio-group">
					<ActionRadio v-model="loaLevel"
						:value="1"
						name="loaLevel"
						:disabled="userObligatedToProvideSsn">
						{{ t('mail', 'LOA-1') }}
					</ActionRadio>
					<ActionRadio v-model="loaLevel"
						:value="2"
						name="loaLevel"
						:disabled="userObligatedToProvideSsn">
						{{ t('mail', 'LOA-2 (SMS)') }}
					</ActionRadio>
					<ActionRadio v-model="loaLevel" :value="3" name="loaLevel">
						{{ t('mail', 'LOA-3 (BankID)') }}
					</ActionRadio>
				</div>
			</div>
			<div v-if="loaLevel === 2" class="composer-fields">
				<div class="composer-fields__label">
					<label class="label" for="sms-number">
						{{ t('mail', 'SMS number') }}
					</label>
				</div>
				<div class="composer-fields--custom">
					<vue-tel-input v-model="smsNumber"
						input-id="sms-number"
						name="smsNumber"
						mode="international"
						:preferred-countries="['SE']"
						:input-options="{ placeholder: t('mail', 'SMS number'), required: true }"
						@input="saveDraftDebounced" />
				</div>
			</div>
			<div v-if="loaLevel === 3 || userObligatedToProvideSsn" class="composer-fields">
				<div class="composer-fields__label">
					<label class="label" for="ssn">
						{{ t('mail', 'Personal identity number') }}
					</label>
				</div>
				<div class="composer-fields--custom">
					<input id="ssn"
						v-model="ssn"
						type="text"
						name="ssn"
						autocomplete="off"
						required
						:placeholder="t('mail', 'YYYYMMDD-XXXX')"
						@input="saveDraftDebounced">
				</div>
			</div>
		</template>
		<!-- New Secure Email END -->

		<!-- New Fax message START -->
		<template v-if="selectedMessageType === MESSAGE_TYPES.FAX.id">
			<div class="composer-fields">
				<div class="composer-fields__label">
					<label class="label" for="fax-address">
						{{ t('mail', 'Fax number') }}
					</label>
				</div>
				<div class="composer-fields--custom">
					<vue-tel-input v-model="faxAddress"
						input-id="fax-address"
						name="faxNumber"
						:input-options="{ placeholder: t('mail', 'Fax number') }"
						@input="saveDraftDebounced" />
				</div>
			</div>
		</template>
		<!-- New Fax message END -->

		<!-- New SMS message START -->
		<template v-if="selectedMessageType === MESSAGE_TYPES.SMS.id">
			<div class="composer-fields">
				<div class="composer-fields__label">
					<label class="label" for="sms-address">
						{{ t('mail', 'Phone number') }}
					</label>
				</div>
				<div class="composer-fields--custom">
					<input id="sms-address"
						v-model="smsAddress"
						type="text"
						name="smsAddress"
						autocomplete="off"
						:placeholder="t('mail', 'Phone number')"
						@input="saveDraftDebounced">
				</div>
			</div>
		</template>
		<!-- New SMS message END -->

		<!-- Hidden for Fax message type Start -->
		<template v-if="selectedMessageType !== MESSAGE_TYPES.FAX.id">
			<!-- Subject field Start -->
			<div class="composer-fields">
				<div class="composer-fields__label">
					<label class="label" for="subject">
						{{ t('mail', 'Subject') }}
					</label>
				</div>
				<div class="composer-fields--custom">
					<input id="subject"
						ref="subjectRef"
						v-model="subjectVal"
						type="text"
						name="subject"
						class="subject"
						:class="{ 'subject--error': subjectMissing }"
						autocomplete="off"
						:placeholder="t('mail', 'Subject …')"
						@input="saveDraftDebounced">
				</div>
			</div>
			<!-- Subject field End -->

			<div v-if="noReply" class="warning noreply-warning">
				{{ t('mail', 'This message came from a noreply address so your reply will probably not be read.') }}
			</div>
			<div v-if="wantsSmimeEncrypt && missingSmimeCertificatesForRecipients.length" class="warning noreply-warning">
				{{
					t('mail', 'The following recipients do not have a S/MIME certificate: {recipients}.', {
						recipients: missingSmimeCertificatesForRecipients.join(', '),
					})
				}}
			</div>
			<div v-if="encrypt && mailvelope.keysMissing.length" class="warning noreply-warning">
				{{
					t('mail', 'The following recipients do not have a PGP key: {recipients}.', {
						recipients: mailvelope.keysMissing.join(', '),
					})
				}}
			</div>
			<div class="composer-fields message-editor">
				<!--@keypress="onBodyKeyPress"-->
				<TextEditor v-if="!encrypt"
					ref="editor"
					:key="editorMode"
					:value="bodyVal"
					:html="!editorPlainText"
					name="body"
					class="message-body"
					:placeholder="t('mail', 'Write message …')"
					:bus="bus"
					@input="onEditorInput"
					@ready="onEditorReady"
					@mention="handleMention"
					@show-toolbar="handleShow" />
				<MailvelopeEditor v-else
					ref="mailvelopeEditor"
					:value="bodyVal"
					:recipients="allRecipients"
					:quoted-text="body"
					:is-reply-or-forward="isReply || isForward"
					@input="onEditorInput" />
			</div>
		</template>
		<!-- Hidden for Fax message type End -->
		<template v-else>
			<div class="composer-fields fax-composer">
				<div class="composer-fields__label">
					<label class="label" for="fax-pdf-input">
						{{ t('mail', 'Fax content (PDF)') }}
					</label>
				</div>
				<div class="composer-fields--custom fax-composer--custom">
					<div class="fax-dropzone"
						:class="{ 'fax-dropzone--dragover': isDragging }"
						role="button"
						tabindex="0"
						aria-describedby="fax-dropzone-help"
						@click="triggerFaxFilePicker"
						@keydown.enter.prevent="triggerFaxFilePicker"
						@keydown.space.prevent="triggerFaxFilePicker"
						@dragenter.prevent="onFaxDragEnter"
						@dragover.prevent="onFaxDragOver"
						@dragleave.prevent="onFaxDragLeave"
						@drop.prevent="onFaxDrop">
						<span id="fax-dropzone-help" class="fax-dropzone-help">Click here to upload PDF file</span>
						<input id="fax-pdf-input"
							ref="faxPdfInput"
							type="file"
							accept="application/pdf"
							class="hidden-visually"
							@change="onFaxFileChange">
					</div>
				</div>
			</div>
		</template>

		<ComposerAttachments ref="composerAttachments"
			v-model="attachments"
			:bus="bus"
			:upload-size-limit="attachmentSizeLimit"
			@upload="$emit('upload-attachment', $event, getMessageData())" />
		<MetadataAttachmentsItsl v-if="metadataAttachmentsVisible"
			:sender-person-i-ds="senderPersonIDs"
			:sender-reference-i-ds="senderReferenceIDs"
			:recipient-person-i-ds="recipientPersonIDs"
			:recipient-reference-i-ds="recipientReferenceIDs"
			:deletable="true"
			@remove-chip="handleRemoveChip" />
		<div class="composer-actions-right composer-actions">
			<div class="composer-actions--primary-actions">
				<p class="composer-actions-draft-status">
					<span v-if="savingDraft" class="draft-status">{{ t('mail', 'Saving draft …') }}</span>
					<span v-else-if="!canSaveDraft" class="draft-status">{{ t('mail', 'Error saving draft') }}</span>
					<span v-else-if="draftSaved" class="draft-status">{{ t('mail', 'Draft saved') }}</span>
				</p>
				<ButtonVue v-if="!savingDraft && !canSaveDraft"
					class="button"
					variant="tertiary"
					:aria-label="t('mail', 'Save draft')"
					@click="saveDraft">
					<template #icon>
						<Download :size="20" :title="t('mail', 'Save draft')" />
					</template>
				</ButtonVue>
				<ButtonVue v-if="!savingDraft && draftSaved"
					class="button"
					variant="tertiary"
					:aria-label="t('mail', 'Discard & close draft')"
					@click="$emit('discard-draft')">
					<template #icon>
						<Delete :size="20" :title="t('mail', 'Discard & close draft')" />
					</template>
				</ButtonVue>
			</div>
			<div class="composer-actions--secondary-actions">
				<!-- Hidden for Fax message type Start -->
				<template v-if="selectedMessageType !== MESSAGE_TYPES.FAX.id">
					<Actions v-if="selectedMessageType === MESSAGE_TYPES.SDK.id" :aria-label="t('mail', 'SenderRecipientIDs')" :menu-name="t('mail', 'SenderRecipientIDs')">
						<template #icon>
							<Plus :size="20" :title="t('mail', 'Add Sender Recipient IDs')" />
						</template>
						<ActionButton :close-after-click="true" @click="showAddPerRefIdModal('sender', 'person')">
							<template #icon>
								<Account :size="20" :title="t('mail', 'Add Sender Personal IDs')" />
							</template>
							{{ t('mail', 'Sender Personal ID') }}
						</ActionButton>
						<ActionButton :close-after-click="true" @click="showAddPerRefIdModal('sender', 'reference')">
							<template #icon>
								<Paperclip :size="20" :title="t('mail', 'Add Sender Reference IDs')" />
							</template>
							{{ t('mail', 'Sender Reference ID') }}
						</ActionButton>
						<ActionButton :close-after-click="true" @click="showAddPerRefIdModal('recipient', 'person')">
							<template #icon>
								<Account :size="20" :title="t('mail', 'Add Recipient Personal IDs')" />
							</template>
							{{ t('mail', 'Recipient Personal ID') }}
						</ActionButton>
						<ActionButton :close-after-click="true" @click="showAddPerRefIdModal('recipient', 'reference')">
							<template #icon>
								<Paperclip :size="20" :title="t('mail', 'Add Recipient Reference IDs')" />
							</template>
							{{ t('mail', 'Recipient Reference ID') }}
						</ActionButton>
					</Actions>
					<ButtonVue v-if="!encrypt && editorPlainText"
						variant="tertiary"
						:aria-label="t('mail', 'Enable formatting')"
						@click="setEditorModeHtml()">
						<template #icon>
							<IconFormat :size="20" :title="t('mail', 'Enable formatting')" />
						</template>
					</ButtonVue>
					<ButtonVue v-if="!encrypt && !editorPlainText"
						variant="tertiary"
						:pressed="true"
						:aria-label="t('mail', 'Disable formatting')"
						@click="setEditorModeText()">
						<template #icon>
							<IconFormat :size="20" :title="t('mail', 'Disable formatting')" />
						</template>
					</ButtonVue>
				</template>
				<!-- Hidden for Fax message type End -->

				<Actions v-if="showAttachmentIcon" :open.sync="isAddAttachmentsOpen">
					<template #icon>
						<Paperclip :size="20" />
					</template>
					<ActionButton :close-after-click="true" @click="onAddLocalAttachment">
						<template #icon>
							<IconUpload :size="20" />
						</template>
						{{
							t('mail', 'Upload attachment')
						}}
					</ActionButton>
					<ActionButton :close-after-click="true" @click="onAddCloudAttachment">
						<template #icon>
							<IconFolder :size="20" />
						</template>
						{{
							t('mail', 'Add attachment from Files')
						}}
					</ActionButton>
					<!-- Hidden for Fax message type Start -->
					<template v-if="selectedMessageType !== MESSAGE_TYPES.FAX.id">
						<ActionButton :close-after-click="true" :disabled="encrypt" @click="onAddCloudAttachmentLink">
							<template #icon>
								<IconPublic :size="20" />
							</template>
							{{
								t('mail', 'Add share link from Files')
							}}
						</ActionButton>
					</template>
					<!-- Hidden for Fax message type End -->
				</Actions>

				<!-- Hidden for Fax message type Start -->
				<template v-if="selectedMessageType !== MESSAGE_TYPES.FAX.id">
					<Actions :open.sync="isActionsOpen"
						@close="isMoreActionsOpen = false">
						<template v-if="!isMoreActionsOpen">
							<ActionButton v-if="isPickerAvailable" :close-after-click="true" @click="openPicker">
								<template #icon>
									<IconLinkPicker :size="20" />
								</template>
								{{
									t('mail', 'Smart picker')
								}}
							</ActionButton>
							<ActionButton v-if="!isScheduledSendingDisabled"
								:close-after-click="false"
								@click="isMoreActionsOpen=true">
								<template #icon>
									<SendClock :size="20" :title="t('mail', 'Send later')" />
								</template>
								{{
									t('mail', 'Send later')
								}}
							</ActionButton>
							<ActionCheckbox v-if="selectedMessageType === MESSAGE_TYPES.SECURE.id"
								:checked="requestMdnVal"
								@check="requestMdnVal = true"
								@uncheck="requestMdnVal = false">
								{{ t('mail', 'Request a read receipt') }}
							</ActionCheckbox>
							<ActionCheckbox v-if="selectedMessageType === MESSAGE_TYPES.SDK.id"
								:checked="confidentiality"
								@check="confidentiality = true"
								@uncheck="confidentiality = false">
								{{ t('mail', 'Confidentiality') }}
							</ActionCheckbox>
							<ActionCheckbox v-if="smimeCertificateForCurrentAlias"
								:checked="wantsSmimeSign"
								@check="wantsSmimeSign = true"
								@uncheck="wantsSmimeSign = false">
								{{ t('mail', 'Sign message with S/MIME') }}
							</ActionCheckbox>
							<ActionCheckbox v-if="smimeCertificateForCurrentAlias"
								:checked="wantsSmimeEncrypt"
								:disabled="encrypt"
								@check="wantsSmimeEncrypt = true"
								@uncheck="wantsSmimeEncrypt = false">
								{{ t('mail', 'Encrypt message with S/MIME') }}
							</ActionCheckbox>
							<ActionCheckbox v-if="mailvelope.available"
								:checked="encrypt"
								:disabled="wantsSmimeEncrypt"
								@change="isActionsOpen = false"
								@check="encrypt = true"
								@uncheck="encrypt = false">
								{{ t('mail', 'Encrypt message with Mailvelope') }}
							</ActionCheckbox>
						</template>
						<template v-if="isMoreActionsOpen">
							<ActionButton :close-after-click="false"
								@click="isMoreActionsOpen=false">
								<template #icon>
									<ChevronLeft :title="t('mail', 'Send later')"
										:size="20" />
									{{ t('mail', 'Send later') }}
								</template>
							</ActionButton>
							<ActionRadio v-model="sendAtVal"
								:value="0"
								name="sendLater"
								class="send-action-radio">
								{{ t('mail', 'Send now') }}
							</ActionRadio>
							<ActionRadio v-model="sendAtVal"
								:value="dateTomorrowMorning"
								name="sendLater"
								class="send-action-radio send-action-radio--multiline">
								{{ t('mail', 'Tomorrow morning') }} - {{ convertToLocalDate(dateTomorrowMorning) }}
							</ActionRadio>
							<ActionRadio v-model="sendAtVal"
								:value="dateTomorrowAfternoon"
								name="sendLater"
								class="send-action-radio send-action-radio--multiline">
								{{ t('mail', 'Tomorrow afternoon') }} - {{ convertToLocalDate(dateTomorrowAfternoon) }}
							</ActionRadio>
							<ActionRadio v-model="sendAtVal"
								:value="dateMondayMorning"
								name="sendLater"
								class="send-action-radio send-action-radio--multiline">
								{{ t('mail', 'Monday morning') }} - {{ convertToLocalDate(dateMondayMorning) }}
							</ActionRadio>
							<ActionRadio v-model="sendAtVal"
								:value="customSendTime"
								name="sendLater"
								class="send-action-radio">
								{{ t('mail', 'Custom date and time') }}
							</ActionRadio>
							<ActionInput v-model="selectedDate"
								:is-native-picker="true"
								:min="dateToday"
								type="datetime-local"
								:first-day-of-week="firstDayDatetimePicker"
								:use12h="showAmPm"
								:formatter="formatter"
								:format="'YYYY-MM-DD HH:mm'"
								icon=""
								:minute-step="5"
								@change="onChangeSendLater(customSendTime)">
								{{ t('mail', 'Enter a date') }}
							</ActionInput>
						</template>
					</Actions>
				</template>
				<!-- Hidden for Fax message type End -->

				<ButtonVue :disabled="!canSend"
					native-type="submit"
					variant="primary"
					:aria-label="submitButtonTitle"
					@click="onSend">
					<template #icon>
						<Send :title="submitButtonTitle"
							:size="20" />
					</template>
					{{ submitButtonTitle }}
				</ButtonVue>
			</div>
		</div>
		<!-- PersonalReferenceIDModalItsl component -->
		<div v-if="selectedMessageType === MESSAGE_TYPES.SDK.id">
			<PersonalReferenceIDModalItsl :visible="isPerRefModalVisible"
				:type="IDmetadataModalType"
				:side="IDmetadataModalSide"
				@update:visible="isPerRefModalVisible = $event"
				@submit="handleModalSubmit" />
		</div>
	</div>
</template>

<script>
import debounce from 'lodash/fp/debounce.js'
import escape from 'lodash/fp/escape.js'
import uniqBy from 'lodash/fp/uniqBy.js'
import trimStart from 'lodash/fp/trimCharsStart.js'
import Autosize from 'vue-autosize'
import debouncePromise from 'debounce-promise'

import { NcActions as Actions, NcActionButton as ActionButton, NcActionCheckbox as ActionCheckbox, NcActionInput as ActionInput, NcActionRadio as ActionRadio, NcButton as ButtonVue, NcSelect } from '@nextcloud/vue'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import ComposerAttachments from '../../../components/ComposerAttachments.vue'
import Download from 'vue-material-design-icons/Download.vue'
import IconUpload from 'vue-material-design-icons/Upload.vue'
import IconFolder from 'vue-material-design-icons/Folder.vue'
import IconPublic from 'vue-material-design-icons/Link.vue'
import IconLinkPicker from 'vue-material-design-icons/Shape.vue'
import Paperclip from 'vue-material-design-icons/Paperclip.vue'
import IconFormat from 'vue-material-design-icons/FormatSize.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Account from 'vue-material-design-icons/Account.vue'

import { showError, showWarning } from '@nextcloud/dialogs'
import { getCanonicalLocale, getFirstDay, getLocale, translate as t } from '@nextcloud/l10n'
import Vue from 'vue'
import mitt from 'mitt'

import { findRecipient } from '../../../service/AutocompleteService.js'
import { detect, html, toHtml, toPlain } from '../../../util/text.js'
import logger from '../../../logger.js'
import TextEditor from '../../../components/TextEditor.vue'
import { buildReplyBody } from '../../../ReplyBuilder.js'
import MailvelopeEditor from '../../../components/MailvelopeEditor.vue'
import { getMailvelope } from '../../../crypto/mailvelope.js'
import { isPgpgMessage } from '../../../crypto/pgp.js'

import { NcReferencePickerModal } from '@nextcloud/vue/components/NcRichText'

import Send from 'vue-material-design-icons/Send.vue'
import SendClock from 'vue-material-design-icons/SendClock.vue'
import moment from '@nextcloud/moment'
import { TRIGGER_CHANGE_ALIAS, TRIGGER_EDITOR_READY } from '../../../ckeditor/signature/InsertSignatureCommand.js'
import { EDITOR_MODE_HTML, EDITOR_MODE_TEXT } from '../../../store/constants.js'
import useMainStore from '../../../store/mainStore.js'
import { mapStores, mapState } from 'pinia'

import useItslStore from '../../store/itslStore.js'
import { MESSAGE_TYPES, MESSAGE_DIRECTION, SDKMC_API_ROUTES } from '../../store/constants.js'
import { parseAddressInfoFromString, messageTypeToIcon } from '../../utils/messageTypeUtils.js'
import { getValidSSN, getValidSMSNumber } from '../../utils/phoneUtils.js'
import { formatParticipantDisplayName, formatSdkOrganizationName } from '../../utils/participantUtils.js'
import PersonalReferenceIDModalItsl from '../modals/PersonReferenceIDModalItsl.vue'
import MetadataAttachmentsItsl from './MetadataAttachmentsItsl.vue'
import Axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const debouncedSearch = debouncePromise(findRecipient, 500)

const NO_ALIAS_SET = -1

Vue.use(Autosize)

export default {
	// eslint-disable-next-line vue/match-component-file-name
	name: 'Composer',
	components: {
		MailvelopeEditor,
		Account, // itsl
		Actions,
		ActionButton,
		ActionCheckbox,
		ActionInput,
		ActionRadio,
		ButtonVue,
		ComposerAttachments,
		ChevronLeft,
		Delete,
		Download,
		IconUpload,
		IconFolder,
		IconPublic,
		IconLinkPicker,
		NcSelect,
		Paperclip,
		Plus, // itsl
		PersonalReferenceIDModalItsl, // itsl
		MetadataAttachmentsItsl,
		TextEditor,
		Send,
		SendClock,
		IconFormat,
		NcReferencePickerModal,
	},
	props: {
		fromAccount: {
			type: Number,
			default: () => undefined,
		},
		fromAlias: {
			type: Number,
			default: undefined,
		},
		to: {
			type: Array,
			default: () => [],
		},
		cc: {
			type: Array,
			default: () => [],
		},
		bcc: {
			type: Array,
			default: () => [],
		},
		subject: {
			type: String,
			default: '',
		},
		body: {
			type: Object,
			default: () => html(''),
		},
		editorBody: {
			type: String,
			default: '',
		},
		inReplyToMessageId: {
			type: String,
			default: undefined,
		},
		replyTo: {
			type: Object,
			required: false,
			default: () => undefined,
		},
		forwardFrom: {
			type: Object,
			required: false,
			default: () => undefined,
		},
		forwardedMessages: {
			type: Array,
			required: false,
			default: () => [],
		},
		smartReply: {
			type: String,
			required: false,
			default: undefined,
		},
		sendAt: {
			type: Number,
			default: undefined,
		},
		attachmentsData: {
			type: Array,
			default: () => [],
		},
		error: {
			type: String,
			default: undefined,
		},
		canSaveDraft: {
			type: Boolean,
			default: false,
		},
		uploadingAttachments: {
			type: Boolean,
			default: false,
		},
		savingDraft: {
			type: Boolean,
			default: false,
		},
		draftSaved: {
			type: Boolean,
			default: false,
		},
		smimeSign: {
			type: Boolean,
			default: false,
		},
		smimeEncrypt: {
			type: Boolean,
			default: false,
		},
		isFirstOpen: {
			type: Boolean,
			required: true,
		},
		requestMdn: {
			type: Boolean,
			default: false,
		},
		accounts: {
			type: Array,
			required: true,
		},
		itsl: {
			type: Object,
			required: false,
			default: null,
		},
	},
	data() {
		// Set default custom date time picker value to now + 1 hour
		const selectedDate = new Date()
		selectedDate.setHours(selectedDate.getHours() + 1)
		return {
			showCC: this.cc.length > 0,
			showBCC: this.bcc.length > 0,
			selectedAlias: NO_ALIAS_SET, // Fixed in `beforeMount`
			autocompleteRecipients: this.to.concat(this.cc).concat(this.bcc),
			newRecipients: [],
			subjectVal: this.subject,
			bodyVal: this.editorBody,
			attachments: this.attachmentsData,
			noReply: this.to.some((to) => to.email?.startsWith('noreply@') || to.email?.startsWith('no-reply@')),
			saveDraftDebounced: debounce(5 * 1000, this.saveDraft),
			selectTo: this.to,
			selectCc: this.cc,
			selectBcc: this.bcc,
			bus: mitt(),
			encrypt: false,
			mailvelope: {
				available: false,
				keyRing: undefined,
				keysMissing: [],
			},
			editorMode: (this.body?.format !== 'html') ? EDITOR_MODE_TEXT : EDITOR_MODE_HTML,
			requestMdnVal: this.requestMdn,
			changeSignature: false,
			loadingIndicatorTo: false,
			loadingIndicatorCc: false,
			loadingIndicatorBcc: false,
			isAddAttachmentsOpen: false,
			isActionsOpen: false,
			isMoreActionsOpen: false,
			selectedDate,
			sendAtVal: this.sendAt || 0, // Normalize undefined/NaN to 0 for v-model radio binding
			firstDayDatetimePicker: getFirstDay() === 0 ? 7 : getFirstDay(),
			formatter: {
				stringify: (date) => {
					return date ? moment(date).format('LLL') : ''
				},
				parse: (value) => {
					return value ? moment(value, 'LLL').toDate() : null
				},
			},
			autoLimit: true,
			wantsSmimeSign: this.smimeSign,
			wantsSmimeEncrypt: this.smimeEncrypt,
			isPickerOpen: false,
			recipientSearchTerms: {},
			functionAddress: '',
			organizationAddress: '',
			email: '',
			notification: '',
			ssn: '',
			smsNumber: '',
			faxAddress: '',
			smsAddress: '',
			MESSAGE_TYPES,
			selectedMessageType: null,
			isPerRefModalVisible: false,
			IDmetadataModalType: '',
			IDmetadataModalSide: '',
			senderPersonIDs: [],
			senderReferenceIDs: [],
			recipientPersonIDs: [],
			recipientReferenceIDs: [],
			confidentiality: false,
			loaLevel: 3,
			isDragging: false,
			emailOptions: [],
			subjectMissing: false,
		}
	},
	computed: {
		...mapStores(useMainStore),
		...mapState(useMainStore, ['isScheduledSendingDisabled']),
		selectedEmailOption() {
			return this.emailOptions.find(o => o.value === this.email) || null
		},
		itslStore() {
			return useItslStore()
		},
		userObligatedToProvideSsn() {
			return this.itslStore.userObligatedToProvideSsn
		},
		organizationAddressesAvailable() {
			return this.itslStore.getAddressBookOrgs
		},
		orgSelectionAvailable() {
			return this.itslStore.getAddressBookLoaded
		},
		functionAddressesAvailable() {
			if (!this.organizationAddress) {
				return []
			}
			return this.organizationAddress.functionAddresses
		},
		isPickerAvailable() {
			return parseInt(this.mainStore.getNcVersion) >= 26
		},
		aliases() {
			// Touch reactive flags so Vue recomputes aliases when itslStore data loads
			void this.itslStore.internalMailboxesLoaded
			void this.itslStore.addressBookLoaded
			let cnt = 0
			const accounts = this.accounts.filter((a) => !a.isUnified).filter((a) => this.filterAccountsByMessageType(a.emailAddress))
			// itsl: look up description from internal mailbox address book or SDK org name
			const descriptionFor = (email) => {
				if (email && email.endsWith('@sdk')) {
					const entry = [...this.itslStore.getValidFromData.entries()].find(([, value]) => value === email)
					return entry ? entry[0] : ''
				}
				return this.emailOptions.find(o => o.value === email)?.description || ''
			}
			// itsl: resolve display name from itslStore (avoids spread-snapshot issue from Home.vue)
			const nameFor = (email, fallback) => {
				return this.itslStore.resolveAccountDisplayName(email) ?? fallback
			}
			const aliases = accounts.flatMap((account) => [
				{
					id: account.id,
					aliasId: null,
					selectId: cnt++,
					editorMode: account.editorMode,
					signature: account.signature,
					name: nameFor(account.emailAddress, account.name),
					emailAddress: account.emailAddress,
					description: descriptionFor(account.emailAddress),
					signatureAboveQuote: account.signatureAboveQuote,
					smimeCertificateId: account.smimeCertificateId,
					selectable: account.connectionStatus,
				},
				account.aliases.map((alias) => {
					return {
						id: account.id,
						aliasId: alias.id,
						selectId: cnt++,
						editorMode: account.editorMode,
						signature: alias.signature,
						name: nameFor(alias.alias, alias.name),
						emailAddress: alias.alias,
						description: descriptionFor(alias.alias),
						signatureAboveQuote: account.signatureAboveQuote,
						smimeCertificateId: alias.smimeCertificateId,
						selectable: account.connectionStatus,
					}
				}),
			])
			return aliases.flat()
		},
		allRecipients() {
			return this.selectTo.concat(this.selectCc).concat(this.selectBcc)
		},
		dateToday() {
			return new Date(new Date().setDate(new Date().getDate()))
		},
		attachmentSizeLimit() {
			return this.mainStore.getPreference('attachment-size-limit')
		},
		selectableRecipients() {
			return uniqBy('email')(this.newRecipients
				.concat(this.autocompleteRecipients)
				.map((recipient) => ({ ...recipient, label: recipient.label || recipient.email })))
		},
		isForward() {
			return this.forwardFrom !== undefined
		},
		isReply() {
			return this.replyTo !== undefined
		},
		canSend() {
			return this.isValidMessageType()
				&& this.isValidEncryption()
				&& this.isValidSubject()
		},
		editorPlainText() {
			return this.editorMode === EDITOR_MODE_TEXT
		},
		submitButtonTitle() {
			if (this.wantsSmimeEncrypt) {
				if (this.sendAtVal) {
					return t('mail', 'Encrypt with S/MIME and send later') + ` ${this.convertToLocalDate(this.sendAtVal)}`
				}
				return t('mail', 'Encrypt with S/MIME and send')
			}

			if (this.mailvelope.available && this.encrypt) {
				if (this.sendAtVal) {
					return t('mail', 'Encrypt with Mailvelope and send later') + ` ${this.convertToLocalDate(this.sendAtVal)}`
				}
				return t('mail', 'Encrypt with Mailvelope and send')
			}

			if (this.sendAtVal) {
				return t('mail', 'Send later') + ` ${this.convertToLocalDate(this.sendAtVal)}`
			}
			return t('mail', 'Send')
		},
		dateTomorrowMorning() {
			const today = new Date()
			today.setTime(today.getTime() + 24 * 60 * 60 * 1000)
			return today.setHours(9, 0, 0, 0)

		},
		dateTomorrowAfternoon() {
			const today = new Date()
			today.setTime(today.getTime() + 24 * 60 * 60 * 1000)
			return today.setHours(14, 0, 0, 0)
		},
		dateMondayMorning() {
			const today = new Date()
			today.setHours(9, 0, 0, 0)
			return today.setDate(today.getDate() + (7 - today.getDay()) % 7 + 1)
		},
		customSendTime() {
			return new Date(this.selectedDate).getTime()
		},
		showAmPm() {
			const localeData = moment().locale(getLocale()).localeData()
			const timeFormat = localeData.longDateFormat('LT').toLowerCase()

			return timeFormat.indexOf('a') !== -1
		},
		isSendAtTomorrowMorning() {
			if (this.sendAtVal && Math.floor(this.dateTomorrowMorning / 1000) === Math.floor(this.sendAtVal / 1000)) {
				return true
			} else {
				return false
			}
		},
		isSendAtTomorrowAfternoon() {
			if (this.sendAtVal && Math.floor(this.dateTomorrowAfternoon / 1000) === Math.floor(this.sendAtVal / 1000)) {
				return true
			} else {
				return false
			}
		},
		isSendAtMondayMorning() {
			if (this.sendAtVal && Math.floor(this.dateMondayMorning / 1000) === Math.floor(this.sendAtVal / 1000)) {
				return true
			} else {
				return false
			}
		},
		isSendAtCustom() {
			if (this.sendAtVal && !this.isSendAtTomorrowMorning && !this.isSendAtTomorrowAfternoon && !this.isSendAtMondayMorning) {
				return true
			} else {
				return false
			}
		},

		/**
		 * The S/MIME certificate object of the current alias/account.
		 *
		 * @return {object|undefined} S/MIME certificate of current account or alias if one is selected
		 */
		smimeCertificateForCurrentAlias() {
			if (this.selectedAlias === NO_ALIAS_SET) {
				return undefined
			}

			return this.smimeCertificateForAlias(this.selectedAlias)
		},

		/**
		 * Whether the outgoing message should be signed with S/MIME.
		 *
		 * @return {boolean} True if the message should be signed
		 */
		shouldSmimeSign() {
			return this.wantsSmimeSign && !!this.smimeCertificateForCurrentAlias
		},

		/**
		 * Whether the outgoing message should be encrypted with S/MIME.
		 *
		 * @return {boolean} True if the message should be encrypted
		 */
		shouldSmimeEncrypt() {
			return this.wantsSmimeEncrypt && !!this.smimeCertificateForCurrentAlias && this.missingSmimeCertificatesForRecipients.length === 0
		},

		/**
		 * Return a list of recipients without a matching S/MIME certificate.
		 *
		 * @return {Array} Recipients without matching certificate
		 */
		missingSmimeCertificatesForRecipients() {
			const missingCertificates = []

			this.allRecipients.forEach((recipient) => {
				const recipientCertificate = this.mainStore.getSmimeCertificateByEmail(recipient.email)
				if (!recipientCertificate) {
					missingCertificates.push(recipient.email)
				}
			})

			return missingCertificates
		},
		messageTypeInfo() {
			try {
				const map = Object.keys(MESSAGE_TYPES).reduce((acc, key) => {
					acc[MESSAGE_TYPES[key].id] = {
						icon: messageTypeToIcon(MESSAGE_TYPES[key].id),
						label: t('mail', MESSAGE_TYPES[key].labelKey),
					}
					return acc
				}, {})

				return map[this.selectedMessageType] || null
			} catch (error) {
				console.error('messageTypeInfo() crashed:', error)
				return null
			}
		},
		metadataAttachmentsVisible() {
			return this.senderPersonIDs.length > 0 || this.senderReferenceIDs.length > 0 || this.recipientPersonIDs.length > 0 || this.recipientReferenceIDs.length > 0
		},
		showAttachmentIcon() {
			if (this.selectedMessageType === MESSAGE_TYPES.FAX.id) {
				return this.attachments.length < 1
			}
			return true
		},
	},
	watch: {
		'$route.params.threadId'() {
			this.reset()
		},
		allRecipients() {
			this.checkRecipientsKeys()
		},
		aliases(newAliases) {
			console.debug('aliases changed')
			if (this.selectedAlias === NO_ALIAS_SET) {
				return
			}

			const newAlias = newAliases.find(alias => alias.id === this.selectedAlias.id && alias.aliasId === this.selectedAlias.aliasId)
			if (newAlias === undefined) {
				// selected alias does not exist anymore.
				this.onAliasChange(newAliases[0])
			} else {
				// update the selected alias
				this.onAliasChange(newAlias)
			}
		},
		selectTo(val) {
			this.$emit('update:to', val)
		},
		selectCc(val) {
			this.$emit('update:cc', val)
		},
		selectBcc(val) {
			this.$emit('update:bcc', val)
		},
		subjectVal(val) {
			this.$emit('update:subject', val)
			if (val.trim()) {
				this.subjectMissing = false
			}
		},
		bodyVal(val) {
			this.$emit('update:editor-body', val)
		},
		attachments(val) {
			this.$emit('update:attachments-data', val)
		},
		sendAtVal(val) {
			this.$emit('update:send-at', val)
		},
		wantsSmimeSign(val) {
			this.$emit('update:smime-sign', val)
		},
		wantsSmimeEncrypt(val) {
			this.$emit('update:smime-encrypt', val)
		},
		requestMdnVal(val) {
			this.$emit('update:request-mdn', val)
		},
		functionAddress(val) {
			this.updateItslObject()
		},
		organizationAddress(val) {
			this.updateItslObject()
		},
		email(val) {
			this.updateItslObject()
		},
		notification(val) {
			this.updateItslObject()
		},
		ssn(val) {
			this.updateItslObject()
		},
		faxAddress(val) {
			this.updateItslObject()
		},
		smsAddress(val) {
			this.updateItslObject()
		},
		smsNumber(val) {
			this.updateItslObject()
		},
		loaLevel(val, oldVal) {
			// If organization forces SSN, always keep LOA-3
			if (this.userObligatedToProvideSsn && val !== 3) {
				this.loaLevel = 3
				return
			}
			if (val !== 2) this.smsNumber = ''
			if (val !== 3) this.ssn = ''
			this.updateItslObject()
		},
		confidentiality(val) {
			this.updateItslObject()
		},
	},
	async beforeMount() {
		this.initInProgress = true

		if (this.itsl) {
			this.selectedMessageType = this.itsl.messageType

			if (this.itsl.alias) { // CASE opening composer from minimized popup, contains alias and other itsl data
				if (this.selectedMessageType === MESSAGE_TYPES.SDK.id) {
					// Set IDs and confidentiality BEFORE addresses, so when address
					// watchers fire createItslDataAttachment() reads correct values
					this.senderPersonIDs = this.itsl.senderPersonIDs || []
					this.senderReferenceIDs = this.itsl.senderReferenceIDs || []
					this.recipientPersonIDs = this.itsl.recipientPersonIDs || []
					this.recipientReferenceIDs = this.itsl.recipientReferenceIDs || []
					this.confidentiality = this.itsl.confidentiality || false
					this.setAddressesFromAddressBook(this.itsl.functionAddress, this.itsl.organizationAddress)
				} else if (this.selectedMessageType === MESSAGE_TYPES.INTERNAL.id) {
					this.email = this.itsl.email
				} else if (this.selectedMessageType === MESSAGE_TYPES.SECURE.id) {
					this.notification = this.itsl.notification
					this.smsNumber = this.itsl.smsNumber || ''
					this.ssn = this.itsl.ssn || ''
					// Force LOA-3 if organization requires it, otherwise restore saved loaLevel
					if (this.userObligatedToProvideSsn) {
						this.loaLevel = 3
					} else {
						this.loaLevel = this.itsl.loaLevel || (this.itsl.isSendingToPerson ? 3 : 1) // backward compat
					}
				} else if (this.selectedMessageType === MESSAGE_TYPES.FAX.id) {
					this.faxAddress = this.itsl.faxAddress
				} else if (this.selectedMessageType === MESSAGE_TYPES.SMS.id) {
					this.smsAddress = this.itsl.smsAddress
				}
			} else {
				this.loadToDataFromToField()
				if (this.selectedMessageType === MESSAGE_TYPES.SDK.id && this.itsl.sdk?.messageHeader) {
					this.loadReferenceDataFromItslObject()
					this.loadAdditionalDataFromItslObject()
				}
			}
		} else {
			// CASE new message button clicked
			this.selectedMessageType = this.itslStore.getSelectedMessageType
			if (this.selectedMessageType === MESSAGE_TYPES.SECURE.id) {
				this.loaLevel = 3 // Default to LOA-3 for new SECURE messages
			}
			this.itslStore.setMessageType('') // clearing messageType
		}
		if (this.selectedMessageType === MESSAGE_TYPES.INTERNAL.id || this.selectedMessageType === MESSAGE_TYPES.SECURE.id) {
			this.loadInternalMailboxes()
		}
		// accounts are being changed during beforeMount phase that causes vue warning because aliases() are being calculated while editor is not ready yet
		this.$nextTick(() => {
			this.setAlias()
			// Emit complete itsl state after all initialization (including alias resolution).
			// This ensures the store has correct data even if intermediate watcher emissions
			// had stale values during beforeMount.
			if (this.selectedMessageType) {
				this.$emit('update:itsl', this.createItslDataAttachment())
			}
		})
		// endITSL
		this.initBody()
		await this.onMailvelopeLoaded(await getMailvelope())
	},
	mounted() {
		// startITSL code invalid for our composer since to field is changed
		// if (!this.isReply && this.isFirstOpen) {
		//    this.$nextTick(() => this.$refs.toLabel.$el.focus())
		// }
		// endITSL

		// Add attachments in case of forward
		if (this.forwardFrom?.attachments !== undefined) {
			this.forwardFrom.attachments.forEach(att => {
				this.attachments.push({
					fileName: att.fileName,
					displayName: trimStart('/', att.fileName),
					id: att.id,
					messageId: this.forwardFrom.databaseId,
					type: 'message-attachment',
				})
			})
		}

		// Add messages forwarded as attachments
		for (const id of this.forwardedMessages) {
			const env = this.mainStore.getEnvelope(id)
			if (!env) {
				// TODO: also happens when the composer page is reloaded
				showError(t('mail', 'Message {id} could not be found', {
					id,
				}))
				continue
			}

			this.bus.emit('on-add-message-as-attachment', {
				id,
				fileName: env.subject + '.eml',
			})
		}

		// Set custom date and time picker value if initialized with custom send at value
		if (this.sendAt && this.isSendAtCustom) {
			this.selectedDate = new Date(this.sendAt)
		}

		// special case for forward to Internal with additional PDF
		if (this.itsl?.additionalAttachment) {

			this.$nextTick(() => {
				const messageData = this.getMessageData()

				const file = new File(
					[this.itsl.additionalAttachment],
					this.itsl.additionalAttachmentName,
					{ type: 'application/pdf' },
				)

				const inputEvent = {
					target: {
						files: [file],
					},
				}

				const done = this.$refs.composerAttachments.onLocalAttachmentSelected(inputEvent)
				this.$emit('upload-additional-attachment', done, messageData)
			})
		}

		this.initInProgress = false

		this.$nextTick(() => {
			this.saveDraft()
		})
	},
	beforeDestroy() {
		window.removeEventListener('mailvelope', this.onMailvelopeLoaded)
	},
	methods: {
		updateItslObject() {
			if (this.initInProgress) {
				return
			}
			this.$emit('update:itsl', this.createItslDataAttachment())
		},
		updateEmailSelect() {
			this.saveDraftDebounced()
		},
		emailFilter(option, label, search) {
			if (!search) return true
			const term = search.toLowerCase()
			return (
				option.name.toLowerCase().includes(term)
				|| option.description.toLowerCase().includes(term)
				|| option.value.toLowerCase().includes(term)
			)
		},
		updateFuncAddrSelect() {
			this.$nextTick(() => {
				this.$refs.subjectRef.focus()
			})
			this.saveDraftDebounced()
		},
		updateOrgSelect() {
			this.functionAddress = ''
			this.$nextTick(() => {
				this.$refs.functionAddressRef.$refs.search?.focus()
			})
			this.saveDraftDebounced()
		},
		addressFilter(option, label, search) {
			return option.searchableName.includes(search.toLowerCase())
		},
		handleRemoveChip({ group, index }) {
			switch (group) {
			case 'senderPersonIDs':
				this.senderPersonIDs.splice(index, 1)
				break
			case 'senderReferenceIDs':
				this.senderReferenceIDs.splice(index, 1)
				break
			case 'recipientPersonIDs':
				this.recipientPersonIDs.splice(index, 1)
				break
			case 'recipientReferenceIDs':
				this.recipientReferenceIDs.splice(index, 1)
				break
			}
			this.updateItslObject()
		},
		showAddPerRefIdModal(side, type) {
			this.IDmetadataModalSide = side
			this.IDmetadataModalType = type
			this.isPerRefModalVisible = true
		},
		handleModalSubmit(payload) {
			const targetMap = {
				sender: {
					person: this.senderPersonIDs,
					reference: this.senderReferenceIDs,
				},
				recipient: {
					person: this.recipientPersonIDs,
					reference: this.recipientReferenceIDs,
				},
			}

			const targetList = targetMap[payload.side]?.[payload.type]
			if (!targetList) return

			const idKey = payload.type === 'person' ? 'personId' : 'referenceId'
			targetList.push({
				[idKey]: {
					root: payload.firstRow.value,
					extension: payload.secondRow,
				},
				label: payload.thirdRow,
			})
			this.updateItslObject()
		},
		setAddressesFromAddressBook(functionAddress, organizationAddress) {
			if (functionAddress && organizationAddress) {
				let foundFunctionAddress
				let foundOrganizationAddress

				for (const org of this.organizationAddressesAvailable) {
					if (org.address === organizationAddress) {
						const match = org.functionAddresses.find(f => f.address === functionAddress)
						if (match) {
							foundOrganizationAddress = org
							foundFunctionAddress = match
							break
						}
					}
				}
				if (foundOrganizationAddress) {
					this.organizationAddress = foundOrganizationAddress
					this.functionAddress = foundFunctionAddress
				}
			} else if (organizationAddress) {
				const found = this.organizationAddressesAvailable.find((item) => item.address === organizationAddress)
				if (found) {
					this.organizationAddress = found
				}
			}
		},
		loadToDataFromToField() {
			if (this.isForward) { // ignoring to fields in Forward scenario
				return
			}
			if (this.selectedMessageType === MESSAGE_TYPES.SDK.id && this.itsl.sdk?.messageHeader) {
				if (this.isReply && this.itsl?.messageDirection === MESSAGE_DIRECTION.INCOMING) {
					this.setAddressesFromAddressBook(this.itsl.sdk.messageHeader.sender.attention.subOrganization.organizationId.extension, this.itsl.sdk.messageHeader.sender.senderId.extension)
				} else {
					this.setAddressesFromAddressBook(this.itsl.sdk.messageHeader.recipient.attention.subOrganization.organizationId.extension, this.itsl.sdk.messageHeader.recipient.recipientId.extension)
				}
			} else {

				if (this.to && this.to.length === 0) {
					return
				}
				// data should be parsed from this.to
				const toFieldInfo = parseAddressInfoFromString(this.selectedMessageType, this.to[0].email)
				if (this.selectedMessageType === MESSAGE_TYPES.INTERNAL.id) {
					this.email = toFieldInfo.email
				} else if (this.selectedMessageType === MESSAGE_TYPES.SECURE.id) {
					this.notification = toFieldInfo.notification
					this.ssn = toFieldInfo.ssn
					// Derive loaLevel from parsed address (can only detect LOA-1 or LOA-3 from email format)
					// LOA-2 (SMS) info comes from database via itsl.loaLevel
					if (this.itsl?.loaLevel) {
						this.loaLevel = this.itsl.loaLevel
					} else {
						this.loaLevel = toFieldInfo.isSendingToPerson ? 3 : 1
					}
					// Restore smsNumber from original message for LOA-2 reply chains (see securemail#32)
					if (this.itsl?.smsNumber) {
						this.smsNumber = this.itsl.smsNumber
					}
				} else if (this.selectedMessageType === MESSAGE_TYPES.FAX.id) {
					this.faxAddress = toFieldInfo.faxAddress
				} else if (this.selectedMessageType === MESSAGE_TYPES.SMS.id) {
					this.smsAddress = toFieldInfo.smsAddress
				}
			}
		},
		loadReferenceDataFromItslObject() {
			const invertSides = this.itsl.messageDirection === MESSAGE_DIRECTION.INCOMING && !this.isForward
			const attentionSender = this.itsl.sdk.messageHeader.sender.attention || {}
			const attentionRecipient = this.itsl.sdk.messageHeader.recipient.attention || {}

			const senderSource = invertSides ? attentionRecipient : attentionSender
			const recipientSource = invertSides ? attentionSender : attentionRecipient

			if (Array.isArray(senderSource.person)) {
				this.senderPersonIDs.push(...senderSource.person)
			}

			if (Array.isArray(senderSource.reference)) {
				this.senderReferenceIDs.push(...senderSource.reference)
			}

			if (Array.isArray(recipientSource.person)) {
				this.recipientPersonIDs.push(...recipientSource.person)
			}

			if (Array.isArray(recipientSource.reference)) {
				this.recipientReferenceIDs.push(...recipientSource.reference)
			}
		},
		loadAdditionalDataFromItslObject() {
			this.confidentiality = this.itsl.sdk.messageHeader.confidentiality
		},
		clearOnBlur(event) {
			if (this.recipientSearchTerms[event]) {
				return this.recipientSearchTerms[event].includes('@')
			}
			return false
		},
		handleShow(event) {
			this.$emit('show-toolbar', event)
		},
		openPicker() {
			this.isPickerOpen = true
		},
		closePicker() {
			this.isPickerOpen = false
		},
		filterOption(option, label, search, list) {
			let select = []
			if (list === 'to') {
				select = this.selectTo
			} else if (list === 'cc') {
				select = this.selectCc

			} else if (list === 'bcc') {
				select = this.selectBcc
			}

			return (label || '').toLocaleLowerCase().indexOf(search.toLocaleLowerCase()) > -1 && !select.some((item) => item.email === option.email)
		},
		setAlias() {
			const previous = this.selectedAlias

			if (this.fromAccount && this.fromAlias) {
				this.selectedAlias = this.aliases.find((alias) => {
					return alias.id === this.fromAccount && alias.aliasId === this.fromAlias
				})
			} else {
				if (this.fromAccount) {
					this.selectedAlias = this.aliases.find((alias) => {
						return alias.id === this.fromAccount && !alias.aliasId
					})
				} else {
					const currentAccountId = this.mainStore.getMailbox(this.$route.params.mailboxId)?.accountId
					if (currentAccountId) {
						this.selectedAlias = this.aliases.find((alias) => {
							return alias.id === currentAccountId
						})
					} else {
						this.selectedAlias = this.aliases[0]
					}
				}
			}
			// fallback when fromAccount couldn't be figured out based on previous rules
			if (!this.selectedAlias && this.aliases.length > 0) {
				this.selectedAlias = this.aliases[0]
			}

			// Only overwrite editormode if body is empty
			if (previous === NO_ALIAS_SET && (!this.body || this.body.value === '')) {
				this.editorMode = this.selectedAlias.editorMode
			}
		},
		async checkRecipientsKeys() {
			if (!this.encrypt || !this.mailvelope.available) {
				return
			}

			const recipients = this.allRecipients.map((r) => r.email)
			const keysValid = await this.mailvelope.keyRing.validKeyForAddress(recipients)
			logger.debug('recipients keys validated', { recipients, keysValid })
			this.mailvelope.keysMissing = recipients.filter((r) => keysValid[r] === false)
		},
		initBody() {
			/** @member {Text} body */
			let body
			if (this.replyTo && this.isFirstOpen) {
				body = '' // Don't quote original message
			} else if (this.forwardFrom && this.isFirstOpen) {
				const dateString = moment.unix(this.forwardFrom.dateInt).format('LLL')
				const originalContent = this.bodyVal || ''
				const email = this.forwardFrom.from[0]?.email || ''
				const label = this.forwardFrom.from[0]?.label || ''
				const internalMailboxName = this.itslStore.getInternalMailboxName(email)
				const sdkSender = this.forwardFrom.itsl?.sdk?.messageHeader?.sender

				// Use original message type for display: when forwarding SDK→Internal,
				// selectedMessageType is INTERNAL but sender should display as SDK
				const originalMessageType = sdkSender ? MESSAGE_TYPES.SDK.id : this.selectedMessageType

				const senderDisplay = formatParticipantDisplayName(originalMessageType, {
					email,
					label,
					internalMailboxName,
					sdkParty: sdkSender,
				})

				// SDK: append organization if available (translatable)
				let headerDisplay = senderDisplay
				if (originalMessageType === MESSAGE_TYPES.SDK.id) {
					const orgName = formatSdkOrganizationName(sdkSender)
					if (orgName) {
						headerDisplay = t('mail', '{name} from {organization}', {
							name: senderDisplay,
							organization: orgName,
						})
					}
				}

				// Build translatable, HTML-escaped header line
				const headerLine = t('mail', 'On {date} {name} wrote:', {
					date: escape(dateString),
					name: escape(headerDisplay),
				})
				body = `<p></p><p></p><p>${headerLine}</p><p>---</p>${originalContent}`
			} else {
				body = this.bodyVal
			}
			if (this.itsl?.overrideBody) {
				body = t('mail', 'The forwarded message is attached as a PDF.')
			}
			this.bodyVal = html(body).value
		},
		getMessageData() {
			const data = {
				// TODO: Rename account to accountId
				account: this.selectedAlias.id,
				accountId: this.selectedAlias.id,
				aliasId: this.selectedAlias.aliasId,
				to: this.selectTo,
				cc: this.selectCc,
				bcc: this.selectBcc,
				subject: this.subjectVal,
				attachments: this.attachments,
				inReplyToMessageId: this.inReplyToMessageId ?? (this.replyTo ? this.replyTo.messageId : undefined),
				replyToDatabaseId: this.replyTo?.databaseId,
				isHtml: !this.encrypt && !this.editorPlainText,
				requestMdn: this.requestMdnVal,
				sendAt: this.sendAtVal ? Math.floor(this.sendAtVal / 1000) : undefined,
				smimeSign: this.shouldSmimeSign,
				smimeEncrypt: this.shouldSmimeEncrypt,
				smimeCertificateId: this.smimeCertificateForCurrentAlias?.id,
				isPgpMime: this.encrypt,
				itsl: this.createItslDataAttachment(),
			}

			if (data.isHtml) {
				data.bodyHtml = this.bodyVal
			} else {
				data.bodyPlain = toPlain(html(this.bodyVal)).value
			}

			return data
		},
		createItslDataAttachment() {
			let typeSpecific = {}
			switch (this.selectedMessageType) {
			case MESSAGE_TYPES.SDK.id:
				typeSpecific = {
					functionAddress: this.functionAddress?.address,
					functionAddressLabel: this.functionAddress?.name,
					organizationAddress: this.organizationAddress?.address,
					organizationAddressLabel: this.organizationAddress?.name,
					messageSubject: this.subjectVal,
					confidentiality: this.confidentiality,
				}
				break
			case MESSAGE_TYPES.INTERNAL.id:
				typeSpecific = { email: this.email }
				break
			case MESSAGE_TYPES.SECURE.id:
				typeSpecific = {
					notification: this.notification,
					ssn: this.loaLevel === 3 ? getValidSSN(this.ssn) : '',
					smsNumber: this.loaLevel === 2 ? (getValidSMSNumber(this.smsNumber) || '') : '',
					isSendingToPerson: this.loaLevel === 3, // backward compat
					loaLevel: this.loaLevel,
				}
				break
			case MESSAGE_TYPES.FAX.id:
				typeSpecific = {
					faxAddress: (this.faxAddress || '').replace(/[-\s]/g, ''),
				}
				break
			case MESSAGE_TYPES.SMS.id:
				typeSpecific = { smsAddress: this.smsAddress }
				break
			}

			return {
				messageType: this.selectedMessageType,
				alias: this.selectedAlias,
				...typeSpecific,
				senderPersonIDs: this.senderPersonIDs,
				senderReferenceIDs: this.senderReferenceIDs,
				recipientPersonIDs: this.recipientPersonIDs,
				recipientReferenceIDs: this.recipientReferenceIDs,
			}
		},
		filterAccountsByMessageType(emailAddress) {
			if (!emailAddress || !this.selectedMessageType) {
				return true // show all accounts if one of data is missing
			}
			const suffixes = []
			if (this.selectedMessageType === MESSAGE_TYPES.SDK.id) {
				suffixes.push('@sdk')
			} else if (this.selectedMessageType === MESSAGE_TYPES.INTERNAL.id || this.selectedMessageType === MESSAGE_TYPES.SECURE.id) {
				suffixes.push('@gruppbox', '@personlig')
			} else if (this.selectedMessageType === MESSAGE_TYPES.FAX.id) {
				suffixes.push('@fax')
			} else if (this.selectedMessageType === MESSAGE_TYPES.SMS.id) {
				suffixes.push('@sms')
			} else {
				return false
			}
			return suffixes.some(suffix => emailAddress.endsWith(suffix))
		},
		saveDraft() {
			if (this.selectedAlias === NO_ALIAS_SET || !this.selectedAlias) {
				logger.debug('No account selected yet, skipping draft save')
				return
			}

			const draftData = this.getMessageData()
			if (draftData.subject === ''
				&& !(draftData.bodyHtml || draftData.bodyPlain)
				&& draftData.cc.length === 0
				&& draftData.bcc.length === 0
				&& draftData.to.length === 0
				&& draftData.sendAt === undefined
				&& !draftData.itsl?.messageType) {
				// this might happen after a call to reset()
				// where the text input gets reset as well
				// and fires an input event
				logger.debug('Nothing substantial to save, ignoring draft save')
				return
			}

			this.$emit('draft', draftData)
		},
		insertSignature() {
			let trigger

			if (this.changeSignature) {
				trigger = TRIGGER_CHANGE_ALIAS
			} else {
				trigger = TRIGGER_EDITOR_READY
			}

			this.$refs.editor.editorExecute('insertSignature',
				trigger,
				toHtml(detect(this.selectedAlias.signature)).value,
				this.selectedAlias.signatureAboveQuote,
			)

			this.changeSignature = false
		},
		onPicked(content) {
			this.closePicker()
			this.bus.emit('append-to-body-at-cursor', content)
		},
		onEditorInput(text) {
			this.bodyVal = text
			this.saveDraftDebounced()
		},
		onEditorReady(editor) {
			this.bodyVal = editor.getData()
			this.insertSignature()
			if (this.smartReply) {
				this.bus.emit('append-to-body-at-cursor', this.smartReply)
			}

			// Track body focus to highlight empty subject
			editor.editing.view.document.on('change:isFocused', (evt, name, isFocused) => {
				if (isFocused && this.selectedMessageType !== MESSAGE_TYPES.FAX.id && !this.subjectVal.trim()) {
					this.subjectMissing = true
				}
			})

			this.$emit('ready')
		},
		onChangeSendLater(value) {
			this.sendAtVal = value ? Number.parseInt(value, 10) : 0
		},
		convertToLocalDate(timestamp) {
			const options = {
				month: 'short',
				day: 'numeric',
				hour: '2-digit',
				minute: '2-digit',
			}
			return new Date(timestamp).toLocaleString(getCanonicalLocale(), options)
		},
		onAliasChange(alias) {
			logger.debug('changed alias', { alias })
			this.selectedAlias = alias
			this.changeSignature = true

			this.$emit('update:from-account', alias.id)
			if (alias.aliasId) {
				this.$emit('update:from-alias', alias.aliasId)
			}

			if (this.wantsSmimeSign || this.wantsSmimeEncrypt) {
				if (!this.smimeCertificateForAlias(alias)) {
					this.wantsSmimeSign = false
					this.wantsSmimeEncrypt = false
					showWarning(t('mail', 'Sign or Encrypt with S/MIME was selected, but we don\'t have a certificate for the selected alias. The message will not be signed or encrypted.'))
				}
			}

			/**
			 * Alias change may change the editor mode as well.
			 *
			 * As editorMode is the key for the TextEditor component a change will destroy the current instance
			 * and the signature for the alias is inserted via onEditorReady event.
			 *
			 * Otherwise (when editorMode is the same) call insertSignature directly.
			 */
			if (this.editorMode === EDITOR_MODE_TEXT && alias.editorMode === EDITOR_MODE_HTML) {
				this.editorMode = EDITOR_MODE_HTML
			} else {
				this.insertSignature()
			}
		},
		onAddLocalAttachment() {
			if (this.selectedMessageType === MESSAGE_TYPES.FAX.id) {
			    this.triggerFaxFilePicker()
			} else {
				this.bus.emit('on-add-local-attachment')
				this.saveDraftDebounced()
			}
		},
		onAddCloudAttachment() {
			this.bus.emit('on-add-cloud-attachment')
			this.saveDraftDebounced()
		},
		onAddCloudAttachmentLink() {
			this.bus.emit('on-add-cloud-attachment-link')
		},
		onAutocomplete(term, addressType) {
			if (term === undefined || term === '') {
				return
			}
			this.loadingIndicatorTo = addressType === 'to'
			this.loadingIndicatorCc = addressType === 'cc'
			this.loadingIndicatorBcc = addressType === 'bcc'
			this.recipientSearchTerms[addressType] = term
			debouncedSearch(term).then((results) => {
				if (addressType === 'to') {
					this.loadingIndicatorTo = false
				} else if (addressType === 'cc') {
					this.loadingIndicatorCc = false
				} else if (addressType === 'bcc') {
					this.loadingIndicatorBcc = false
				}

				// Search results might not have labels
				for (const result of results) {
					if (!result.label) {
						result.label = result.email
					}
				}

				this.autocompleteRecipients = uniqBy('email')(this.autocompleteRecipients.concat(results))
			})
		},
		async onMailvelopeLoaded(mailvelope) {
			this.encrypt = isPgpgMessage(this.body)
			this.mailvelope.available = true
			logger.info('Mailvelope loaded', {
				encrypt: this.encrypt,
				isPgpgMessage: isPgpgMessage(this.body),
				keyRing: this.mailvelope.keyRing,
			})
			this.mailvelope.keyRing = await mailvelope.getKeyring()
			await this.checkRecipientsKeys()
		},
		handleMention(option) {
			this.editorMode = EDITOR_MODE_HTML
			this.onNewToAddr(option)
		},
		onNewToAddr(option) {
			this.onNewAddr(option, this.selectTo, 'to')
		},
		onNewCcAddr(option) {
			this.onNewAddr(option, this.selectCc, 'cc')
		},
		onNewBccAddr(option) {
			this.onNewAddr(option, this.selectBcc, 'bcc')
		},
		onNewAddr(option, list, type) {
			if (
				(option === null || option === undefined)
				&& this.recipientSearchTerms[type] !== undefined
				&& this.recipientSearchTerms[type] !== ''
			) {
				if (!this.recipientSearchTerms[type].includes('@')) {
					return
				}
				option = {}
				option.email = this.recipientSearchTerms[type]
				option.label = this.recipientSearchTerms[type]
				this.recipientSearchTerms[type] = ''
			}

			if (list.some((recipient) => recipient.email === option?.email) || !option) {
				return
			}
			const recipient = { ...option }
			this.newRecipients.push(recipient)
			list.push(recipient)
			this.saveDraftDebounced()
		},
		async onSend(_, force = false) {
			if (this.encrypt) {
				logger.debug('get encrypted message from mailvelope')
				await this.$refs.mailvelopeEditor.pull()
			}

			this.$emit('send', {
				...this.getMessageData(),
				force,
			})
		},
		reset() {
			this.selectTo = []
			this.selectCc = []
			this.selectBcc = []
			this.subjectVal = ''
			this.bodyVal = '<p></p><p></p>'
			this.attachments = []
			this.autocompleteRecipients = []
			this.newRecipients = []
			this.requestMdnVal = false
			this.changeSignature = false
			this.sendAtVal = 0

			this.setAlias()
			this.initBody()
		},
		/**
		 * Format aliases for the Multiselect
		 *
		 * @param {object} alias the alias to format
		 * @return {string}
		 */
		formatAliases(alias) {
			if (!alias.name) {
				return alias.emailAddress
			}

			return `${alias.name} <${alias.emailAddress}>`
		},
		/**
		 * Whether the date is acceptable
		 *
		 * @param {Date} date The date to compare to
		 * @return {boolean}
		 */
		disabledDatetimepickerDate(date) {
			const minimumDate = new Date()
			// Make it one sec before midnight so it shows the next full day as available
			minimumDate.setHours(0, 0, 0)
			minimumDate.setSeconds(minimumDate.getSeconds() - 1)

			return date.getTime() <= minimumDate
		},

		/**
		 * Whether the time for date is acceptable
		 *
		 * @param {Date} date The date to compare to
		 * @return {boolean}
		 */
		disabledDatetimepickerTime(date) {
			const now = new Date()
			const minimumDate = new Date(now.getTime())
			return date.getTime() <= minimumDate
		},
		/**
		 * Remove recipient from recipients array (To,Cc,Bcc)
		 *
		 * @param {Array} option Current option from Multiselect
		 * @param {Array} field List of recipients (ex. this.selectTo)
		 */
		onRemoveRecipient(option, field) {
			switch (field) {
			case 'to':
				this.removeRecipientTo(option)
				break
			case 'cc':
				this.removeRecipientCc(option)
				break
			case 'bcc':
				this.removeRecipientBcc(option)
				break
			}
		},
		removeRecipient(option, list) {
			return list.filter((recipient) => recipient.email !== option.email)
		},
		removeRecipientTo(option) {
			this.selectTo = this.removeRecipient(option, this.selectTo)
		},
		removeRecipientCc(option) {
			this.selectCc = this.removeRecipient(option, this.selectCc)
		},
		removeRecipientBcc(option) {
			this.selectBcc = this.removeRecipient(option, this.selectBcc)
		},
		toggleViewMode() {
			this.autoLimit = !this.autoLimit
			this.showCC = !(this.showCC && this.selectCc.length === 0 && this.autoLimit)
			this.showBCC = !(this.showBCC && this.selectBcc.length === 0 && this.autoLimit)
		},
		setEditorModeHtml() {
			this.editorMode = EDITOR_MODE_HTML
		},
		setEditorModeText() {
			OC.dialogs.confirmDestructive(
				t('mail', 'Any existing formatting (for example bold, italic, underline or inline images) will be removed.'),
				t('mail', 'Turn off formatting'),
				{
					type: OC.dialogs.YES_NO_BUTTONS,
					confirm: t('mail', 'Turn off and remove formatting'),
					confirmClasses: 'error',
					cancel: t('mail', 'Keep formatting'),
				},
				(decision) => {
					if (decision) {
						this.editorMode = EDITOR_MODE_TEXT
					}
				},
			)
		},
		/**
		 * The S/MIME certificate object for an alias/account.
		 *
		 * @param {object} alias object
		 * @return {object|undefined} S/MIME certificate of account or alias if one is selected
		 */
		smimeCertificateForAlias(alias) {
			const certificateId = alias.smimeCertificateId
			if (!certificateId) {
				return undefined
			}
			return this.mainStore.getSmimeCertificate(certificateId)
		},

		/**
		 * Create a new option for the to, cc and bcc selects.
		 *
		 * @param {string} value The string (email) typed by the user
		 * @return {{email: string, label: string}} The new option
		 */
		createRecipientOption(value) {
			return { email: value, label: value }
		},

		/**
		 * Return the subname for recipient suggestion.
		 *
		 * Empty if label and email are the same or
		 * if the suggestion is a group.
		 *
		 * @param {{email: string, label: string}} option
		 * @return string
		 */
		getSubnameForRecipient(option) {
			if (option.source && option.source === 'groups') {
				return ''
			}

			if (option.label === option.email) {
				return ''
			}

			return option.email
		},
		isValidMessageType() {
			switch (this.selectedMessageType) {
			case MESSAGE_TYPES.SDK.id:
				return this.isValidSdkMessage()
			case MESSAGE_TYPES.INTERNAL.id:
				return this.isValidInternalMessage()
			case MESSAGE_TYPES.SECURE.id:
				return this.isValidSecureEmail()
			case MESSAGE_TYPES.FAX.id:
				return this.isValidFaxMessage()
			case MESSAGE_TYPES.SMS.id:
				return this.isValidSmsMessage()
			default:
				return false
			}
		},

		isValidSdkMessage() {
			return Boolean(this.functionAddress && this.organizationAddress)
		},

		isValidInternalMessage() {
			return Boolean(this.email)
		},

		isValidSecureEmail() {
			if (!this.notification) return false
			if (this.loaLevel === 2) return getValidSMSNumber(this.smsNumber) !== null
			if (this.loaLevel === 3) return Boolean(this.ssn)
			return true // LOA-1 only needs notification
		},

		isValidFaxMessage() {
			return Boolean(this.faxAddress)
		},

		isValidSmsMessage() {
			return Boolean(this.smsAddress)
		},

		isValidEncryption() {
			// S/MIME validation
			if (this.wantsSmimeEncrypt && (!this.smimeCertificateForCurrentAlias || this.missingSmimeCertificatesForRecipients.length)) {
				return false
			}

			// PGP validation
			if (this.encrypt && this.mailvelope.keysMissing.length) {
				return false
			}

			return true
		},

		isValidSubject() {
			if (this.selectedMessageType === MESSAGE_TYPES.FAX.id) {
				return true
			}
			return Boolean(this.subjectVal.trim())
		},

		triggerFaxFilePicker() {
			if (this.attachments.length !== 0) {
				return
			}
			this.$refs.faxPdfInput?.click()
		},
		onFaxFileChange(e) {
			const files = Array.from(e.target.files || [])
			this.handleFaxFiles(files)
			// reset so selecting the same file again still triggers change
			e.target.value = ''
		},
		onFaxDragEnter() {
			this.isDragging = true
		},
		onFaxDragOver() {
			this.isDragging = true
		},
		onFaxDragLeave() {
			this.isDragging = false
		},
		onFaxDrop(e) {
			this.isDragging = false
			const items = e.dataTransfer?.files ? Array.from(e.dataTransfer.files) : []
			this.handleFaxFiles(items)
		},
		handleFaxFiles(files) {
			// accept only the first valid PDF
			const pdf = files.find(f => f && (f.type === 'application/pdf' || /\.pdf$/i.test(f.name)))
			if (!pdf) return
			const inputEvent = { target: { files: [pdf] } }
			const done = this.$refs.composerAttachments.onLocalAttachmentSelected(inputEvent)
			this.$emit('upload-attachment', done, this.getMessageData())
			this.saveDraftDebounced()
		},
		async loadInternalMailboxes() {
			try {
				const url = generateUrl(SDKMC_API_ROUTES.GET_INTERNAL_EMAILS)
				const { data } = await Axios.get(url)

				const toOption = (item) => ({
					value: item.email ?? '',
					name: item.name ?? '',
					description: item.description ?? '',
				})

				const options = Array.isArray(data) ? data.map(toOption) : []
				const valid = options.filter(o => o.value && o.name)

				// API returns gruppbox first, then personlig — find the boundary and sort each group.
				// Gruppbox emails end with @gruppbox, personlig with @personlig.
				const isGruppbox = (o) => o.value.endsWith('@gruppbox')
				const grupp = valid.filter(isGruppbox)
				const pers = valid.filter(o => !isGruppbox(o))
				const naturalSort = (a, b) => a.name.localeCompare(b.name, 'sv', { numeric: true })
				this.emailOptions = [...grupp.sort(naturalSort), ...pers.sort(naturalSort)]
			} catch (e) {
				console.error('Failed to load internal mailboxes:', e)
				this.emailOptions = []
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.message-composer {
	z-index: 100;
	display: flex;
	flex-direction: column;
	min-height: 700px;
	height: 700px;
	max-height: 100%;
}

.message-composer__header {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 12px 0;
    border-bottom: .1px solid black;
    font-weight: bold;
    border: 1px solid rgba(0, 0, 0, 0.1);
    position: absolute;
    z-index: 1;
    width: calc(100% - 11.6px);
	justify-content: flex-start;
	padding-inline-start: 10px;
    background: var(--color-main-background);
}

.message-composer__icon {
	max-height: 25px;
}

.message-composer__label {
	font-weight: 500;
}

.composer-actions {
	position: sticky;
	background: var(--color-main-background);
}
.composer-fields {
	padding: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 2) 0 calc(var(--default-grid-baseline) * 2);

	&__label {
		display: flex;
		flex-direction: row;
		justify-content: space-between;
		align-items: flex-end;

		/** NcButton does not allow font weight styling */
		:deep(.button-vue__text) {
			font-weight: normal;
		}
	}

	&.mail-account {
		border-top: none;
		padding-top: 10px;
		margin-top: 47px;
	}

	input,
	TextEditor {
		flex-grow: 1;
		max-width: none;
	}

	.composer-fields--custom {
		display: flex;
		align-items: flex-start;
		justify-content: space-between;
		padding-top: 2px;

		button {
			margin-top: 0;
			margin-bottom: 0;
			background-color: transparent;
			border: none;
			opacity: 0.5;
			padding: 10px 16px;
		}

		.select {
			width: 100%;
		}
		.vs__search{
			width: 100%;
		}
		.v-select{
			flex-grow: 0.95;
		}
	}

	.composer-single-checkbox {
		list-style: none;
		margin-top: 5px;
		margin-inline-start: -5px;
	}

	.subject {
		width: 100%;
	}
	.subject--error {
		border-color: var(--color-error, #c62828) !important;
	}

	.message-body {
		min-height: 100%;
		height: 100%;
		width: 100%;
		overflow-y: auto;
		overflow-x: hidden;
		margin: 0;
		border: none !important;
		outline: none !important;
		box-shadow: none !important;

		// Fix contenteditable not becoming focused upon clichint within it's
		// boundaries in safari
		-webkit-user-select: text;
		user-select: text;
	}
}

// Make composer editor expand
.message-editor {
	flex: 1 1 100%;
	min-height: 0;
	border-top: 1px solid var(--color-border);
	box-sizing: border-box;
}

.draft-status {
	padding: 2px;
	opacity: 0.5;
	font-size: small;
	display: block;
}

.label {
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.bcc-label {
	top: initial;
	bottom: 0;
}

.copy-toggle {
	cursor: pointer;
	width: initial;

	&:hover,
	&:focus {
		color: var(--color-main-text);
	}
}

.reply {
	min-height: 100px;
}

:deep([data-select="create"] .avatardiv--unknown) {
	background: var(--color-text-maxcontrast) !important;
}
#from{
	width: 100%;
	cursor: pointer;
}
:deep(.vs__actions){
	display: none;
}

:deep(.v-select.select){
	left: 0 !important;
}

:deep(.vs__dropdown-menu){
	padding: 0 !important;
}

:deep(.vs__dropdown-option){
	border-radius: 0  !important;
}
.submit-message.send.primary.icon-confirm-white {
	color: var(--color-main-background);
}
.button {
	background-color: transparent;
	border: none;
}
.send-button {
	display: flex;
	align-items: center;
	padding: 10px 15px;
	margin-inline-start: 5px;
}
.send-button .send-icon {
	padding-inline-end: 5px;
}
.centered-content {
	margin-top: 0 !important;
}
.composer-actions-right {
	display: flex;
	align-items: center;
	flex-direction: row;
	justify-content: space-between;
	flex: 1 1 auto;
}
.composer-actions--primary-actions {
	display: flex;
	flex-direction: row;
	padding-inline-start: 10px;
	align-items: center;
}
.composer-actions--secondary-actions {
	display: flex;
	flex-direction: row;
	padding: 12px;
	gap: 5px;
}
.composer-actions--primary-actions .button {
	padding: 2px;
}
.composer-actions--secondary-actions .button{
	flex-shrink: 0;
}

.composer-actions-draft-status {
	padding-inline-start: 10px;
}

.fax-dropzone {
	flex: 1 1 auto;
	height: 100%;
	width: 100%;
	min-height: 140px;
	border: 2px dashed var(--color-border-dark);
	border-radius: 8px;
	display: flex;
	align-items: center;
	justify-content: center;
	text-align: center;
	padding: 16px;
	cursor: pointer;
	user-select: none;
	outline: none;
}
.fax-dropzone--dragover {
	border-color: var(--color-primary);
	background: var(--color-background-hover);
}
.fax-composer {
	height: 100%;
}
.fax-composer--custom {
	position: relative;
	height: 86%;
}
.fax-dropzone-help {
	cursor: pointer !important;
}
:deep(.vs__selected-options .vs__dropdown-toggle .vs--multiple ){
	width: 100%;
}
:deep(.action-button .material-design-icon) {
	min-width: 34px;
}

:deep(.vue-tel-input) {
	width: 100%;
}
.loa-radio-group {
	display: flex;
	flex-direction: row;
	gap: 4px;
	:deep(.action) {
		list-style: none;
		margin-inline-start: 0;
	}
	:deep(.action-radio__label) {
		font-size: 13px;
	}
}

@media only screen and (max-width: 580px) {
	.composer-actions-right {
		align-items: end;
		flex-direction: column-reverse;
	}
	.composer-actions-draft-status {
		text-align: end;
		padding-inline-end: 15px;
	}
	.composer-actions--primary-actions {
		padding-inline-end: 5px;
	}
	.composer-single-checkbox {
		:deep(.action-checkbox__label) {
			white-space: normal;
			line-height: normal;
			gap: 7px;
			margin: 5px 0 10px 0;
		}
	}
	.message-composer :deep(.vs__selected) {
		white-space: nowrap;
	}
	.composer-actions--secondary-actions {
		flex-wrap: wrap;
		justify-content: end;
	}
	:deep(.select) {
		min-width: 227px;
	}
	:global(.vs__dropdown-menu .name-parts__first),
	:global(.vs__dropdown-menu .name-parts__last) {
		white-space: normal !important;
		word-break: break-all;
		font-size: 14px;
	}
	:global(.vs__dropdown-menu .vs__dropdown-option .name-parts) {
		display: inline;
	}
}
.option-container {
    display: flex;
    flex-direction: column;
}
.option-name {
    font-weight: 500;
}
.option-description {
    font-size: 12px;
    color: #666;
    margin-top: 2px;
}
.selected-ellipsis {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
