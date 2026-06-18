/**
 * SPDX-FileCopyrightText: 2024 ITSL AB
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Webpack-dev-server / HMR config, run by the per-app sidecar. Merges
 * over webpack.itsl.js (which carries devtool + watchOptions +
 * snapshot); HMR's deltas conflict with non-HMR output, hence a
 * separate file: overrides devtool, stabilises chunkFilename, adds
 * the devServer block.
 *
 * WEBPACK_APP_NAME stays an env var (the only one kept) because URLs
 * and chunkFilename legitimately vary per app; port/host are fixed.
 *
 * Apache inside the nextcloud container reverse-proxies
 * /apps-extra/<app>/{js,ws} to this sidecar via .htaccess rules
 * written by regen-htaccess, refreshed from `make webpack`.
 *
 * MODE=force copies both this file and webpack.itsl.js into the app's
 * .build/, so the relative require below resolves in-container — do
 * not make the path absolute.
 */
const { merge } = require('webpack-merge')
let itsl = require('./webpack.itsl.js')

const appName = process.env.WEBPACK_APP_NAME
if (!appName) {
	throw new Error('WEBPACK_APP_NAME environment variable is required')
}

module.exports = async () => {
	if (typeof itsl === 'function') itsl = await itsl()
	return merge(itsl, {
	// vue-loader HMR requires 'eval' (vue-loader#1795); hmr_enabler's
	// LaxifyCSP allows the resulting unsafe-eval.
	devtool: 'eval',

	output: {
		// No [contenthash]: HMR can't update a changing URL in-place,
		// so chunk names must stay stable across rebuilds.
		chunkFilename: `${appName}.[name].js`,
	},

	devServer: {
		devMiddleware: {
			// Serve assets at Nextcloud's app path (Apache proxies here).
			publicPath: `/apps-extra/${appName}/js/`,
			// Nextcloud's jsresourceloader checks the script exists on
			// disk before emitting its <script> tag; without writeToDisk
			// the tag is never rendered and the app loads blank.
			writeToDisk: true,
		},
		// Every sidecar binds the same fixed port; compose service-name
		// DNS disambiguates per app, so do not make this per-app.
		port: 3000,
		host: '0.0.0.0',
		open: false,
		allowedHosts: 'all',
		hot: true,
		client: {
			// Browser reaches the HMR socket via Apache on :8080, not
			// the sidecar port directly.
			webSocketURL: `ws://localhost:8080/apps-extra/${appName}/ws`,
			overlay: { errors: true, warnings: false },
		},
		// This path must match what Apache proxies for the socket.
		webSocketServer: {
			options: {
				path: `/apps-extra/${appName}/ws`,
			},
		},
	},
})}
