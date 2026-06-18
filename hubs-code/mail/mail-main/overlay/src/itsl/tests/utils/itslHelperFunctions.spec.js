import { MESSAGE_TYPES, MESSAGE_DIRECTION } from '../../store/constants.js'

vi.mock('../../components/assets/SDKIcon.vue', () => ({ default: { name: 'MockSDKIcon' } }))
vi.mock('vue-material-design-icons/Forum.vue', () => ({ default: { name: 'MockForum' } }))
vi.mock('vue-material-design-icons/MessageTextLock.vue', () => ({ default: { name: 'MockMessageTextLock' } }))
vi.mock('vue-material-design-icons/Fax.vue', () => ({ default: { name: 'MockFax' } }))
vi.mock('vue-material-design-icons/CellphoneMessage.vue', () => ({ default: { name: 'MockCellphoneMessage' } }))
vi.mock('libphonenumber-js', () => ({
	parsePhoneNumber: vi.fn((phone) => {
		const numberMap = {
			// Local Swedish mobile — national format "070-123 45 67"
			'0701234567': { country: 'SE', formatNational: () => '070-123 45 67', formatInternational: () => '+46 70 123 45 67' },
			// International Swedish mobile — national format preserves "+46 70 123 45 67" shape
			'+46701234567': { country: 'SE', formatNational: () => '+46 70 123 45 67', formatInternational: () => '+46 70 123 45 67' },
			// International Swedish Stockholm landline (area code 8)
			'+46812345678': { country: 'SE', formatNational: () => '+46 8 123 456 78', formatInternational: () => '+46 8 123 456 78' },
			// International Swedish landline single-digit area code (non-Stockholm)
			'+46312345678': { country: 'SE', formatNational: () => '+46 3 123 456 78', formatInternational: () => '+46 3 123 456 78' },
		}
		const match = numberMap[phone]
		if (match) return { isValid: () => true, ...match }
		return { isValid: () => false }
	}),
	AsYouType: vi.fn().mockImplementation(() => ({
		input: vi.fn((val) => val),
	})),
}))

import {
	parseAddressInfoFromString,
	messageTypeToLabelKey,
	messageTypeToFolderName,
	messageTypeToIcon,
	hasInternalMailboxFunc,
	getValidSSN,
	getValidSMSNumber,
	extractPhoneFromEmail,
	formatPhoneNumber,
	formatLocalPhoneNumber,
	formatParticipantDisplayName,
	formatSdkFunctionName,
	formatSdkOrganizationName,
	generateHtmlForMessage,
	generateFilename,
	messageToHtml,
} from '../../utils/itslHelperFunctions.js'

