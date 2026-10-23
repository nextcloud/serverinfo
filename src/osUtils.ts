/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { OsConcern, OsConcernId, OsLevel, OsPayload, OsStatus } from './types.ts'

import { getCanonicalLocale, n, t } from '@nextcloud/l10n'

const RANK: Record<OsLevel, number> = { unknown: 0, ok: 0, info: 1, warning: 2, error: 3 }

/** Brand names of the paid extended support programmes */
export const PROGRAMMES: Record<string, string> = {
	'ubuntu-pro': 'Ubuntu Pro',
	'debian-elts': 'Debian Extended LTS',
	'rhel-els': 'Red Hat Extended Life Cycle Support',
	'suse-ltss': 'SUSE Long Term Service Pack Support',
	'oracle-extended': 'Oracle Linux Extended Support',
}

/**
 * @param a first level
 * @param b second level
 */
export function compareLevels(a: OsLevel, b: OsLevel): number {
	return RANK[a] - RANK[b]
}

/**
 * @param payload the /os payload
 * @param id the concern to look up
 */
export function concernOf(payload: OsPayload | null, id: OsConcernId): OsConcern | null {
	return payload?.verdict?.concerns.find((concern) => concern.id === id) ?? null
}

/**
 * Concerns worth showing above the fold, most severe first.
 *
 * @param payload the /os payload
 */
export function problems(payload: OsPayload | null): OsConcern[] {
	return (payload?.verdict?.concerns ?? [])
		.filter((concern) => RANK[concern.level] >= RANK.info && concern.id !== 'helper')
		.sort((a, b) => compareLevels(b.level, a.level))
}

/**
 * @param fact a fact value of a concern
 */
export function factNumber(fact: unknown): number | null {
	return typeof fact === 'number' ? fact : null
}

/**
 * @param level the overall level
 */
export function headline(level: OsLevel): string {
	switch (level) {
		case 'error':
			// TRANSLATORS: Headline of the operating system page when something needs action now
			return t('serverinfo', 'The operating system needs attention')
		case 'warning':
			// TRANSLATORS: Headline of the operating system page when something should be done soon
			return t('serverinfo', 'Some things should be taken care of')
		case 'info':
			// TRANSLATORS: Headline of the operating system page when something is pending, but not for long enough to warn about
			return t('serverinfo', 'Nothing urgent right now')
		case 'ok':
			// TRANSLATORS: Headline of the operating system page when nothing is pending
			return t('serverinfo', 'Everything is up to date')
		default:
			// TRANSLATORS: Headline of the operating system page when nothing could be determined
			return t('serverinfo', 'The operating system status is unknown')
	}
}

/**
 * Short label of a concern, for the chips under the headline.
 *
 * @param concern the concern
 */
export function concernLabel(concern: OsConcern): string {
	const facts = concern.facts
	const days = factNumber(facts.days)
	switch (concern.id) {
		case 'updates': {
			const security = factNumber(facts.security) ?? 0
			const other = factNumber(facts.other) ?? 0
			return facts.classified
				? n('serverinfo', '%n security update', '%n security updates', security)
				: n('serverinfo', '%n update', '%n updates', other)
		}
		case 'reboot':
			return days !== null && days > 0
				? n('serverinfo', 'Reboot pending for %n day', 'Reboot pending for %n days', days)
				: t('serverinfo', 'Reboot required')
		case 'restart':
			return facts.php
				? t('serverinfo', 'PHP restart required')
				: n('serverinfo', '%n service needs a restart', '%n services need a restart', factNumber(facts.services) ?? 0)
		case 'support':
			return facts.state === 'ended'
				? t('serverinfo', 'Release no longer supported')
				: t('serverinfo', 'Support ends soon')
		case 'package-lists':
			return t('serverinfo', 'Package lists are old')
		case 'automatic-updates':
			return t('serverinfo', 'Automatic updates are off')
		case 'failed-units':
			return n('serverinfo', '%n failed service', '%n failed services', (facts.units as string[] | undefined)?.length ?? 0)
		case 'time-sync':
			return t('serverinfo', 'Clock not synchronized')
		default:
			return t('serverinfo', 'Root helper')
	}
}

/**
 * Where Nextcloud runs, e.g. "Virtual machine (KVM)" or "Docker container".
 *
 * @param environment the detected environment
 */
export function environmentLabel(environment: OsStatus['environment']): string {
	const virtualization = environment.virtualization
	switch (environment.kind) {
		case 'application-container':
		case 'system-container':
			// TRANSLATORS: {technology} is a container technology such as Docker, LXC or Podman
			return t('serverinfo', '{technology} container', { technology: containerName(environment.container) })
		case 'snap':
			return t('serverinfo', 'Snap package')
		default:
			if (virtualization === 'wsl') {
				return t('serverinfo', 'Windows Subsystem for Linux')
			}
			return virtualization
				// TRANSLATORS: {hypervisor} is the name of a hypervisor such as KVM or VMware
				? t('serverinfo', 'Virtual machine ({hypervisor})', { hypervisor: hypervisorName(virtualization) })
				: t('serverinfo', 'Physical machine')
	}
}

/**
 * @param container container technology as detected
 */
function containerName(container: string | null): string {
	const names: Record<string, string> = {
		docker: 'Docker',
		podman: 'Podman',
		kubernetes: 'Kubernetes',
		lxc: 'LXC',
		'lxc-libvirt': 'LXC',
		incus: 'Incus',
		lxd: 'LXD',
		'systemd-nspawn': 'systemd-nspawn',
		openvz: 'OpenVZ',
	}
	return names[container ?? ''] ?? (container ?? '')
}

/**
 * @param hypervisor hypervisor as systemd-detect-virt names it
 */
function hypervisorName(hypervisor: string): string {
	const names: Record<string, string> = {
		kvm: 'KVM',
		qemu: 'QEMU',
		vmware: 'VMware',
		microsoft: 'Hyper-V',
		oracle: 'VirtualBox',
		xen: 'Xen',
		amazon: 'AWS',
		google: 'Google Cloud',
		parallels: 'Parallels',
		bochs: 'Bochs',
		apple: 'Apple Virtualization',
		utm: 'UTM',
	}
	return names[hypervisor] ?? hypervisor
}

/**
 * A calendar date from the support table (Y-m-d), in the user's locale.
 *
 * @param date date as Y-m-d
 */
export function formatDay(date: string | null): string {
	if (!date) {
		return ''
	}
	const parsed = new Date(`${date}T12:00:00Z`)
	return Number.isNaN(parsed.getTime())
		? date
		: parsed.toLocaleDateString(getCanonicalLocale(), { dateStyle: 'long', timeZone: 'UTC' })
}

/**
 * Whole days between two server timestamps.
 *
 * @param now current server time
 * @param since earlier unix timestamp
 */
export function daysSince(now: number, since: number | null): number | null {
	return since === null ? null : Math.max(0, Math.floor((now - since) / 86400))
}

/**
 * "today", "1 day", "5 days".
 *
 * @param days number of days
 */
export function formatDays(days: number | null): string {
	if (days === null) {
		return ''
	}
	return days === 0
		// TRANSLATORS: How long something has been pending when it appeared today
		? t('serverinfo', 'since today')
		: n('serverinfo', 'for %n day', 'for %n days', days)
}

/**
 * Quotes a value for a POSIX shell, so a copied command works whatever the
 * path contains.
 *
 * @param value the word to quote
 */
export function shellQuote(value: string): string {
	return /^[A-Za-z0-9@%+=:,./_-]+$/.test(value) ? value : `'${value.replace(/'/g, "'\\''")}'`
}
