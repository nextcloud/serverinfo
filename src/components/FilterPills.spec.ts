/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import FilterPills from './FilterPills.vue'

describe('FilterPills', () => {
	it('is a labelled group of toggle buttons, not a landmark', async () => {
		const wrapper = mount(FilterPills, {
			props: {
				modelValue: 'all',
				label: 'Filter updates',
				options: [{ id: 'all', label: 'All', count: 2 }, { id: 'stack', label: 'Nextcloud stack', count: 1 }],
			},
		})

		expect(wrapper.find('nav').exists()).toBe(false)
		expect(wrapper.attributes('role')).toBe('group')
		expect(wrapper.attributes('aria-label')).toBe('Filter updates')
		expect(wrapper.findAll('button').map((button) => button.attributes('aria-pressed'))).toEqual(['true', 'false'])

		await wrapper.findAll('button')[1].trigger('click')
		expect(wrapper.emitted('update:modelValue')).toEqual([['stack']])
	})
})
