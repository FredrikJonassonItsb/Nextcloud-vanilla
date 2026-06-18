/**
 * SPDX-FileCopyrightText: 2026 ITSL AB <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Shared mock helpers for ITSL test suite.
 * Import these in test files to reduce duplication.
 */

/**
 * Create a stub component for vue-material-design-icons or similar.
 *
 * @param {string} name - Component name
 * @return {object} Vue component stub
 */
export function iconStub(name) {
	return { name, template: '<span />', props: ['size'] }
}

/**
 * Register vi.mock calls for common ITSL icon dependencies.
 * Must be called at module level (before imports).
 *
 * Usage: place these vi.mock calls at the top of your test file:
 *   vi.mock('../../components/assets/SDKIcon.vue', () => iconStub('MockSDKIcon'))
 *   ...etc
 *
 * This function returns the mock definitions for use with vi.mock().
 */
export const ICON_MOCKS = {
	'../../components/assets/SDKIcon.vue': () => iconStub('MockSDKIcon'),
	'vue-material-design-icons/Forum.vue': () => iconStub('MockForum'),
	'vue-material-design-icons/MessageTextLock.vue': () => iconStub('MockMessageTextLock'),
	'vue-material-design-icons/Fax.vue': () => iconStub('MockFax'),
	'vue-material-design-icons/CellphoneMessage.vue': () => iconStub('MockCellphoneMessage'),
	'vue-material-design-icons/Close.vue': () => iconStub('Close'),
	'vue-material-design-icons/Delete.vue': () => iconStub('Delete'),
	'vue-material-design-icons/Star.vue': () => iconStub('Star'),
	'vue-material-design-icons/StarOutline.vue': () => iconStub('StarOutline'),
	'vue-material-design-icons/Alert.vue': () => iconStub('Alert'),
	'vue-material-design-icons/MenuDown.vue': () => iconStub('MenuDown'),
}

/**
 * Create the standard @nextcloud/auth mock object.
 *
 * @param {string} uid - User ID to return from getCurrentUser
 * @return {object} Mock object for vi.mock('@nextcloud/auth', () => ...)
 */
export function createAuthMock(uid = 'testuser') {
	return {
		getCurrentUser: () => ({ uid }),
		getRequestToken: vi.fn(() => 'mock-token'),
		onRequestTokenUpdate: vi.fn(),
	}
}

/**
 * Standard translation mock: returns the key string unchanged.
 *
 * @return {object} Object with `t` function for use in Vue `mocks`
 */
export function createTranslationMock() {
	return { t: (app, str) => str }
}

/**
 * Create a stub for NcPopover with trigger and default slots.
 *
 * @return {object} Vue component stub
 */
export function ncPopoverStub() {
	return {
		name: 'NcPopover',
		template: '<div class="nc-popover"><slot name="trigger" :attrs="{}" :on="{}" /><slot /></div>',
		props: ['placement'],
	}
}

/**
 * Create a logger mock matching the @nextcloud/log or local logger pattern.
 *
 * @return {object} Mock logger with debug/error/warn/info
 */
export function createLoggerMock() {
	return {
		debug: vi.fn(),
		error: vi.fn(),
		warn: vi.fn(),
		info: vi.fn(),
	}
}

/**
 * Helper to flush all pending promises (microtask queue).
 *
 * @return {Promise<void>}
 */
export function flushPromises() {
	return new Promise((resolve) => setTimeout(resolve, 0))
}
