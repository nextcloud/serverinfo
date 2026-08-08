/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

export interface ThermalZone {
	zone: string
	type: string
	temp: number
}

/** Payload of /update, polled every two seconds */
export interface LiveData {
	cpu: { load: number[] | false }
	memory: { total: number, free: number, swap_total: number, swap_free: number }
	servertime: string
	uptime: string
	thermalzones: ThermalZone[]
}

export type JobStatus = 'RUNNING' | 'SUCCEEDED' | 'FAILED' | 'CRASHED'

export interface JobRun {
	runId: string
	className: string
	serverId: number
	pid: number
	/** Unix timestamp on the server clock */
	startedAt: number
	status: JobStatus
	/** Milliseconds */
	duration: number | null
	/** Kilobytes, base 10 */
	memoryPeak: number | null
}

/** One job class aggregated over every retained run */
export interface SlowJob {
	className: string
	runs: number
	/** Milliseconds */
	avgDuration: number
	/** Milliseconds */
	maxDuration: number
	/** Kilobytes, base 10 */
	memoryPeak: number
}

/** Payload of /periodic, polled every minute */
export interface PeriodicData {
	cron: {
		mode: string
		lastRun: number
		/** Server clock, so job ages never depend on the browser's */
		now: number
	}
	backgroundJobs: {
		recent: JobRun[]
		failures: JobRun[]
		/** Empty until the collecting job has run once */
		slowest: SlowJob[]
		/** Runs older than this are pruned, bounding what "no failures" means */
		retentionDays: number
	}
}
