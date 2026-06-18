import { shallowMount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import MessageHeaderItsl from '../../components/message/MessageHeaderItsl.vue'
import { MESSAGE_TYPES, MESSAGE_DIRECTION } from '../../store/constants.js'

vi.mock('@nextcloud/vue/components/NcPopover', () => ({
	default: {
		name: 'NcPopover',
		template: '<div class="nc-popover"><slot name="trigger" :attrs="{}" :on="{}" /><slot /></div>',
		props: ['placement'],
	},
}))

vi.mock('vue-material-design-icons/MenuDown.vue', () => ({
	default: {
		name: 'MenuDown',
		template: '<span />',
		props: ['size'],
	},
}))

// Use real parseAddressInfoFromString to avoid mock drift.
// Only wrap it with vi.fn() for call tracking.
// Component imports from messageTypeUtils.js directly, so mock that module.
vi.mock('../../utils/messageTypeUtils.js', async () => {
	const actual = await vi.importActual('../../utils/messageTypeUtils.js')
	return {
		...actual,
		parseAddressInfoFromString: vi.fn(actual.parseAddressInfoFromString),
	}
})

import { parseAddressInfoFromString } from '../../utils/messageTypeUtils.js'

describe('MessageHeaderItsl', () => {
	const stubs = {
		NcPopover: {
			name: 'NcPopover',
			template: '<div><slot name="trigger" :attrs="{}" :on="{}" /><slot /></div>',
		},
		MenuDown: { name: 'MenuDown', template: '<span />' },
	}

	const mountHeader = (message = null) => {
		return shallowMount(MessageHeaderItsl, {
			propsData: { message },
			stubs,
		})
	}

	beforeEach(() => {
		setActivePinia(createPinia())
		vi.clearAllMocks()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	// --- SDK messages ---

	it('SDK: mailHeaderFirstLine shows function name (organization)', () => {
		const message = {
			subject: 'Test Subject',
			from: [{ email: 'sdk@sdk' }],
			to: [{ email: 'to@sdk' }],
			itsl: {
				messageType: MESSAGE_TYPES.SDK.id,
				messageDirection: MESSAGE_DIRECTION.INCOMING,
				sdk: {
					messageHeader: {
						sender: {
							label: 'Sender Org',
							senderId: { extension: 'SE111' },
							attention: {
								subOrganization: {
									label: 'Sender Function',
									organizationId: { extension: 'FUNC001' },
								},
							},
						},
						recipient: {
							label: 'Recipient Org',
							recipientId: { extension: 'SE222' },
							attention: {
								subOrganization: {
									label: 'Recipient Function',
									organizationId: { extension: 'FUNC002' },
								},
							},
						},
					},
				},
			},
		}
		const wrapper = mountHeader(message)
		expect(wrapper.text()).toContain('Sender Function (Sender Org)')
	})

	it('SDK: popover shows From/To organization, address, subject, confidential', () => {
		const message = {
			subject: 'SDK Subject',
			from: [{ email: 'sdk@sdk' }],
			to: [{ email: 'to@sdk' }],
			itsl: {
				messageType: MESSAGE_TYPES.SDK.id,
				messageDirection: MESSAGE_DIRECTION.INCOMING,
				sdk: {
					messageHeader: {
						confidential: true,
						sender: {
							label: 'Sender Org',
							senderId: { extension: 'SE111' },
							attention: {
								subOrganization: {
									label: 'Sender Func',
									organizationId: { extension: 'F001' },
								},
							},
						},
						recipient: {
							label: 'Rcpt Org',
							recipientId: { extension: 'SE222' },
							attention: {
								subOrganization: {
									label: 'Rcpt Func',
									organizationId: { extension: 'F002' },
								},
							},
						},
					},
				},
			},
		}
		const wrapper = mountHeader(message)
		const text = wrapper.text()
		expect(text).toContain('From organization')
		expect(text).toContain('Sender Org (SE111)')
		expect(text).toContain('From address')
		expect(text).toContain('Sender Func (F001)')
		expect(text).toContain('To organization')
		expect(text).toContain('Rcpt Org (SE222)')
		expect(text).toContain('To address')
		expect(text).toContain('Rcpt Func (F002)')
		expect(text).toContain('Subject')
		expect(text).toContain('SDK Subject')
		expect(text).toContain('Confidential')
		expect(text).toContain('Yes')
	})

	// --- SECURE incoming ---

	it('SECURE incoming: popover shows From SSN, From email, To, Subject', () => {
		const message = {
			subject: 'Secure Subject',
			from: [{ email: 'user@example.com.197012345678.securemail' }],
			to: [{ email: 'recipient@gruppbox' }],
			itsl: {
				messageType: MESSAGE_TYPES.SECURE.id,
				messageDirection: MESSAGE_DIRECTION.INCOMING,
			},
		}
		const wrapper = mountHeader(message)
		const text = wrapper.text()
		expect(text).toContain('From SSN')
		expect(text).toContain('197012345678')
		expect(text).toContain('From email')
		expect(text).toContain('user@example.com')
		expect(text).toContain('To')
		expect(text).toContain('recipient@gruppbox')
		expect(text).toContain('Subject')
		expect(text).toContain('Secure Subject')
	})

	// --- SECURE outgoing ---

	it('SECURE outgoing: popover shows Security level, From, To email, Subject', () => {
		const message = {
			subject: 'Outgoing Secure',
			from: [{ email: 'sender@gruppbox' }],
			to: [{ email: 'recipient@example.com' }],
			itsl: {
				messageType: MESSAGE_TYPES.SECURE.id,
				messageDirection: MESSAGE_DIRECTION.OUTGOING,
				loaLevel: 1,
			},
		}
		const wrapper = mountHeader(message)
		const text = wrapper.text()
		expect(text).toContain('Security level')
		expect(text).toContain('LOA-1')
		expect(text).toContain('From')
		expect(text).toContain('sender@gruppbox')
		expect(text).toContain('To email')
		expect(text).toContain('Outgoing Secure')
	})

	it('SECURE outgoing LOA-2: shows LOA-2 (SMS) label and SMS number', () => {
		const message = {
			subject: 'LOA-2 Secure',
			from: [{ email: 'sender@gruppbox' }],
			to: [{ email: 'recipient@example.com' }],
			itsl: {
				messageType: MESSAGE_TYPES.SECURE.id,
				messageDirection: MESSAGE_DIRECTION.OUTGOING,
				loaLevel: 2,
				smsNumber: '+46701234567',
			},
		}
		const wrapper = mountHeader(message)
		const text = wrapper.text()
		expect(text).toContain('LOA-2 (SMS)')
		expect(text).toContain('One-time code to')
		expect(text).toContain('+46701234567')
	})

	it('SECURE outgoing LOA-3: shows LOA-3 (BankID) label and To SSN', () => {
		const message = {
			subject: 'LOA-3 Secure',
			from: [{ email: 'sender@personlig' }],
			to: [{ email: 'recipient@example.com.197611111234.securemail' }],
			itsl: {
				messageType: MESSAGE_TYPES.SECURE.id,
				messageDirection: MESSAGE_DIRECTION.OUTGOING,
				loaLevel: 3,
			},
		}
		const wrapper = mountHeader(message)
		const text = wrapper.text()
		expect(text).toContain('LOA-3 (BankID)')
		expect(text).toContain('To SSN')
		expect(text).toContain('197611111234')
	})

	// --- FAX ---

	it('FAX: shows from/to without @fax suffix, no subject', () => {
		const message = {
			subject: 'Fax Subject',
			from: [{ email: '08123456@fax' }],
			to: [{ email: '08654321@fax' }],
			itsl: {
				messageType: MESSAGE_TYPES.FAX.id,
				messageDirection: MESSAGE_DIRECTION.OUTGOING,
			},
		}
		const wrapper = mountHeader(message)
		const text = wrapper.text()
		// From/To should strip @fax (formatPhoneNumber adds spacing)
		expect(text).toContain('08-12 34 56')
		expect(text).toContain('08-65 43 21')
		expect(text).not.toContain('@fax')
		// Subject should NOT be shown for FAX (v-if="!isFax")
		const popoverContent = wrapper.find('.popover-content')
		const subjectDivs = popoverContent.findAll('div').filter(d => d.text().includes('Subject'))
		expect(subjectDivs).toHaveLength(0)
	})

	// --- INTERNAL ---

	it('INTERNAL: first line shows email', () => {
		const message = {
			subject: 'Internal Subject',
			from: [{ email: 'internal@gruppbox' }],
			to: [{ email: 'other@gruppbox' }],
			itsl: {
				messageType: MESSAGE_TYPES.INTERNAL.id,
				messageDirection: MESSAGE_DIRECTION.OUTGOING,
			},
		}
		const wrapper = mountHeader(message)
		// For INTERNAL outgoing, mailHeaderFirstLine uses to[0].email
		// parseAddressInfoFromString returns { email: 'other@gruppbox' }
		expect(wrapper.vm.mailHeaderFirstLine).toBe('other@gruppbox')
	})

	// --- Null message ---

	it('null message: first line is empty', () => {
		const wrapper = mountHeader(null)
		expect(wrapper.vm.mailHeaderFirstLine).toBe('')
	})

	// --- parseAddressInfoFromString called correctly ---

	it('SECURE incoming: parseAddressInfoFromString called with from email', () => {
		const message = {
			subject: 'Test',
			from: [{ email: 'person@test.com.1234.securemail' }],
			to: [{ email: 'to@gruppbox' }],
			itsl: {
				messageType: MESSAGE_TYPES.SECURE.id,
				messageDirection: MESSAGE_DIRECTION.INCOMING,
			},
		}
		mountHeader(message)
		expect(parseAddressInfoFromString).toHaveBeenCalledWith(MESSAGE_TYPES.SECURE.id, 'person@test.com.1234.securemail')
	})

	// --- Click emits toggle-expand ---

	it('click on container emits toggle-expand', async () => {
		const message = {
			subject: 'Test',
			from: [{ email: 'test@test.com' }],
			to: [{ email: 'to@test.com' }],
			itsl: {
				messageType: MESSAGE_TYPES.INTERNAL.id,
				messageDirection: MESSAGE_DIRECTION.INCOMING,
			},
		}
		const wrapper = mountHeader(message)
		await wrapper.find('.mail-header-container').trigger('click')
		expect(wrapper.emitted('toggle-expand')).toHaveLength(1)
	})
})
