import { defineStore } from 'pinia'
import mapStoreGetters from './itslStore/getters.js'
import mapStoreActions from './itslStore/actions.js'

export default defineStore('itsl', {
	state: () => {
		return {
			threadSortNewestFirst: true, // Default true, overridden by admin setting
			selectNewestInThread: false, // Default false (hybrid selection), overridden by admin setting
			validFromData: new Map(),
			selectedMessageType: '',
			sender: {
				organizationExtension: null,
			},
			addressBookOrgs: [],
			addressBookLoaded: false,
			userObligatedToProvideSsn: false,
			// ITSL: Tags per account (keyed by accountId)
			tagsByAccount: {},
			tagsLoaded: false,
			// ITSL: Virtual mailbox tag injection state (for My Messages/Unassigned)
			virtualMailboxTag: null, // { tagSearchValue, tagName } - tagSearchValue is numeric ID or 'none'
			// ITSL: Assignment tags dropped when entering Unassigned (to restore on exit)
			droppedAssignmentTags: [],
			// ITSL: Pending sidebar tag click (for sidebar -> search flow without navigation)
			pendingSidebarTagClick: null, // { imapLabel, tagName, timestamp } or null
			// ITSL: Signal from TagSearchIndicator X button click (for virtual mailbox clear)
			virtualSearchClearedSignal: null, // { timestamp } or null
			// ITSL: Permission flag for tag management (create/edit/delete)
			canManageTags: false,
			// ITSL: Pending tag changes for optimistic UI ({ [databaseId]: { [imapLabel]: timestamp } })
			pendingTagRemovals: {},
			pendingTagAdditions: {},
			// ITSL: Cached internal mailboxes (gruppbox/personlig) for sidebar display
			internalMailboxes: [],
			internalMailboxesLoaded: false,
		}
	},
	getters: {
		...mapStoreGetters(),
	},
	actions: {
		...mapStoreActions(),
	},
})
