/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import OsUpdatesSection from './OsUpdatesSection.vue'
import { concern, NOW, status, update } from '../../testing/osPayload.ts'

const rows = (wrapper: ReturnType<typeof mount>) => wrapper.findAll('tbody tr').map((row) => row.find('.os-updates__package').text())

describe('OsUpdatesSection', () => {
	it('starts with the security updates and can show all of them', async () => {
		const wrapper = mount(OsUpdatesSection, { props: { status: status(), concern: concern('updates', 'warning'), now: NOW } })

		expect(rows(wrapper)).toEqual(['openssl'])
		expect(wrapper.text()).toContain('for 5 days')

		const all = wrapper.findAll('.filter-pills__pill').find((pill) => pill.text().startsWith('All'))
		await all!.trigger('click')
		expect(rows(wrapper)).toEqual(['openssl', 'tzdata'])
	})

	it('offers the security and the full upgrade command', () => {
		const wrapper = mount(OsUpdatesSection, { props: { status: status(), concern: null, now: NOW } })
		const commands = wrapper.findAll('.os-command__code').map((code) => code.text())
		expect(commands).toEqual(['sudo unattended-upgrade -v', 'sudo apt update && sudo apt full-upgrade'])
	})

	it('does not call a system without security updates fully patched', () => {
		const wrapper = mount(OsUpdatesSection, { props: { status: status({ updates: [update({ name: 'libc6', security: false })] }), concern: concern('updates', 'ok'), now: NOW } })
		expect(wrapper.text()).toContain('Regular updates carry fixes too')

		const withSecurity = mount(OsUpdatesSection, { props: { status: status(), concern: concern('updates', 'warning'), now: NOW } })
		expect(withSecurity.text()).not.toContain('Regular updates carry fixes too')
	})

	it('says when there is nothing to do', () => {
		const wrapper = mount(OsUpdatesSection, { props: { status: status({ updates: [] }), concern: concern('updates', 'ok'), now: NOW } })
		expect(wrapper.text()).toContain('Everything is up to date')
		expect(wrapper.find('table').exists()).toBe(false)
	})

	it('does not pretend to know when the package manager could not be asked', () => {
		const wrapper = mount(OsUpdatesSection, { props: { status: status({ updates: null }), concern: concern('updates', 'unknown'), now: NOW } })
		expect(wrapper.text()).toContain('could not be determined')
		expect(wrapper.text()).not.toContain('up to date')
	})

	it('offers the root helper when updates cannot be asked for', async () => {
		const wrapper = mount(OsUpdatesSection, { props: { status: status({ updates: null, packageManager: 'dnf' }), concern: concern('updates', 'unknown'), now: NOW } })

		await wrapper.findAll('button').find((button) => button.text() === 'Set up the root helper')!.trigger('click')
		expect(wrapper.emitted('helper')).toHaveLength(1)
	})

	it('has no security filter where updates are not classified', () => {
		const wrapper = mount(OsUpdatesSection, {
			props: {
				status: status({ securityClassified: false, packageManager: 'pacman', updates: [update({ security: false })] }),
				concern: concern('updates', 'info'),
				now: NOW,
			},
		})
		expect(wrapper.findAll('.filter-pills__pill').map((pill) => pill.text())).toEqual(['Nextcloud stack 1', 'All 1'])
		expect(rows(wrapper)).toEqual(['openssl'])
	})

	it('shows what a SUSE patch fixes instead of a version', () => {
		const patch = update({
			name: 'openSUSE-Leap-16.0-1157',
			installed: null,
			candidate: '1',
			kind: 'patch',
			summary: 'Security update for glibc',
			advisories: ['openSUSE-Leap-16.0-1157', 'CVE-2026-5435'],
			severity: 'Moderate',
		})
		const wrapper = mount(OsUpdatesSection, { props: { status: status({ packageManager: 'zypper', updates: [patch] }), concern: null, now: NOW } })

		expect(wrapper.find('.os-updates__summary').text()).toBe('Security update for glibc')
		expect(wrapper.find('.os-updates__version').exists()).toBe(false)
		expect(wrapper.find('.os-updates__advisories').text()).toBe('CVE-2026-5435')
	})

	it('shows only the first rows until asked for more', async () => {
		const many = Array.from({ length: 20 }, (_, i) => update({ name: `lib${String(i).padStart(2, '0')}` }))
		const wrapper = mount(OsUpdatesSection, { props: { status: status({ updates: many }), concern: null, now: NOW } })
		expect(rows(wrapper)).toHaveLength(12)

		const more = wrapper.findAll('button').find((button) => button.text().startsWith('Show all'))
		await more!.trigger('click')
		expect(rows(wrapper)).toHaveLength(20)
	})
})