describe('parseAddressInfoFromString', () => {
	it('returns email for INTERNAL type', () => {
		const result = parseAddressInfoFromString(MESSAGE_TYPES.INTERNAL.id, 'user@gruppbox')
		expect(result.email).toBe('user@gruppbox')
	})

	it('parses SECURE person address with SSN', () => {
		const result = parseAddressInfoFromString(
			MESSAGE_TYPES.SECURE.id,
			'user@example.com.123456789012.securemail',
		)
		expect(result.notification).toBe('user@example.com')
		expect(result.ssn).toBe('123456789012')
		expect(result.isSendingToPerson).toBe(true)
	})

	it('parses SECURE org address', () => {
		const result = parseAddressInfoFromString(
			MESSAGE_TYPES.SECURE.id,
			'user@example.com.org.securemail',
		)
		expect(result.notification).toBe('user@example.com')
		expect(result.isSendingToPerson).toBe(false)
		expect(result.ssn).toBe('')
	})

	it('handles SECURE address without .securemail suffix', () => {
		const result = parseAddressInfoFromString(
			MESSAGE_TYPES.SECURE.id,
			'user@example.com',
		)
		expect(result.notification).toBe('user@example.com')
		expect(result.isSendingToPerson).toBe(false)
		expect(result.ssn).toBe('')
	})

	it('parses FAX address', () => {
		const result = parseAddressInfoFromString(
			MESSAGE_TYPES.FAX.id,
			'+46812345678@fax',
		)
		expect(result.faxAddress).toBe('+46812345678')
	})

	it('parses SMS address', () => {
		const result = parseAddressInfoFromString(
			MESSAGE_TYPES.SMS.id,
			'+46701234567@sms',
		)
		expect(result.smsAddress).toBe('+46701234567')
	})

	it('returns safe defaults for null addressValue (bug #128)', () => {
		const result = parseAddressInfoFromString(MESSAGE_TYPES.INTERNAL.id, null)
		expect(result).toEqual({
			email: '',
			notification: '',
			ssn: '',
			isSendingToPerson: false,
			faxAddress: '',
			smsAddress: '',
		})
	})

	it('returns safe defaults for undefined addressValue', () => {
		const result = parseAddressInfoFromString(MESSAGE_TYPES.INTERNAL.id, undefined)
		expect(result).toEqual({
			email: '',
			notification: '',
			ssn: '',
			isSendingToPerson: false,
			faxAddress: '',
			smsAddress: '',
		})
	})

	it('returns safe defaults for empty string addressValue', () => {
		const result = parseAddressInfoFromString(MESSAGE_TYPES.INTERNAL.id, '')
		expect(result).toEqual({
			email: '',
			notification: '',
			ssn: '',
			isSendingToPerson: false,
			faxAddress: '',
			smsAddress: '',
		})
	})

	it('returns empty object for SDK type (no SDK-specific parsing)', () => {
		const result = parseAddressInfoFromString(
			MESSAGE_TYPES.SDK.id,
			'urn.riv.SE1234567890.1001@iso6523.SE9876543210.sdk',
		)
		// SDK type is not handled - falls through to empty result
		expect(result).toEqual({})
	})
})

describe('messageTypeToLabelKey', () => {
	it.each([
		[MESSAGE_TYPES.SDK.id, 'SDK Message'],
		[MESSAGE_TYPES.INTERNAL.id, 'Internal Message'],
		[MESSAGE_TYPES.SECURE.id, 'Secure E-mail'],
		[MESSAGE_TYPES.FAX.id, 'Digital Fax'],
		[MESSAGE_TYPES.SMS.id, 'SMS Message'],
	])('maps %s to "%s"', (type, expected) => {
		expect(messageTypeToLabelKey(type)).toBe(expected)
	})

	it('returns "Unknown" for unknown type', () => {
		expect(messageTypeToLabelKey('nonexistent')).toBe('Unknown')
	})
})

describe('messageTypeToFolderName', () => {
	it.each([
		[MESSAGE_TYPES.SDK.id, 'Saved SDK messages'],
		[MESSAGE_TYPES.INTERNAL.id, 'Saved internal messages'],
		[MESSAGE_TYPES.SECURE.id, 'Saved secure messages'],
		[MESSAGE_TYPES.SMS.id, 'Saved SMS messages'],
		[MESSAGE_TYPES.FAX.id, 'Saved FAX messages'],
	])('maps %s to "%s"', (type, expected) => {
		expect(messageTypeToFolderName(type)).toBe(expected)
	})

	it('returns undefined for unknown type', () => {
		expect(messageTypeToFolderName('nonexistent')).toBeUndefined()
	})
})

describe('messageTypeToIcon', () => {
	it('returns MockSDKIcon for SDK', () => {
		expect(messageTypeToIcon(MESSAGE_TYPES.SDK.id)).toEqual({ name: 'MockSDKIcon' })
	})

	it('returns MockForum for INTERNAL', () => {
		expect(messageTypeToIcon(MESSAGE_TYPES.INTERNAL.id)).toEqual({ name: 'MockForum' })
	})

	it('returns MockMessageTextLock for SECURE', () => {
		expect(messageTypeToIcon(MESSAGE_TYPES.SECURE.id)).toEqual({ name: 'MockMessageTextLock' })
	})

	it('returns MockCellphoneMessage for SMS', () => {
		expect(messageTypeToIcon(MESSAGE_TYPES.SMS.id)).toEqual({ name: 'MockCellphoneMessage' })
	})

	it('returns MockFax for FAX', () => {
		expect(messageTypeToIcon(MESSAGE_TYPES.FAX.id)).toEqual({ name: 'MockFax' })
	})

	it('returns undefined for unknown type', () => {
		expect(messageTypeToIcon('nonexistent')).toBeUndefined()
	})
})

