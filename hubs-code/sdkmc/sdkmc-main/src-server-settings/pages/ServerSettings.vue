<template>
	<div class="settings">
		<NcAppSidebar :active.sync="activeTab" :name="t('sdkmc', 'Server settings')" aria-labelledby="server-settings-title">
			<h2 id="server-settings-title" class="hidden-visually">
				{{ t('sdkmc', 'Server Settings') }}
			</h2>
			<NcAppSidebarTab id="global-tab" :name="t('sdkmc', 'Global Settings')" :order="1">
				<form class="form-grid" @submit.prevent="submitForm('global')">
					<NcInputFieldItsl
						type="password"
						:model-value="forms.global.sdkmcmwSecretPassword"
						:label="t('sdkmc', 'Internal API Key')"
						:tooltip="t('sdkmc', 'Internal API key used to authenticate SDK calls.')"
						:validator="formsConfig.global.validators.sdkmcmwSecretPassword"
						@update:modelValue="forms.global.sdkmcmwSecretPassword = $event" />
					<NcInputFieldItsl
						:model-value="forms.global.smtpHost"
						:label="t('sdkmc', 'SMTP Host')"
						:tooltip="t('sdkmc', 'Host name or IP address for the outgoing SMTP server.')"
						:validator="formsConfig.global.validators.smtpHost"
						@update:modelValue="forms.global.smtpHost = $event" />
					<NcInputFieldItsl
						:model-value="forms.global.smtpPort"
						:label="t('sdkmc', 'SMTP Port')"
						:tooltip="t('sdkmc', 'Port number used for the outgoing SMTP server.')"
						:validator="formsConfig.global.validators.smtpPort"
						@update:modelValue="forms.global.smtpPort = $event" />
					<NcInputFieldItsl
						:model-value="forms.global.smtpInboundHost"
						:label="t('sdkmc', 'Inbound SMTP Host')"
						:tooltip="t('sdkmc', 'Host name for the incoming SMTP server.')"
						:validator="formsConfig.global.validators.smtpInboundHost"
						@update:modelValue="forms.global.smtpInboundHost = $event" />
					<NcInputFieldItsl
						:model-value="forms.global.smtpInboundPort"
						:label="t('sdkmc', 'Inbound SMTP Port')"
						:tooltip="t('sdkmc', 'Port number for the incoming SMTP server.')"
						:validator="formsConfig.global.validators.smtpInboundPort"
						@update:modelValue="forms.global.smtpInboundPort = $event" />
					<NcInputFieldItsl
						:model-value="forms.global.imapHost"
						:label="t('sdkmc', 'IMAP Host')"
						:tooltip="t('sdkmc', 'Host name or IP address for the IMAP server used to fetch email.')"
						:validator="formsConfig.global.validators.imapHost"
						@update:modelValue="forms.global.imapHost = $event" />
					<NcInputFieldItsl
						:model-value="forms.global.imapPort"
						:label="t('sdkmc', 'IMAP Port')"
						:tooltip="t('sdkmc', 'Port number used for the IMAP server.')"
						:validator="formsConfig.global.validators.imapPort"
						@update:modelValue="forms.global.imapPort = $event" />
					<NcInputFieldItsl
						:model-value="forms.global.hideDefaultLoginLink"
						:component="NcCheckboxRadioSwitch"
						:component-props="{
							type: 'switch',
						}"
						:label-above="t('sdkmc', 'Hide default login link')"
						:tooltip="t('sdkmc', 'Hides the default login link on the login page.')"
						:validator="formsConfig.global.validators.hideDefaultLoginLink"
						@update:modelValue="forms.global.hideDefaultLoginLink = $event" />
					<NcInputFieldItsl
						:model-value="forms.global.loa3Enabled"
						:component="NcCheckboxRadioSwitch"
						:component-props="{
							type: 'switch',
						}"
						:label-above="t('sdkmc', 'LOA 3 functionality')"
						:tooltip="t('sdkmc', 'Custom features based on LOA support')"
						:validator="formsConfig.global.validators.loa3Enabled"
						@update:modelValue="forms.global.loa3Enabled = $event" />
					<NcInputFieldItsl
						:model-value="forms.global.loginProvider"
						:label="t('sdkmc', 'Plugin used for LOA-3 login')"
						:tooltip="t('sdkmc', 'Name of the login plugin that handles LOA-3 authentication.')"
						:validator="formsConfig.global.validators.loginProvider"
						@update:modelValue="forms.global.loginProvider = $event" />
					<NcInputFieldItsl
						:model-value="forms.global.loa3Auth"
						:label="t('sdkmc', 'Authentication method used for LOA-login')"
						:tooltip="t('sdkmc', 'Authentication method identifier used by the LOA-3 login plugin.')"
						:validator="formsConfig.global.validators.loa3Auth"
						@update:modelValue="forms.global.loa3Auth = $event" />
					<NcInputFieldItsl
						:model-value="forms.global.loa3Tag"
						:label="t('sdkmc', 'LOA 3 Tag')"
						:tooltip="t('sdkmc', 'Tag for files that require LOA 3 access to open')"
						:validator="formsConfig.global.validators.loa3Tag"
						@update:modelValue="forms.global.loa3Tag = $event" />
					<NcInputFieldItsl
						:model-value="forms.global.debugLogin"
						:component="NcCheckboxRadioSwitch"
						:component-props="{
							type: 'switch',
						}"
						:label-above="t('sdkmc', 'Debug login logging')"
						:tooltip="t('sdkmc', 'Enables detailed logging of the login process.')"
						:validator="formsConfig.global.validators.debugLogin"
						@update:modelValue="forms.global.debugLogin = $event" />
					<NcInputFieldItsl
						:model-value="forms.global.autoLogoutSeconds"
						:label="t('sdkmc', 'Automatic logout after inactivity (seconds)')"
						:tooltip="t('sdkmc', 'Enter 0 to disable automatic logout, or a value greater than 120 to enable it')"
						:validator="formsConfig.global.validators.autoLogoutSeconds"
						@update:modelValue="forms.global.autoLogoutSeconds = $event" />
					<NcInputFieldItsl
						:model-value="forms.global.threadSortNewestFirst"
						:component="NcCheckboxRadioSwitch"
						:component-props="{
							type: 'switch',
						}"
						:label-above="t('sdkmc', 'Show newest messages first in threads')"
						:tooltip="t('sdkmc', 'When enabled, threads display the newest message at the top instead of the oldest.')"
						:validator="formsConfig.global.validators.threadSortNewestFirst"
						@update:modelValue="forms.global.threadSortNewestFirst = $event" />
					<NcInputFieldItsl
						:model-value="forms.global.selectNewestInThread"
						:component="NcCheckboxRadioSwitch"
						:component-props="{
							type: 'switch',
						}"
						:label-above="t('sdkmc', 'Auto-expand newest message in threads')"
						:tooltip="t('sdkmc', 'When enabled, always expands the absolute newest message in a thread regardless of folder. When disabled (default), inbox expands the last received message and sent expands the last sent message.')"
						:validator="formsConfig.global.validators.selectNewestInThread"
						@update:modelValue="forms.global.selectNewestInThread = $event" />
					<div class="row">
						<NcButton
							type="submit"
							:disabled="loading">
							{{ t('sdkmc', 'Save') }}
						</NcButton>
					</div>
				</form>
			</NcAppSidebarTab>

			<NcAppSidebarTab id="notifications-tab" :name="t('sdkmc', 'Notification Defaults')" :order="2">
				<div class="form-grid">
					<p class="notification-tab-description">
						{{ t('sdkmc', 'Current admin defaults for new users. Select the settings you want to apply to all existing users.') }}
						<a
							:href="activitySettingsUrl"
							target="_blank"
							rel="noopener"
							class="notification-tab-link">
							{{ t('sdkmc', 'Change defaults in Activity settings') }} ↗
						</a>
					</p>

					<NcNoteCard v-if="notificationStatus && !notificationStatus.hasDefaults" type="warning">
						{{ t('sdkmc', 'No notification defaults have been configured yet. Set them in Activity settings first.') }}
					</NcNoteCard>

					<NcNoteCard v-else-if="notificationStatus && !hasAnyDrift" type="success">
						{{ t('sdkmc', 'All users match the current admin defaults.') }}
					</NcNoteCard>

					<div v-if="notificationStatus && notificationStatus.hasDefaults" class="notification-groups">
						<div class="notification-row notification-row--header">
							<div class="notification-row__name">
								{{ t('sdkmc', 'Setting') }}
							</div>
							<div class="notification-row__drift">
								&nbsp;
							</div>
							<div class="notification-row__method">
								{{ t('sdkmc', 'Email') }}
							</div>
							<div class="notification-row__method">
								{{ t('sdkmc', 'Push') }}
							</div>
						</div>

						<div v-for="group in notificationStatus.groups" :key="group.id" class="notification-group">
							<div class="notification-row notification-row--group" @click="toggleGroup(group.id)">
								<div class="notification-row__name">
									<button
										class="notification-group__toggle"
										:aria-expanded="expandedGroups.has(group.id)"
										:aria-controls="'notification-group-content-' + group.id"
										:aria-label="(expandedGroups.has(group.id) ? t('sdkmc', 'Collapse group') : t('sdkmc', 'Expand group')) + ': ' + group.name"
										@click.stop="toggleGroup(group.id)">
										<span class="notification-group__chevron" :class="{ 'notification-group__chevron--open': expandedGroups.has(group.id) }">&#9654;</span>
									</button>
									<span class="notification-group__name">{{ group.name }}</span>
									<span class="notification-group__count">({{ group.settings.length }})</span>
								</div>
								<div class="notification-row__drift">
									<span v-if="groupDriftCount(group) > 0" class="notification-drift-badge notification-drift-badge--warning">
										{{ t('sdkmc', '{count} total differences', { count: groupDriftCount(group) }) }}
									</span>
									<span v-else class="notification-drift-badge notification-drift-badge--success">
										{{ t('sdkmc', 'all match') }}
									</span>
								</div>
								<div class="notification-row__method" @click.stop>
									<NcCheckboxRadioSwitch
										:checked="groupEmailState(group) === 'checked'"
										:indeterminate="groupEmailState(group) === 'indeterminate'"
										@update:checked="toggleGroupEmail(group)" />
								</div>
								<div class="notification-row__method" @click.stop>
									<NcCheckboxRadioSwitch
										:checked="groupPushState(group) === 'checked'"
										:indeterminate="groupPushState(group) === 'indeterminate'"
										@update:checked="toggleGroupPush(group)" />
								</div>
							</div>

							<template v-if="expandedGroups.has(group.id)">
								<div :id="'notification-group-content-' + group.id">
									<div
										v-for="setting in group.settings"
										:key="setting.id"
										class="notification-row notification-row--setting"
										:class="{ 'notification-row--disabled': !setting.email.canChange && !setting.push.canChange }">
										<div class="notification-row__name notification-row__name--indent">
											{{ setting.name }}
										</div>
										<div class="notification-row__drift">
										&nbsp;
										</div>
										<div class="notification-row__method"
											:class="{
												'notification-row__method--drift': setting.email.differCount > 0,
												'notification-row__method--match': setting.email.differCount === 0,
											}">
											<div class="notification-cell">
												<NcCheckboxRadioSwitch
													:checked="selectedKeys.has(setting.email.key)"
													:disabled="!setting.email.canChange"
													:title="!setting.email.canChange ? t('sdkmc', 'This setting cannot be changed from here.') : undefined"
													@update:checked="toggleKey(setting.email.key)">
													{{ setting.email.adminDefault ? t('sdkmc', 'ON') : t('sdkmc', 'OFF') }}
												</NcCheckboxRadioSwitch>
												<span class="notification-cell__drift">
													{{ setting.email.differCount > 0
														? t('sdkmc', '{count} users differ', { count: setting.email.differCount })
														: '' }}
												</span>
											</div>
										</div>
										<div class="notification-row__method"
											:class="{
												'notification-row__method--drift': setting.push.differCount > 0,
												'notification-row__method--match': setting.push.differCount === 0,
											}">
											<div class="notification-cell">
												<NcCheckboxRadioSwitch
													:checked="selectedKeys.has(setting.push.key)"
													:disabled="!setting.push.canChange"
													:title="!setting.push.canChange ? t('sdkmc', 'This setting cannot be changed from here.') : undefined"
													@update:checked="toggleKey(setting.push.key)">
													{{ setting.push.adminDefault ? t('sdkmc', 'ON') : t('sdkmc', 'OFF') }}
												</NcCheckboxRadioSwitch>
												<span class="notification-cell__drift">
													{{ setting.push.differCount > 0
														? t('sdkmc', '{count} users differ', { count: setting.push.differCount })
														: '' }}
												</span>
											</div>
										</div>
									</div>
								</div>
							</template>
						</div>

						<div class="notification-actions">
							<div class="notification-select-all">
								<NcCheckboxRadioSwitch
									:checked="allDriftSelected"
									:disabled="!hasAnyDrift"
									@update:checked="toggleSelectAllDrift">
									{{ t('sdkmc', 'Select all with differences') }}
								</NcCheckboxRadioSwitch>
							</div>
							<div class="notification-buttons">
								<NcButton
									:disabled="isLoadingStatus || isApplyingDefaults"
									:loading="isLoadingStatus"
									@click="loadNotificationStatus">
									{{ t('sdkmc', 'Refresh') }}
								</NcButton>
								<NcButton
									type="primary"
									:disabled="!hasSelectedKeys || isApplyingDefaults"
									:loading="isApplyingDefaults"
									@click="showApplyConfirmDialog = true">
									{{ t('sdkmc', 'Apply selected to all users') }}
								</NcButton>
							</div>
						</div>
					</div>

					<div v-if="isLoadingStatus && !notificationStatus" class="notification-loading">
						{{ t('sdkmc', 'Loading...') }}
					</div>
				</div>
			</NcAppSidebarTab>

			<NcAppSidebarTab id="retention-tab" :name="t('sdkmc', 'Retention Settings')" :order="3">
				<form class="form-grid" @submit.prevent="submitForm('retentionSettings')">
					<NcInputFieldItsl
						type="number"
						:model-value="forms.retentionSettings.deleteTalkAfterDays"
						:label="t('sdkmc', 'Delete regular Talk conversations after inactivity (days)')"
						:tooltip="t('sdkmc', 'Set the number of inactive days after which regular Talk conversations are automatically deleted. Use 0 to disable automatic deletion.')"
						:validator="formsConfig.retentionSettings.validators.deleteTalkAfterDays"
						@update:modelValue="forms.retentionSettings.deleteTalkAfterDays = $event" />
					<NcInputFieldItsl
						type="number"
						:model-value="forms.retentionSettings.retention_event_rooms"
						:label="t('sdkmc', 'Delete event Talk conversations after inactivity (days)')"
						:tooltip="t('sdkmc', 'Set the number of inactive days after which event Talk conversations are automatically deleted. Use 0 to disable automatic deletion.')"
						:validator="formsConfig.retentionSettings.validators.retention_event_rooms"
						@update:modelValue="forms.retentionSettings.retention_event_rooms = $event" />
					<NcInputFieldItsl
						type="number"
						:model-value="forms.retentionSettings.mailRetentionDefault"
						:label="t('sdkmc', 'Default retention period (days)')"
						:tooltip="t('sdkmc', 'Defines the default number of days after which messages are automatically deleted, unless overridden by specific folder rules. Use 0 to disable automatic deletion.')"
						:validator="formsConfig.retentionSettings.validators.mailRetentionDefault"
						@update:modelValue="forms.retentionSettings.mailRetentionDefault = $event" />

					<NcInputFieldItsl
						type="number"
						:model-value="forms.retentionSettings.mailRetentionInbox"
						:label="t('sdkmc', 'Inbox retention period (days)')"
						:tooltip="t('sdkmc', 'Set how many days messages can remain in the Inbox before they are automatically deleted. Use 0 to disable automatic deletion.')"
						:validator="formsConfig.retentionSettings.validators.mailRetentionInbox"
						@update:modelValue="forms.retentionSettings.mailRetentionInbox = $event" />

					<NcInputFieldItsl
						type="number"
						:model-value="forms.retentionSettings.mailRetentionSent"
						:label="t('sdkmc', 'Sent retention period (days)')"
						:tooltip="t('sdkmc', 'Set how many days sent messages are kept before being automatically deleted. Use 0 to disable automatic deletion.')"
						:validator="formsConfig.retentionSettings.validators.mailRetentionSent"
						@update:modelValue="forms.retentionSettings.mailRetentionSent = $event" />

					<NcInputFieldItsl
						type="number"
						:model-value="forms.retentionSettings.mailRetentionArchive"
						:label="t('sdkmc', 'Processed retention period (days)')"
						:tooltip="t('sdkmc', 'Set how many days processed messages are kept before being automatically deleted. Use 0 to disable automatic deletion.')"
						:validator="formsConfig.retentionSettings.validators.mailRetentionArchive"
						@update:modelValue="forms.retentionSettings.mailRetentionArchive = $event" />

					<NcInputFieldItsl
						type="number"
						:model-value="forms.retentionSettings.mailRetentionTrash"
						:label="t('sdkmc', 'Trash retention period (days)')"
						:tooltip="t('sdkmc', 'Set how many days deleted messages remain in the Trash before being permanently removed. Use 0 to disable automatic deletion.')"
						:validator="formsConfig.retentionSettings.validators.mailRetentionTrash"
						@update:modelValue="forms.retentionSettings.mailRetentionTrash = $event" />

					<NcInputFieldItsl
						type="number"
						:model-value="forms.retentionSettings.mailRetentionDraft"
						:label="t('sdkmc', 'Drafts retention period (days)')"
						:tooltip="t('sdkmc', 'Set how many days draft messages are kept before being automatically deleted. Use 0 to disable automatic deletion.')"
						:validator="formsConfig.retentionSettings.validators.mailRetentionDraft"
						@update:modelValue="forms.retentionSettings.mailRetentionDraft = $event" />
					<div class="row">
						<NcButton
							type="submit"
							:disabled="loading">
							{{ t('sdkmc', 'Save') }}
						</NcButton>
					</div>
					<div class="retention-action-section">
						<p class="retention-action-description">
							{{ t('sdkmc', 'Manually trigger retention cleanup to delete messages older than the configured retention periods.') }}
						</p>
						<NcButton
							type="error"
							:label="isRunningRetention ? t('sdkmc', 'Running...') : t('sdkmc', 'Run Now')"
							:loading="isRunningRetention"
							:disabled="loading"
							@click="runRetentionNow">
							{{ t('sdkmc', 'Run Now') }}
						</NcButton>
					</div>
				</form>
			</NcAppSidebarTab>
			<NcAppSidebarTab id="sdk-tab" :name="t('sdkmc', 'SDK Settings')" :order="4">
				<form class="form-grid" @submit.prevent="submitForm('sdk')">
					<NcInputFieldItsl
						:model-value="forms.sdk.organizationExtension"
						label="name"
						:label-above="t('sdkmc', 'Organization Extension')"
						:tooltip="t('sdkmc', 'Identifier or extension used to distinguish this organization.')"
						:component="NcSelect"
						:component-props="{
							options: organizations,
							optionValue: 'address',
							searchable: true,
							filterBy: addressFilter,
							mapOptions: true,
						}"
						@update:modelValue="forms.sdk.organizationExtension = $event" />
					<NcInputFieldItsl
						:model-value="forms.sdk.addressBookBaseUrl"
						:label="t('sdkmc', 'Base URL for SDK address book updates')"
						:tooltip="t('sdkmc', 'Base URL used by the SDK to update the address book.')"
						:validator="formsConfig.sdk.validators.addressBookBaseUrl"
						@update:modelValue="forms.sdk.addressBookBaseUrl = $event" />
					<div class="settings-input-wrapper settings-input-wrapper--with-btn">
						<NcInputFieldItsl
							:model-value="forms.sdk.addressBookUpdateFrequency"
							:label="t('sdkmc', 'Update frequency for address book (in seconds)')"
							:tooltip="t('sdkmc', 'How often the address book is updated, in seconds.')"
							:validator="formsConfig.sdk.validators.addressBookUpdateFrequency"
							@update:modelValue="forms.sdk.addressBookUpdateFrequency = $event" />
						<NcButton
							class="ml-2 settings-update-btn"
							:label="isUpdatingAddressBook ? t('sdkmc', 'Updating...') : t('sdkmc', 'Update Now')"
							:loading="isUpdatingAddressBook"
							:disabled="loading"
							@click="updateAddressBookNow">
							{{ t('sdkmc', 'Update Now') }}
						</NcButton>
					</div>

					<div class="row">
						<NcButton
							type="submit"
							:disabled="loading">
							{{ t('sdkmc', 'Save') }}
						</NcButton>
					</div>
				</form>
			</NcAppSidebarTab>

			<NcAppSidebarTab id="secure-mail-tab" :name="t('sdkmc', 'Secure Mail Settings')" :order="5">
				<form class="form-grid" @submit.prevent="submitForm('secureMail')">
					<NcInputFieldItsl
						:model-value="forms.secureMail.secureMailFromEmail"
						:label="t('sdkmc', 'From address for secure mail')"
						:tooltip="t('sdkmc', 'Email address used as the From sender for secure mail.')"
						:validator="formsConfig.secureMail.validators.secureMailFromEmail"
						@update:modelValue="forms.secureMail.secureMailFromEmail = $event" />
					<NcInputFieldItsl
						:model-value="forms.secureMail.secureMailSubject"
						:label="t('sdkmc', 'Email subject for secure mail')"
						:tooltip="t('sdkmc', 'Subject line used for secure mail sent to individuals.')"
						:validator="formsConfig.secureMail.validators.secureMailSubject"
						@update:modelValue="forms.secureMail.secureMailSubject = $event" />
					<NcInputFieldItsl
						:model-value="forms.secureMail.secureMailMessage"
						:label="t('sdkmc', 'Secure mail notification message')"
						:tooltip="t('sdkmc', 'Email body template used for secure mail notifications to private recipients.')"
						:is-textarea="true"
						:validator="formsConfig.secureMail.validators.secureMailMessage"
						:error="errors.secureMail.secureMailMessage"
						@update:modelValue="forms.secureMail.secureMailMessage = $event" />
					<NcInputFieldItsl
						:model-value="forms.secureMail.orgSecureMailSubject"
						:label="t('sdkmc', 'Email subject for organization secure mail')"
						:tooltip="t('sdkmc', 'Subject line used for secure mail sent to organizations or unverified recipients.')"
						:validator="formsConfig.secureMail.validators.orgSecureMailSubject"
						:error="errors.secureMail.orgSecureMailSubject"
						@update:modelValue="forms.secureMail.orgSecureMailSubject = $event" />
					<NcInputFieldItsl
						:model-value="forms.secureMail.orgSecureMailMessage"
						:label="t('sdkmc', 'Organization secure mail notification message')"
						:tooltip="t('sdkmc', 'Email body template used for secure mail notifications to organizations or unverified recipients.')"
						:is-textarea="true"
						:validator="formsConfig.secureMail.validators.orgSecureMailMessage"
						:error="errors.secureMail.orgSecureMailMessage"
						@update:modelValue="forms.secureMail.orgSecureMailMessage = $event" />
					<NcInputFieldItsl
						:model-value="forms.secureMail.enforcePersonalSecuremail"
						:component="NcCheckboxRadioSwitch"
						:component-props="{
							type: 'switch',
						}"
						:label-above="t('sdkmc', 'Enforce personal secure message policy')"
						:tooltip="t('sdkmc', 'Forces all secure messages to be sent as personal messages. Users must provide SSN.')"
						:validator="formsConfig.secureMail.validators.enforcePersonalSecuremail"
						@update:modelValue="forms.secureMail.enforcePersonalSecuremail = $event" />
					<div class="row">
						<NcButton
							type="submit"
							:disabled="loading">
							{{ t('sdkmc', 'Save') }}
						</NcButton>
					</div>
				</form>
			</NcAppSidebarTab>

			<NcAppSidebarTab id="meetings-tab" :name="t('sdkmc', 'Meetings Settings')" :order="6">
				<form class="form-grid" @submit.prevent="submitForm('meetings')">
					<NcInputFieldItsl
						:model-value="Number(forms.meetings.default_lobby_state) === 1"
						:component="NcCheckboxRadioSwitch"
						:component-props="{
							type: 'switch',
						}"
						:label-above="t('sdkmc', 'Default lobby active for new conversations')"
						:tooltip="t('sdkmc', 'Every newly created group or public conversation starts with lobby enabled')"
						@update:modelValue="forms.meetings.default_lobby_state = $event ? 1 : 0" />
					<NcInputFieldItsl
						:model-value="forms.meetings.secureMeetingsEnabled"
						:component="NcCheckboxRadioSwitch"
						:component-props="{
							type: 'switch',
						}"
						:label-above="t('sdkmc', 'Enable Secure Meeting bookings')"
						:tooltip="t('sdkmc', 'This toggle enables or disables the possibility to book secure meetings through the calendar')"
						:validator="formsConfig.meetings.validators.secureMeetingsEnabled"
						@update:modelValue="forms.meetings.secureMeetingsEnabled = $event" />
					<NcInputFieldItsl
						:model-value="forms.meetings.clientId"
						:label="t('sdkmc', 'Client ID for OIDC provider')"
						:tooltip="t('sdkmc', 'Client ID configured in the external OIDC provider.')"
						:validator="formsConfig.meetings.validators.clientId"
						@update:modelValue="forms.meetings.clientId = $event" />
					<NcInputFieldItsl
						type="password"
						:model-value="forms.meetings.clientSecret"
						:label="t('sdkmc', 'Client Secret for OIDC provider')"
						:tooltip="t('sdkmc', 'Client secret configured in the external OIDC provider.')"
						:validator="formsConfig.meetings.validators.clientSecret"
						@update:modelValue="forms.meetings.clientSecret = $event" />
					<NcInputFieldItsl
						:model-value="forms.meetings.authorizeUrl"
						:label="t('sdkmc', 'Authorize URL')"
						:tooltip="t('sdkmc', 'URL to the OIDC authorization endpoint.')"
						:validator="formsConfig.meetings.validators.authorizeUrl"
						@update:modelValue="forms.meetings.authorizeUrl = $event" />
					<NcInputFieldItsl
						:model-value="forms.meetings.tokenUrl"
						:label="t('sdkmc', 'Token URL')"
						:tooltip="t('sdkmc', 'URL to the OIDC token endpoint.')"
						:validator="formsConfig.meetings.validators.tokenUrl"
						@update:modelValue="forms.meetings.tokenUrl = $event" />
					<NcInputFieldItsl
						:model-value="forms.meetings.redirectUrl"
						:label="t('sdkmc', 'Redirect URL')"
						:tooltip="t('sdkmc', 'URL where the OIDC provider should redirect back after authentication.')"
						:validator="formsConfig.meetings.validators.redirectUrl"
						@update:modelValue="forms.meetings.redirectUrl = $event" />
					<NcInputFieldItsl
						:model-value="forms.meetings.scope"
						:label="t('sdkmc', 'Scope')"
						:tooltip="t('sdkmc', 'Space separated list of scopes to request (e.g. profile email openid).')"
						:validator="formsConfig.meetings.validators.scope"
						@update:modelValue="forms.meetings.scope = $event" />
					<NcInputFieldItsl
						:model-value="forms.meetings.ssnClaim"
						:label="t('sdkmc', 'SSN Claim')"
						:tooltip="t('sdkmc', 'Name of the claim in the OIDC ID token that contains the personnummer (e.g. personalNumber, preferred_username). Leave empty to use default fallback chain.')"
						placeholder="personalNumber"
						:validator="formsConfig.meetings.validators.ssnClaim"
						@update:modelValue="forms.meetings.ssnClaim = $event" />
					<NcInputFieldItsl
						:model-value="forms.meetings.firstNameClaim"
						:label="t('sdkmc', 'First Name Claim')"
						:tooltip="t('sdkmc', 'Name of the claim in the OIDC ID token that contains the first name (e.g. givenName, given_name). Leave empty to use default fallback chain.')"
						placeholder="givenName"
						:validator="formsConfig.meetings.validators.firstNameClaim"
						@update:modelValue="forms.meetings.firstNameClaim = $event" />
					<NcInputFieldItsl
						:model-value="forms.meetings.lastNameClaim"
						:label="t('sdkmc', 'Last Name Claim')"
						:tooltip="t('sdkmc', 'Name of the claim in the OIDC ID token that contains the last name (e.g. surname, family_name). Leave empty to use default fallback chain.')"
						placeholder="surname"
						:validator="formsConfig.meetings.validators.lastNameClaim"
						@update:modelValue="forms.meetings.lastNameClaim = $event" />
					<NcInputFieldItsl
						:model-value="forms.meetings.smsGateway"
						label="label"
						:label-above="t('sdkmc', 'SMS Gateway')"
						:tooltip="t('sdkmc', 'Configured SMS gateway/integration used for sending text messages.')"
						:component="NcSelect"
						:component-props="{
							options: smsGateways,
							optionValue: 'value',
							searchable: false,
							mapOptions: true,
						}"
						@update:modelValue="forms.meetings.smsGateway = $event" />
					<NcInputFieldItsl
						:model-value="forms.meetings.smsGatewayUsername"
						:label="t('sdkmc', 'SMS Gateway Username')"
						:tooltip="t('sdkmc', 'Account username for authenticating with the SMS gateway service.')"
						:validator="formsConfig.meetings.validators.smsGatewayUsername"
						@update:modelValue="forms.meetings.smsGatewayUsername = $event" />
					<NcInputFieldItsl
						type="password"
						:model-value="forms.meetings.smsGatewayPassword"
						:label="t('sdkmc', 'SMS Gateway Password')"
						:tooltip="t('sdkmc', 'Account password for authenticating with the SMS gateway service.')"
						:validator="formsConfig.meetings.validators.smsGatewayPassword"
						@update:modelValue="forms.meetings.smsGatewayPassword = $event" />
					<NcInputFieldItsl
						:model-value="forms.meetings.smsGatewaySender"
						:label="t('sdkmc', 'SMS Gateway Sender')"
						:tooltip="t('sdkmc', 'Alphanumeric, max 11 chars, must start with a letter')"
						:validator="formsConfig.meetings.validators.smsGatewaySender"
						@update:modelValue="forms.meetings.smsGatewaySender = $event" />
					<NcInputFieldItsl
						:model-value="forms.meetings.smsMessageContent"
						:label="t('sdkmc', 'SMS message: use #ORGANIZER_NAME for organizer, #ATTENDEE_EMAIL for attendee')"
						:tooltip="t('sdkmc', 'Use placeholders in your message, e.g. “You have been invited by #ORGANIZER_NAME and the invitation was sent to #ATTENDEE_EMAIL.”')"
						:validator="formsConfig.meetings.validators.smsMessageContent"
						@update:modelValue="forms.meetings.smsMessageContent = $event" />

					<NcInputFieldItsl
						:model-value="forms.meetings.eventInvitationOrganizer"
						:label="t('sdkmc', 'Organizer for calendar event invitation email')"
						:tooltip="t('sdkmc', 'Provide the organizer line for the calendar invitation email. Leave blank for the organizers actual name and email')"
						:validator="formsConfig.meetings.validators.eventInvitationOrganizer"
						@update:modelValue="forms.meetings.eventInvitationOrganizer = $event" />
					<NcInputFieldItsl
						:model-value="forms.meetings.eventInvitationSubject"
						:label="t('sdkmc', 'Subject for calendar event invitation email')"
						:tooltip="t('sdkmc', 'Provide the subject line for the calendar invitation email. You may use placeholders such as #ORGANIZER_NAME and #ATTENDEE_EMAIL.')"
						:validator="formsConfig.meetings.validators.eventInvitationSubject"
						@update:modelValue="forms.meetings.eventInvitationSubject = $event" />
					<NcInputFieldItsl
						:model-value="forms.meetings.eventInvitationBody"
						:label="t('sdkmc', '[HTML] Body for calendar event invitation email')"
						:tooltip="t('sdkmc', 'Compose the HTML email body for the invitation. Available placeholders:\n\n• #INVITATION_LINK (required) - Meeting invitation link\n• #TITLE - Event title\n• #DESCRIPTION - Event description\n• #DATE_AND_TIME - Formatted date and time\n• #ACCEPT_LINK - Accept invitation link\n• #DECLINE_LINK - Decline invitation link')"
						:is-textarea="true"
						:validator="formsConfig.meetings.validators.eventInvitationBody"
						:error="errors.meetings.eventInvitationBody"
						@update:modelValue="forms.meetings.eventInvitationBody = $event" />
					<div class="row">
						<NcButton
							type="submit"
							:disabled="loading">
							{{ t('sdkmc', 'Save') }}
						</NcButton>
					</div>
				</form>
			</NcAppSidebarTab>

			<NcAppSidebarTab id="entra-id" :name="t('sdkmc', 'Entra ID')" :order="7">
				<form class="form-grid" @submit.prevent="submitForm('entraID')">
					<NcInputFieldItsl
						:disabled="true"
						:model-value="forms.entraID.tenantUrl"
						:label="t('sdkmc', 'Tenant URL')"
						:tooltip="t('sdkmc', 'URL to the Entra ID tenant. Read-only, configured by the SCIM component.')" />
					<NcInputFieldItsl
						:disabled="true"
						:model-value="forms.entraID['jwt-token']"
						:label="t('sdkmc', 'JWT Token')"
						:tooltip="t('sdkmc', 'Authentication token. Read-only, configured by the SCIM component.')" />
					<NcInputFieldItsl
						:model-value="forms.entraID.ENTRA_GRAPH_CLIENT_ID"
						:label="t('sdkmc', 'Graph Client ID')"
						:tooltip="t('sdkmc', 'Client ID from your Microsoft Entra ID app registration.')"
						:validator="formsConfig.entraID.validators.ENTRA_GRAPH_CLIENT_ID"
						@update:modelValue="forms.entraID.ENTRA_GRAPH_CLIENT_ID = $event" />
					<NcInputFieldItsl
						:model-value="forms.entraID.ENTRA_GRAPH_CLIENT_SECRET"
						:label="t('sdkmc', 'Graph Client Secret')"
						:tooltip="t('sdkmc', 'Client secret value from your Microsoft Entra ID app registration.')"
						:validator="formsConfig.entraID.validators.ENTRA_GRAPH_CLIENT_SECRET"
						@update:modelValue="forms.entraID.ENTRA_GRAPH_CLIENT_SECRET = $event" />
					<NcInputFieldItsl
						:model-value="forms.entraID.ENTRA_GRAPH_TENANT_ID"
						:label="t('sdkmc', 'Graph Tenant ID')"
						:tooltip="t('sdkmc', 'Tenant ID from your Microsoft Entra ID.')"
						:validator="formsConfig.entraID.validators.ENTRA_GRAPH_TENANT_ID"
						@update:modelValue="forms.entraID.ENTRA_GRAPH_TENANT_ID = $event" />
					<NcInputFieldItsl
						:model-value="forms.entraID.SYNC_GROUP_PATTERN"
						:label="t('sdkmc', 'Sync Group Pattern')"
						:tooltip="t('sdkmc', 'Sync Group Pattern')"
						:validator="formsConfig.entraID.validators.SYNC_GROUP_PATTERN"
						@update:modelValue="forms.entraID.SYNC_GROUP_PATTERN = $event" />
					<div class="row">
						<NcButton
							type="submit"
							:disabled="loading">
							{{ t('sdkmc', 'Save') }}
						</NcButton>
					</div>
				</form>
			</NcAppSidebarTab>
		</NcAppSidebar>

		<!-- Apply Notification Defaults Confirmation Dialog -->
		<div v-if="showApplyConfirmDialog" class="custom-modal-backdrop" @click.self="showApplyConfirmDialog = false">
			<div class="custom-modal"
				role="dialog"
				aria-modal="true"
				:aria-label="t('sdkmc', 'Apply notification defaults')">
				<div class="custom-modal__header">
					<h2 class="custom-modal__title">
						{{ t('sdkmc', 'Apply notification defaults') }}
					</h2>
					<button class="custom-modal__close" :aria-label="t('sdkmc', 'Close')" @click="showApplyConfirmDialog = false">
						<span aria-hidden="true">&times;</span>
					</button>
				</div>
				<div class="custom-modal__content">
					<p class="modal-warning-text">
						{{ t('sdkmc', 'The following settings will be applied to all existing users:') }}
					</p>
					<ul class="apply-summary-list">
						<li v-for="(item, index) in selectedSummary" :key="index">
							{{ item.name }} — {{ item.method }}:
							{{ item.value ? t('sdkmc', 'ON') : t('sdkmc', 'OFF') }}
							<span v-if="item.differCount > 0" class="apply-summary-drift">
								({{ t('sdkmc', '{count} users differ', { count: item.differCount }) }})
							</span>
						</li>
					</ul>
					<p class="modal-warning-text modal-warning-text--danger">
						{{ t('sdkmc', 'Users who have customized these settings will have them overwritten.') }}
					</p>
					<div class="modal-footer">
						<NcButton @click="showApplyConfirmDialog = false">
							{{ t('sdkmc', 'Cancel') }}
						</NcButton>
						<NcButton type="warning" @click="confirmApplyDefaults">
							{{ isApplyingDefaults ? t('sdkmc', 'Applying...') : t('sdkmc', 'Apply') }}
						</NcButton>
					</div>
				</div>
			</div>
		</div>

		<!-- Retention Confirmation Dialog -->
		<div v-if="showRetentionConfirmDialog" class="custom-modal-backdrop" @click.self="showRetentionConfirmDialog = false">
			<div class="custom-modal retention-confirm-modal"
				role="dialog"
				aria-modal="true"
				:aria-label="t('sdkmc', 'Confirm retention cleanup')">
				<div class="custom-modal__header">
					<h2 class="custom-modal__title">
						{{ t('sdkmc', 'Confirm retention cleanup') }}
					</h2>
					<button class="custom-modal__close" :aria-label="t('sdkmc', 'Close')" @click="showRetentionConfirmDialog = false">
						<span aria-hidden="true">&times;</span>
					</button>
				</div>
				<div class="custom-modal__content">
					<p class="modal-warning-text">
						{{ t('sdkmc', 'This will permanently delete messages older than the configured retention periods for all mailboxes.') }}
					</p>
					<p class="modal-warning-text modal-warning-text--danger">
						{{ t('sdkmc', 'This action cannot be undone.') }}
					</p>
					<div class="modal-footer">
						<NcButton @click="showRetentionConfirmDialog = false">
							{{ t('sdkmc', 'Cancel') }}
						</NcButton>
						<NcButton type="error" @click="confirmRunRetention">
							{{ t('sdkmc', 'Run Now') }}
						</NcButton>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script setup>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { reactive, ref, computed, onBeforeMount, onMounted, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcInputFieldItsl from '../components/NcInputFieldItsl.vue'
import { SDKMC_API_ROUTES } from '../utils/constants.js'
import {
	NcButton,
	NcAppSidebar,
	NcAppSidebarTab,
	NcSelect,
	NcCheckboxRadioSwitch,
	NcNoteCard,
} from '@nextcloud/vue'
import { getRequestToken } from '@nextcloud/auth'
import { showError, showSuccess } from '@nextcloud/dialogs'

const formsConfig = {
	global: {
		fields: {
			sdkmcmwSecretPassword: '',
			smtpHost: '',
			smtpPort: '',
			smtpInboundHost: '',
			smtpInboundPort: '',
			imapHost: '',
			imapPort: '',
			hideDefaultLoginLink: '',
			loginProvider: '',
			loa3Enabled: '',
			loa3Auth: '',
			loa3Tag: '',
			debugLogin: '',
			autoLogoutSeconds: '',
			threadSortNewestFirst: '',
			selectNewestInThread: '',
		},
		validators: {
			sdkmcmwSecretPassword: v => '',
			smtpHost: v => '',
			smtpPort: v => v && isNaN(+v) ? t('sdkmc', 'Please enter a valid number.') : '',
			smtpInboundHost: v => '',
			smtpInboundPort: v => v && isNaN(+v) ? t('sdkmc', 'Please enter a valid number.') : '',
			imapHost: v => '',
			imapPort: v => v && isNaN(+v) ? t('sdkmc', 'Please enter a valid number.') : '',
			hideDefaultLoginLink: v => '',
			loginProvider: v => {
				if (!v) return ''
				const regex = /^[a-zA-Z0-9_]+$/
				return regex.test(v.trim()) ? '' : t('sdkmc', 'Invalid format. Use letters (a-z, A-Z), numbers (0-9) and underscore (_) only.')
			},
			loa3Enabled: v => '',
			loa3Auth: v => {
				if (!v) return ''
				const regex = /^[a-zA-Z0-9-_/]+$/
				return regex.test(v.trim()) ? '' : t('sdkmc', 'Invalid format. Use letters (a-z, A-Z), numbers (0-9), hyphen (-), underscore (_) and slash (/) only.')
			},
			loa3Tag: v => '',
			debugLogin: v => '',
			autoLogoutSeconds: v => {
				const num = Number(v)
				if (isNaN(num)) {
					return t('sdkmc', 'Please enter a valid number.')
				}

				if (num !== 0 && num < 120) {
					return t('sdkmc', 'Use 0 to keep sessions always active, or 120+ seconds to enable autologout.')
				}

				return ''
			},
			threadSortNewestFirst: v => '',
			selectNewestInThread: v => '',
		},
	},
	sdk: {
		fields: {
			organizationExtension: '',
			addressBookBaseUrl: '',
			addressBookUpdateFrequency: '',
		},
		validators: {
			organizationExtension: v => '',
			addressBookBaseUrl: v => '',
			addressBookUpdateFrequency: v => {
				if (!v) return ''
				if (!/^\d+$/.test(v)) return 'Invalid number. Only non-negative integers are allowed.'
				return ''
			},
		},
	},
	retentionSettings: {
		fields: {
			deleteTalkAfterDays: '',
			retention_event_rooms: '',
			mailRetentionDefault: '',
			mailRetentionInbox: '',
			mailRetentionSent: '',
			mailRetentionArchive: '',
			mailRetentionTrash: '',
			mailRetentionDraft: '',
		},
		validators: {
			deleteTalkAfterDays: v => {
				const num = Number(v)
				if (isNaN(num)) {
					return t('sdkmc', 'Please enter a valid number.')
				}

				if (num < 0) {
					return t('sdkmc', 'Use 0 to disable this feature, or enter the number of days to keep inactive conversations.')
				}

				return ''
			},
			retention_event_rooms: v => {
				const num = Number(v)
				if (isNaN(num)) {
					return t('sdkmc', 'Please enter a valid number.')
				}

				if (num < 0) {
					return t('sdkmc', 'Use 0 to disable this feature, or enter the number of days to keep inactive conversations.')
				}

				return ''
			},
			_makeDaysValidator: v => {
				const raw = (v ?? '').toString().trim()
				const num = Number(raw === '' ? 0 : raw)

				if (Number.isNaN(num)) {
					return t('sdkmc', 'Please enter a valid number.')
				}
				if (!Number.isInteger(num)) {
					return t('sdkmc', 'Please enter a whole number of days.')
				}
				if (num < 0) {
					return t('sdkmc', 'Use 0 to disable this feature, or enter the number of days to keep items.')
				}
				return ''
			},
			mailRetentionDefault: v => {
				return formsConfig.retentionSettings.validators._makeDaysValidator(v)
			},
			mailRetentionInbox: v => {
				return formsConfig.retentionSettings.validators._makeDaysValidator(v)
			},
			mailRetentionSent: v => {
				return formsConfig.retentionSettings.validators._makeDaysValidator(v)
			},
			mailRetentionArchive: v => {
				return formsConfig.retentionSettings.validators._makeDaysValidator(v)
			},
			mailRetentionTrash: v => {
				return formsConfig.retentionSettings.validators._makeDaysValidator(v)
			},
			mailRetentionDraft: v => {
				return formsConfig.retentionSettings.validators._makeDaysValidator(v)
			},
		},
	},
	secureMail: {
		fields: {
			secureMailFromEmail: '',
			secureMailSubject: '',
			secureMailMessage: '',
			orgSecureMailSubject: '',
			orgSecureMailMessage: '',
			enforcePersonalSecuremail: '',
		},
		validators: {
			secureMailFromEmail: v => {
				if (!v) return ''
				return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v) ? '' : 'Invalid email address.'
			},
			secureMailSubject: v => '',
			secureMailMessage: v => '',
			orgSecureMailSubject: v => '',
			orgSecureMailMessage: v => '',
			enforcePersonalSecuremail: v => '',
		},
	},
	meetings: {
		fields: {
			default_lobby_state: 0,
			secureMeetingsEnabled: '',
			clientId: '',
			clientSecret: '',
			authorizeUrl: '',
			tokenUrl: '',
			redirectUrl: '',
			scope: '',
			ssnClaim: '',
			firstNameClaim: '',
			lastNameClaim: '',
			smsGateway: '',
			smsGatewayUsername: '',
			smsGatewayPassword: '',
			smsGatewaySender: '',
			smsMessageContent: '',
			eventInvitationOrganizer: '',
			eventInvitationSubject: '',
			eventInvitationBody: '',
		},
		validators: {
			default_lobby_state: v => '',
			secureMeetingsEnabled: v => '',
			clientId: v => '',
			clientSecret: v => '',
			authorizeUrl: v => '',
			tokenUrl: v => '',
			redirectUrl: v => '',
			scope: v => '',
			ssnClaim: v => '',
			firstNameClaim: v => '',
			lastNameClaim: v => '',
			smsGateway: v => '',
			smsGatewayUsername: v => '',
			smsGatewayPassword: v => '',
			smsGatewaySender: v => {
				if (!v) return ''
				if (v.length > 11) return t('sdkmc', 'Sender must not exceed 11 characters.')
				if (!/^[A-Za-zåäöÅÄÖ][A-Za-z0-9åäöÅÄÖ]*$/.test(v)) {
					return t('sdkmc', 'Sender must start with a letter and contain only letters and numbers.')
				}
				return ''
			},
			smsMessageContent: v => '',
			eventInvitationOrganizer: v => '',
			eventInvitationSubject: v => '',
			eventInvitationBody: v => {
				if (!v) return ''
				if (!v.includes('#INVITATION_LINK')) {
					return t('sdkmc', 'The body must include the #INVITATION_LINK placeholder.')
				}
				return ''
			},
		},
	},
	entraID: {
		fields: {
			tenantUrl: '',
			'jwt-token': '',
			ENTRA_GRAPH_CLIENT_ID: '',
			ENTRA_GRAPH_CLIENT_SECRET: '',
			ENTRA_GRAPH_TENANT_ID: '',
			SYNC_GROUP_PATTERN: '',
		},
		validators: {
			tenantUrl: v => '',
			'jwt-token': v => '',
			ENTRA_GRAPH_CLIENT_ID: v => '',
			ENTRA_GRAPH_CLIENT_SECRET: v => '',
			ENTRA_GRAPH_TENANT_ID: v => '',
			SYNC_GROUP_PATTERN: v => '',
		},
	},
}

