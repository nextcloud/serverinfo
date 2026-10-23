/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import OsSettingsDialog from './OsSettingsDialog.vue'
import { payload } from '../../testing/osPayload.ts'

vi.mock('@nextcloud/axios', () => ({
	default: { put: vi.fn(), isAxiosError: (e: { isAxiosError?: boolean }) => e?.isAxiosError === true },
}))

const DialogStub = { template: '<div><slot /><slot name="actions" /></div>' }

function mountDialog(overrides: Partial<InstanceType<typeof OsSettingsDialog>['$props']> = {}) {
	const data = payload()
	return mount(OsSettingsDialog, {
		props: { thresholds: data.thresholds, notifications: true, defaults: data.settingsDefaults, limits: data.thresholdLimits, ...overrides },
		global: { stubs: { NcDialog: DialogStub } },
	})
}

function field(wrapper: ReturnType<typeof mountDialog>, label: string, group: string) {
	return wrapper.findAll('fieldset')
		.find((set) => set.find('legend').text() === group)!
		.findAll('input')
		.at(label === 'warning' ? 0 : 1)!
}

const saveButton = (wrapper: ReturnType<typeof mountDialog>) => wrapper.findAll('button').find((button) => button.text() === 'Save')!

describe('OsSettingsDialog', () => {
	beforeEach(() => {
		vi.mocked(axios.put).mockReset()
	})

	it('refuses an error threshold before the warning threshold', async () => {
		const wrapper = mountDialog()
		await field(wrapper, 'error', 'Reboot pending').setValue('2')
		await saveButton(wrapper).trigger('click')

		expect(axios.put).not.toHaveBeenCalled()
		expect(wrapper.text()).toContain('The error threshold cannot come before the warning threshold.')
	})

	it('refuses something that is not a number of days', async () => {
		const wrapper = mountDialog()
		await field(wrapper, 'warning', 'Security updates pending').setValue('-1')
		await saveButton(wrapper).trigger('click')

		expect(axios.put).not.toHaveBeenCalled()
		expect(wrapper.text()).toContain('Enter a whole number of days between 0 and 3650.')
	})

	it('saves numbers and hands the new state back', async () => {
		vi.mocked(axios.put).mockResolvedValue({ data: payload() })
		const wrapper = mountDialog()
		await field(wrapper, 'warning', 'Security updates pending').setValue('1')
		await saveButton(wrapper).trigger('click')
		await flushPromises()

		const [, body] = vi.mocked(axios.put).mock.calls[0] as [string, { thresholds: Record<string, number>, notifications: boolean }]
		expect(body.thresholds.securityWarning).toBe(1)
		expect(body.thresholds.rebootError).toBe(30)
		expect(body.notifications).toBe(true)
		expect(wrapper.emitted('saved')).toHaveLength(1)
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('takes the limits from the server', async () => {
		const wrapper = mountDialog({ limits: { ...payload().thresholdLimits, maxDays: 100 } })
		await field(wrapper, 'warning', 'Security updates pending').setValue('101')
		await saveButton(wrapper).trigger('click')

		expect(axios.put).not.toHaveBeenCalled()
		expect(wrapper.text()).toContain('Enter a whole number of days between 0 and 100.')
	})

	it('restores the defaults from the server, notifications included', async () => {
		vi.mocked(axios.put).mockResolvedValue({ data: payload() })
		const wrapper = mountDialog({
			thresholds: { ...payload().thresholds, rebootWarning: 1 },
			notifications: false,
			defaults: { thresholds: { ...payload().thresholds, securityWarning: 5 }, notifications: true },
		})
		await wrapper.findAll('button').find((button) => button.text() === 'Restore defaults')!.trigger('click')
		await saveButton(wrapper).trigger('click')
		await flushPromises()

		const [, body] = vi.mocked(axios.put).mock.calls[0] as [string, { thresholds: Record<string, number>, notifications: boolean }]
		expect(body.thresholds.securityWarning).toBe(5)
		expect(body.thresholds.rebootWarning).toBe(7)
		expect(body.notifications).toBe(true)
	})

	it('marks the field the server rejected', async () => {
		vi.mocked(axios.put).mockRejectedValue({ isAxiosError: true, response: { status: 400, data: { field: 'listsError' } } })
		const wrapper = mountDialog()
		await saveButton(wrapper).trigger('click')
		await flushPromises()

		expect(wrapper.emitted('close')).toBeUndefined()
		expect(wrapper.text()).toContain('The error threshold cannot come before the warning threshold.')
	})
})
