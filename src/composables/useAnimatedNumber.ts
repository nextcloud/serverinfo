/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { onBeforeUnmount, ref, watch, type Ref } from 'vue'

const easeOutCubic = (t: number): number => 1 - Math.pow(1 - t, 3)

/**
 * Smoothly animate a numeric ref toward changes in the source value.
 * Uses requestAnimationFrame and an ease-out curve.
 *
 * Honors prefers-reduced-motion: if the user has reduced motion enabled,
 * the value snaps to the target immediately.
 *
 * @param source - reactive source value
 * @param duration - tween duration in ms
 */
export function useAnimatedNumber(
	source: Ref<number>,
	duration = 700,
): Ref<number> {
	const display = ref(source.value)
	let raf: number | undefined
	let startTime = 0
	let startValue = source.value
	let targetValue = source.value

	const reduceMotion = typeof window !== 'undefined'
		&& typeof window.matchMedia === 'function'
		&& window.matchMedia('(prefers-reduced-motion: reduce)').matches

	const tick = (now: number): void => {
		const elapsed = now - startTime
		const progress = Math.min(1, elapsed / duration)
		const eased = easeOutCubic(progress)
		display.value = startValue + (targetValue - startValue) * eased
		if (progress < 1) {
			raf = requestAnimationFrame(tick)
		} else {
			raf = undefined
		}
	}

	watch(source, (next) => {
		if (!Number.isFinite(next)) {
			display.value = next
			return
		}
		if (reduceMotion) {
			display.value = next
			return
		}
		startValue = display.value
		targetValue = next
		startTime = performance.now()
		if (raf === undefined) {
			raf = requestAnimationFrame(tick)
		}
	})

	onBeforeUnmount(() => {
		if (raf !== undefined) {
			cancelAnimationFrame(raf)
		}
	})

	return display
}
