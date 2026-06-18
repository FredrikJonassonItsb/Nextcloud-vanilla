<!--
  - SPDX-FileCopyrightText: 2026 ITSL AB <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="participants-list">
		<div v-for="participant in participants"
			:key="participant.identifier"
			class="participant">
			<!-- Count badge: popover with message count -->
			<NcPopover :triggers="['hover', 'focus']"
				placement="left-start"
				container="body"
				:delay="{ show: 200, hide: 0 }"
				:distance="6">
				<template #trigger="{ attrs }">
					<span class="participant__count" tabindex="0" v-bind="attrs">
						{{ participant.messageCount }}
					</span>
				</template>
				<div class="participant__popover">
					<div class="participant__popover-row">
						{{ n('mail', 'Has written {count} message in this thread', 'Has written {count} messages in this thread', participant.messageCount, { count: participant.messageCount }) }}
					</div>
				</div>
			</NcPopover>
			<!-- Name area: popover with address/org details -->
			<NcPopover :triggers="['hover', 'focus']"
				placement="left-start"
				container="body"
				:delay="{ show: 200, hide: 0 }"
				:distance="6">
				<template #trigger="{ attrs }">
					<div class="participant__info" tabindex="0" v-bind="attrs">
						<div class="participant__primary">
							<span class="participant__name">
								{{ participant.displayName }}
							</span>
							<span v-for="role in participant.roles"
								:key="role"
								class="participant__role"
								:class="'participant__role--' + role"
								:title="roleTooltip(role)">
								{{ roleIcon(role) }}
							</span>
						</div>
						<div v-if="participant.secondaryLine"
							class="participant__secondary">
							{{ participant.secondaryLine }}
						</div>
					</div>
				</template>
				<div class="participant__popover">
					<!-- SDK: Address + Organization -->
					<template v-if="participant.isSdk">
						<div class="participant__popover-row">
							<strong>{{ t('mail', 'Address') }}:</strong>
							{{ participant.functionLabel }}
							<span v-if="participant.functionId" class="participant__popover-id">
								({{ participant.functionId }})
							</span>
						</div>
						<div class="participant__popover-row">
							<strong>{{ t('mail', 'Organization') }}:</strong>
							{{ participant.orgLabel }}
							<span v-if="participant.orgId" class="participant__popover-id">
								({{ participant.orgId }})
							</span>
						</div>
					</template>
					<!-- Internal: name + email -->
					<template v-else-if="participant.isInternal">
						<div class="participant__popover-row">
							{{ participant.displayName }}
						</div>
						<div class="participant__popover-row participant__popover-row--muted">
							{{ participant.tooltip }}
						</div>
					</template>
					<!-- Non-SDK, non-internal: full identifier -->
					<div v-else class="participant__popover-row">
						{{ participant.tooltip || participant.displayName }}
					</div>
				</div>
			</NcPopover>
		</div>
	</div>
</template>

<script>
import { mapStores } from 'pinia'
import { NcPopover } from '@nextcloud/vue'
import useItslStore from '../../store/itslStore.js'
import { MESSAGE_TYPES } from '../../store/constants.js'
import { parseAddressInfoFromString } from '../../utils/messageTypeUtils.js'
import { formatPhoneNumber } from '../../utils/phoneUtils.js'
import { formatSdkFunctionName, formatSdkOrganizationName } from '../../utils/participantUtils.js'

