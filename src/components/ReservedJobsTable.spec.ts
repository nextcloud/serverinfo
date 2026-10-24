/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import ReservedJobsTable from './ReservedJobsTable.vue'
import { jobQueue } from '../testing/jobQueue.ts'

function mountTable() {
	const data = jobQueue()
	return mount(ReservedJobsTable, { props: { jobs: data.queue.reservedJobs, now: data.now, stuckAfter: data.stuckAfter } })
}

describe('ReservedJobsTable', () => {
	it('offers the actions only on a stuck job', () => {
		const rows = mountTable().findAll('tbody tr')

		expect(rows[0].text()).toContain('Files ScanFiles')
		expect(rows[0].text()).toContain('stuck')
		expect(rows[0].findAll('button').map((button) => button.text())).toEqual(['Release reservation', 'Run at next cron'])
		// Reserved a minute ago: it may be running
		expect(rows[1].text()).not.toContain('stuck')
		expect(rows[1].findAll('button')).toHaveLength(0)
		expect(rows[1].text()).toContain('Never')
	})

	it('names the job in each button for assistive technology', () => {
		const labels = mountTable().findAll('tbody tr')[0].findAll('button').map((button) => button.attributes('aria-label'))

		expect(labels).toEqual(['Release the reservation of Files ScanFiles', 'Run Files ScanFiles at the next cron run'])
	})

	it('asks for the action with the job it belongs to', async () => {
		const wrapper = mountTable()
		const buttons = wrapper.findAll('tbody tr')[0].findAll('button')

		await buttons[0].trigger('click')
		await buttons[1].trigger('click')

		const job = { id: '7000000000000000001', className: 'OCA\\Files\\BackgroundJob\\ScanFiles' }
		expect(wrapper.emitted('action')).toEqual([[{ action: 'release', ...job }], [{ action: 'run', ...job }]])
	})
})
