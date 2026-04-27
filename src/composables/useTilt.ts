/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { onBeforeUnmount, ref, type Ref } from 'vue'

const MAX_TILT_DEG = 2.4

const reduceMotion = (): boolean =>
	typeof window !== 'undefined'
		&& typeof window.matchMedia === 'function'
		&& window.matchMedia('(prefers-reduced-motion: reduce)').matches

/**
 * Subtle 3D parallax tilt. Bind `bind` to an element to opt it in.
 *
 * Returns reactive style strings the consumer should spread onto the
 * tilted element. The tilt resets on mouse leave and is disabled when
 * the user prefers reduced motion.
 */
export function useTilt(): {
	bind: {
		onMousemove: (e: MouseEvent) => void
		onMouseleave: () => void
	}
	style: Ref<Record<string, string>>
} {
	const style = ref<Record<string, string>>({})

	const onMousemove = (e: MouseEvent): void => {
		if (reduceMotion()) return
		const target = e.currentTarget as HTMLElement | null
		if (!target) return
		const rect = target.getBoundingClientRect()
		const px = (e.clientX - rect.left) / rect.width
		const py = (e.clientY - rect.top) / rect.height
		const rx = (0.5 - py) * MAX_TILT_DEG * 2
		const ry = (px - 0.5) * MAX_TILT_DEG * 2
		const gx = Math.round(px * 100)
		const gy = Math.round(py * 100)
		style.value = {
			transform: `perspective(900px) rotateX(${rx.toFixed(2)}deg) rotateY(${ry.toFixed(2)}deg)`,
			'--tilt-glow-x': `${gx}%`,
			'--tilt-glow-y': `${gy}%`,
			'--tilt-active': '1',
		}
	}

	const onMouseleave = (): void => {
		style.value = {
			transform: 'perspective(900px) rotateX(0) rotateY(0)',
			'--tilt-active': '0',
		}
	}

	onBeforeUnmount(() => {
		style.value = {}
	})

	return { bind: { onMousemove, onMouseleave }, style }
}
