/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { OsConcern, OsConcernId, OsLevel, OsPayload, OsStatus } from './types.ts'

import { n, t } from '@nextcloud/l10n'

/**
 * Options for t() and n() where Vue inserts the result as text. Vue escapes
 * it already, so the placeholders must not be escaped or sanitized as HTML.
 */
export const PLAIN = { escape: false, sanitize: false }

const RANK: Record<OsLevel, number> = { unknown: 0, ok: 0, info: 1, warning: 2, error: 3 }

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
			return t('serverinfo', '{technology} container', { technology: containerName(environment.container) }, PLAIN)
		case 'snap':
			return t('serverinfo', 'Snap package')
		default:
			if (virtualization === 'wsl') {
				return t('serverinfo', 'Windows Subsystem for Linux')
			}
			return virtualization
				// TRANSLATORS: {hypervisor} is the name of a hypervisor such as KVM or VMware
				? t('serverinfo', 'Virtual machine ({hypervisor})', { hypervisor: hypervisorName(virtualization) }, PLAIN)
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