describe('hasInternalMailboxFunc', () => {
	it('returns true for @gruppbox alias', () => {
		expect(hasInternalMailboxFunc([{ emailAddress: 'test@gruppbox' }])).toBe(true)
	})

	it('returns true for @personlig alias', () => {
		expect(hasInternalMailboxFunc([{ emailAddress: 'test@personlig' }])).toBe(true)
	})

	it('returns false for regular email alias', () => {
		expect(hasInternalMailboxFunc([{ emailAddress: 'test@example.com' }])).toBe(false)
	})

	it('returns false for empty aliases array', () => {
		expect(hasInternalMailboxFunc([])).toBe(false)
	})
})

describe('getValidSSN', () => {
	it('returns 12-digit SSN as-is', () => {
		expect(getValidSSN('199001011234')).toBe('199001011234')
	})

	it('prepends 19 to 10-digit SSN', () => {
		expect(getValidSSN('9001011234')).toBe('199001011234')
	})

	it('removes dash and prepends century', () => {
		expect(getValidSSN('900101-1234')).toBe('199001011234')
	})

	it('returns empty string for null', () => {
		expect(getValidSSN(null)).toBe('')
	})

	it('returns empty string for undefined', () => {
		expect(getValidSSN(undefined)).toBe('')
	})

	it('returns empty string for empty string', () => {
		expect(getValidSSN('')).toBe('')
	})
})

describe('getValidSMSNumber', () => {
	it('returns valid E.164 number as-is', () => {
		expect(getValidSMSNumber('+46701234567')).toBe('+46701234567')
	})

	it('strips spaces from valid number', () => {
		expect(getValidSMSNumber('+46 70 123 45 67')).toBe('+46701234567')
	})

	it('returns null for null input', () => {
		expect(getValidSMSNumber(null)).toBeNull()
	})

	it('returns null for number type input', () => {
		expect(getValidSMSNumber(123)).toBeNull()
	})

	it('returns null for too-short string', () => {
		expect(getValidSMSNumber('123')).toBeNull()
	})

	it('returns null for too-long number (16 digits)', () => {
		expect(getValidSMSNumber('+1234567890123456')).toBeNull()
	})
})

describe('extractPhoneFromEmail', () => {
	it('extracts phone from SMS email', () => {
		expect(extractPhoneFromEmail('+46701234567@sms')).toBe('+46701234567')
	})

	it('extracts phone from FAX email', () => {
		expect(extractPhoneFromEmail('+46812345678@fax')).toBe('+46812345678')
	})

	it('returns null for regular email', () => {
		expect(extractPhoneFromEmail('user@example.com')).toBeNull()
	})

	it('returns null for null input', () => {
		expect(extractPhoneFromEmail(null)).toBeNull()
	})
})

describe('formatPhoneNumber', () => {
	it('formats Swedish mobile number', () => {
		expect(formatPhoneNumber('+46701234567')).toBe('+46 70 123 45 67')
	})

	it('formats Swedish landline (Stockholm area code 8)', () => {
		expect(formatPhoneNumber('+46812345678')).toBe('+46 8 123 456 78')
	})

	it('formats Swedish landline with single-digit area code (non-Stockholm)', () => {
		// +46 + 9 digits with area code 3 - matches single-digit area code pattern
		expect(formatPhoneNumber('+46312345678')).toBe('+46 3 123 456 78')
	})

	it('returns unrecognized number as-is', () => {
		expect(formatPhoneNumber('+4681234567890')).toBe('+4681234567890')
	})

	it('returns empty string for null', () => {
		expect(formatPhoneNumber(null)).toBe('')
	})

	it('returns empty string for undefined', () => {
		expect(formatPhoneNumber(undefined)).toBe('')
	})
})

