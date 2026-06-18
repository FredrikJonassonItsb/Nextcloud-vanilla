<!--
  - SPDX-FileCopyrightText: 2026 ITSL AB <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div v-if="messageType" class="message-type-badge" :class="[typeClass, { 'message-type-badge--compact': compact }]">
		<component :is="iconComponent" :size="compact ? 12 : 16" class="message-type-badge__icon" />
		<span class="message-type-badge__label">{{ compact ? compactLabel : label }}</span>
	</div>
</template>

<script>
import { MESSAGE_TYPES } from '../../store/constants.js'
import { messageTypeToIcon } from '../../utils/messageTypeUtils.js'

export default {
	name: 'MessageTypeBadge',
	props: {
		messageType: {
			type: String,
			default: null,
		},
		compact: {
			type: Boolean,
			default: false,
		},
	},
	computed: {
		iconComponent() {
			return messageTypeToIcon(this.messageType)
		},
		label() {
			switch (this.messageType) {
			case MESSAGE_TYPES.SDK.id:
				return this.t('mail', 'SDK Message')
			case MESSAGE_TYPES.SECURE.id:
				return this.t('mail', 'Secure E-mail')
			case MESSAGE_TYPES.INTERNAL.id:
				return this.t('mail', 'Internal Message')
			case MESSAGE_TYPES.FAX.id:
				return this.t('mail', 'Fax Message')
			case MESSAGE_TYPES.SMS.id:
				return this.t('mail', 'SMS Message')
			default:
				return ''
			}
		},
		compactLabel() {
			switch (this.messageType) {
			case MESSAGE_TYPES.SDK.id:
				return 'SDK'
			case MESSAGE_TYPES.SECURE.id:
				return this.t('mail', 'Secure')
			case MESSAGE_TYPES.INTERNAL.id:
				return this.t('mail', 'Internal')
			case MESSAGE_TYPES.FAX.id:
				return this.t('mail', 'Fax')
			case MESSAGE_TYPES.SMS.id:
				return 'SMS'
			default:
				return ''
			}
		},
		typeClass() {
			switch (this.messageType) {
			case MESSAGE_TYPES.SDK.id:
				return 'message-type-badge--sdk'
			case MESSAGE_TYPES.SECURE.id:
				return 'message-type-badge--secure'
			case MESSAGE_TYPES.INTERNAL.id:
				return 'message-type-badge--internal'
			case MESSAGE_TYPES.FAX.id:
				return 'message-type-badge--fax'
			case MESSAGE_TYPES.SMS.id:
				return 'message-type-badge--sms'
			default:
				return ''
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.message-type-badge {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 4px 10px;
	border-radius: 4px;
	font-size: 12px;
	font-weight: 500;
}

.message-type-badge__icon {
	flex-shrink: 0;
}

.message-type-badge--sdk {
	background: var(--itsl-badge-sdk-bg);
	color: var(--itsl-badge-sdk-text);
}

.message-type-badge--secure {
	background: var(--itsl-badge-secure-bg);
	color: var(--itsl-badge-secure-text);
}

.message-type-badge--internal {
	background: var(--itsl-badge-internal-bg);
	color: var(--itsl-badge-internal-text);
}

.message-type-badge--fax {
	background: var(--itsl-badge-fax-bg);
	color: var(--itsl-badge-fax-text);
}

.message-type-badge--sms {
	background: var(--itsl-badge-sms-bg);
	color: var(--itsl-badge-sms-text);
}

.message-type-badge--compact {
	padding: 2px 6px;
	margin-inline-start: auto;
	gap: 4px;
	font-size: 10px;
	max-width: 100%;

	.message-type-badge__label {
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}
}
</style>
