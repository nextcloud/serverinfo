/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { compareLevels, daysSince, environmentLabel, formatDays, problems, shellQuote } from './osUtils.ts'
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
	it('describes where Nextcloud runs', () => {
		expect(environmentLabel({ kind: 'host', container: null, virtualization: 'kvm' })).toBe('Virtual machine (KVM)')
		expect(environmentLabel({ kind: 'host', container: null, virtualization: null })).toBe('Physical machine')
		expect(environmentLabel({ kind: 'application-container', container: 'docker', virtualization: null })).toBe('Docker container')
		expect(environmentLabel({ kind: 'system-container', container: 'lxc', virtualization: null })).toBe('LXC container')
		expect(environmentLabel({ kind: 'host', container: null, virtualization: 'wsl' })).toBe('Windows Subsystem for Linux')
	})

	it('leaves escaping to Vue', () => {
		expect(environmentLabel({ kind: 'application-container', container: 'r&d <lab>', virtualization: null })).toBe('r&d <lab> container')
	})
})
