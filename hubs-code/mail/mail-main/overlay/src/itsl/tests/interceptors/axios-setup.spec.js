vi.mock('@nextcloud/axios', () => {
	const requestInterceptors = []
	const responseInterceptors = []
	return {
		__esModule: true,
		default: {
			interceptors: {
				request: {
					use: vi.fn((onFulfilled, onRejected) => {
						requestInterceptors.push({ onFulfilled, onRejected })
					}),
				},
				response: {
					use: vi.fn((onFulfilled, onRejected) => {
						responseInterceptors.push({ onFulfilled, onRejected })
					}),
				},
			},
			get: vi.fn(() => Promise.resolve({ data: {} })),
			post: vi.fn(() => Promise.resolve({ data: {} })),
			put: vi.fn(() => Promise.resolve({ data: {} })),
			delete: vi.fn(() => Promise.resolve({ data: {} })),
		},
		_requestInterceptors: requestInterceptors,
		_responseInterceptors: responseInterceptors,
	}
})

vi.mock('../../store/itslStore.js', () => {
	const store = {
		tagsByAccount: {},
		getValidFromData: new Map(),
		getSDKOrganizationRoot: 'urn:riv:infrastructure:messaging:functionalAddress',
		getSDKactoridUpisRoot: 'iso6523-actorid-upis',
		getSenderORGextension: 'SE1234567890',
		getSenderLabels: vi.fn(() => ({ functionAddressLabel: 'Test Dept', organizationAddressLabel: 'Test Org' })),
		initStore: vi.fn(() => Promise.resolve()),
	}
	return { default: vi.fn(() => store) }
})

vi.mock('@/store/mainStore.js', () => {
	const store = {
		envelopes: {},
		tags: {},
		getMailbox: vi.fn(),
		mailboxes: {},
	}
	return { default: vi.fn(() => store) }
})

import { createPinia, setActivePinia } from 'pinia'
import { MESSAGE_TYPES, SDKMC_API_ROUTES } from '../../store/constants.js'
import * as axiosMod from '@nextcloud/axios'
import { initInterceptors } from '../../interceptors/axios-setup.js'
import useItslStore from '../../store/itslStore.js'
import useMainStore from '@/store/mainStore.js'

let requestInterceptor
let responseInterceptor
let responseRejector
let itslStoreMock
let mainStoreMock

beforeEach(() => {
	setActivePinia(createPinia())

	// Clear interceptor arrays before each test suite reset
	axiosMod._requestInterceptors.length = 0
	axiosMod._responseInterceptors.length = 0
	axiosMod.default.interceptors.request.use.mockClear()
	axiosMod.default.interceptors.response.use.mockClear()

	// Re-init interceptors
	initInterceptors()

	requestInterceptor = axiosMod._requestInterceptors[0].onFulfilled
	responseInterceptor = axiosMod._responseInterceptors[0].onFulfilled
	responseRejector = axiosMod._responseInterceptors[0].onRejected

	itslStoreMock = useItslStore()
	mainStoreMock = useMainStore()
})

afterEach(() => {
	vi.restoreAllMocks()
})

