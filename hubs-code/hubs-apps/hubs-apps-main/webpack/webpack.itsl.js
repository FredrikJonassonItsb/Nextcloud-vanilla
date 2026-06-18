/**
 * SPDX-FileCopyrightText: 2024 ITSL AB
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * ITSL webpack config — extends the app's webpack base config with
 * ITSL additions. Chain: <app base> → itsl → hmr (HMR only;
 * webpack.hmr.js adds devServer + eval devtool).
 *
 * Mode is NOT set here — set via the `--mode` CLI flag per invocation.
 * Upstream configs (e.g. @nextcloud/webpack-vue-config) gate
 * optimization.minimize / TerserPlugin off process.env.NODE_ENV at
 * require-time, and webpack's `mode:` key does NOT propagate to
 * NODE_ENV. So invocations set both --mode and NODE_ENV (see Makefile
 * sidecar/dist recipes; sidecar env via scripts/compose.sh fragments).
 *
 * App base config resolved via WEBPACK_APP_DIR (the bind-mounted /app
 * in sidecars); node_modules via NODE_PATH.
 */
const path = require('path')
const fs = require('fs')
const { merge } = require('webpack-merge')

const appDir = process.env.WEBPACK_APP_DIR
if (!appDir) {
	throw new Error('WEBPACK_APP_DIR environment variable is required')
}

// Find the app's webpack base config (mail: webpack.common.js in
// overlay; calendar: webpack.config.js from upstream). Add a name
// only when a real app needs it.
const candidates = ['webpack.common.js', 'webpack.config.js', 'webpack.js']
const commonPath = candidates.map(f => path.join(appDir, f)).find(p => fs.existsSync(p))
if (!commonPath) {
	throw new Error(`No webpack base config found in ${appDir} (tried: ${candidates.join(', ')})`)
}
let common = require(commonPath)

const appNodeModules = path.join(appDir, 'node_modules')

// Support async configs: apps with ESM-only deps (e.g.
// @ckeditor/ckeditor5-dev-utils v44+) must export an async function;
// await it transparently.
//
// The `env = {}` default matters: webpack-cli calls the config as
// (env, argv), and apps that destructure env (e.g. cookbook's
// `function(env) { ... env.dev_server ... }`) crash on undefined
// without it. We forward no --env flags; such apps hit their defaults.
module.exports = async (env = {}) => {
	if (typeof common === 'function') common = await common(env)
	return merge(common, {
		// Resolve loaders from the app's node_modules; else webpack
		// looks relative to this config (webpack/, no node_modules).
		// Only resolveLoader — do NOT set resolve.modules: it bypasses
		// npm's nested node_modules resolution, causing version
		// conflicts (e.g. @floating-ui/dom hoisting).
		resolveLoader: {
			modules: [appNodeModules, 'node_modules'],
		},
		// Context = app dir so relative paths in the app's config
		// resolve from there, not from webpack/.
		context: appDir,

		// Source maps for local dev (one-shot `make build`) and watch; HMR
		// overrides to 'eval' (vue-loader#1795; hmr_enabler's LaxifyCSP allows
		// unsafe-eval). CI distributable builds drop them (build.sh sets
		// WEBPACK_DIST for MODE=snapshot/production): sourcemap emit is among the
		// slowest sealing steps and bloats the shipped tarball, and dev1/customer
		// artifacts don't need them. Local dev (WEBPACK_DIST unset) keeps them.
		devtool: process.env.WEBPACK_DIST ? false : 'cheap-source-map',

		// Read only under --watch / webpack-dev-server; baking them
		// unconditional costs nothing in one-shot or production builds.
		//
		// poll: 1000 — macOS fs.watch() watches the symlink inode, not
		// target content, so native events miss edits through .build/'s
		// overlay symlinks. Polling is the portable choice.
		// followSymlinks: walk the dep graph through those symlinks.
		// aggregateTimeout: 600 — batch save-bursts into one rebuild.
		// ignored: skips huge dep dirs and stops quilt pop-then-push
		// cycles from triggering recompiles.
		watchOptions: {
			poll: 1000,
			followSymlinks: true,
			aggregateTimeout: 600,
			ignored: [
				'**/node_modules',
				'**/vendor',
				'**/vendor-bin',
				'**/.git',
				'**/.pc',
				'**/js',
			],
		},

		// Hash-based snapshot validation: webpack 5's default timestamp
		// snapshots don't propagate through symlinks (webpack/webpack#15100);
		// hashing reads actual content. Watch-mode-only, also baked.
		snapshot: {
			module: { hash: true },
			resolve: { hash: true },
		},
	})
}
