/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { formatBytes, formatMegabytes, formatPercent, statusForUsage } from './useFormat.ts'

describe('serverinfo - useFormat', () => {
	describe('formatBytes', () => {
		it('returns a placeholder for non-finite or negative input', () => {
			expect(formatBytes(Number.NaN)).toBe('–')
			expect(formatBytes(Number.POSITIVE_INFINITY)).toBe('–')
			expect(formatBytes(-1)).toBe('–')
		})

		it('formats sub-KB values in bytes', () => {
			expect(formatBytes(0)).toBe('0.0 B')
			expect(formatBytes(1023)).toBe('1023.0 B')
		})

		it('walks units up to EB', () => {
			expect(formatBytes(1024)).toBe('1.0 KB')
			expect(formatBytes(1024 * 1024)).toBe('1.0 MB')
			expect(formatBytes(1024 ** 3)).toBe('1.0 GB')
			expect(formatBytes(1024 ** 4)).toBe('1.0 TB')
			expect(formatBytes(1024 ** 5)).toBe('1.0 PB')
			expect(formatBytes(1024 ** 6)).toBe('1.0 EB')
		})

		it('caps at the largest defined unit', () => {
			// 1 ZB worth of bytes still renders in EB rather than throwing
			expect(formatBytes(1024 ** 7)).toBe('1024.0 EB')
		})

		it('respects fraction digits', () => {
			expect(formatBytes(1536, 0)).toBe('2 KB')
			expect(formatBytes(1536, 2)).toBe('1.50 KB')
		})
	})

	describe('formatMegabytes', () => {
		it('treats input as megabytes', () => {
			expect(formatMegabytes(1024)).toBe('1.0 GB')
			expect(formatMegabytes(0)).toBe('0.0 B')
		})
	})

	describe('formatPercent', () => {
		it('renders integer percents by default', () => {
			expect(formatPercent(0)).toBe('0%')
			expect(formatPercent(75.4)).toBe('75%')
			expect(formatPercent(99.9)).toBe('100%')
		})

		it('respects fraction digits', () => {
			expect(formatPercent(75.45, 1)).toBe('75.5%')
		})

		it('returns a placeholder for non-finite input', () => {
			expect(formatPercent(Number.NaN)).toBe('–')
			expect(formatPercent(Number.POSITIVE_INFINITY)).toBe('–')
		})
	})

	describe('statusForUsage', () => {
		it.each([
			[0, 'ok'],
			[50, 'ok'],
			[69.9, 'ok'],
			[70, 'warning'],
			[89.9, 'warning'],
			[90, 'critical'],
			[100, 'critical'],
		])('maps %f%% to %s', (input, expected) => {
			expect(statusForUsage(input)).toBe(expected)
		})

		it('falls back to ok for non-finite input', () => {
			expect(statusForUsage(Number.NaN)).toBe('ok')
		})
	})
})
