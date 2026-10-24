/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineComponent, h } from 'vue'

/**
 * Stands in for vue-chartjs' Line, which needs a canvas the test DOM lacks.
 * It renders the accessible name and the plotted datasets as attributes.
 */
export const LineStub = defineComponent({
	props: ['data', 'options', 'ariaLabel'],
	setup: (props) => () => h('canvas', {
		role: 'img',
		'aria-label': props.ariaLabel,
		'data-labels': JSON.stringify(props.data.datasets.map((dataset: { label?: string }) => dataset.label ?? '')),
		'data-points': JSON.stringify(props.data.datasets.map((dataset: { data: unknown }) => dataset.data)),
	}),
})
