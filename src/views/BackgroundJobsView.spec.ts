/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { JobQueue } from '../types.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import BackgroundJobsView from './BackgroundJobsView.vue'
import { jobQueue, periodicData } from '../testing/jobQueue.ts'

const state = {
	data: ref<JobQueue | null>(null),
	reload: vi.fn(),
	act: vi.fn(),
}

vi.mock('../composables/useJobQueue.ts', () => ({
	useJobQueue: () => ({
		data: state.data,
		loading: ref(false),
		error: ref(false),
		reload: state.reload,
		act: state.act,
	}),
}))

const DialogStub = { template: '<div class="dialog-stub"><slot /><slot name="actions" /></div>' }

function mountView(active: boolean) {
	return mount(BackgroundJobsView, {
		props: { periodicData: periodicData(), active },
		global: { stubs: { NcDialog: DialogStub } },
	})
}

const button = (wrapper: ReturnType<typeof mountView>, text: string, root = '') => wrapper.findAll(`${root} button`).find((candidate) => candidate.text() === text)!

describe('BackgroundJobsView', () => {
	beforeEach(() => {
		state.data.value = jobQueue()
		state.reload.mockReset()
		state.act.mockReset().mockResolvedValue(true)
	})

	it('reads the queue only once the tab is shown', async () => {
		const wrapper = mountView(false)
		expect(state.reload).not.toHaveBeenCalled()

		await wrapper.setProps({ active: true })
		expect(state.reload).toHaveBeenCalledTimes(1)
	})

	it('reads the queue again with each minute poll while shown', async () => {
		const wrapper = mountView(true)
		expect(state.reload).toHaveBeenCalledTimes(1)

		await wrapper.setProps({ periodicData: periodicData() })
		expect(state.reload).toHaveBeenCalledTimes(2)

		await wrapper.setProps({ active: false })
		await wrapper.setProps({ periodicData: periodicData() })
		expect(state.reload).toHaveBeenCalledTimes(2)
	})

	it('waits for the poll data before reading the queue', () => {
		mount(BackgroundJobsView, { props: { periodicData: null, active: true } })

		expect(state.reload).not.toHaveBeenCalled()
	})

	it('acts only after the admin confirmed', async () => {
		const wrapper = mountView(true)

		await button(wrapper, 'Run at next cron').trigger('click')
		expect(wrapper.find('.dialog-stub').text()).toContain('Files ScanFiles')
		expect(state.act).not.toHaveBeenCalled()

		await button(wrapper, 'Run at next cron', '.dialog-stub').trigger('click')
		await flushPromises()

		expect(state.act).toHaveBeenCalledWith({ action: 'run', id: '7000000000000000001', className: 'OCA\\Files\\BackgroundJob\\ScanFiles' })
		expect(wrapper.find('.dialog-stub').exists()).toBe(false)
	})

	it('leaves the job alone when the dialog is cancelled', async () => {
		const wrapper = mountView(true)

		await button(wrapper, 'Release reservation').trigger('click')
		expect(wrapper.find('.dialog-stub').text()).toContain('Cron already ignores reservations that old')
		await button(wrapper, 'Cancel', '.dialog-stub').trigger('click')

		expect(state.act).not.toHaveBeenCalled()
		expect(wrapper.find('.dialog-stub').exists()).toBe(false)
	})
})
