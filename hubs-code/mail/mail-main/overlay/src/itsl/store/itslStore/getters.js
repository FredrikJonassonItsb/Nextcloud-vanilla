import { SDK_ORGANIZATION_ROOT, SDK_ACTORID_UPIS_ROOT } from '../constants.js'
import { getCurrentUser } from '@nextcloud/auth'
import { formatPhoneNumber } from '../../utils/phoneUtils.js'

export default function itslStore() {
	return {
		getSelectedMessageType: (state) => {
			return state.selectedMessageType
		},
		getSDKOrganizationRoot: () => {
			return SDK_ORGANIZATION_ROOT
		},
		getValidFromData: (state) => {
			return state.validFromData
		},
		getSenderORGextension() {
			return this.sender.organizationExtension
		},
		getSDKactoridUpisRoot() {
			return SDK_ACTORID_UPIS_ROOT
		},
		getAddressBookLoaded(state) {
			return state.addressBookLoaded
		},
		getAddressBookOrgs(state) {
			return state.addressBookOrgs
		},
		// ITSL: Tag getters
		getTagsForAccount: (state) => (accountId) => {
			return state.tagsByAccount[accountId] || []
		},
		getTagsLoaded(state) {
			return state.tagsLoaded
		},
		/**
		 * Get internal mailbox name by email address.
		 * Returns the display name for @gruppbox/@personlig mailboxes.
		 * @param {object} state - The store state
		 * @return {function(string): string|null} Function that takes email and returns display name or null
		 */
		getInternalMailboxName: (state) => (email) => {
			if (!email) return null
			const mailbox = state.internalMailboxes.find(m => m.email === email)
			return mailbox?.name || null
		},
		/**
		 * Get the current user's assignment tag.
		 * Finds the assignment tag where username matches the current user.
		 * @param {object} state - The store state
		 * @return {object|null} The assignment tag or null if not found
		 */
		currentUserAssignmentTag(state) {
			const currentUser = getCurrentUser()?.uid
			if (!currentUser) return null

			// Search all accounts for an assignment tag matching current user
			for (const tags of Object.values(state.tagsByAccount)) {
				const assignmentTag = tags.find(
					tag => tag.isAssignmentTag && tag.username === currentUser,
				)
				if (assignmentTag) return assignmentTag
			}
			return null
		},
		/**
		 * Look up address book labels for any organization (not just the user's own).
		 * Unlike getSenderLabels which only searches the user's org, this searches all known orgs.
		 * @param {object} state - The store state
		 * @return {function(string, string): { functionAddressLabel: string, organizationAddressLabel: string }}
		 */
		lookupAddressBookLabels: (state) => (functionExtension, orgExtension) => {
			const result = { functionAddressLabel: '', organizationAddressLabel: '' }
			if (!functionExtension && !orgExtension) return result
			for (const org of state.addressBookOrgs) {
				if (orgExtension && org.address !== orgExtension) continue
				const match = org.functionAddresses.find(f => f.address === functionExtension)
				if (match) {
					result.organizationAddressLabel = org.name
					result.functionAddressLabel = match.name
					break
				}
			}
			return result
		},
		/**
		 * Resolve the display name for an ITSL account email address.
		 * Returns null while data is still loading or for non-ITSL accounts.
		 * @param {object} state - The store state
		 * @return {function(string): string|null}
		 */
		resolveAccountDisplayName: (state) => (email) => {
			if (!email) return null
			try {
				if (email.endsWith('@personlig') || email.endsWith('@gruppbox')) {
					if (!state.internalMailboxesLoaded) return null
					const mailbox = state.internalMailboxes.find(m => m.email === email)
					return mailbox?.name || email.split('@')[0]
				}
				if (email.endsWith('@sdk')) {
					if (!state.addressBookLoaded) return null
					const foundEntry = [...state.validFromData.entries()].find(([, value]) => value === email)
					if (foundEntry) {
						const functionAddress = foundEntry[0]
						const orgExt = state.sender.organizationExtension
						for (const org of state.addressBookOrgs) {
							if (orgExt && org.address !== orgExt) continue
							const func = org.functionAddresses.find(f => f.address === functionAddress)
							if (func) return func.name
						}
					}
					return email.split('@')[0]
				}
				if (email.endsWith('@fax') || email.endsWith('@sms')) {
					return formatPhoneNumber(email)
				}
				return null
			} catch {
				return email.split('@')[0]
			}
		},
		getExpandedThreadOverride: (state) => (thread) => {
			if (!state.selectNewestInThread || thread.length === 0) return null
			return state.threadSortNewestFirst
				? [thread[0].databaseId]
				: [thread[thread.length - 1].databaseId]
		},
	}
}