const loading = ref(false)
const isUpdatingAddressBook = ref(false)
const isRunningRetention = ref(false)
const showRetentionConfirmDialog = ref(false)
const activeTab = ref('global-tab')

// Notifications tab state
const notificationStatus = ref(null)
const isLoadingStatus = ref(false)
const isApplyingDefaults = ref(false)
const showApplyConfirmDialog = ref(false)
const selectedKeys = ref(new Set())
const expandedGroups = ref(new Set())
const organizations = ref([])
const forms = reactive(Object.fromEntries(Object.entries(formsConfig).map(([k, v]) => [k, { ...v.fields }])))
const errors = reactive(Object.fromEntries(Object.entries(formsConfig).map(([k, v]) => [k, Object.fromEntries(Object.keys(v.fields).map(f => [f, '']))])))
const smsGateways = ref([
	{ label: '46Elks', value: '46elks' },
	{ label: 'Generic', value: 'generic' },
	{ label: 'Mock Gateway', value: 'mock' },
])

const addressFilter = function(option, label, search) {
	return option.searchableName.includes(search.toLowerCase())
}

onBeforeMount(async () => {
	try {
		const responseAddressBookOrgs = await axios.get(SDKMC_API_ROUTES.ADDRESS_BOOK.GET_ORGANIZTIONS)
		const tempMap = new Map()
		if (responseAddressBookOrgs.data?.data) {
			for (const orgInfo of responseAddressBookOrgs.data.data) {
				tempMap.set(orgInfo.id, {
					id: orgInfo.id,
					originalName: orgInfo.attributes.name,
					name: `${orgInfo.attributes.name || `(${t('sdkmc', 'Name not provided')})`} (${orgInfo.attributes.participantIdentifier})`.trim(),
					searchableName: `${orgInfo.attributes.name || ''} (${orgInfo.attributes.participantIdentifier})`.trim().toLowerCase() ?? '',
					address: orgInfo.attributes.participantIdentifier,
					functionAddresses: [],
				})
			}
		}
		organizations.value.push(...Array.from(tempMap.values()))

		const response = await axios.get(SDKMC_API_ROUTES.SERVER_SETTINGS.GET)
		// Update form values
		for (const section in formsConfig) {
			for (const field in formsConfig[section].fields) {
				if (response.data[field] !== undefined) {
					forms[section][field] = response.data[field]
				}
			}
		}
		if (organizations.value.length > 0) {
			forms.sdk.organizationExtension = organizations.value.find((elem) => elem.address === forms.sdk.organizationExtension)
		}
		if (forms.meetings.smsGateway) {
			forms.meetings.smsGateway = smsGateways.value.find(elem => elem.value === forms.meetings.smsGateway)
		}
	} catch (e) {
		console.error('Failed to load settings:', e)
		showError(t('sdkmc', 'Failed to load settings. Please try again.'))
	} finally {
		loading.value = false
	}
})
onMounted(() => {
	// Load notification status if the tab is already active on mount
	if (activeTab.value === 'notifications-tab') {
		loadNotificationStatus()
	}
})
function validate(formKey) {
	let valid = true
	const validators = formsConfig[formKey].validators

	for (const field in forms[formKey]) {
		const val = forms[formKey][field]
		const validateFn = validators[field]
		const error = validateFn ? validateFn(val) : ''
		errors[formKey][field] = error
		if (error) valid = false
	}

	return valid
}