export default {
	name: 'ThreadParticipantList',
	components: {
		NcPopover,
	},
	props: {
		thread: {
			type: Array,
			required: true,
		},
		messageType: {
			type: String,
			default: null,
		},
	},
	computed: {
		...mapStores(useItslStore),
		participants() {
			if (this.thread.length === 0) return []

			// Sort thread by date to determine first/last
			const sortedThread = [...this.thread].sort((a, b) => a.dateInt - b.dateInt)
			const firstMessage = sortedThread[0]
			const lastMessage = sortedThread[sortedThread.length - 1]

			// Collect all unique participants and count messages sent
			const participantMap = new Map()

			// SDK: Use metadata directly (consistent identifiers)
			if (this.messageType === MESSAGE_TYPES.SDK.id) {
				this.thread.forEach(env => {
					const sdkHeader = env.itsl?.sdk?.messageHeader

					// Process sender
					const sender = sdkHeader?.sender
					if (sender) {
						const key = this.getSdkPartyKey(sender)
						if (!participantMap.has(key)) {
							participantMap.set(key, {
								...this.formatSdkParty(sender, key),
								roles: [],
								envelope: env,
								messageCount: 1,
							})
						} else {
							participantMap.get(key).messageCount++
						}
					}

					// Process recipient
					const recipient = sdkHeader?.recipient
					if (recipient) {
						const key = this.getSdkPartyKey(recipient)
						if (!participantMap.has(key)) {
							participantMap.set(key, {
								...this.formatSdkParty(recipient, key),
								roles: [],
								envelope: env,
								messageCount: 0,
							})
						}
					}
				})

				// Assign roles using SDK metadata
				const firstSender = firstMessage.itsl?.sdk?.messageHeader?.sender
				const firstRecipient = firstMessage.itsl?.sdk?.messageHeader?.recipient
				const lastSender = lastMessage.itsl?.sdk?.messageHeader?.sender

				if (firstSender) {
					const key = this.getSdkPartyKey(firstSender)
					if (participantMap.has(key)) participantMap.get(key).roles.push('started')
				}
				if (firstRecipient) {
					const key = this.getSdkPartyKey(firstRecipient)
					if (participantMap.has(key)) participantMap.get(key).roles.push('first-recipient')
				}
				if (lastSender) {
					const key = this.getSdkPartyKey(lastSender)
					if (participantMap.has(key)) participantMap.get(key).roles.push('last-reply')
				}

				return Array.from(participantMap.values())
			}

			// Non-SDK: existing logic using from/to emails
			this.thread.forEach(env => {
				// Process sender - increment message count
				const from = env.from?.[0]
				if (from) {
					const key = this.getParticipantKey(from, env)
					if (!participantMap.has(key)) {
						participantMap.set(key, {
							...this.formatParticipant(from, env),
							roles: [],
							envelope: env,
							messageCount: 1,
						})
					} else {
						participantMap.get(key).messageCount++
					}
				}

				// Process recipients (don't count as messages sent)
				const recipients = env.to || []
				recipients.forEach(recipient => {
					const key = this.getParticipantKey(recipient, env)
					if (!participantMap.has(key)) {
						participantMap.set(key, {
							...this.formatParticipant(recipient, env),
							roles: [],
							envelope: env,
							messageCount: 0,
						})
					}
				})
			})

			// Assign roles
			const firstSenderKey = this.getParticipantKey(firstMessage.from?.[0], firstMessage)
			const firstRecipientKey = this.getParticipantKey(firstMessage.to?.[0], firstMessage)
			const lastSenderKey = this.getParticipantKey(lastMessage.from?.[0], lastMessage)

			if (participantMap.has(firstSenderKey)) {
				participantMap.get(firstSenderKey).roles.push('started')
			}
			if (participantMap.has(firstRecipientKey)) {
				participantMap.get(firstRecipientKey).roles.push('first-recipient')
			}
			if (participantMap.has(lastSenderKey)) {
				participantMap.get(lastSenderKey).roles.push('last-reply')
			}

			return Array.from(participantMap.values())
		},
	},
	methods: {
		getSdkPartyKey(party) {
			// Composite key: function + org (function IDs are unique within org, not globally)
			const functionExt = party?.attention?.subOrganization?.organizationId?.extension || ''
			const orgExt = party?.senderId?.extension || party?.recipientId?.extension || ''
			return `sdk:${functionExt.toLowerCase()}|${orgExt.toLowerCase()}`
		},
		formatSdkParty(party, key) {
			const functionId = party.attention?.subOrganization?.organizationId?.extension || ''
			const orgId = party.senderId?.extension || party.recipientId?.extension || ''

			// Look up address book for human-readable names (searches all known orgs)
			const abLabels = this.itslStore.lookupAddressBookLabels(functionId, orgId)

			// Primary: address book label > SDK header label > extension ID
			const functionLabel = abLabels.functionAddressLabel
				|| formatSdkFunctionName(party)
				|| functionId
				|| 'Unknown'

			// Secondary: address book label > SDK header label > extension ID
			const orgLabel = abLabels.organizationAddressLabel
				|| formatSdkOrganizationName(party)
				|| orgId

			return {
				identifier: key,
				displayName: functionLabel,
				secondaryLine: orgLabel ? `@ ${orgLabel}` : null,
				functionId,
				orgId,
				functionLabel,
				orgLabel,
				isSdk: true,
			}
		},
		getParticipantKey(emailObj, envelope) {
			if (!emailObj) return 'unknown'
			const email = emailObj.email || emailObj.label || 'unknown'

			// For secure messages, include LOA level in key so same person at
			// different LOA levels appears as separate entries
			if (this.messageType === MESSAGE_TYPES.SECURE.id && email.endsWith('.securemail')) {
				const addressInfo = parseAddressInfoFromString(this.messageType, email)
				// LOA-3 if SSN present, otherwise check envelope, default to 1
				const loaLevel = addressInfo?.ssn ? 3 : (envelope?.itsl?.loaLevel || 1)
				return `${email}:loa${loaLevel}`
			}

			return email
		},
		formatParticipant(emailObj, envelope) {
			const email = emailObj?.email || ''
			const label = emailObj?.label || ''

			// Internal: Use cached internal mailbox names
			if (this.messageType === MESSAGE_TYPES.INTERNAL.id) {
				const name = this.itslStore.getInternalMailboxName(email) || label || email
				return {
					identifier: email,
					displayName: name,
					secondaryLine: null,
					tooltip: email,
					isSdk: false,
					isInternal: true,
				}
			}

			// Fax/SMS: Format phone number (strips @fax/@sms suffix internally)
			if (this.messageType === MESSAGE_TYPES.FAX.id || this.messageType === MESSAGE_TYPES.SMS.id) {
				return {
					identifier: email,
					displayName: formatPhoneNumber(email),
					secondaryLine: null,
					tooltip: email,
					isSdk: false,
				}
			}

			// Secure email: Parse encoded address and show LOA indicators
			if (this.messageType === MESSAGE_TYPES.SECURE.id) {
				// Only external recipients have .securemail encoding
				if (email.endsWith('.securemail')) {
					const addressInfo = parseAddressInfoFromString(this.messageType, email)

					// LOA detection: SSN in email → LOA-3, else check itsl.loaLevel, default to 1
					const loaLevel = addressInfo?.ssn
						? 3
						: (envelope?.itsl?.loaLevel || 1)

					// Parse actual email from encoding
					const actualEmail = addressInfo?.notification || email.replace(/\.(org|[\d]+)\.securemail$/, '')

					if (loaLevel === 3) {
						// LOA-3: SSN is the RECIPIENT (person identified by SSN)
						const formattedSSN = this.formatSSN(addressInfo.ssn)
						return {
							identifier: email,
							displayName: formattedSSN,
							secondaryLine: `✉️ ${actualEmail}`,
							tooltip: `SSN: ${formattedSSN}, Notification: ${actualEmail}`,
							isSdk: false,
						}
					} else if (loaLevel === 2) {
						// LOA-2: Email + phone for SMS OTP
						const smsNumber = envelope?.itsl?.smsNumber
						return {
							identifier: email,
							displayName: actualEmail,
							secondaryLine: smsNumber ? `📱 ${formatPhoneNumber(smsNumber)}` : '📱 SMS verification',
							tooltip: actualEmail,
							isSdk: false,
						}
					} else {
						// LOA-1: Email + password indicator
						return {
							identifier: email,
							displayName: actualEmail,
							secondaryLine: '🔐 Password login',
							tooltip: actualEmail,
							isSdk: false,
						}
					}
				}

				// Internal sender (no .securemail encoding) - use internal name or label
				const internalName = this.itslStore.getInternalMailboxName(email)
				if (internalName) {
					return {
						identifier: email,
						displayName: internalName,
						secondaryLine: null,
						tooltip: email,
						isSdk: false,
						isInternal: true,
					}
				}
			}

			// Default: Show email or label
			return {
				identifier: email,
				displayName: label || email,
				secondaryLine: null,
				tooltip: email,
				isSdk: false,
			}
		},
		roleIcon(role) {
			switch (role) {
			case 'started':
				return '\u25B6' // ▶
			case 'first-recipient':
				return '\u25C0' // ◀
			case 'last-reply':
				return '\u21A9' // ↩
			default:
				return ''
			}
		},
		roleTooltip(role) {
			switch (role) {
			case 'started':
				return this.t('mail', 'Started this thread')
			case 'first-recipient':
				return this.t('mail', 'Received the first message')
			case 'last-reply':
				return this.t('mail', 'Sent the most recent message')
			default:
				return ''
			}
		},
		formatSSN(ssn) {
			if (!ssn) return ''
			// Remove any existing dashes
			const digits = ssn.replace(/-/g, '')
			// Handle 10-digit format (YYMMDDNNNN) - add century
			if (digits.length === 10) {
				const year = parseInt(digits.substring(0, 2), 10)
				// Cutoff is current year + 1 (e.g., in 2026: 26 → 2026, 27 → 1927)
				const cutoff = (new Date().getFullYear() + 1) % 100
				const century = year >= cutoff ? '19' : '20'
				return `${century}${digits.substring(0, 6)}-${digits.substring(6)}`
			}
			// Handle 12-digit format (YYYYMMDDNNNN)
			if (digits.length === 12) {
				return `${digits.substring(0, 8)}-${digits.substring(8)}`
			}
			// Return as-is if unexpected format
			return ssn
		},
	},
}
</script>