describe('formatLocalPhoneNumber', () => {
	it('formats valid Swedish local number via parsePhoneNumber mock', () => {
		expect(formatLocalPhoneNumber('0701234567')).toBe('070-123 45 67')
	})

	it('returns empty string for null', () => {
		expect(formatLocalPhoneNumber(null)).toBe('')
	})

	it('returns empty string for empty string', () => {
		expect(formatLocalPhoneNumber('')).toBe('')
	})
})

describe('formatParticipantDisplayName', () => {
	it('returns subOrganization label for SDK with sdkParty', () => {
		const sdkParty = {
			attention: {
				subOrganization: { label: 'Dept A' },
			},
		}
		expect(formatParticipantDisplayName(MESSAGE_TYPES.SDK.id, { sdkParty })).toBe('Dept A')
	})

	it('falls back to label for SDK without sdkParty', () => {
		expect(formatParticipantDisplayName(MESSAGE_TYPES.SDK.id, { label: 'Org Label' })).toBe('Org Label')
	})

	it('falls back to email for SDK without sdkParty or label', () => {
		expect(formatParticipantDisplayName(MESSAGE_TYPES.SDK.id, { email: 'test@example.com' })).toBe('test@example.com')
	})

	it('returns internalMailboxName for INTERNAL', () => {
		expect(formatParticipantDisplayName(MESSAGE_TYPES.INTERNAL.id, { internalMailboxName: 'My Mailbox' })).toBe('My Mailbox')
	})

	it('falls back to label for INTERNAL without mailbox name', () => {
		expect(formatParticipantDisplayName(MESSAGE_TYPES.INTERNAL.id, { label: 'Some Label' })).toBe('Some Label')
	})

	it('falls back to email for INTERNAL without mailbox name or label', () => {
		expect(formatParticipantDisplayName(MESSAGE_TYPES.INTERNAL.id, { email: 'a@b.com' })).toBe('a@b.com')
	})

	it('returns internalMailboxName for SECURE', () => {
		expect(formatParticipantDisplayName(MESSAGE_TYPES.SECURE.id, { internalMailboxName: 'Secure Box' })).toBe('Secure Box')
	})

	it('returns formatted phone for FAX with phone email', () => {
		expect(formatParticipantDisplayName(MESSAGE_TYPES.FAX.id, { email: '+46701234567@fax' })).toBe('+46 70 123 45 67')
	})

	it('returns formatted phone for SMS with phone email', () => {
		expect(formatParticipantDisplayName(MESSAGE_TYPES.SMS.id, { email: '+46701234567@sms' })).toBe('+46 70 123 45 67')
	})

	it('returns label for unknown message type', () => {
		expect(formatParticipantDisplayName('unknown_type', { label: 'Fallback', email: 'x@y.com' })).toBe('Fallback')
	})

	it('returns email for unknown message type without label', () => {
		expect(formatParticipantDisplayName('unknown_type', { email: 'x@y.com' })).toBe('x@y.com')
	})
})

describe('formatSdkFunctionName', () => {
	it('returns subOrganization label when present', () => {
		const party = { attention: { subOrganization: { label: 'Unit X' } } }
		expect(formatSdkFunctionName(party)).toBe('Unit X')
	})

	it('falls back to organizationId extension', () => {
		const party = {
			attention: {
				subOrganization: {
					label: null,
					organizationId: { extension: 'SE123:001' },
				},
			},
		}
		expect(formatSdkFunctionName(party)).toBe('SE123:001')
	})

	it('returns empty string for null', () => {
		expect(formatSdkFunctionName(null)).toBe('')
	})
})

