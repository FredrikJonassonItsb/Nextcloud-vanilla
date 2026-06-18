module.exports = {
	root: true,
	extends: [
		'@nextcloud/eslint-config/vue',
		'@nextcloud',
	],
	env: {
		jest: true,
	},
	rules: {
		'jsdoc/require-jsdoc': 'off',
		'vue/first-attribute-linebreak': 'off',
	},
}