async function updateAddressBookNow() {
	isUpdatingAddressBook.value = true
	try {
		const response = await axios.get(generateUrl(SDKMC_API_ROUTES.ADDRESS_BOOK.UPDATE_NOW))
		if (response.status === 200 || response.status === 204) {
			showSuccess(t('sdkmc', 'Address book updated successfully!'))
		} else {
			throw new Error(`Unexpected response status: ${response.status}`)
		}
	} catch (e) {
		console.error('Update address book failed:', e)
		showError(t('sdkmc', 'Failed to update address book. Please try again.'))
	} finally {
		isUpdatingAddressBook.value = false
	}
}

function runRetentionNow() {
	showRetentionConfirmDialog.value = true
}

async function confirmRunRetention() {
	showRetentionConfirmDialog.value = false
	isRunningRetention.value = true
	try {
		const response = await axios.get(generateUrl('/apps/sdkmc/api/v2/admin/runExpungeNow'))
		if (response.status === 200 || response.status === 204) {
			showSuccess(t('sdkmc', 'Retention job started successfully!'))
		} else {
			throw new Error(`Unexpected response status: ${response.status}`)
		}
	} catch (e) {
		console.error('Run retention failed:', e)
		showError(t('sdkmc', 'Failed to start retention job. Please try again.'))
	} finally {
		isRunningRetention.value = false
	}
}