describe('Response interceptor - normalizeEnvelopeTags', () => {
	it('normalizes tag IDs to canonical when envelope has tags object', () => {
		itslStoreMock.tagsByAccount = {
			1: [{ id: 100, imapLabel: '$tag_a', displayName: 'Tag A' }],
		}

		const response = {
			config: { url: '/api/messages/123' },
			data: {
				databaseId: 1,
				accountId: 1,
				tags: { 0: { id: 999, imapLabel: '$tag_a' } },
			},
		}

		const result = responseInterceptor(response)
		expect(result.data.tags[0].id).toBe(100)
	})

	it('skips when tags is an array', () => {
		const response = {
			config: { url: '/api/messages/123' },
			data: {
				databaseId: 1,
				accountId: 1,
				tags: [{ id: 1, imapLabel: '$tag_a' }],
			},
		}

		const result = responseInterceptor(response)
		// Should not crash; tags remain unchanged
		expect(result.data.tags).toEqual([{ id: 1, imapLabel: '$tag_a' }])
	})

	it('skips when no tags', () => {
		const response = {
			config: { url: '/api/messages/123' },
			data: { databaseId: 1, accountId: 1 },
		}

		const result = responseInterceptor(response)
		expect(result.data.tags).toBeUndefined()
	})

	it('skips when accountId cannot be determined', () => {
		mainStoreMock.getMailbox.mockReturnValue(null)

		const response = {
			config: { url: '/api/messages/123' },
			data: {
				databaseId: 1,
				mailboxId: 999,
				tags: { 0: { id: 1, imapLabel: '$tag_a' } },
			},
		}

		// Should not crash
		const result = responseInterceptor(response)
		expect(result.data.tags[0].id).toBe(1) // unchanged
	})

	it('handles single envelope response (data.databaseId exists)', () => {
		itslStoreMock.tagsByAccount = {
			1: [{ id: 50, imapLabel: '$label_x', displayName: 'X' }],
		}

		const response = {
			config: { url: '/api/messages/5' },
			data: {
				databaseId: 5,
				accountId: 1,
				tags: { 0: { id: 999, imapLabel: '$label_x' } },
			},
		}

		const result = responseInterceptor(response)
		expect(result.data.tags[0].id).toBe(50)
	})

	it('handles array of envelopes', () => {
		itslStoreMock.tagsByAccount = {
			1: [{ id: 10, imapLabel: '$tag_1', displayName: 'Tag 1' }],
		}

		const response = {
			config: { url: '/api/messages' },
			data: [
				{ databaseId: 1, accountId: 1, tags: { 0: { id: 999, imapLabel: '$tag_1' } } },
				{ databaseId: 2, accountId: 1, tags: { 0: { id: 888, imapLabel: '$tag_1' } } },
			],
		}

		const result = responseInterceptor(response)
		expect(result.data[0].tags[0].id).toBe(10)
		expect(result.data[1].tags[0].id).toBe(10)
	})

	it('handles sync response (data.newMessages, data.changedMessages)', () => {
		itslStoreMock.tagsByAccount = {
			1: [{ id: 20, imapLabel: '$tag_sync', displayName: 'Sync Tag' }],
		}

		const response = {
			config: { url: '/api/mailboxes/1/sync' },
			data: {
				newMessages: [
					{ databaseId: 10, accountId: 1, tags: { 0: { id: 777, imapLabel: '$tag_sync' } } },
				],
				changedMessages: [
					{ databaseId: 11, accountId: 1, tags: { 0: { id: 666, imapLabel: '$tag_sync' } } },
				],
			},
		}

		const result = responseInterceptor(response)
		expect(result.data.newMessages[0].tags[0].id).toBe(20)
		expect(result.data.changedMessages[0].tags[0].id).toBe(20)
	})

	it('skips non-envelope responses (URL does not include /api/messages or /api/mailboxes)', () => {
		const response = {
			config: { url: '/api/settings' },
			data: { someKey: 'someValue' },
		}

		const result = responseInterceptor(response)
		expect(result.data.someKey).toBe('someValue')
	})
})

describe('Response interceptor - error rejection path', () => {
	it('rejects with the original error when response interceptor rejects', async () => {
		const error = new Error('Network failure')
		error.response = { status: 500 }

		if (responseRejector) {
			await expect(Promise.reject(error).catch(responseRejector)).rejects.toThrow('Network failure')
		} else {
			// If no reject handler registered, the error propagates unchanged
			expect(responseRejector).toBeUndefined()
		}
	})
})

