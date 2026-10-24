/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { confirmPassword } from '@nextcloud/password-confirmation'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { jobQueue } from '../testing/jobQueue.ts'
import { showError, showSuccess } from '../toast.ts'
import { useJobQueue } from './useJobQueue.ts'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), isAxiosError: (e: { isAxiosError?: boolean }) => e?.isAxiosError === true },
}))
vi.mock('../toast.ts', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/password-confirmation', () => ({ confirmPassword: vi.fn() }))
vi.mock('@nextcloud/password-confirmation/style.css', () => ({}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url: string, params: Record<string, string> = {}) => url.replace(/{(\w+)}/g, (_, key: string) => params[key] ?? ''),
}))

const target = { action: 'run' as const, id: '7000000000000000003', className: 'OCA\\Mail\\BackgroundJob\\SyncJob' }

function rejection(status: number, reason?: string) {
	return { isAxiosError: true, response: { status, data: reason ? { reason } : {} } }
}

describe('useJobQueue', () => {
	beforeEach(() => {
		vi.mocked(axios.get).mockReset().mockResolvedValue({ data: jobQueue() })
		vi.mocked(axios.post).mockReset().mockResolvedValue({ data: { id: target.id } })
		vi.mocked(confirmPassword).mockReset().mockResolvedValue()
		vi.mocked(showError).mockReset()
		vi.mocked(showSuccess).mockReset()
	})

	it('reads the queue and remembers a failure', async () => {
		const queue = useJobQueue()

		await queue.reload()
		expect(axios.get).toHaveBeenCalledWith('/apps/serverinfo/jobs')
		expect(queue.data.value?.queue.total).toBe(120)
		expect(queue.error.value).toBe(false)

		vi.mocked(axios.get).mockRejectedValueOnce(new Error('offline'))
		await queue.reload()
		expect(queue.error.value).toBe(true)
		// The last values stay on screen
		expect(queue.data.value?.queue.total).toBe(120)
	})

	it('asks for the password before it acts, then reads the queue again', async () => {
		const queue = useJobQueue()

		expect(await queue.act(target)).toBe(true)

		expect(vi.mocked(confirmPassword).mock.invocationCallOrder[0]).toBeLessThan(vi.mocked(axios.post).mock.invocationCallOrder[0]!)
		expect(axios.post).toHaveBeenCalledWith('/apps/serverinfo/jobs/7000000000000000003/run')
		expect(showSuccess).toHaveBeenCalledWith('Mail SyncJob runs at the next cron run.')
		expect(axios.get).toHaveBeenCalledTimes(1)
	})

	it('releases a reservation through its own endpoint', async () => {
		await useJobQueue().act({ ...target, action: 'release' })

		expect(axios.post).toHaveBeenCalledWith('/apps/serverinfo/jobs/7000000000000000003/release')
		expect(showSuccess).toHaveBeenCalledWith('The reservation of Mail SyncJob was released.')
	})

	it('does nothing when the password prompt is closed', async () => {
		vi.mocked(confirmPassword).mockRejectedValue(new Error('Dialog closed'))

		expect(await useJobQueue().act(target)).toBe(false)

		expect(axios.post).not.toHaveBeenCalled()
		expect(axios.get).not.toHaveBeenCalled()
		expect(showError).not.toHaveBeenCalled()
	})

	it.each([
		[409, 'running', 'Mail SyncJob may be running right now, so it was left alone.'],
		[409, 'changed', 'Cron picked up Mail SyncJob in the meantime, so it was left alone.'],
		[409, 'not-stuck', 'Mail SyncJob is not stuck any more.'],
		[404, 'unknown', 'Mail SyncJob is no longer queued.'],
		[429, undefined, 'Too many changes in a short time. Try again in a few minutes.'],
		[500, undefined, 'Mail SyncJob could not be changed.'],
	])('says why a %i %s was refused and refreshes', async (status, reason, message) => {
		vi.mocked(axios.post).mockRejectedValue(rejection(status, reason))

		expect(await useJobQueue().act(target)).toBe(false)

		expect(showError).toHaveBeenCalledWith(message)
		expect(showSuccess).not.toHaveBeenCalled()
		expect(axios.get).toHaveBeenCalledTimes(1)
	})
})
