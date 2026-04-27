/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { computed, type ComputedRef, type Ref } from 'vue'

const TRANSLATIONS: Record<string, string> = {
	CPU: 'Brain juice',
	Memory: 'Short-term thinky',
	Disk: 'Forever box',
	Active: 'Awake people',
	Files: 'Stuff',
	'CPU load': 'Brain effort',
	'System status': 'Vibe check',
	'Background jobs': 'Helper gnomes',
	'Job queue': 'Gnome to-do list',
	'App updates': 'Fashion check',
	'Loaded PHP modules': 'Spell book',
	System: 'The whole shebang',
	Disks: 'Forever boxes',
	Network: 'Pipes',
	'Active users': 'Currently breathing',
	Database: 'The big spreadsheet',
	PHP: 'PHP (Pretty Helpful Pals)',
	'External monitoring tool': 'Stalker hookup',
	Temperature: 'How toasty',
}

/**
 * Wrap a translation function. When dad mode is enabled, swap matching
 * labels for goofy alternatives. Otherwise pass through unchanged.
 */
export function useDadMode(
	dadMode: Ref<boolean>,
	translate: (key: string, ...args: unknown[]) => string,
): ComputedRef<(key: string, ...args: unknown[]) => string> {
	return computed(() => {
		if (!dadMode.value) {
			return translate
		}
		return (key: string, ...args: unknown[]) => {
			if (TRANSLATIONS[key]) {
				return TRANSLATIONS[key]
			}
			return translate(key, ...args)
		}
	})
}
