/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { JobQueue, PeriodicData } from '../types.ts'

export const NOW = 1_800_000_000

/** A /jobs payload with one stuck and one running job, and two failing classes */
export function jobQueue(): JobQueue {
	return {
		now: NOW,
		stuckAfter: 43200,
		queue: {
			total: 120,
			reserved: 2,
			stuck: 1,
			oldestLastRun: NOW - 86400 * 3,
			topClasses: [{ class: 'OCA\\Files\\BackgroundJob\\ScanFiles', count: 80 }],
			reservedJobs: [
				{ id: '7000000000000000001', class: 'OCA\\Files\\BackgroundJob\\ScanFiles', reservedAt: NOW - 50000, lastRun: NOW - 90000 },
				{ id: '7000000000000000002', class: 'OCA\\Talk\\BackgroundJob\\RemoveEmptyRooms', reservedAt: NOW - 60, lastRun: 0 },
			],
		},
		failures: [
			{ className: 'OCA\\Mail\\BackgroundJob\\SyncJob', failures: 12, lastFailedAt: NOW - 300, jobId: '7000000000000000003' },
			{ className: 'OCA\\Files\\BackgroundJob\\PreviewJob', failures: 1, lastFailedAt: NOW - 9000, jobId: null },
		],
	}
}

export function periodicData(): PeriodicData {
	return {
		cron: { mode: 'cron', lastRun: NOW - 120, now: NOW },
		backgroundJobs: { recent: [], slowest: [], retentionDays: 60 },
	}
}