describe('Request interceptor - draft/outbox recipient building', () => {
	function makeDraftConfig(itslData, overrides = {}) {
		return {
			method: 'post',
			url: '/api/drafts',
			data: {
				to: [{ email: 'placeholder@test.com', label: 'placeholder' }],
				itsl: {
					messageType: null,
					...itslData,
				},
			},
			...overrides,
		}
	}

	it('SDK: generates valid SDK address from organizationAddress + functionAddress', () => {
		const config = makeDraftConfig({
			messageType: MESSAGE_TYPES.SDK.id,
			organizationAddress: 'iso6523-actorid-upis:0007:SE1234567890',
			functionAddress: 'urn:riv:infrastructure:messaging:functionalAddress:SE1234567890:1001',
			alias: { emailAddress: 'test@example.com', name: 'Test' },
		})

		// Mock getValidFromData to find the alias
		itslStoreMock.getValidFromData = new Map([
			['SE1234567890:1001', 'test@example.com'],
		])

		const result = requestInterceptor(config)
		expect(result.data.to).toHaveLength(1)
		expect(result.data.to[0].email).toContain('.sdk')
	})

	it('INTERNAL: uses itsl.email directly', () => {
		const config = makeDraftConfig({
			messageType: MESSAGE_TYPES.INTERNAL.id,
			email: 'user@gruppbox',
		})

		const result = requestInterceptor(config)
		expect(result.data.to[0].email).toBe('user@gruppbox')
	})

	it('SECURE person (with ssn): builds notification.ssn.securemail', () => {
		const config = makeDraftConfig({
			messageType: MESSAGE_TYPES.SECURE.id,
			notification: 'user@example.com',
			ssn: '199001011234',
		})

		const result = requestInterceptor(config)
		expect(result.data.to[0].email).toBe('user@example.com.199001011234.securemail')
	})

	it('SECURE org (no ssn): builds notification.org.securemail', () => {
		const config = makeDraftConfig({
			messageType: MESSAGE_TYPES.SECURE.id,
			notification: 'org@example.com',
			ssn: '',
		})

		const result = requestInterceptor(config)
		expect(result.data.to[0].email).toBe('org@example.com.org.securemail')
	})

	it('FAX: builds faxAddress@fax', () => {
		const config = makeDraftConfig({
			messageType: MESSAGE_TYPES.FAX.id,
			faxAddress: '+46812345678',
		})

		const result = requestInterceptor(config)
		expect(result.data.to[0].email).toBe('+46812345678@fax')
	})

	it('SMS: builds smsAddress@sms', () => {
		const config = makeDraftConfig({
			messageType: MESSAGE_TYPES.SMS.id,
			smsAddress: '+46701234567',
		})

		const result = requestInterceptor(config)
		expect(result.data.to[0].email).toBe('+46701234567@sms')
	})

	it('clears and replaces to array', () => {
		const config = makeDraftConfig({
			messageType: MESSAGE_TYPES.INTERNAL.id,
			email: 'new@gruppbox',
		})
		config.data.to = [
			{ email: 'old1@test.com', label: 'Old 1' },
			{ email: 'old2@test.com', label: 'Old 2' },
		]

		const result = requestInterceptor(config)
		expect(result.data.to).toHaveLength(1)
		expect(result.data.to[0].email).toBe('new@gruppbox')
	})

	it('PUT /api/drafts also triggers recipient building', () => {
		const config = makeDraftConfig({
			messageType: MESSAGE_TYPES.INTERNAL.id,
			email: 'user@gruppbox',
		}, { method: 'put', url: '/api/drafts/99' })

		const result = requestInterceptor(config)
		expect(result.data.to[0].email).toBe('user@gruppbox')
	})

	it('cleans up ITSL temp fields (cleanupItslDataObject)', () => {
		const config = makeDraftConfig({
			messageType: MESSAGE_TYPES.INTERNAL.id,
			email: 'user@gruppbox',
			organizationAddress: 'should-be-removed',
			functionAddress: 'should-be-removed',
			notification: 'should-be-removed',
			ssn: 'should-be-removed',
			faxAddress: 'should-be-removed',
			smsAddress: 'should-be-removed',
			senderPersonIDs: 'should-be-removed',
			senderReferenceIDs: 'should-be-removed',
			recipientPersonIDs: 'should-be-removed',
			recipientReferenceIDs: 'should-be-removed',
			confidentiality: 'should-be-removed',
			additionalAttachment: 'should-be-removed',
			additionalAttachmentName: 'should-be-removed',
			overrideBody: 'should-be-removed',
		})

		const result = requestInterceptor(config)
		expect(result.data.itsl.organizationAddress).toBeUndefined()
		expect(result.data.itsl.functionAddress).toBeUndefined()
		expect(result.data.itsl.email).toBeUndefined()
		expect(result.data.itsl.notification).toBeUndefined()
		expect(result.data.itsl.ssn).toBeUndefined()
		expect(result.data.itsl.faxAddress).toBeUndefined()
		expect(result.data.itsl.smsAddress).toBeUndefined()
		expect(result.data.itsl.senderPersonIDs).toBeUndefined()
		expect(result.data.itsl.senderReferenceIDs).toBeUndefined()
		expect(result.data.itsl.recipientPersonIDs).toBeUndefined()
		expect(result.data.itsl.recipientReferenceIDs).toBeUndefined()
		expect(result.data.itsl.confidentiality).toBeUndefined()
		expect(result.data.itsl.additionalAttachment).toBeUndefined()
		expect(result.data.itsl.additionalAttachmentName).toBeUndefined()
		expect(result.data.itsl.overrideBody).toBeUndefined()
		// messageType should be preserved
		expect(result.data.itsl.messageType).toBe(MESSAGE_TYPES.INTERNAL.id)
	})
})

