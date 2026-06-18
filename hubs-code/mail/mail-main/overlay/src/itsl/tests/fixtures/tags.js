let nextTagId = 500

export function createTag(overrides = {}) {
	const id = nextTagId++
	return {
		id,
		displayName: `Tag ${id}`,
		color: '#ff0000',
		imapLabel: `$tag_${id}`,
		isDefaultTag: false,
		isAssignmentTag: false,
		username: null,
		...overrides,
	}
}

export function createAssignmentTag(overrides = {}) {
	return createTag({
		displayName: 'Test User',
		imapLabel: '$assignee_testuser',
		isAssignmentTag: true,
		username: 'testuser',
		...overrides,
	})
}

export function createTagsByAccount(accounts = [1, 2]) {
	const result = {}
	for (const accountId of accounts) {
		result[accountId] = [
			createTag({ id: accountId * 100, imapLabel: '$tag_shared', displayName: 'Shared Tag' }),
			createTag({ id: accountId * 100 + 1, imapLabel: '$tag_unique_' + accountId, displayName: `Unique ${accountId}` }),
			createAssignmentTag({ id: accountId * 100 + 2, imapLabel: '$assignee_testuser', displayName: 'Test User', username: 'testuser' }),
		]
	}
	return result
}
