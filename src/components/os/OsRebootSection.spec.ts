/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import OsRebootSection from './OsRebootSection.vue'
import { concern, NOW, payload, status } from '../../testing/osPayload.ts'

describe('OsRebootSection', () => {
	it('explains a pending kernel reboot and how long it has waited', () => {
		const wrapper = mount(OsRebootSection, {
			props: {
				status: status({ rebootReasons: [{ kind: 'kernel', detail: '6.8.0-47-generic', packages: [], since: NOW - 9 * 86400 }] }),
				php: payload().php,
				commands: payload().commands,
				rebootConcern: concern('reboot', 'warning', { days: 9, kinds: ['kernel'], livePatched: false }, 'Reboot required', 'A newer kernel is installed. Pending for 9 days.'),
				restartConcern: concern('restart', 'ok'),
			},
		})
		expect(wrapper.find('strong').text()).toBe('Reboot required')
		expect(wrapper.text()).toContain('A newer kernel is installed. Pending for 9 days.')
		expect(wrapper.find('.os-reasons code').text()).toBe('6.8.0-47-generic')
		expect(wrapper.text()).toContain('sudo systemctl reboot')
	})

	it('tells which PHP service to restart for replaced libraries', () => {
		const wrapper = mount(OsRebootSection, {
			props: {
				status: status(),
				php: { ...payload().php!, outdatedLibraries: ['libssl.so.3', 'libcrypto.so.3'] },
				commands: payload().commands,
				rebootConcern: concern('reboot', 'ok'),
				restartConcern: concern('restart', 'warning', { php: true, services: 0 }),
			},
		})
		expect(wrapper.text()).toContain('PHP is running outdated libraries')
		expect(wrapper.text()).toContain('libssl.so.3, libcrypto.so.3')
		expect(wrapper.text()).toContain('sudo systemctl restart php8.3-fpm')
	})

	it('points to the root helper when other services are not known', async () => {
		const wrapper = mount(OsRebootSection, {
			props: { status: status({ restartServices: null }), php: payload().php, commands: payload().commands, rebootConcern: concern('reboot', 'ok'), restartConcern: concern('restart', 'ok') },
		})
		const setup = wrapper.findAll('button').find((button) => button.text() === 'Set up the root helper')
		await setup!.trigger('click')
		expect(wrapper.emitted('helper')).toHaveLength(1)
	})

	it('lists services from the helper, the Nextcloud stack first', () => {
		const wrapper = mount(OsRebootSection, {
			props: {
				status: status({ restartServices: [{ unit: 'cron.service', since: null, stack: false }, { unit: 'php8.3-fpm.service', since: null, stack: true }] }),
				php: payload().php,
				commands: { ...payload().commands, services: { 'cron.service': 'sudo systemctl restart cron', 'php8.3-fpm.service': 'sudo systemctl restart php8.3-fpm' } },
				rebootConcern: concern('reboot', 'ok'),
				restartConcern: concern('restart', 'info', { php: false, services: 2 }),
			},
		})
		expect(wrapper.findAll('.os-services .os-command__code').map((code) => code.text()))
			.toEqual(['sudo systemctl restart php8.3-fpm', 'sudo systemctl restart cron'])
	})

	it('shows no commands when the init system is unknown', () => {
		const wrapper = mount(OsRebootSection, {
			props: {
				status: status({
					rebootReasons: [{ kind: 'distribution', detail: null, packages: [], since: null }],
					restartServices: [{ unit: 'cron.service', since: null, stack: false }],
				}),
				php: { ...payload().php!, outdatedLibraries: ['libssl.so.3'] },
				commands: { reboot: null, php: null, services: { 'cron.service': null } },
				rebootConcern: concern('reboot', 'info', { kinds: ['distribution'] }),
				restartConcern: concern('restart', 'info', { php: true, services: 1 }),
			},
		})
		expect(wrapper.findAll('.os-command__code')).toHaveLength(0)
		expect(wrapper.text()).toContain('Reboot the machine to apply the updates.')
		expect(wrapper.find('.os-services code').text()).toBe('cron.service')
	})

	it('has no reboot or service checks inside an application container', () => {
		const wrapper = mount(OsRebootSection, {
			props: {
				status: status({ environment: { kind: 'application-container', container: 'docker', virtualization: null }, rebootReasons: null }),
				php: payload().php,
				commands: payload().commands,
				rebootConcern: null,
				restartConcern: null,
			},
		})
		expect(wrapper.findAll('.os-checklist__item')).toHaveLength(1)
	})
})
