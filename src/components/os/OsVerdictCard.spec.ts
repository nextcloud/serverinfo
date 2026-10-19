/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import OsVerdictCard from './OsVerdictCard.vue'
import { concern, status } from '../../testing/osPayload.ts'

describe('OsVerdictCard', () => {
	const mountCard = (level: 'ok' | 'warning' | 'error' | 'unknown', refreshing = false) => mount(OsVerdictCard, {
		props: {
			level,
			status: status(),
			concerns: level === 'ok' ? [] : [concern('updates', 'warning', { classified: true, security: 7, other: 0 })],
			refreshing,
		},
	})

	it.each([
		['ok', 'Everything is up to date'],
		['warning', 'Some things should be taken care of'],
		['error', 'The operating system needs attention'],
		['unknown', 'The operating system status is unknown'],
	] as const)('headlines a %s verdict', (level, text) => {
		const wrapper = mountCard(level)
		expect(wrapper.find('h2').text()).toBe(text)
		expect(wrapper.classes()).toContain(`os-verdict--${level}`)
	})

	it('names the system and lists the concerns', () => {
		const wrapper = mountCard('warning')
		expect(wrapper.text()).toContain('Ubuntu 24.04.3 LTS')
		expect(wrapper.text()).toContain('Virtual machine (KVM)')
		expect(wrapper.findAll('.os-verdict__chip').map((chip) => chip.text())).toEqual(['Warning 7 security updates'])
	})

	it('asks for a refresh and shows that one is running', async () => {
		const wrapper = mountCard('ok')
		await wrapper.findAll('button')[0].trigger('click')
		expect(wrapper.emitted('refresh')).toHaveLength(1)

		const busy = mountCard('ok', true)
		expect(busy.findAll('button')[0].attributes('disabled')).toBeDefined()
		expect(busy.text()).toContain('Checking…')
	})
})
