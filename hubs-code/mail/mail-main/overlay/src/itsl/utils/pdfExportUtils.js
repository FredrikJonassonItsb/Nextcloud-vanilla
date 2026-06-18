/**
 * PDF export utilities - HTML generation and filename formatting for message export.
 */

import { MESSAGE_DIRECTION, MESSAGE_TYPES } from '../store/constants.js'
import { escape } from 'lodash'
import { translate as t } from '@nextcloud/l10n'
import { parseAddressInfoFromString } from './messageTypeUtils.js'

export function messageToHtml(message) {
	const pdfData = mapMessageToPdfData(message)
	return generateHtmlForMessage(pdfData)
}

function mapMessageToPdfData(message) {
	const messageType = message?.itsl?.messageType
	const direction = message?.itsl?.messageDirection
	const sdkHeader = message?.itsl?.sdk?.messageHeader || {}

	const getFirstLine = (party, emailObj) => {
		if (messageType === MESSAGE_TYPES.SDK.id) {
			if (party?.label) {
				return `${t('mail', 'Organization')}: ${party.label}(${party?.recipientId?.extension || party?.senderId?.extension || ''})`
			}
			return `${t('mail', 'Organization')}: ${party?.recipientId?.extension || party?.senderId?.extension || ''}`
		}

		const emailValue = emailObj?.email
		if (!emailValue) return ''

		const info = parseAddressInfoFromString(messageType, emailValue)

		switch (messageType) {
		case MESSAGE_TYPES.INTERNAL.id:
			return `${info.email}`
		case MESSAGE_TYPES.SECURE.id:
			return `${t('mail', 'Email')}: ${info.notification}`
		case MESSAGE_TYPES.FAX.id:
			return `${info.faxAddress}`
		case MESSAGE_TYPES.SMS.id:
			return `${info.smsAddress}`
		default:
			return emailValue || ''
		}
	}

	const getSecondLine = (party, emailObj) => {
		if (messageType === MESSAGE_TYPES.SDK.id) {

			if (party?.attention?.subOrganization?.label) {
				return `${t('mail', 'Address')}: ${party.attention.subOrganization.label}(${party?.attention?.subOrganization?.organizationId?.extension || ''})`
			}
			return `${t('mail', 'Address')}: ${party?.attention?.subOrganization?.organizationId?.extension || ''}`
		}

		if (messageType === MESSAGE_TYPES.SECURE.id) {
			const info = parseAddressInfoFromString(messageType, emailObj?.email || '')
			return `${t('mail', 'PIN')}: ${info.ssn}`
		}

		return ''
	}

	const fromParty = direction === MESSAGE_DIRECTION.OUTGOING ? sdkHeader.sender : sdkHeader.recipient
	const toParty = direction === MESSAGE_DIRECTION.OUTGOING ? sdkHeader.recipient : sdkHeader.sender
	const fromEmail = direction === MESSAGE_DIRECTION.OUTGOING ? message?.from?.[0] : message?.to?.[0]
	const toEmail = direction === MESSAGE_DIRECTION.OUTGOING ? message?.to?.[0] : message?.from?.[0]

	const fromFirstLine = getFirstLine(fromParty, fromEmail)
	const fromSecondLine = getSecondLine(fromParty, fromEmail)
	const toFirstLine = getFirstLine(toParty, toEmail)
	const toSecondLine = getSecondLine(toParty, toEmail)

	const date = message?.dateInt ? new Date(message.dateInt * 1000) : null
	const formattedDate = date ? date.toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : ''

	const extractIds = (party) => {
		const ids = []

		party?.attention?.person?.forEach(person => {
			ids.push({
				label: person.label || '',
				type: 'person',
				row1: person.personId?.extension || '',
				row2: person.personId?.root || '',
				row3: person.label || '',
			})
		})

		party?.attention?.reference?.forEach(reference => {
			ids.push({
				label: reference.label || '',
				type: 'reference',
				row1: reference.referenceId?.extension || '',
				row2: reference.referenceId?.root || '',
				row3: reference.label || '',
			})
		})

		return ids
	}

	const senderIDs = fromParty ? extractIds(fromParty) : []
	const recipientIDs = toParty ? extractIds(toParty) : []

	return {
		fromFirstLine,
		fromSecondLine,
		toFirstLine,
		toSecondLine,
		sentAt: formattedDate,
		subject: message?.subject || '',
		body: message?.bodyHtml || message?.bodyPlain || '',
		senderIDs,
		recipientIDs,
	}
}