<style lang="scss" scoped>
.participants-list {
	display: flex;
	flex-direction: column;
}

.participant {
	display: flex;
	align-items: center;
	margin-bottom: 8px;

	&:last-child {
		margin-bottom: 0;
	}

	// NcPopover wraps triggers in div.v-popper — constrain for flex truncation
	:deep(.v-popper:first-child) {
		flex-shrink: 0;
	}

	:deep(.v-popper:last-child) {
		flex: 1;
		min-width: 0;
	}
}

.participant__count {
	flex-shrink: 0;
	min-width: 18px;
	height: 18px;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	font-size: 11px;
	font-weight: 700;
	color: var(--color-primary-element-light-text);
	background: var(--color-primary-element-light);
	border-radius: 100px;
	padding: 0 5px;
	margin-inline-end: 8px;
}

.participant__info {
	flex: 1;
	min-width: 0;
}

.participant__primary {
	display: flex;
	align-items: center;
	gap: 4px;
	font-size: 13px;
	color: var(--color-main-text);
	line-height: 1.5;
	min-width: 0;
}

.participant__name {
	color: var(--color-main-text);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.participant__role {
	display: inline-block;
	flex-shrink: 0;
	font-size: 8px;
	padding: 2px 4px;
	border-radius: 4px;
	font-weight: 500;
}

.participant__role--started {
	background: var(--itsl-role-started-bg);
	color: var(--itsl-role-started-text);
}

.participant__role--first-recipient {
	background: var(--itsl-role-recipient-bg);
	color: var(--itsl-role-recipient-text);
}

.participant__role--last-reply {
	background: var(--itsl-role-reply-bg);
	color: var(--itsl-role-reply-text);
}

.participant__secondary {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	padding-inline-start: 4px;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.participant__popover {
	padding: 8px 10px;
	font-size: 13px;
	line-height: 1.6;
}

.participant__popover-row {
	white-space: nowrap;
}

.participant__popover-row--muted {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	margin-top: 2px;
}

.participant__popover-id {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}
</style>