describe('Request interceptor - tag redirect', () => {
	it('DELETE tag: redirects from /apps/mail/ to /apps/sdkmc/', () => {
		const config = {
			method: 'delete',
			url: '/apps/mail/api/tags/1/delete/5',
		}

		const result = requestInterceptor(config)
		expect(result.url).toBe(SDKMC_API_ROUTES.DELETE_TAG(1, 5))
		expect(result.url).toContain('/apps/sdkmc/')
	})

	it('PUT message tag: redirects to sdkmc, enriches with accountId/messageId', () => {
		mainStoreMock.envelopes = {
			42: { accountId: 1, messageId: 'msg-42' },
		}

		const config = {
			method: 'put',
			url: '/apps/mail/api/messages/42/tags/$label_test',
		}

		const result = requestInterceptor(config)
		expect(result.url).toBe(SDKMC_API_ROUTES.SET_MESSAGE_TAG(42, '$label_test'))
		expect(result.data.accountId).toBe(1)
		expect(result.data.messageId).toBe('msg-42')
	})

	it('DELETE message tag: redirects to sdkmc, enriches with accountId/messageId', () => {
		mainStoreMock.envelopes = {
			42: { accountId: 1, messageId: 'msg-42' },
		}

		const config = {
			method: 'delete',
			url: '/apps/mail/api/messages/42/tags/$label_test',
		}

		const result = requestInterceptor(config)
		expect(result.url).toBe(SDKMC_API_ROUTES.REMOVE_MESSAGE_TAG(42, '$label_test'))
		expect(result.data.accountId).toBe(1)
		expect(result.data.messageId).toBe('msg-42')
	})
})

describe('Request interceptor - tag ID translation in search', () => {
	beforeEach(() => {
		mainStoreMock.tags = {
			10: { id: 10, displayName: 'Urgent', imapLabel: '$tag_urgent', isAssignmentTag: false },
			20: { id: 20, displayName: 'Review', imapLabel: '$tag_review', isAssignmentTag: false },
			30: { id: 30, displayName: 'Assigned User', imapLabel: '$assignee_user', isAssignmentTag: true },
		}
		mainStoreMock.getMailbox.mockReturnValue({ accountId: 1 })
		itslStoreMock.tagsByAccount = {
			1: [
				{ id: 100, displayName: 'Urgent', imapLabel: '$tag_urgent_acct1' },
				{ id: 101, displayName: 'Review', imapLabel: '$tag_review_acct1' },
				{ id: 102, displayName: 'Assigned User', imapLabel: '$assignee_user_acct1', isAssignmentTag: true },
			],
		}
	})

	it('translates numeric tag IDs to imapLabels in search filter', () => {
		const config = {
			method: 'get',
			url: '/api/messages',
			params: {
				filter: 'tags:10',
				mailboxId: 100,
			},
		}

		const result = requestInterceptor(config)
		expect(result.params.filter).toBe('tags:$tag_urgent_acct1')
	})

	it('handles comma-separated tag IDs', () => {
		const config = {
			method: 'get',
			url: '/api/messages',
			params: {
				filter: 'tags:10,20',
				mailboxId: 100,
			},
		}

		const result = requestInterceptor(config)
		expect(result.params.filter).toContain('$tag_urgent_acct1')
		expect(result.params.filter).toContain('$tag_review_acct1')
	})

	it('preserves "none" marker', () => {
		const config = {
			method: 'get',
			url: '/api/messages',
			params: {
				filter: 'tags:none,10',
				mailboxId: 100,
			},
		}

		const result = requestInterceptor(config)
		expect(result.params.filter).toContain('none')
		expect(result.params.filter).toContain('$tag_urgent_acct1')
	})

	it('returns empty results (adapter) for unknown tag', () => {
		const config = {
			method: 'get',
			url: '/api/messages',
			params: {
				filter: 'tags:9999',
				mailboxId: 100,
			},
		}

		const result = requestInterceptor(config)
		expect(result.adapter).toBeDefined()
		expect(typeof result.adapter).toBe('function')
	})
})

