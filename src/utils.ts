/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Formats a size in megabytes to a human-readable string.
 *
 * @param mb size in megabytes
 */
export function formatMegabytes(mb: number): string {
	const units = ['MB', 'GB', 'TB', 'PB', 'EB']
	let value = mb
	let i = 0
	while (value >= 1024 && i < units.length - 1) {
		value /= 1024
		i++
	}
	return value.toFixed(2) + ' ' + units[i]
}

/**
 * Adds alpha to a CSS color string (handles #RRGGBB and rgb()).
 *
 * @param color base colour as #RRGGBB or rgb(...)
 * @param alpha opacity between 0 and 1
 */
export function withAlpha(color: string, alpha: number): string {
	if (color.startsWith('#') && color.length === 7) {
		const r = parseInt(color.slice(1, 3), 16)
		const g = parseInt(color.slice(3, 5), 16)
		const b = parseInt(color.slice(5, 7), 16)
		return `rgba(${r}, ${g}, ${b}, ${alpha})`
	}
	if (color.startsWith('rgb(')) {
		return color.replace('rgb(', 'rgba(').replace(')', `, ${alpha})`)
	}
	return color
}

/**
 * Formats a size in bytes to a human-readable string.
 *
 * @param bytes size in bytes
 */
export function formatBytes(bytes: number): string {
	const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB']
	let value = Math.abs(bytes)
	let i = 0
	while (value >= 1024 && i < units.length - 1) {
		value /= 1024
		i++
	}
	return value.toFixed(1) + ' ' + units[i]
}

/**
 * The themed primary accent colour, read from the active theme's CSS custom
 * property so it follows light/dark mode and custom themes.
 */
export function primaryColor(): string {
	return getComputedStyle(document.documentElement)
		.getPropertyValue('--color-primary-element')
		.trim() || '#3681c3'
}
