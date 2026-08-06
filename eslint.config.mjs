/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { recommended } from '@nextcloud/eslint-config'

export default [
	...recommended,
	{
		rules: {
			'no-console': 'error',
			'no-unused-vars': 'warn',

			// 'jsdoc/no-undefined-types': 'error',
			'jsdoc/require-jsdoc': 'off',
			// 'jsdoc/require-param': 'off',

			'vue/multi-word-component-names': 'off',

			// 'sort-imports': ['error', { ignoreDeclarationSort: true }],
			// 'import/order': ['error', { groups: ['builtin', 'external', 'internal'], alphabetize: { order: 'asc', caseInsensitive: true } }],

			// // Relax some rules for now. Can be improved later one (baseline).
			//
			// // JSDocs are welcome but lint:fix should not create empty ones
			// 'jsdoc/require-jsdoc': 'off',
			// 'jsdoc/require-param': 'off',
			// Forbid empty JSDocs
			// TODO: Enable this rule once @nextcloud/eslint-config was updated and pulls the
			//       newest version of eslint-plugin-jsdoc (is a recent feature/rule).
			// 'jsdoc/no-blank-blocks': 'error',
		},
	},
]
