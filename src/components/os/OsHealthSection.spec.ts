/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { OsPayload } from '../../types.ts'

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import OsHealthSection from './OsHealthSection.vue'
import { concern, payload, status } from '../../testing/osPayload.ts'

function mountSection(initSystem: OsPayload['initSystem'], overrides: Parameters<typeof status>[0] = {}) {
	return mount(OsHealthSection, {
		props: {
			status: status({ failedUnits: null, ...overrides }),
			php: payload().php,
			initSystem,
			failedConcern: concern('failed-units', 'unknown'),
			timeConcern: concern('time-sync', 'warning'),
		},
	})
}

describe('OsHealthSection', () => {
	it('does not blame systemd on a system without it', () => {
		const wrapper = mountSection('openrc')
		expect(wrapper.text()).toContain('Failed services are only checked on systems that run systemd.')
		expect(wrapper.text()).not.toContain('could not ask systemd')
	})

	it('says when systemd did not answer', () => {
		expect(mountSection('systemd').text()).toContain('The web server could not ask systemd about them.')
	})

	it('shows what the server says about failed services and the clock', () => {
		const wrapper = mount(OsHealthSection, {
			props: {
				status: status({ failedUnits: ['php8.3-fpm.service'], timeSynchronized: false }),
				php: payload().php,
				initSystem: 'systemd',
				failedConcern: concern('failed-units', 'error', { units: ['php8.3-fpm.service'] }, '1 system service has failed'),
				timeConcern: concern('time-sync', 'warning', {}, 'The system clock is not synchronized', 'Two-factor codes fail when the clock drifts.'),
			},
		})
		expect(wrapper.findAll('strong').map((strong) => strong.text())).toContain('1 system service has failed')
		expect(wrapper.text()).toContain('The system clock is not synchronized')
		expect(wrapper.text()).toContain('Two-factor codes fail when the clock drifts.')
	})

	it('offers timedatectl only with systemd', () => {
		const commands = (initSystem: OsPayload['initSystem']) => mountSection(initSystem, { timeSynchronized: false })
			.findAll('.os-command__code').map((code) => code.text())
		expect(commands('systemd')).toEqual(['sudo timedatectl set-ntp true'])
		expect(commands('openrc')).toEqual([])
	})
})
