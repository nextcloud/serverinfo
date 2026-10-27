/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { JobQueue } from '../types.ts'

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import BackgroundJobsSection from './BackgroundJobsSection.vue'
import { jobQueue, periodicData } from '../testing/jobQueue.ts'

function mountSection(queue: JobQueue | null, queueError = false) {
	const data = periodicData()
	return mount(BackgroundJobsSection, { props: { cron: data.cron, jobs: data.backgroundJobs, queue, queueError } })
}

function tiles(wrapper: ReturnType<typeof mountSection>) {
	return Object.fromEntries(wrapper.findAll('.stat-tile').map((tile) => [
		tile.find('.stat-tile__label').text(),
		tile.find('.stat-tile__value'),
	]))
}

describe('BackgroundJobsSection', () => {
	it('shows the queue as tiles, a stuck job as a warning', () => {
		const shown = tiles(mountSection(jobQueue()))

		expect(shown.Queued.text()).toBe('120')
		expect(shown['Reserved now'].text()).toBe('2')
		expect(shown.Stuck.text()).toContain('1')
		expect(shown.Stuck.classes()).toContain('stat-tile__value--warning')
		expect(shown['Oldest last run'].exists()).toBe(true)
	})

	it('shows no stuck warning on a healthy queue', () => {
		const queue = jobQueue()
		queue.queue.stuck = 0

		expect(tiles(mountSection(queue)).Stuck.classes()).not.toContain('stat-tile__value--warning')
	})

	it('shows only the cron tiles until the queue is read', () => {
		const wrapper = mountSection(null)

		expect(Object.keys(tiles(wrapper))).toEqual(['Mode', 'Last run'])
		expect(wrapper.text()).not.toContain('Failures by job')
	})

	it('says when the queue could not be read', () => {
		expect(mountSection(null, true).text()).toContain('The background job queue could not be read.')
	})

	it('lists reserved jobs, failures by job and the most queued jobs', () => {
		const text = mountSection(jobQueue()).text()

		expect(text).toContain('Reserved jobs')
		expect(text).toContain('Failures by job (last 60 days)')
		expect(text).toContain('Mail SyncJob')
		expect(text).toContain('Most queued jobs')
	})

	it('says when only part of the reserved jobs is listed', () => {
		const queue = jobQueue()
		queue.queue.reserved = 25

		expect(mountSection(queue).text()).toContain('The 2 jobs reserved longest are listed.')
	})

	it('confirms a quiet history instead of an empty table', () => {
		const queue = jobQueue()
		queue.failures = []
		queue.queue.reservedJobs = []

		const text = mountSection(queue).text()
		expect(text).toContain('No background job failed in the last 60 days.')
		expect(text).not.toContain('Reserved jobs')
	})

	it('passes the actions of both tables on', async () => {
		const wrapper = mountSection(jobQueue())

		await wrapper.findAll('button').find((button) => button.text() === 'Release reservation')!.trigger('click')

		expect(wrapper.emitted('action')?.[0]?.[0]).toMatchObject({ action: 'release', id: '7000000000000000001' })
	})
})
