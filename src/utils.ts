/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { t } from '@nextcloud/l10n'

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
 * Reads a themed colour from the active theme's CSS custom properties, so that
 * canvas drawing (which cannot resolve `var(...)` itself) follows light/dark
 * mode and custom themes.
 *
 * @param name custom property name, including the leading dashes
 * @param fallback colour to use when the property is not set
 */
export function cssColor(name: string, fallback: string): string {
	return getComputedStyle(document.documentElement)
		.getPropertyValue(name)
		.trim() || fallback
}

/**
 * The themed primary accent colour.
 */
export function primaryColor(): string {
	return cssColor('--color-primary-element', '#3681c3')
}

/**
 * Shortens a job class for a table column: for an `OCA\` job the second segment
 * is the app id, so `OCA\Talk\BackgroundJob\RemoveEmptyRooms` becomes
 * "Talk RemoveEmptyRooms".
 *
 * @param className fully qualified class name
 */
export function jobName(className: string): string {
	const parts = className.split('\\')
	const name = parts.pop() ?? className
	return parts[0] === 'OCA' && parts[1] ? `${parts[1]} ${name}` : name
}

/**
 * @param duration run time in milliseconds, null when the job reported none
 */
export function formatDuration(duration: number | null): string {
	if (duration === null) {
		return '–'
	}
	if (duration < 1000) {
		// TRANSLATORS: {duration} is a number of milliseconds, e.g. "412 ms"
		return t('serverinfo', '{duration} ms', { duration })
	}
	// TRANSLATORS: {duration} is a number of seconds with one decimal, e.g. "1.4 s"
	return t('serverinfo', '{duration} s', { duration: (duration / 1000).toFixed(1) })
}

/**
 * @param memoryPeak peak memory in kilobytes, base 10 as the job runner reports it
 */
export function formatKilobytes(memoryPeak: number | null): string {
	return memoryPeak === null ? '–' : formatBytes(memoryPeak * 1000)
}