// Notifications tab computed properties
const activitySettingsUrl = computed(() => generateUrl('/settings/admin/activity'))

const hasAnyDrift = computed(() => {
	if (!notificationStatus.value?.groups) return false
	return notificationStatus.value.groups.some(
		g => g.settings.some(s => s.email.differCount > 0 || s.push.differCount > 0),
	)
})

const hasSelectedKeys = computed(() => selectedKeys.value.size > 0)

const selectedSummary = computed(() => {
	if (!notificationStatus.value?.groups) return []
	const summary = []
	for (const group of notificationStatus.value.groups) {
		for (const setting of group.settings) {
			if (selectedKeys.value.has(setting.email.key)) {
				summary.push({
					name: setting.name,
					method: t('sdkmc', 'Email'),
					value: setting.email.adminDefault,
					differCount: setting.email.differCount,
				})
			}
			if (selectedKeys.value.has(setting.push.key)) {
				summary.push({
					name: setting.name,
					method: t('sdkmc', 'Push'),
					value: setting.push.adminDefault,
					differCount: setting.push.differCount,
				})
			}
		}
	}
	return summary
})

const allDriftSelected = computed(() => {
	if (!notificationStatus.value?.groups) return false
	for (const g of notificationStatus.value.groups) {
		for (const s of g.settings) {
			if (s.email.differCount > 0 && !selectedKeys.value.has(s.email.key)) return false
			if (s.push.differCount > 0 && !selectedKeys.value.has(s.push.key)) return false
		}
	}
	return hasAnyDrift.value
})

