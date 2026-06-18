const webpackConfig = require('@nextcloud/webpack-vue-config')
const ESLintPlugin = require('eslint-webpack-plugin')
const StyleLintPlugin = require('stylelint-webpack-plugin')
const path = require('path')

webpackConfig.entry = {
	'sdkmc-logs-page': { import: path.join(__dirname, 'src-sdkmc-logs', 'sdkmc-logs-page.js'), filename: 'sdkmc-logs-page.js' },
	loa3: { import: path.join(__dirname, 'src-loa3', 'loa3.js'), filename: 'loa3.js' },
	'server-settings-page': { import: path.join(__dirname, 'src-server-settings', 'server-settings-page.js'), filename: 'server-settings-page.js' },
	'mailbox-settings-page': { import: path.join(__dirname, 'src-mailbox-settings', 'mailbox-settings-page.js'), filename: 'mailbox-settings-page.js' },
	'calendar-sms': { import: path.join(__dirname, 'src-calendar-sms', 'calendar-sms.js'), filename: 'calendar-sms.js' },
}

webpackConfig.plugins.push(
	 new ESLintPlugin({
		 extensions: ['js', 'vue'],
		 files: 'src',
	 }),
)
webpackConfig.plugins.push(
	new StyleLintPlugin({
		files: 'src/**/*.{css,scss,vue}',
	}),
)

webpackConfig.module.rules.push({
	test: /\.svg$/i,
	type: 'asset/source',
})

module.exports = webpackConfig
