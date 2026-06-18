import { MESSAGE_TYPES, MESSAGE_DIRECTION } from '../../store/constants.js'

export function createFullMessage(overrides = {}) {
	return {
		databaseId: 1,
		subject: 'Test Subject',
		body: 'This is the message body.',
		dateInt: Math.floor(new Date('2025-06-15T10:30:00Z').getTime() / 1000),
		from: [{ email: 'sender@example.com', label: 'Sender Name' }],
		to: [{ email: 'recipient@example.com', label: 'Recipient Name' }],
		itsl: {
			messageType: MESSAGE_TYPES.SDK.id,
			messageDirection: MESSAGE_DIRECTION.OUTGOING,
			sdk: {
				messageHeader: {
					creationDateTime: '2025-06-15T10:30:00Z',
					label: 'Test SDK Message',
					sender: {
						senderId: { extension: 'SE1111111111', root: 'iso6523-actorid-upis' },
						attention: {
							subOrganization: {
								organizationId: { extension: 'SE1111111111:1001', root: 'urn:riv:infrastructure:messaging:functionalAddress' },
								label: 'Sender Dept',
							},
							person: [
								{ label: 'Person A', personId: { extension: 'PER001', root: '1.2.3' } },
							],
							reference: [
								{ label: 'Ref A', referenceId: { extension: 'REF001', root: '1.2.3' } },
							],
						},
						label: 'Sender Org',
					},
					recipient: {
						recipientId: { extension: 'SE2222222222', root: 'iso6523-actorid-upis' },
						attention: {
							subOrganization: {
								organizationId: { extension: 'SE2222222222:2001', root: 'urn:riv:infrastructure:messaging:functionalAddress' },
								label: 'Recipient Dept',
							},
							person: [],
							reference: [],
						},
						label: 'Recipient Org',
					},
				},
			},
		},
		...overrides,
	}
}
