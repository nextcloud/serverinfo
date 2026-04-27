/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { computed, ref, watch } from 'vue'

export type CardId =
	| 'liveLoad'
	| 'recentErrors'
	| 'osUpdates'
	| 'eolWarnings'
	| 'cronAndApps'
	| 'jobQueue'
	| 'cacheStats'
	| 'infraGrid'
	| 'loginActivity'
	| 'usersInsights'
	| 'systemAndThermal'
	| 'disks'
	| 'diskPrediction'
	| 'network'
	| 'usersAndShares'
	| 'federation'
	| 'phpDatabase'
	| 'monitoring'

const STORAGE_KEY = 'serverinfo:layout-v4'
const PIN_KEY = 'serverinfo:pinned-v4'

const DEFAULT_ORDER: CardId[] = [
	'liveLoad',
	'osUpdates',
	'eolWarnings',
	'cronAndApps',
	'jobQueue',
	'cacheStats',
	'infraGrid',
	'loginActivity',
	'usersInsights',
	'systemAndThermal',
	'disks',
	'diskPrediction',
	'network',
	'usersAndShares',
	'federation',
	'phpDatabase',
	'recentErrors',
	'monitoring',
]

const isCardId = (v: unknown): v is CardId =>
	typeof v === 'string' && (DEFAULT_ORDER as string[]).includes(v)

const readOrder = (): CardId[] => {
	if (typeof window === 'undefined') return [...DEFAULT_ORDER]
	try {
		const raw = window.localStorage.getItem(STORAGE_KEY)
		if (!raw) return [...DEFAULT_ORDER]
		const parsed = JSON.parse(raw)
		if (!Array.isArray(parsed)) return [...DEFAULT_ORDER]
		const filtered = parsed.filter(isCardId)
		// re-add any new defaults that didn't exist when the user saved
		for (const id of DEFAULT_ORDER) {
			if (!filtered.includes(id)) filtered.push(id)
		}
		return filtered
	} catch {
		return [...DEFAULT_ORDER]
	}
}

const readPinned = (): Set<CardId> => {
	if (typeof window === 'undefined') return new Set()
	try {
		const raw = window.localStorage.getItem(PIN_KEY)
		if (!raw) return new Set()
		const parsed = JSON.parse(raw)
		if (!Array.isArray(parsed)) return new Set()
		return new Set(parsed.filter(isCardId))
	} catch {
		return new Set()
	}
}

const order = ref<CardId[]>(readOrder())
const pinned = ref<Set<CardId>>(readPinned())

watch(order, (next) => {
	try {
		window.localStorage.setItem(STORAGE_KEY, JSON.stringify(next))
	} catch {
		// ignore
	}
}, { deep: true })

watch(pinned, (next) => {
	try {
		window.localStorage.setItem(PIN_KEY, JSON.stringify(Array.from(next)))
	} catch {
		// ignore
	}
}, { deep: true })

/**
 * The visible order is `pinned` first (in their pinned order), then the
 * remaining cards in the user's saved order. This singleton is shared
 * across the dashboard so all consumers stay in sync.
 */
export function useLayout() {
	const visibleOrder = computed<CardId[]>(() => {
		const head: CardId[] = []
		const tail: CardId[] = []
		for (const id of order.value) {
			if (pinned.value.has(id)) {
				head.push(id)
			} else {
				tail.push(id)
			}
		}
		return [...head, ...tail]
	})

	const togglePin = (id: CardId): void => {
		const next = new Set(pinned.value)
		if (next.has(id)) next.delete(id)
		else next.add(id)
		pinned.value = next
	}

	const isPinned = (id: CardId): boolean => pinned.value.has(id)

	const moveBefore = (source: CardId, target: CardId): void => {
		if (source === target) return
		const cur = [...order.value]
		const sIdx = cur.indexOf(source)
		if (sIdx === -1) return
		cur.splice(sIdx, 1)
		const tIdx = cur.indexOf(target)
		if (tIdx === -1) {
			cur.push(source)
		} else {
			cur.splice(tIdx, 0, source)
		}
		order.value = cur
	}

	const reset = (): void => {
		order.value = [...DEFAULT_ORDER]
		pinned.value = new Set()
	}

	return { visibleOrder, togglePin, isPinned, moveBefore, reset }
}