// Notifications tab methods
async function loadNotificationStatus() {
	isLoadingStatus.value = true
	try {
		const response = await axios.get(generateUrl(SDKMC_API_ROUTES.ACTIVITY.GET_STATUS))
		notificationStatus.value = response.data
		// Clear selections that no longer have drift
		for (const key of [...selectedKeys.value]) {
			let found = false
			for (const g of response.data.groups ?? []) {
				if (g.settings.some(
					s => (s.email.key === key && s.email.differCount > 0)
					  || (s.push.key === key && s.push.differCount > 0),
				)) { found = true; break }
			}
			if (!found) selectedKeys.value.delete(key)
		}
		selectedKeys.value = new Set(selectedKeys.value)
	} catch (e) {
		console.error('Failed to load notification status:', e)
		showError(t('sdkmc', 'Failed to load settings. Please try again.'))
	} finally {
		isLoadingStatus.value = false
	}
}

function toggleKey(key) {
	if (selectedKeys.value.has(key)) {
		selectedKeys.value.delete(key)
	} else {
		selectedKeys.value.add(key)
	}
	// Force reactivity on Set
	selectedKeys.value = new Set(selectedKeys.value)
}

function toggleSelectAllDrift() {
	if (allDriftSelected.value) {
		selectedKeys.value = new Set()
	} else {
		const keys = new Set()
		const groupsToExpand = new Set(expandedGroups.value)
		for (const g of notificationStatus.value?.groups ?? []) {
			let groupHasDrift = false
			for (const s of g.settings) {
				if (s.email.differCount > 0) { keys.add(s.email.key); groupHasDrift = true }
				if (s.push.differCount > 0) { keys.add(s.push.key); groupHasDrift = true }
			}
			if (groupHasDrift) groupsToExpand.add(g.id)
		}
		selectedKeys.value = keys
		expandedGroups.value = groupsToExpand
	}
}

