/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { HealthStatus } from '../types.ts'

const UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB']

/**
 * Format a number of bytes into a human-readable string.
 *
 * @param bytes - The size in bytes
 * @param fractionDigits - Number of decimal places
 */
export function formatBytes(bytes: number, fractionDigits = 1): string {
	if (!Number.isFinite(bytes) || bytes < 0) {
		return '–'
	}
	let value = bytes
	let unit = 0
	while (value >= 1024 && unit < UNITS.length - 1) {
		value /= 1024
		unit++
	}
	return `${value.toFixed(fractionDigits)} ${UNITS[unit]}`
}

/**
 * Format a size given in megabytes.
 *
 * @param mb - The size in MB
 * @param fractionDigits - Number of decimal places
 */
export function formatMegabytes(mb: number, fractionDigits = 1): string {
	return formatBytes(mb * 1024 * 1024, fractionDigits)
}

/**
 * Format a percentage from 0-100 (or larger).
 *
 * @param percent - The percentage value
 * @param fractionDigits - Number of decimal places
 */
export function formatPercent(percent: number, fractionDigits = 0): string {
	if (!Number.isFinite(percent)) {
		return '–'
	}
	return `${percent.toFixed(fractionDigits)}%`
}

/**
 * Map a usage percentage to a semantic health status.
 *
 * @param percent - 0-100 usage percentage
 */
export function statusForUsage(percent: number): HealthStatus {
	if (!Number.isFinite(percent)) {
		return 'ok'
	}
	if (percent >= 90) {
		return 'critical'
	}
	if (percent >= 70) {
		return 'warning'
	}
	return 'ok'
}
