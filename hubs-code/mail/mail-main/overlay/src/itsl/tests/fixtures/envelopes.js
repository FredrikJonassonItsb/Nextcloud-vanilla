import { MESSAGE_TYPES, MESSAGE_DIRECTION } from '../../store/constants.js'

let nextId = 1000

export function createEnvelope(overrides = {}) {
	const id = nextId++
	return {
		databaseId: id,
		uid: id,
		mailboxId: 100,
		accountId: 1,
		threadRootId: `thread-${id}`,
		dateInt: Math.floor(Date.now() / 1000),
		subject: `Test Subject ${id}`,
		from: [{ email: 'sender@example.com', label: 'Sender' }],
		to: [{ email: 'recipient@example.com', label: 'Recipient' }],
		tags: {},
		flags: { seen: false, flagged: false, important: false },
		itsl: null,
		...overrides,
	}
}

export function createSdkEnvelope(overrides = {}) {
	return createEnvelope({
		itsl: {
			messageType: MESSAGE_TYPES.SDK.id,
			messageDirection: MESSAGE_DIRECTION.OUTGOING,
			sdk: {
				messageHeader: {
					creationDateTime: new Date().toISOString(),
					label: 'Test SDK Message',
					sender: createSdkParty({ isSender: true }),
					recipient: createSdkParty({ isSender: false }),
				},
			},
		},
		...overrides,
	})
}

export function createSecureEnvelope(overrides = {}) {
	return createEnvelope({
		to: [{ email: 'user@example.com.123456789012.securemail', label: 'Secure User' }],
		itsl: {
			messageType: MESSAGE_TYPES.SECURE.id,
			messageDirection: MESSAGE_DIRECTION.OUTGOING,
		},
		...overrides,
	})
}

export function createInternalEnvelope(overrides = {}) {
	return createEnvelope({
		to: [{ email: 'user@gruppbox', label: 'Internal User' }],
		itsl: {
			messageType: MESSAGE_TYPES.INTERNAL.id,
			messageDirection: MESSAGE_DIRECTION.OUTGOING,
		},
		...overrides,
	})
}

export function createFaxEnvelope(overrides = {}) {
	return createEnvelope({
		to: [{ email: '+46812345678@fax', label: '+46812345678' }],
		itsl: {
			messageType: MESSAGE_TYPES.FAX.id,
			messageDirection: MESSAGE_DIRECTION.OUTGOING,
		},
		...overrides,
	})
}

export function createSmsEnvelope(overrides = {}) {
	return createEnvelope({
		to: [{ email: '+46701234567@sms', label: '+46701234567' }],
		itsl: {
			messageType: MESSAGE_TYPES.SMS.id,
			messageDirection: MESSAGE_DIRECTION.OUTGOING,
		},
		...overrides,
	})
}

export function createSecureEnvelopeLoa2(overrides = {}) {
	return createSecureEnvelope({
		itsl: {
			messageType: MESSAGE_TYPES.SECURE.id,
			messageDirection: MESSAGE_DIRECTION.OUTGOING,
			loaLevel: 2,
			smsNumber: '+46701234567',
		},
		...overrides,
	})
}

export function createSecureEnvelopeLoa3(overrides = {}) {
	return createSecureEnvelope({
		to: [{ email: 'user@example.com.199001011234.securemail', label: 'Secure User' }],
		itsl: {
			messageType: MESSAGE_TYPES.SECURE.id,
			messageDirection: MESSAGE_DIRECTION.OUTGOING,
			loaLevel: 3,
		},
		...overrides,
	})
}

export function createSdkEnvelopeConfidential(overrides = {}) {
	return createSdkEnvelope({
		itsl: {
			messageType: MESSAGE_TYPES.SDK.id,
			messageDirection: MESSAGE_DIRECTION.OUTGOING,
			sdk: {
				messageHeader: {
					creationDateTime: new Date().toISOString(),
					label: 'Confidential SDK Message',
					confidential: true,
					sender: createSdkParty({ isSender: true }),
					recipient: createSdkParty({ isSender: false }),
				},
			},
		},
		...overrides,
	})
}

export function createEnvelopeWithTags(tags, overrides = {}) {
	return createEnvelope({
		tags: tags.reduce((acc, tag, i) => { acc[i] = tag; return acc }, {}),
		...overrides,
	})
}

export function createMalformedEnvelope(overrides = {}) {
	return createEnvelope({
		itsl: null,
		from: [],
		to: [],
		subject: '',
		...overrides,
	})
}

export function createSdkParty({ isSender = false } = {}) {
	const idKey = isSender ? 'senderId' : 'recipientId'
	return {
		[idKey]: {
			extension: 'SE1234567890',
			root: 'iso6523-actorid-upis',
		},
		attention: {
			subOrganization: {
				organizationId: {
					extension: 'SE1234567890:1001',
					root: 'urn:riv:infrastructure:messaging:functionalAddress',
				},
				label: 'Test Department',
			},
			person: [],
			reference: [],
		},
		label: 'Test Organization',
	}
}
