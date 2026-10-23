/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import OsHelperSection from './OsHelperSection.vue'
import { status } from '../../testing/osPayload.ts'

describe('OsHelperSection', () => {
	it('tells a report from the future apart from an old one', () => {
		const helper = { ...status().helper, state: 'clock-skew' as const }
		const wrapper = mount(OsHelperSection, { props: { helper } })

		expect(wrapper.find('strong').text()).toBe('The report is dated in the future')
		expect(wrapper.text()).toContain('Check that the system clock is synchronized.')
	})
})
