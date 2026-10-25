/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import OsReleaseSection from './OsReleaseSection.vue'
import { concern, status } from '../../testing/osPayload.ts'

describe('OsReleaseSection', () => {
	it('says what the server says about release support', () => {
		const wrapper = mount(OsReleaseSection, {
			props: {
				status: status(),
				supportConcern: concern('support', 'warning', { state: 'supported' }, 'Security updates for Ubuntu 24.04.3 LTS end on May 31, 2029'),
				automaticConcern: null,
			},
		})
		expect(wrapper.find('.os-release__support > span:not(.os-level-icon)').text()).toBe('Security updates for Ubuntu 24.04.3 LTS end on May 31, 2029')
	})

	it('admits not knowing without a support concern', () => {
		const wrapper = mount(OsReleaseSection, { props: { status: status(), supportConcern: null, automaticConcern: null } })
		expect(wrapper.find('.os-release__support > span:not(.os-level-icon)').text()).toBe('Unknown')
	})
})
