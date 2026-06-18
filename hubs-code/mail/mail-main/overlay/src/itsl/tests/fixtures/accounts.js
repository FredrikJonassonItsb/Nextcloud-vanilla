export function createAccount(overrides = {}) {
	return {
		id: 1,
		accountId: 1,
		name: 'Test Account',
		emailAddress: 'test@example.com',
		sentMailboxId: 200,
		mailboxes: [100, 200, 300],
		aliases: [],
		...overrides,
	}
}

export function createMailbox(overrides = {}) {
	return {
		databaseId: 100,
		id: 100,
		accountId: 1,
		name: 'INBOX',
		specialRole: 'inbox',
		specialUse: ['inbox'],
		isUnified: false,
		isPriorityInbox: false,
		unread: 0,
		envelopeLists: {},
		mailboxes: [],
		...overrides,
	}
}

export function createUnifiedMailbox(overrides = {}) {
	return createMailbox({
		databaseId: 'unified',
		id: 'unified',
		accountId: 0,
		name: 'All inboxes',
		isUnified: true,
		...overrides,
	})
}
