<template>
	<div class="mail-header-container" @click="onHeaderClick">
		{{ mailHeaderFirstLine }}
		<NcPopover placement="bottom">
			<template #trigger="slotProps">
				<span class="popover-trigger"
					aria-label="Show information"
					v-bind="slotProps.attrs"
					v-on="slotProps.on"
					@click.stop.prevent>
					<MenuDown :size="25" class="header-popover-trigger-icon" />
				</span>
			</template>
			<template #default>
				<div class="popover-content" tabindex="0">
					<template v-if="isSdk">
						<div><strong>{{ t('mail', 'From organization') }}:</strong> {{ fromOrganization }}</div>
						<div><strong>{{ t('mail', 'From address') }}:</strong> {{ fromAddress }}</div>
						<div><strong>{{ t('mail', 'To organization') }}:</strong> {{ toOrganization }}</div>
						<div><strong>{{ t('mail', 'To address') }}:</strong> {{ toAddress }}</div>
						<div><strong>{{ t('mail', 'Subject') }}:</strong> {{ subject }}</div>
						<div><strong>{{ t('mail', 'Confidential') }}:</strong> {{ isConfidential ? t('mail', 'Yes') : t('mail', 'No') }}</div>
					</template>
					<template v-else-if="isSecure">
						<template v-if="isIncomming">
							<div v-if="fromSSN">
								<strong>{{ t('mail', 'From SSN') }}:</strong> {{ fromSSN }}
							</div>
							<div><strong>{{ t('mail', 'From email') }}:</strong> {{ fromNotification }} </div>
							<div><strong>{{ t('mail', 'To') }}:</strong> {{ to }}</div>
							<div><strong>{{ t('mail', 'Subject') }}:</strong> {{ subject }}</div>
						</template>
						<template v-else>
							<div><strong>{{ t('mail', 'Security level') }}:</strong> {{ loaLevelLabel }}</div>
							<div><strong>{{ t('mail', 'From') }}:</strong> {{ from }}</div>
							<div v-if="toSSN">
								<strong>{{ t('mail', 'To SSN') }}:</strong> {{ toSSN }}
							</div>
							<div v-if="toSmsNumber">
								<strong>{{ t('mail', 'One-time code to') }}:</strong> {{ toSmsNumber }}
							</div>
							<div><strong>{{ t('mail', 'To email') }}:</strong> {{ toNotification }} </div>
							<div><strong>{{ t('mail', 'Subject') }}:</strong> {{ subject }}</div>
						</template>
					</template>
					<template v-else-if="isInternal">
						<div><strong>{{ t('mail', 'From') }}:</strong> {{ from }}</div>
						<div><strong>{{ t('mail', 'From email') }}:</strong> {{ message.from[0]?.email }}</div>
						<div><strong>{{ t('mail', 'To') }}:</strong> {{ to }}</div>
						<div><strong>{{ t('mail', 'To email') }}:</strong> {{ message.to[0]?.email }}</div>
						<div><strong>{{ t('mail', 'Subject') }}:</strong> {{ subject }}</div>
					</template>
					<template v-else-if="isFax || isSms">
						<div><strong>{{ t('mail', 'From') }}:</strong> {{ fromFormatted }}</div>
						<div><strong>{{ t('mail', 'To') }}:</strong> {{ toFormatted }}</div>
					</template>
					<template v-else>
						<div><strong>{{ t('mail', 'From') }}:</strong> {{ from }}</div>
						<div><strong>{{ t('mail', 'To') }}:</strong> {{ to }}</div>
						<div><strong>{{ t('mail', 'Subject') }}:</strong> {{ subject }}</div>
					</template>
				</div>
			</template>
		</NcPopover>
	</div>
</template>

