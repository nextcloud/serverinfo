/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Tiny deterministic hash → 32-bit int. Input strings of any length map
 * to a stable integer suitable for seeding cosmetic randomness.
 */
const fnv1a = (input: string): number => {
	let hash = 0x811c9dc5
	for (let i = 0; i < input.length; i++) {
		hash ^= input.charCodeAt(i)
		hash = (hash + ((hash << 1) + (hash << 4) + (hash << 7) + (hash << 8) + (hash << 24))) >>> 0
	}
	return hash
}

const mulberry32 = (seed: number) => {
	let s = seed >>> 0
	return (): number => {
		s = (s + 0x6D2B79F5) >>> 0
		let t = s
		t = Math.imul(t ^ (t >>> 15), t | 1)
		t ^= t + Math.imul(t ^ (t >>> 7), t | 61)
		return ((t ^ (t >>> 14)) >>> 0) / 4294967296
	}
}

export interface FingerprintShape {
	cx: number
	cy: number
	r: number
	hue: number
	opacity: number
}

export interface Fingerprint {
	seed: number
	shapes: FingerprintShape[]
	hue: number
}

/**
 * Generate a deterministic abstract "fingerprint" for a server, derived
 * from its hostname. Same hostname always renders the same constellation
 * of soft circles in a consistent color family — every server gets a
 * unique visual signature.
 *
 * @param hostname - The server identifier
 * @param size - Viewport width/height (square)
 * @param count - Number of shapes
 */
export function makeFingerprint(hostname: string, size = 100, count = 7): Fingerprint {
	const seed = fnv1a(hostname || 'unknown')
	const rand = mulberry32(seed)
	const baseHue = Math.floor(rand() * 360)
	const shapes: FingerprintShape[] = []
	for (let i = 0; i < count; i++) {
		shapes.push({
			cx: rand() * size,
			cy: rand() * size,
			r: 8 + rand() * (size / 3),
			hue: (baseHue + (rand() * 90 - 45)) % 360,
			opacity: 0.18 + rand() * 0.32,
		})
	}
	return { seed, shapes, hue: baseHue }
}
