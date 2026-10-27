/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { JobQueue } from '../types.ts'

import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import { confirmPassword } from '@nextcloud/password-confirmation'
import { generateUrl } from '@nextcloud/router'
import { ref } from 'vue'
import { jobName } from '../utils.ts'

import '@nextcloud/password-confirmation/style.css'

/** Release reservation, or run at the next cron run */
export type JobAction = 'release' | 'run'

/** What an action is about, as the page shows it */
export interface JobActionTarget {
	action: JobAction
	id: string
	className: string
}

/**
 * The queue health behind the Background jobs tab, and the two actions on a
 * single job. The server scans the whole jobs table for it, so it is read
 * only while the tab is shown.
 */
export function useJobQueue() {
	const data = ref<JobQueue | null>(null)
	const loading = ref(false)
	const error = ref(false)

	async function reload() {
		loading.value = true
		try {
			const response = await axios.get<JobQueue>(generateUrl('/apps/serverinfo/jobs'))
			data.value = response.data
			error.value = false
		} catch {
			error.value = true
		} finally {
			loading.value = false
		}
	}

	/**
	 * Asks for the password, applies the action and reads the queue again,
	 * also after a failure: the job usually changed under the admin's eyes.
	 *
	 * @param target the job and what to do with it
	 * @return whether the server applied the action
	 */
	async function act(target: JobActionTarget): Promise<boolean> {
		try {
			await confirmPassword()
		} catch {
			return false
		}

		const job = jobName(target.className)
		let message: string
		let applied = false
		try {
			await axios.post(generateUrl(`/apps/serverinfo/jobs/{id}/${target.action}`, { id: target.id }))
			message = target.action === 'release'
				// TRANSLATORS: Confirmation after releasing the reservation of a background job. {job} is its name.
				? t('serverinfo', 'The reservation of {job} was released.', { job })
				// TRANSLATORS: Confirmation after scheduling a background job. {job} is its name.
				: t('serverinfo', '{job} runs at the next cron run.', { job })
			applied = true
		} catch (caught) {
			message = failureMessage(caught, job)
		}

		const { showError, showSuccess } = await import('../toast.ts')
		if (applied) {
			showSuccess(message)
		} else {
			showError(message)
		}

		await reload()
		return applied
	}

	return { data, loading, error, reload, act }
}

/**
 * @param caught what axios threw
 * @param job the job's display name
 */
function failureMessage(caught: unknown, job: string): string {
	const status = axios.isAxiosError(caught) ? caught.response?.status : undefined
	const reason = axios.isAxiosError(caught) ? caught.response?.data?.reason : undefined

	if (status === 429) {
		// TRANSLATORS: Shown when an admin changed background jobs too often in a short time
		return t('serverinfo', 'Too many changes in a short time. Try again in a few minutes.')
	}
	switch (reason) {
		case 'unknown':
			// TRANSLATORS: Shown when a background job was removed before the admin's change reached it. {job} is its name.
			return t('serverinfo', '{job} is no longer queued.', { job })
		case 'not-stuck':
			// TRANSLATORS: Shown when a background job's reservation is no longer old enough to release. {job} is its name.
			return t('serverinfo', '{job} is not stuck any more.', { job })
		case 'running':
			// TRANSLATORS: Shown when a background job cannot be scheduled because it holds a recent reservation. {job} is its name.
			return t('serverinfo', '{job} may be running right now, so it was left alone.', { job })
		case 'changed':
			// TRANSLATORS: Shown when cron picked up a background job while the admin was changing it. {job} is its name.
			return t('serverinfo', 'Cron picked up {job} in the meantime, so it was left alone.', { job })
		default:
			// TRANSLATORS: Shown when changing a background job failed for an unexpected reason. {job} is its name.
			return t('serverinfo', '{job} could not be changed.', { job })
	}
}
