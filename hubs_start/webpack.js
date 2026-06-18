const webpackConfig = require('@nextcloud/webpack-vue-config')
const ESLintPlugin = require('eslint-webpack-plugin')
const StyleLintPlugin = require('stylelint-webpack-plugin')
const path = require('path')

// Single entry: the Hubs Start SPA, mounted on the page template.
webpackConfig.entry = {
	main: { import: path.join(__dirname, 'src', 'main.js'), filename: 'hubs_start-main.js' },
}

// Lint is a separate quality gate (`npm run lint` / `npm run stylelint`), not a
// build-blocker. Enable inline linting during webpack only when HS_LINT=1.
if (process.env.HS_LINT === '1') {
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
}

webpackConfig.module.rules.push({
	test: /\.svg$/i,
	type: 'asset/source',
})

module.exports = webpackConfig
