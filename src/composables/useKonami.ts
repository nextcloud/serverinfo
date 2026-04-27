/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { onBeforeUnmount, onMounted, ref } from 'vue'

const KONAMI = [
	'ArrowUp', 'ArrowUp',
	'ArrowDown', 'ArrowDown',
	'ArrowLeft', 'ArrowRight',
	'ArrowLeft', 'ArrowRight',
	'b', 'a',
]

const STORAGE_KEY = 'serverinfo:dadmode'

const readStored = (): boolean => {
	if (typeof window === 'undefined') return false
	try {
		return window.localStorage.getItem(STORAGE_KEY) === '1'
	} catch {
		return false
	}
}

const dadMode = ref<boolean>(readStored())

/**
 * Listen for the konami code globally; toggling "dad mode" when entered.
 * Persisted across reloads — enter again to disable.
 */
export function useKonami() {
	let progress = 0

	const handler = (e: KeyboardEvent): void => {
		const expected = KONAMI[progress]
		const got = e.key
		// case-insensitive for letters
		const match = expected.length === 1
			? got.toLowerCase() === expected.toLowerCase()
			: got === expected
		if (match) {
			progress += 1
			if (progress === KONAMI.length) {
				progress = 0
				const next = !dadMode.value
				dadMode.value = next
				try {
					window.localStorage.setItem(STORAGE_KEY, next ? '1' : '0')
				} catch {
					// ignore
				}
			}
		} else {
			progress = got === KONAMI[0] ? 1 : 0
		}
	}

	onMounted(() => {
		window.addEventListener('keydown', handler)
	})

	onBeforeUnmount(() => {
		window.removeEventListener('keydown', handler)
	})

	return { dadMode }
}
