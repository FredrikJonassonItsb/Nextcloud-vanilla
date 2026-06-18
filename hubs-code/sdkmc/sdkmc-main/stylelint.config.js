// SPDX-FileCopyrightText: Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
const stylelintConfig = require('@nextcloud/stylelint-config')

module.exports = {
	...stylelintConfig,
	rules: {
		...stylelintConfig.rules,
		'selector-pseudo-class-no-unknown': [true, {
			ignorePseudoClasses: ['global', 'deep'],
		}],
	},
}
