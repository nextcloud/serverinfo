/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { t } from '@nextcloud/l10n'
import { computed, ref, watch, type Ref } from 'vue'
import type { ServerInfoState, SystemInfo } from '../types.ts'

export interface Achievement {
	id: string
	emoji: string
	title: string
	description: string
}

const STORAGE_KEY = 'serverinfo:achievements'

const readEarned = (): Set<string> => {
	if (typeof window === 'undefined') return new Set()
	try {
		const raw = window.localStorage.getItem(STORAGE_KEY)
		if (!raw) return new Set()
		const parsed = JSON.parse(raw)
		return Array.isArray(parsed) ? new Set(parsed.filter((v): v is string => typeof v === 'string')) : new Set()
	} catch {
		return new Set()
	}
}

const writeEarned = (set: Set<string>): void => {
	if (typeof window === 'undefined') return
	try {
		window.localStorage.setItem(STORAGE_KEY, JSON.stringify(Array.from(set)))
	} catch {
		// best-effort
	}
}

interface Definition {
	id: string
	emoji: string
	title: () => string
	description: () => string
	test: (ctx: AchievementContext) => boolean
}

interface AchievementContext {
	state: ServerInfoState
	system: SystemInfo
	uptimeSeconds: number
}

const definitions: Definition[] = [
	{
		id: 'uptime-week',
		emoji: '👍',
		title: () => t('serverinfo', 'A week of stability'),
		description: () => t('serverinfo', 'Server has been up for 7 consecutive days.'),
		test: (c) => c.uptimeSeconds >= 7 * 86400,
	},
	{
		id: 'uptime-month',
		emoji: '✨',
		title: () => t('serverinfo', 'One month strong'),
		description: () => t('serverinfo', 'Uptime crossed 30 days.'),
		test: (c) => c.uptimeSeconds >= 30 * 86400,
	},
	{
		id: 'uptime-100',
		emoji: '🎉',
		title: () => t('serverinfo', '100 days unbroken'),
		description: () => t('serverinfo', 'Triple-digit uptime, no reboots.'),
		test: (c) => c.uptimeSeconds >= 100 * 86400,
	},
	{
		id: 'uptime-year',
		emoji: '🏆',
		title: () => t('serverinfo', 'Legendary uptime'),
		description: () => t('serverinfo', 'A full year without a reboot.'),
		test: (c) => c.uptimeSeconds >= 365 * 86400,
	},
	{
		id: 'files-1k',
		emoji: '📁',
		title: () => t('serverinfo', '1,000 files served'),
		description: () => t('serverinfo', 'The file cache crossed 1k entries.'),
		test: (c) => c.state.storage.num_files >= 1000,
	},
	{
		id: 'files-100k',
		emoji: '🗂️',
		title: () => t('serverinfo', '100,000 files'),
		description: () => t('serverinfo', 'Six-figure file count reached.'),
		test: (c) => c.state.storage.num_files >= 100000,
	},
	{
		id: 'files-1m',
		emoji: '📦',
		title: () => t('serverinfo', 'One million files'),
		description: () => t('serverinfo', 'Crossed a million files in storage.'),
		test: (c) => c.state.storage.num_files >= 1_000_000,
	},
	{
		id: 'survived-mem',
		emoji: '😅',
		title: () => t('serverinfo', 'Lived to tell the tale'),
		description: () => t('serverinfo', 'Memory hit 90 % and the server kept going.'),
		test: (c) => {
			if (c.system.mem_total <= 0) return false
			const pct = ((c.system.mem_total - c.system.mem_free) / c.system.mem_total) * 100
			return pct >= 90
		},
	},
	{
		id: 'cron-clean',
		emoji: '⏱️',
		title: () => t('serverinfo', 'Cron whisperer'),
		description: () => t('serverinfo', 'Background jobs are running on the system cron — admin best practice.'),
		test: (c) => c.state.cron.mode === 'cron' && c.state.cron.status === 'ok',
	},
	{
		id: 'all-updated',
		emoji: '🚀',
		title: () => t('serverinfo', 'Squeaky clean'),
		description: () => t('serverinfo', 'All apps are on their latest version.'),
		test: (c) => c.state.apps.numInstalled > 0 && c.state.apps.numUpdatesAvailable === 0,
	},
	{
		id: 'users-100',
		emoji: '👥',
		title: () => t('serverinfo', 'A hundred souls'),
		description: () => t('serverinfo', 'You\'re hosting 100+ registered users.'),
		test: (c) => c.state.storage.num_users >= 100,
	},
]

interface ToastEvent extends Achievement {
	receivedAt: number
}

/**
 * Singleton state — achievements are global to the dashboard so we
 * don't fire the same toast twice if a user's reactive context
 * re-mounts.
 */
const earned = ref<Set<string>>(readEarned())
const toastQueue = ref<ToastEvent[]>([])

watch(earned, (next) => writeEarned(next), { deep: true })

/**
 * Achievement engine. Pass in reactive state and the live system snapshot
 * plus uptime; this composable evaluates achievements on every change and
 * pushes new ones to the toast queue.
 */
export function useAchievements(
	state: Ref<ServerInfoState>,
	system: Ref<SystemInfo>,
	uptimeSeconds: Ref<number>,
) {
	const evaluate = (): void => {
		const ctx: AchievementContext = {
			state: state.value,
			system: system.value,
			uptimeSeconds: uptimeSeconds.value,
		}
		for (const def of definitions) {
			if (earned.value.has(def.id)) continue
			if (!def.test(ctx)) continue
			const next = new Set(earned.value)
			next.add(def.id)
			earned.value = next
			toastQueue.value = [
				...toastQueue.value,
				{
					id: def.id,
					emoji: def.emoji,
					title: def.title(),
					description: def.description(),
					receivedAt: Date.now(),
				},
			]
		}
	}

	watch([state, system, uptimeSeconds], evaluate, { immediate: true, deep: true })

	const dismissToast = (id: string): void => {
		toastQueue.value = toastQueue.value.filter((t) => t.id !== id)
	}

	const totalEarned = computed(() => earned.value.size)
	const totalAvailable = definitions.length

	const catalog = computed(() => definitions.map((def) => ({
		id: def.id,
		emoji: def.emoji,
		title: def.title(),
		description: def.description(),
		earned: earned.value.has(def.id),
	})))

	return { toastQueue, dismissToast, totalEarned, totalAvailable, catalog }
}