function groupDriftCount(group) {
	let total = 0
	for (const s of group.settings) {
		total += s.email.differCount + s.push.differCount
	}
	return total
}

function groupEmailState(group) {
	const changeable = group.settings.filter(s => s.email.canChange)
	if (changeable.length === 0) return 'unchecked'
	const selectedCount = changeable.filter(s => selectedKeys.value.has(s.email.key)).length
	if (selectedCount === 0) return 'unchecked'
	if (selectedCount === changeable.length) return 'checked'
	return 'indeterminate'
}

function groupPushState(group) {
	const changeable = group.settings.filter(s => s.push.canChange)
	if (changeable.length === 0) return 'unchecked'
	const selectedCount = changeable.filter(s => selectedKeys.value.has(s.push.key)).length
	if (selectedCount === 0) return 'unchecked'
	if (selectedCount === changeable.length) return 'checked'
	return 'indeterminate'
}

function toggleGroupEmail(group) {
	const state = groupEmailState(group)
	const changeable = group.settings.filter(s => s.email.canChange)
	const keys = new Set(selectedKeys.value)
	if (state === 'checked') {
		for (const s of changeable) keys.delete(s.email.key)
	} else {
		for (const s of changeable) keys.add(s.email.key)
	}
	selectedKeys.value = keys
}