<script>
import { defineComponent, computed } from 'vue'
import NcPopover from '@nextcloud/vue/components/NcPopover'
import { MESSAGE_TYPES, MESSAGE_DIRECTION } from '../../store/constants.js'
import { parseAddressInfoFromString } from '../../utils/messageTypeUtils.js'
import { resolveMessageDisplayName } from '../../utils/participantUtils.js'
import { formatPhoneNumber } from '../../utils/phoneUtils.js'
import { translate as t } from '@nextcloud/l10n'
import MenuDown from 'vue-material-design-icons/MenuDown.vue'
import useItslStore from '../../store/itslStore.js'

export default defineComponent({
	name: 'MessageHeaderItsl',
	components: {
		NcPopover,
		MenuDown,
	},
	props: {
		message: {
			type: Object,
			required: false,
			default: null,
		},

	},
	emits: ['toggle-expand'],
	setup(props, { emit }) {
		const itslStore = useItslStore()

		const onHeaderClick = (event) => {
			emit('toggle-expand', event)
		}
		const isSdk = computed(() => props.message?.itsl?.messageType === MESSAGE_TYPES.SDK.id)
		const isSecure = computed(() => props.message?.itsl?.messageType === MESSAGE_TYPES.SECURE.id)
		const isFax = computed(() => props.message?.itsl?.messageType === MESSAGE_TYPES.FAX.id)
		const isSms = computed(() => props.message?.itsl?.messageType === MESSAGE_TYPES.SMS.id)
		const isInternal = computed(() => props.message?.itsl?.messageType === MESSAGE_TYPES.INTERNAL.id)

		const subject = computed(() => props.message?.subject || '')
		const isConfidential = computed(() => props.message?.itsl?.sdk?.messageHeader?.confidential === true)
		const isIncomming = computed(() => props.message?.itsl?.messageDirection === MESSAGE_DIRECTION.INCOMING)

		const fromSSN = computed(() => {
			if (props.message?.itsl?.messageType !== MESSAGE_TYPES.SECURE.id) {
				return ''
			}

			const infoData = parseAddressInfoFromString(props.message.itsl.messageType, props.message.from?.[0]?.email)

			return infoData.ssn
		})
		const fromNotification = computed(() => {
			if (props.message?.itsl?.messageType !== MESSAGE_TYPES.SECURE.id) {
				return ''
			}

			const infoData = parseAddressInfoFromString(props.message.itsl.messageType, props.message.from?.[0]?.email)

			return infoData.notification
		})
		const toSSN = computed(() => {
			if (props.message?.itsl?.messageType !== MESSAGE_TYPES.SECURE.id) {
				return ''
			}

			const infoData = parseAddressInfoFromString(props.message.itsl.messageType, props.message.to?.[0]?.email)

			return infoData.ssn
		})
		const toNotification = computed(() => {
			if (props.message?.itsl?.messageType !== MESSAGE_TYPES.SECURE.id) {
				return ''
			}

			const infoData = parseAddressInfoFromString(props.message.itsl.messageType, props.message.to?.[0]?.email)

			return infoData.notification
		})

		const loaLevel = computed(() => {
			return props.message?.itsl?.loaLevel || 1
		})

		const loaLevelLabel = computed(() => {
			const level = loaLevel.value
			if (level === 1) return t('mail', 'LOA-1')
			if (level === 2) return t('mail', 'LOA-2 (SMS)')
			if (level === 3) return t('mail', 'LOA-3 (BankID)')
			return `LOA-${level}`
		})

		const toSmsNumber = computed(() => {
			if (props.message?.itsl?.messageType !== MESSAGE_TYPES.SECURE.id) {
				return ''
			}
			return props.message?.itsl?.smsNumber || ''
		})

		const fromSdk = computed(() => props.message?.itsl?.sdk?.messageHeader?.sender)
		const toSdk = computed(() => props.message?.itsl?.sdk?.messageHeader?.recipient)

		const fromOrganization = computed(() => {
			if (!fromSdk.value) return ''
			if (fromSdk.value.label) {
				return `${fromSdk.value.label} (${fromSdk.value.senderId?.extension ?? ''})`
			}
			return fromSdk.value.senderId?.extension ?? ''
		})

		const fromAddress = computed(() => {
			if (!fromSdk.value) return ''
			if (fromSdk.value.attention?.subOrganization?.label) {
				return `${fromSdk.value.attention?.subOrganization?.label} (${fromSdk.value.attention?.subOrganization?.organizationId?.extension ?? ''})`
			}
			return fromSdk.value.attention?.subOrganization?.organizationId?.extension ?? ''
		})

		const toOrganization = computed(() => {
			if (!toSdk.value) return ''
			if (toSdk.value.label) {
				return `${toSdk.value.label} (${toSdk.value.recipientId?.extension ?? ''})`
			}
			return toSdk.value.recipientId?.extension ?? ''
		})

		const toAddress = computed(() => {
			if (!toSdk.value) return ''
			if (toSdk.value.attention?.subOrganization?.label) {
				return `${toSdk.value.attention.subOrganization.label} (${toSdk.value.attention?.subOrganization?.organizationId?.extension ?? ''})`
			}
			return toSdk.value.attention?.subOrganization?.organizationId?.extension ?? ''
		})

		const mailHeaderFirstLine = computed(() => {
			const msg = props.message
			if (!msg || !msg.itsl) return ''
			const messageType = msg.itsl.messageType
			const direction = msg.itsl.messageDirection

			// Pick counterparty by direction
			if (messageType === MESSAGE_TYPES.SDK.id) {
				const sdkParty = direction === MESSAGE_DIRECTION.INCOMING
					? msg.itsl.sdk?.messageHeader?.sender
					: msg.itsl.sdk?.messageHeader?.recipient
				return resolveMessageDisplayName(messageType, {
					email: msg.from?.[0]?.email || '',
					label: msg.from?.[0]?.label || '',
					sdkParty,
				}).raw || ''
			}

			const contact = direction === MESSAGE_DIRECTION.OUTGOING
				? msg.to?.[0]
				: msg.from?.[0]
			return resolveMessageDisplayName(messageType, {
				email: contact?.email || '',
				label: contact?.label || '',
				internalMailboxName: itslStore.getInternalMailboxName(contact?.email),
			}).raw || ''
		})

		const from = computed(() => {
			const msg = props.message
			if (!msg || !msg.itsl) return ''
			const fromContact = msg.from?.[0]
			return resolveMessageDisplayName(msg.itsl.messageType, {
				email: fromContact?.email || '',
				label: fromContact?.label || '',
				internalMailboxName: itslStore.getInternalMailboxName(fromContact?.email),
			}).name
		})

		const to = computed(() => {
			const msg = props.message
			if (!msg || !msg.itsl) return ''
			const toContact = msg.to?.[0]
			return resolveMessageDisplayName(msg.itsl.messageType, {
				email: toContact?.email || '',
				label: toContact?.label || '',
				internalMailboxName: itslStore.getInternalMailboxName(toContact?.email),
			}).name
		})

		const fromFormatted = computed(() => {
			const msg = props.message
			if (!msg) return ''
			return formatPhoneNumber(msg.from?.[0]?.email || '')
		})

		const toFormatted = computed(() => {
			const msg = props.message
			if (!msg) return ''
			return formatPhoneNumber(msg.to?.[0]?.email || '')
		})

		return {
			onHeaderClick,
			mailHeaderFirstLine,
			isSdk,
			isSecure,
			isFax,
			isSms,
			isInternal,
			isIncomming,
			fromOrganization,
			fromAddress,
			toOrganization,
			toAddress,
			subject,
			isConfidential,
			from,
			to,
			fromFormatted,
			toFormatted,
			t,
			fromSSN,
			fromNotification,
			toSSN,
			toNotification,
			loaLevelLabel,
			toSmsNumber,
		}
	},
})
</script>

<style scoped>
.mail-header-container {
	display: flex;
	flex-direction: row;
	margin-inline-start: 10px;
}
.popover-trigger {
	position: relative;
	left: 2px;
}
.popover-content {
	padding: 10px;
	font-size: 14px;
}
.header-popover-trigger-icon {
	color: var(--color-primary-element)
}
</style>
