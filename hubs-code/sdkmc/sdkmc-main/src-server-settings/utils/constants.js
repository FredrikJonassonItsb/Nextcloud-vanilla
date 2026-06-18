export const SDKMC_API_ROUTES = {
	SERVER_SETTINGS: {
		GET: '/apps/sdkmc/api/v2/admin/serversettings',
		UPDATE: '/apps/sdkmc/api/v2/admin/serversettings',
	},
	ADDRESS_BOOK: {
		UPDATE_NOW: '/apps/sdkmc/api/v2/admin/updateAddressBook',
		GET_ORGANIZTIONS: '/apps/sdkmc/api/v2/frontend/sdk/addressbook/api/organizations',
	},
	ACTIVITY: {
		GET_STATUS: '/apps/sdkmc/api/v2/admin/activityNotificationStatus',
		PROPAGATE_DEFAULTS: '/apps/sdkmc/api/v2/admin/propagateActivityDefaults',
	},
}