function toggleGroupPush(group) {
	const state = groupPushState(group)
	const changeable = group.settings.filter(s => s.push.canChange)
	const keys = new Set(selectedKeys.value)
	if (state === 'checked') {
		for (const s of changeable) keys.delete(s.push.key)
	} else {
		for (const s of changeable) keys.add(s.push.key)
	}
	selectedKeys.value = keys
}

function toggleGroup(groupId) {
	const groups = new Set(expandedGroups.value)
	if (groups.has(groupId)) {
		groups.delete(groupId)
	} else {
		groups.add(groupId)
	}
	expandedGroups.value = groups
}

async function confirmApplyDefaults() {
	showApplyConfirmDialog.value = false
	isApplyingDefaults.value = true
	try {
		const formData = new FormData()
		formData.append('requesttoken', getRequestToken())
		for (const key of selectedKeys.value) {
			formData.append('keys[]', key)
		}
		const response = await axios.post(
			generateUrl(SDKMC_API_ROUTES.ACTIVITY.PROPAGATE_DEFAULTS),
			formData,
			{ headers: { 'Content-Type': 'multipart/form-data' } },
		)
		if (response.status === 200) {
			const count = response.data?.updated ?? 0
			showSuccess(t('sdkmc', 'Notification defaults applied to {count} users.', { count }))
			selectedKeys.value = new Set()
			await loadNotificationStatus()
		} else {
			throw new Error(`Unexpected response status: ${response.status}`)
		}
	} catch (e) {
		console.error('Apply defaults failed:', e)
		const msg = e.response?.data?.error || t('sdkmc', 'Failed to apply notification defaults. Please try again.')
		showError(msg)
	} finally {
		isApplyingDefaults.value = false
	}
}

watch(activeTab, (newTab) => {
	if (newTab === 'notifications-tab') {
		loadNotificationStatus()
	}
})

