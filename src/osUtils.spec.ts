/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { compareLevels, concernLabel, daysSince, environmentLabel, formatDay, formatDays, problems, shellQuote } from './osUtils.ts'
import { concern, NOW, payload } from './testing/osPayload.ts'

describe('shellQuote', () => {
	it('leaves plain paths alone', () => {
		expect(shellQuote('/var/www/nextcloud/apps/serverinfo/bin/serverinfo-os-report')).toBe('/var/www/nextcloud/apps/serverinfo/bin/serverinfo-os-report')
	})

	it('quotes spaces and shell metacharacters', () => {
		expect(shellQuote('/srv/my cloud/x')).toBe("'/srv/my cloud/x'")
		expect(shellQuote('/srv/$(reboot)')).toBe("'/srv/$(reboot)'")
	})

	it('escapes embedded single quotes', () => {
		expect(shellQuote("/srv/it's")).toBe("'/srv/it'\\''s'")
	})
})

describe('days', () => {
	it('counts whole days on the server clock', () => {
		expect(daysSince(NOW, NOW - 86400 * 2 - 10)).toBe(2)
		expect(daysSince(NOW, NOW + 100)).toBe(0)
		expect(daysSince(NOW, null)).toBeNull()
	})

	it('formats a waiting time', () => {
		expect(formatDays(0)).toBe('since today')
		expect(formatDays(1)).toBe('for 1 day')
		expect(formatDays(5)).toBe('for 5 days')
		expect(formatDays(null)).toBe('')
	})

	it('formats a support date independently of the browser zone', () => {
		expect(formatDay('2029-05-31')).toContain('2029')
		expect(formatDay(null)).toBe('')
		expect(formatDay('not a date')).toBe('not a date')
	})
})

describe('problems', () => {
	it('lists info and above, most severe first, without the helper', () => {
		const data = payload({
			verdict: {
				level: 'error',
				concerns: [
					concern('updates', 'info'),
					concern('support', 'error'),
					concern('reboot', 'ok'),
					concern('helper', 'warning'),
					concern('time-sync', 'warning'),
					concern('failed-units', 'unknown'),
				],
			},
		})

		expect(problems(data).map((c) => c.id)).toEqual(['support', 'time-sync', 'updates'])
	})

	it('ranks levels', () => {
		expect(compareLevels('error', 'warning')).toBeGreaterThan(0)
		expect(compareLevels('unknown', 'ok')).toBe(0)
	})
})

describe('labels', () => {
	it('names security updates only where they can be told apart', () => {
		expect(concernLabel(concern('updates', 'warning', { classified: true, security: 7, other: 2 }))).toBe('7 security updates')
		expect(concernLabel(concern('updates', 'info', { classified: false, security: 0, other: 3 }))).toBe('3 updates')
	})

	it('says how long a reboot has waited', () => {
		expect(concernLabel(concern('reboot', 'warning', { days: 9 }))).toBe('Reboot pending for 9 days')
		expect(concernLabel(concern('reboot', 'info', { days: 0 }))).toBe('Reboot required')
	})

	it('describes where Nextcloud runs', () => {
		expect(environmentLabel({ kind: 'host', container: null, virtualization: 'kvm' })).toBe('Virtual machine (KVM)')
		expect(environmentLabel({ kind: 'host', container: null, virtualization: null })).toBe('Physical machine')
		expect(environmentLabel({ kind: 'application-container', container: 'docker', virtualization: null })).toBe('Docker container')
		expect(environmentLabel({ kind: 'system-container', container: 'lxc', virtualization: null })).toBe('LXC container')
		expect(environmentLabel({ kind: 'host', container: null, virtualization: 'wsl' })).toBe('Windows Subsystem for Linux')
	})
})