export function generateHtmlForMessage({
	fromFirstLine = '',
	fromSecondLine = '',
	toFirstLine = '',
	toSecondLine = '',
	sentAt = '',
	subject = '',
	body = '',
	senderIDs = [],
	recipientIDs = [],
}) {
	const escapeOrEmpty = (val) => escape(val || 'empty')

	const renderInlineField = (label, value) => `
	<div class="inline-field">
		<strong>${label}</strong> ${value}
	</div>
`

	const renderChip = (item) => {
		const mainLabel = escapeOrEmpty(item.row1)
		const code = escapeOrEmpty(item.row2)
		const description = escapeOrEmpty(item.row3)

		const idTitle = item.type === 'person'
			? t('mail', 'PersonID')
			: t('mail', 'ReferenceID')

		return `
		<div class="chip">
			<div class="chip-line">
				<strong>${idTitle}:</strong> ${mainLabel}
				<strong>${t('mail', 'Code')}:</strong> ${code}
				<strong>${t('mail', 'Description')}:</strong> ${description}
			</div>
		</div>
	`
	}

	const renderMetadataGroup = (title, items) => {
		if (!items.length) return ''
		return `
		<div class="field">
			<div class="label">${title}</div>
			<div class="chips">${items.map(item => renderChip(item)).join('')}</div>
		</div>
	`
	}

	const timestampLabel = sentAt
		? t('mail', 'Sent at')
		: t('mail', 'Received at') // TODO add similarly to existing one in header, for SDK use 'recieved at'
	const timestampValue = sentAt

	const html = `
		<div class="email-template">

			<div class="email-marker">
				${t('mail', 'Beginning of Message')}
			</div>

			<div class="inline-fields">
				<div class="field">
					<div class="label">${t('mail', 'From')}</div>
					<div class="value">
						${fromFirstLine}
						${fromSecondLine ? `<br/>${fromSecondLine}` : ''}
					</div>
				</div>
				<div class="field small-field">
					<div class="label">${timestampLabel}:</div>
					<div class="value">${timestampValue}</div>
				</div>
			</div>

			<div class="field">
				<div class="label">${t('mail', 'To')}</div>
				<div class="value">
					${toFirstLine}
					${toSecondLine ? `<br/>${toSecondLine}` : ''}
				</div>
			</div>

			<div class="inline-fields">
				${renderInlineField(t('mail', 'Subject'), subject)}
			</div>

			<div class="body-content">
				<pre style="white-space: pre-wrap;word-break: break-word;">${body.trim()}</pre>
			</div>

			${renderMetadataGroup(t('mail', 'SenderIDs'), senderIDs)}
			${renderMetadataGroup(t('mail', 'RecipientIDs'), recipientIDs)}

			<div class="email-marker">
				${t('mail', 'End of Message')}
			</div>
		</div>

		<style>
			.email-template {
				font-family: Arial, sans-serif;
				font-size: 14px;
				line-height: 1.4;
				color: #222;
				padding: 20px;
				background: #fff;
			}
			.field {
				margin-bottom: 8px;
			}
			.label {
				font-weight: bold;
				margin-bottom: 2px;
			}
			.value {
				margin-left: 10px;
			}
			.inline-fields {
				display: flex;
				justify-content: space-between;
				align-items: baseline;
				flex-wrap: wrap;
				gap: 20px;
				margin-bottom: 8px;
			}
			.inline-field {
				display: flex;
				gap: 6px;
				align-items: center;
			}
			.small-field {
				min-width: 200px;
				text-align: right;
			}
			.body-content {
				margin-top: 10px;
				margin-left: 0;
				padding: 0;
			}
			.chips {
				display: flex;
				flex-direction: column;
				gap: 8px;
				margin-top: 8px;
			}
			.chip {
				border: 1px solid #ccc;
				border-radius: 6px;
				padding: 8px;
			}
			.chip-line {
				display: flex;
				flex-wrap: wrap;
				gap: 12px;
				align-items: center;
			}
			.chip-line strong {
				margin-right: 4px;
			}
			.email-marker {
				margin-top: 20px;
				text-align: center;
				font-size: 12px;
				color: #777;
			}
		</style>
	`

	return html
}

export function generateFilename(message) {
	const maxFilenameLength = 200
	const date = new Date(message.itsl?.messageType === MESSAGE_TYPES.SDK.id ? Math.floor(new Date(message.itsl.sdk.messageHeader.creationDateTime).getTime()) : message.dateInt * 1000)
	const pad = (num) => String(num).padStart(2, '0')

	const formattedDate = `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}-${pad(date.getHours())}-${pad(date.getMinutes())}-${pad(date.getSeconds())}`

	const isOutgoing = message?.itsl?.messageDirection === MESSAGE_DIRECTION.OUTGOING

	// messages other than SDK
	const contact = isOutgoing ? message.to?.[0] : message.from?.[0]
	let party = contact?.label || contact?.email

	if (message.itsl?.messageType === MESSAGE_TYPES.SDK.id) {
		const sdkHeader = isOutgoing
			? message.itsl.sdk.messageHeader.recipient
			: message.itsl.sdk.messageHeader.sender

		party = sdkHeader.attention.subOrganization.label
			|| sdkHeader.attention.subOrganization.organizationId.extension
	}

	const subject = message.subject || 'no-subject'

	const sanitize = (str) => (str || '')
		.replace(/[^a-zA-Z0-9-_]/g, '-')
		.replace(/-+/g, '-')
		.replace(/^-|-$/g, '')

	let filename = `${formattedDate}_${sanitize(party)}_${sanitize(subject)}`

	if (filename.length > maxFilenameLength) {
		filename = filename.substring(0, maxFilenameLength)
	}

	return `${filename}.pdf`
}