describe('formatSdkOrganizationName', () => {
	it('returns label when present', () => {
		expect(formatSdkOrganizationName({ label: 'Org ABC' })).toBe('Org ABC')
	})

	it('falls back to senderId extension', () => {
		const party = { label: null, senderId: { extension: 'SE999' } }
		expect(formatSdkOrganizationName(party)).toBe('SE999')
	})

	it('falls back to recipientId extension', () => {
		const party = { label: null, senderId: null, recipientId: { extension: 'SE888' } }
		expect(formatSdkOrganizationName(party)).toBe('SE888')
	})

	it('returns empty string for null', () => {
		expect(formatSdkOrganizationName(null)).toBe('')
	})
})

describe('generateHtmlForMessage', () => {
	it('renders From, To, Subject and body sections', () => {
		const html = generateHtmlForMessage({
			fromFirstLine: 'Sender Org',
			toFirstLine: 'Recipient Org',
			subject: 'Test Subject',
			body: 'Hello world',
			sentAt: '15 Jun 2025, 10:30',
		})
		expect(html).toContain('From')
		expect(html).toContain('To')
		expect(html).toContain('Subject')
		expect(html).toContain('Hello world')
		expect(html).toContain('Sender Org')
		expect(html).toContain('Recipient Org')
	})

	it('renders PersonID and ReferenceID chips for senderIDs/recipientIDs', () => {
		const html = generateHtmlForMessage({
			senderIDs: [
				{ type: 'person', row1: 'PER001', row2: '1.2.3', row3: 'Person A' },
			],
			recipientIDs: [
				{ type: 'reference', row1: 'REF001', row2: '1.2.3', row3: 'Ref A' },
			],
		})
		expect(html).toContain('PersonID')
		expect(html).toContain('PER001')
		expect(html).toContain('ReferenceID')
		expect(html).toContain('REF001')
	})

	it('does not render metadata groups when senderIDs/recipientIDs are empty', () => {
		const html = generateHtmlForMessage({
			senderIDs: [],
			recipientIDs: [],
			subject: 'No IDs',
		})
		expect(html).not.toContain('SenderIDs')
		expect(html).not.toContain('RecipientIDs')
	})

	it('escapes HTML entities in values', () => {
		const html = generateHtmlForMessage({
			senderIDs: [
				{ type: 'person', row1: '<script>alert("xss")</script>', row2: 'code', row3: 'desc' },
			],
		})
		expect(html).toContain('&lt;script&gt;')
		expect(html).not.toContain('<script>alert')
	})
})

