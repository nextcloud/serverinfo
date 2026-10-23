/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { OsConcern, OsPayload, OsPendingUpdate, OsStatus } from '../types.ts'

/** 2026-10-19 12:00:00 UTC */
export const NOW = 1792411200

const DAY = 86400

/**
 * @param overrides fields to change
 */
export function update(overrides: Partial<OsPendingUpdate> = {}): OsPendingUpdate {
	return {
		name: 'openssl',
		installed: '3.0.13-0ubuntu3.4',
		candidate: '3.0.13-0ubuntu3.5',
		security: true,
		origin: 'Ubuntu:24.04/noble-security',
		advisories: [],
		severity: null,
		since: NOW - 5 * DAY,
		kind: 'package',
		stack: true,
		summary: null,
		...overrides,
	}
}

/**
 * @param overrides fields to change
 */
export function status(overrides: Partial<OsStatus> = {}): OsStatus {
	return {
		state: 'ok',
		collectedAt: NOW - 600,
		release: { id: 'ubuntu', name: 'Ubuntu', prettyName: 'Ubuntu 24.04.3 LTS', versionId: '24.04', codename: 'noble' },
		kernel: '6.8.0-45-generic',
		architecture: 'x86_64',
		environment: { kind: 'host', container: null, virtualization: 'kvm' },
		support: {
			rolling: false,
			securityEnds: '2029-05-31',
			extendedEnds: '2034-04-25',
			extended: 'ubuntu-pro',
			extendedActive: false,
			ltsFrom: null,
			dataDate: '2026-10-19',
		},
		packageManager: 'apt',
		updates: [update(), update({ name: 'tzdata', security: false, stack: false, since: null })],
		updatesSource: 'system',
		securityClassified: true,
		updatesOmitted: 0,
		held: [],
		listsRefreshedAt: NOW - 4 * 3600,
		lastUpgradeAt: NOW - 7 * DAY,
		automaticUpdates: { state: 'enabled', mechanism: 'unattended-upgrades', scope: 'security' },
		rebootReasons: [],
		restartServices: null,
		livePatches: [],
		failedUnits: [],
		timeSynchronized: true,
		timeSource: 'timedatectl',
		selinux: 'disabled',
		apparmor: 'enabled',
		upgradeCommands: { all: 'sudo apt update && sudo apt full-upgrade', security: 'sudo unattended-upgrade -v' },
		helper: { state: 'absent', path: '/var/lib/serverinfo/os-report.json', generatedAt: null, version: null, outdated: false, errors: [] },
		bootTime: NOW - 30 * DAY,
		diagnostics: [],
		...overrides,
	}
}

/**
 * @param id concern
 * @param level its level
 * @param facts what it is based on
 */
export function concern(id: OsConcern['id'], level: OsConcern['level'], facts: OsConcern['facts'] = {}): OsConcern {
	return { id, level, facts }
}

/**
 * @param overrides fields to change
 */
export function payload(overrides: Partial<OsPayload> = {}): OsPayload {
	return {
		now: NOW,
		status: status(),
		verdict: {
			level: 'warning',
			concerns: [
				concern('updates', 'warning', { security: 1, other: 1, classified: true, stack: 1, days: 5 }),
				concern('reboot', 'ok'),
				concern('support', 'ok', { state: 'supported', ends: '2029-05-31', daysLeft: 954, extended: 'ubuntu-pro', extendedEnds: '2034-04-25' }),
			],
		},
		outdated: false,
		php: { sampledAt: NOW - 60, sapi: 'fpm-fcgi', outdatedLibraries: [], service: 'php8.3-fpm', confinement: null },
		initSystem: 'systemd',
		commands: { reboot: 'sudo systemctl reboot', php: 'sudo systemctl restart php8.3-fpm', services: {} },
		thresholds: {
			securityWarning: 3,
			securityError: 14,
			rebootWarning: 7,
			rebootError: 30,
			restartWarning: 3,
			eolWarning: 90,
			listsWarning: 7,
			listsError: 30,
		},
		notifications: true,
		settingsDefaults: {
			thresholds: { securityWarning: 3, securityError: 14, rebootWarning: 7, rebootError: 30, restartWarning: 3, eolWarning: 90, listsWarning: 7, listsError: 30 },
			notifications: true,
		},
		thresholdLimits: { maxDays: 3650, pairs: [['securityWarning', 'securityError'], ['rebootWarning', 'rebootError'], ['listsWarning', 'listsError']] },
		refresh: { systemCron: true, running: false },
		helper: {
			bundledVersion: '1.0.0',
			installPath: '/usr/local/sbin/serverinfo-os-report',
			reportPath: '/var/lib/serverinfo/os-report.json',
		},
		...overrides,
	}
}