describe('Request interceptor - generateValidSDKAddress', () => {
	function makeSdkDraftConfig(orgAddress, funcAddress) {
		return {
			method: 'post',
			url: '/api/drafts',
			data: {
				to: [{ email: 'placeholder', label: 'placeholder' }],
				itsl: {
					messageType: MESSAGE_TYPES.SDK.id,
					organizationAddress: orgAddress,
					functionAddress: funcAddress,
					alias: { emailAddress: 'test@example.com', name: 'Test' },
					senderPersonIDs: [],
					senderReferenceIDs: [],
					recipientPersonIDs: [],
					recipientReferenceIDs: [],
					confidentiality: 'normal',
				},
			},
		}
	}

	beforeEach(() => {
		itslStoreMock.getValidFromData = new Map([
			['SE1234567890:1001', 'test@example.com'],
		])
	})

	it('produces correct format: functionAddress formatted + @ + orgAddress formatted + .sdk', () => {
		const config = makeSdkDraftConfig(
			'iso6523-actorid-upis:0007:SE1234567890',
			'urn:riv:infrastructure:messaging:functionalAddress:SE1234567890:1001',
		)

		const result = requestInterceptor(config)
		const email = result.data.to[0].email
		expect(email).toContain('@')
		expect(email.endsWith('.sdk')).toBe(true)
	})

	it('strips :0203: suffix from functionAddress', () => {
		const config = makeSdkDraftConfig(
			'iso6523-actorid-upis:0007:SE1234567890',
			'urn:riv:infrastructure:messaging:functionalAddress:SE1234567890:1001:0203:extra',
		)

		const result = requestInterceptor(config)
		const email = result.data.to[0].email
		expect(email).not.toContain('0203')
	})

	it('replaces colons with dots', () => {
		const config = makeSdkDraftConfig(
			'iso6523-actorid-upis:0007:SE1234567890',
			'urn:riv:infrastructure:messaging:functionalAddress:SE1234567890:1001',
		)

		const result = requestInterceptor(config)
		const email = result.data.to[0].email
		// The local part (before @) should not contain colons
		const localPart = email.split('@')[0]
		expect(localPart).not.toContain(':')
	})

	it('collapses consecutive dots', () => {
		const config = makeSdkDraftConfig(
			'a::b:::c',
			'd::e:::f',
		)

		const result = requestInterceptor(config)
		const email = result.data.to[0].email
		expect(email).not.toContain('..')
	})

	it('returns empty to address when organizationAddress is empty', () => {
		const config = makeSdkDraftConfig('', 'urn:riv:infrastructure:messaging:functionalAddress:SE1234567890:1001')

		const result = requestInterceptor(config)
		// When org or func is empty, generateValidSDKAddress returns ''
		expect(result.data.to[0].email).toBe('')
	})

	it('returns empty to address when functionAddress is null', () => {
		const config = makeSdkDraftConfig('iso6523-actorid-upis:0007:SE1234567890', null)

		const result = requestInterceptor(config)
		expect(result.data.to[0].email).toBe('')
	})
})

describe('cleanupItslDataObject', () => {
	const tempFields = [
		'organizationAddress',
		'organizationAddressLabel',
		'functionAddress',
		'functionAddressLabel',
		'email',
		'notification',
		'ssn',
		'faxAddress',
		'smsAddress',
		'senderPersonIDs',
		'senderReferenceIDs',
		'recipientPersonIDs',
		'recipientReferenceIDs',
		'confidentiality',
		'additionalAttachment',
		'additionalAttachmentName',
		'overrideBody',
	]

	it('removes all temp ITSL fields', () => {
		const itslData = { messageType: MESSAGE_TYPES.INTERNAL.id, email: 'user@gruppbox' }
		for (const field of tempFields) {
			itslData[field] = 'test-value'
		}

		const config = {
			method: 'post',
			url: '/api/drafts',
			data: {
				to: [{ email: 'old', label: 'old' }],
				itsl: itslData,
			},
		}

		const result = requestInterceptor(config)
		for (const field of tempFields) {
			expect(result.data.itsl[field]).toBeUndefined()
		}
	})

	it('preserves non-ITSL data', () => {
		const config = {
			method: 'post',
			url: '/api/drafts',
			data: {
				to: [{ email: 'old', label: 'old' }],
				subject: 'Test Subject',
				body: 'Test body',
				itsl: {
					messageType: MESSAGE_TYPES.INTERNAL.id,
					email: 'user@gruppbox',
					customField: 'should-stay',
				},
			},
		}

		const result = requestInterceptor(config)
		expect(result.data.subject).toBe('Test Subject')
		expect(result.data.body).toBe('Test body')
		expect(result.data.itsl.messageType).toBe(MESSAGE_TYPES.INTERNAL.id)
		expect(result.data.itsl.customField).toBe('should-stay')
	})
})
