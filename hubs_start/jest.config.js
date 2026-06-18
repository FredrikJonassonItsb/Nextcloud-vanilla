module.exports = {
	testEnvironment: 'jsdom',

	moduleFileExtensions: ['js', 'vue'],

	transform: {
		'^.+\\.js$': 'babel-jest',
		'^.+\\.vue$': 'vue-jest',
	},

	// @nextcloud/* packages ship ESM and pull in browser globals that the
	// jsdom test environment does not provide. Point them at lightweight mocks.
	moduleNameMapper: {
		'^@nextcloud/l10n$': '<rootDir>/tests/mocks/nextcloud.js',
		'^@nextcloud/router$': '<rootDir>/tests/mocks/nextcloud.js',
		'^@nextcloud/axios$': '<rootDir>/tests/mocks/nextcloud.js',
		'^@nextcloud/dialogs$': '<rootDir>/tests/mocks/nextcloud.js',
		'^@nextcloud/initial-state$': '<rootDir>/tests/mocks/nextcloud.js',
		// jest does not transform .vue SFCs inside node_modules; stub the icon set.
		'^vue-material-design-icons/.*\\.vue$': '<rootDir>/tests/mocks/icon.js',
	},

	testMatch: ['<rootDir>/tests/unit/**/*.spec.js'],

	clearMocks: true,
}