describe('generateFilename', () => {
	it('generates filename for SDK message with formatted date and party name', () => {
		const message = {
			subject: 'Important Report',
			itsl: {
				messageType: MESSAGE_TYPES.SDK.id,
				messageDirection: MESSAGE_DIRECTION.OUTGOING,
				sdk: {
					messageHeader: {
						creationDateTime: '2025-06-15T10:30:00Z',
						recipient: {
							attention: {
								subOrganization: {
									label: 'Dept Finance',
									organizationId: { extension: 'SE123:001' },
								},
							},
						},
					},
				},
			},
			to: [{ email: 'recipient@example.com', label: 'Recipient' }],
		}
		const filename = generateFilename(message)
		expect(filename).toMatch(/^2025-06-15-/)
		expect(filename).toContain('Dept-Finance')
		expect(filename).toContain('Important-Report')
		expect(filename).toMatch(/\.pdf$/)
	})

	it('generates filename for non-SDK message using contact label', () => {
		const message = {
			subject: 'Hello',
			dateInt: Math.floor(new Date('2025-06-15T10:30:00Z').getTime() / 1000),
			itsl: {
				messageType: MESSAGE_TYPES.INTERNAL.id,
				messageDirection: MESSAGE_DIRECTION.OUTGOING,
			},
			to: [{ email: 'user@gruppbox', label: 'Internal User' }],
		}
		const filename = generateFilename(message)
		expect(filename).toContain('Internal-User')
		expect(filename).toMatch(/\.pdf$/)
	})

	it('uses "no-subject" when subject is missing', () => {
		const message = {
			subject: '',
			dateInt: Math.floor(new Date('2025-06-15T10:30:00Z').getTime() / 1000),
			itsl: {
				messageType: MESSAGE_TYPES.INTERNAL.id,
				messageDirection: MESSAGE_DIRECTION.OUTGOING,
			},
			to: [{ email: 'user@gruppbox', label: 'User' }],
		}
		const filename = generateFilename(message)
		expect(filename).toContain('no-subject')
	})

	it('truncates long filenames at 200 chars', () => {
		const longSubject = 'A'.repeat(300)
		const message = {
			subject: longSubject,
			dateInt: Math.floor(new Date('2025-06-15T10:30:00Z').getTime() / 1000),
			itsl: {
				messageType: MESSAGE_TYPES.INTERNAL.id,
				messageDirection: MESSAGE_DIRECTION.OUTGOING,
			},
			to: [{ email: 'user@gruppbox', label: 'User' }],
		}
		const filename = generateFilename(message)
		// filename without .pdf should be <= 200
		expect(filename.replace('.pdf', '').length).toBeLessThanOrEqual(200)
	})

	it('sanitizes special characters in filename', () => {
		const message = {
			subject: 'Test / Report: Q2 (2025)',
			dateInt: Math.floor(new Date('2025-06-15T10:30:00Z').getTime() / 1000),
			itsl: {
				messageType: MESSAGE_TYPES.INTERNAL.id,
				messageDirection: MESSAGE_DIRECTION.OUTGOING,
			},
			to: [{ email: 'user@gruppbox', label: 'User' }],
		}
		const filename = generateFilename(message)
		expect(filename).not.toMatch(/[\/\:\(\)]/)
		expect(filename).toMatch(/\.pdf$/)
	})
})

describe('messageToHtml (integration)', () => {
	it('produces HTML containing From, To, Subject and body for SDK message', () => {
		const message = {
			subject: 'Integration Test',
			from: [{ email: 'sender@example.com', label: 'Sender Org' }],
			to: [{ email: 'recipient@example.com', label: 'Recipient Org' }],
			itsl: {
				messageType: MESSAGE_TYPES.SDK.id,
				messageDirection: MESSAGE_DIRECTION.OUTGOING,
				sdk: {
					messageHeader: {
						creationDateTime: '2025-06-15T10:30:00Z',
						sender: {
							label: 'Sender Org',
							senderId: { extension: 'SE111', root: 'iso6523-actorid-upis' },
							attention: {
								subOrganization: {
									label: 'Finance',
									organizationId: { extension: 'SE111:001', root: 'urn:riv' },
								},
								person: [],
								reference: [],
							},
						},
						recipient: {
							label: 'Recipient Org',
							recipientId: { extension: 'SE222', root: 'iso6523-actorid-upis' },
							attention: {
								subOrganization: {
									label: 'HR',
									organizationId: { extension: 'SE222:002', root: 'urn:riv' },
								},
								person: [],
								reference: [],
							},
						},
					},
				},
			},
		}
		const html = messageToHtml(message)
		expect(html).toContain('From')
		expect(html).toContain('To')
		expect(html).toContain('Subject')
		expect(html).toContain('Integration Test')
	})

	it('produces HTML for INTERNAL message using email addresses', () => {
		const message = {
			subject: 'Internal Test',
			from: [{ email: 'sender@gruppbox', label: 'Sender' }],
			to: [{ email: 'recipient@gruppbox', label: 'Recipient' }],
			itsl: {
				messageType: MESSAGE_TYPES.INTERNAL.id,
				messageDirection: MESSAGE_DIRECTION.OUTGOING,
			},
		}
		const html = messageToHtml(message)
		expect(html).toContain('Internal Test')
		expect(html).toContain('recipient@gruppbox')
	})
})
