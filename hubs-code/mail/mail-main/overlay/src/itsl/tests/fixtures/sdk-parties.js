export function createSdkParty(overrides = {}) {
	return {
		senderId: {
			extension: 'SE1234567890',
			root: 'iso6523-actorid-upis',
		},
		recipientId: null,
		attention: {
			subOrganization: {
				organizationId: {
					extension: 'SE1234567890:1001',
					root: 'urn:riv:infrastructure:messaging:functionalAddress',
				},
				label: 'Test Department',
			},
			person: [
				{
					label: 'John Doe',
					personId: {
						extension: '191234567890',
						root: '1.2.752.129.2.1.3.1',
					},
				},
			],
			reference: [
				{
					label: 'REF-001',
					referenceId: {
						extension: 'REF001',
						root: '1.2.752.129.2.1.3.1',
					},
				},
			],
		},
		label: 'Test Organization',
		...overrides,
	}
}

export function createSdkPartyMinimal() {
	return {
		senderId: { extension: 'SE0000000000', root: 'iso6523-actorid-upis' },
		attention: {
			subOrganization: {
				organizationId: { extension: 'SE0000000000:0001', root: 'urn:riv:infrastructure:messaging:functionalAddress' },
				label: null,
			},
			person: [],
			reference: [],
		},
		label: null,
	}
}