async function submitForm(formKey) {
	if (!validate(formKey)) {
		showError(t('sdkmc', 'Please fix form errors.'))
		return
	}

	loading.value = true

	const formData = new FormData()

	formData.append('requesttoken', getRequestToken())

	for (const section in formsConfig) {
		for (const field in formsConfig[section].fields) {
			const value = forms[section][field]
			if (value !== undefined && value !== null) {
				formData.append(field, value)
			}
		}
	}
	// custom overriding orgExt value
	formData.set('organizationExtension', forms.sdk.organizationExtension?.address || '')
	formData.set('smsGateway', forms.meetings.smsGateway?.value || '')

	try {
		const response = await axios.post(SDKMC_API_ROUTES.SERVER_SETTINGS.UPDATE, formData, {
			headers: {
				'Content-Type': 'multipart/form-data',
			},
		})

		if (response.status === 200 || response.status === 204) {
			showSuccess(t('sdkmc', 'Settings saved successfully!'))
		} else {
			throw new Error(`Unexpected response status: ${response.status}`)
		}
	} catch (e) {
		console.error(e)
		showError(t('sdkmc', 'Failed to save settings. Please try again.'))
	} finally {
		loading.value = false
	}
}

</script>

<style scoped>
	.form-grid {
		display: flex;
		flex-direction: column;
		gap: 1rem;
		max-width: 600px;
	}

	.row {
		display: flex;
		gap: 1rem;
		margin-top: 1.5rem;
	}

	.retention-action-section {
		margin-top: 2rem;
		padding-top: 1.5rem;
		border-top: 1px solid var(--color-border);
	}

	.retention-action-description {
		margin-bottom: 0.75rem;
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
	}

	/* Retention Confirmation Dialog */
	.custom-modal-backdrop {
		position: fixed;
		top: 0;
		left: 0;
		width: 100%;
		height: 100%;
		background-color: rgba(0, 0, 0, 0.5);
		display: flex;
		justify-content: center;
		align-items: center;
		z-index: 10000;
	}

	.custom-modal {
		background: var(--color-main-background);
		border-radius: var(--border-radius-large);
		box-shadow: 0 0 40px rgba(0, 0, 0, 0.2);
		min-width: 400px;
		max-width: 500px;
	}

	.custom-modal__header {
		display: flex;
		justify-content: space-between;
		align-items: center;
		padding: 16px 24px;
		border-bottom: 1px solid var(--color-border);
	}

	.custom-modal__title {
		margin: 0;
		font-size: 18px;
		font-weight: 600;
		color: var(--color-main-text);
	}

	.custom-modal__close {
		background: none;
		border: none;
		font-size: 24px;
		cursor: pointer;
		color: var(--color-text-maxcontrast);
		padding: 0;
		width: 32px;
		height: 32px;
		display: flex;
		align-items: center;
		justify-content: center;
		border-radius: var(--border-radius);
	}

	.custom-modal__close:hover {
		background-color: var(--color-background-hover);
		color: var(--color-main-text);
	}

	.custom-modal__content {
		padding: 20px 24px;
	}

	.modal-warning-text {
		margin: 0 0 12px 0;
		color: var(--color-main-text);
	}

	.modal-warning-text--danger {
		color: var(--color-error);
		font-weight: 600;
	}

	.modal-footer {
		display: flex;
		justify-content: flex-end;
		gap: 12px;
		margin-top: 20px;
	}

	/* Notifications tab */
	.notification-tab-description {
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
		margin-bottom: 1rem;
	}

	.notification-tab-link {
		display: inline-block;
		margin-top: 0.25rem;
		color: var(--color-primary-element);
		text-decoration: none;
	}

	.notification-tab-link:hover {
		text-decoration: underline;
	}

	.notification-groups {
		width: 100%;
		max-width: 600px;
	}

	.notification-row {
		display: grid;
		grid-template-columns: 1fr 120px 80px 80px;
		align-items: center;
		min-height: 36px;
		padding: 0 12px;
	}

	.notification-row--header {
		border-bottom: 2px solid var(--color-border);
		font-weight: 600;
		font-size: 0.85em;
		color: var(--color-text-maxcontrast);
		min-height: 32px;
	}

	.notification-row--group {
		background-color: var(--color-background-dark);
		min-height: 44px;
		font-weight: 600;
		cursor: pointer;
		border-bottom: 1px solid var(--color-border);
		user-select: none;
	}

	.notification-row--group:hover {
		background-color: var(--color-background-hover);
	}

	.notification-row--setting {
		min-height: 36px;
		border-bottom: 1px solid var(--color-border);
		border-bottom: 1px solid color-mix(in srgb, var(--color-border) 30%, transparent);
	}

	.notification-row--disabled {
		opacity: 0.5;
	}

	.notification-row__name {
		display: flex;
		align-items: center;
		gap: 6px;
		overflow: hidden;
	}

	.notification-row__name--indent {
		padding-left: 36px;
		font-weight: 400;
	}

	.notification-row__drift {
		text-align: right;
		padding-right: 8px;
	}

	.notification-row__method {
		display: flex;
		flex-direction: column;
		align-items: flex-start;
		gap: 2px;
	}

	.notification-row__method--drift {
		/* no background — the drift count text is sufficient */
	}

	.notification-row__method--match {
		/* default appearance */
	}

	.notification-group__toggle {
		background: none;
		border: none;
		padding: 0;
		cursor: pointer;
		display: flex;
		align-items: center;
		color: var(--color-main-text);
		width: 20px;
		flex-shrink: 0;
	}

	.notification-group__chevron {
		font-size: 10px;
		transition: transform 0.15s ease;
		display: inline-block;
	}

	.notification-group__chevron--open {
		transform: rotate(90deg);
	}

	.notification-group__name {
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}

	.notification-group__count {
		color: var(--color-text-maxcontrast);
		font-weight: 400;
		font-size: 0.85em;
		flex-shrink: 0;
	}

	.notification-drift-badge {
		font-size: 0.8em;
		padding: 1px 8px;
		border-radius: 10px;
		white-space: nowrap;
	}

	.notification-drift-badge--warning {
		color: var(--color-warning-text, var(--color-warning));
	}

	.notification-drift-badge--success {
		color: var(--color-success-text, var(--color-success));
	}

	.notification-cell {
		display: flex;
		flex-direction: column;
		gap: 2px;
	}

	.notification-cell__drift {
		font-size: 0.8em;
		color: var(--color-text-maxcontrast);
		padding-left: 26px;
	}

	.notification-row__method--drift .notification-cell__drift {
		color: var(--color-warning-text, var(--color-warning));
	}

	.notification-actions {
		display: flex;
		justify-content: space-between;
		align-items: center;
		margin-top: 1rem;
		flex-wrap: wrap;
		gap: 0.75rem;
	}

	.notification-buttons {
		display: flex;
		gap: 0.5rem;
	}

	.notification-loading {
		color: var(--color-text-maxcontrast);
		padding: 2rem 0;
	}

	/* Confirmation dialog additions */
	.apply-summary-list {
		margin: 0.75rem 0;
		padding-left: 1.5rem;
	}

	.apply-summary-list li {
		margin-bottom: 0.25rem;
	}

	.apply-summary-drift {
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
	}

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

	.settings :deep(.app-sidebar__tab) {
		box-shadow: none;
	}

	.settings :deep(.app-sidebar__tab:focus) {
		box-shadow: none;
	}

	.settings .settings-input-wrapper.settings-input-wrapper--with-btn {
		display: flex;
		align-items: center;
		width: calc(100% + 130px);
	}

	.settings .settings-input-wrapper.settings-input-wrapper--with-btn .settings-update-btn {
		min-width: 123px;
		margin-top: 6px;
		margin-left: 7px;
	}

	.settings :deep(.vs__spinner) {
		font-size: 14px;
	}

	.settings :deep(.select) {
		margin-bottom: -5px;
	}
	@media screen and (max-width: 1090px) {
		.settings .settings-input-wrapper.settings-input-wrapper--with-btn {
			flex-direction: column;
			width: 100%;
			align-items: initial;
		}
		.settings .settings-input-wrapper.settings-input-wrapper--with-btn .settings-update-btn {
			margin-top: 6px;
			margin-left: 0;
		}
	}
	@media screen and (max-width: 768px) {
		.settings :deep(.app-sidebar-tabs__nav) {
			display: none !important;
		}
		.settings :deep(.app-sidebar__tab) {
			display: flex!important;
			border-bottom: 1px solid var(--color-border);
			padding-bottom: 25px;
			margin-bottom: 15px;
			height: auto!important;
			flex-direction: column;
			flex: 1 1 auto;
			max-height: 1080px;
			min-height: 350px;
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
	}
	@media screen and (max-width: 512px) {
		.settings .app-sidebar {
			padding: 25px 20px;
			z-index: 1;
		}
		.settings :deep(.select) {
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
</style>
