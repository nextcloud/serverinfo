/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import FailedJobsTable from './FailedJobsTable.vue'
import { jobQueue } from '../testing/jobQueue.ts'

describe('FailedJobsTable', () => {
	it('shows one row per job with its count', () => {
		const rows = mount(FailedJobsTable, { props: { failures: jobQueue().failures } }).findAll('tbody tr')

		expect(rows).toHaveLength(2)
		expect(rows[0].text()).toContain('Mail SyncJob')
		expect(rows[0].text()).toContain('12')
		expect(rows[1].text()).toContain('Files PreviewJob')
	})

	it('offers to run the job only when the class has a single job', async () => {
		const wrapper = mount(FailedJobsTable, { props: { failures: jobQueue().failures } })
		const rows = wrapper.findAll('tbody tr')

		expect(rows[1].findAll('button')).toHaveLength(0)
		await rows[0].find('button').trigger('click')

		expect(wrapper.emitted('action')).toEqual([[{ action: 'run', id: '7000000000000000003', className: 'OCA\\Mail\\BackgroundJob\\SyncJob' }]])
	})
})
