/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { t } from '@nextcloud/l10n'
import { computed, onBeforeUnmount, onMounted, type ComputedRef, ref } from 'vue'

export interface Greeting {
	emoji: string
	headline: string
	subline: string
}

const greetingFor = (now: Date, hostname: string): Greeting => {
	const h = now.getHours()
	const name = hostname || t('serverinfo', 'admin')

	if (h >= 5 && h < 11) {
		return {
			emoji: '☀️',
			headline: t('serverinfo', 'Good morning, {name}', { name }),
			subline: t('serverinfo', 'Fresh start. Servers are awake.'),
		}
	}
	if (h >= 11 && h < 14) {
		return {
			emoji: '🌤️',
			headline: t('serverinfo', 'Good day, {name}', { name }),
			subline: t('serverinfo', 'Lunch run, servers run.'),
		}
	}
	if (h >= 14 && h < 18) {
		return {
			emoji: '🌥️',
			headline: t('serverinfo', 'Afternoon, {name}', { name }),
			subline: t('serverinfo', 'Steady as she goes.'),
		}
	}
	if (h >= 18 && h < 22) {
		return {
			emoji: '🌆',
			headline: t('serverinfo', 'Good evening, {name}', { name }),
			subline: t('serverinfo', 'Wrapping up?'),
		}
	}
	if (h >= 22 || h < 2) {
		return {
			emoji: '🌙',
			headline: t('serverinfo', 'Burning the midnight oil, {name}', { name }),
			subline: t('serverinfo', 'Late-night ops. Coffee on standby.'),
		}
	}
	return {
		emoji: '🦉',
		headline: t('serverinfo', 'Up early, {name}', { name }),
		subline: t('serverinfo', 'The servers are night owls too.'),
	}
}

/**
 * Reactive greeting that re-evaluates every minute (so the message rolls
 * over at hour boundaries without a page reload).
 *
 * @param hostname - The server hostname, used to address the admin
 */
export function useGreeting(hostname: string): ComputedRef<Greeting> {
	const now = ref(new Date())
	let timer: ReturnType<typeof setInterval> | undefined

	onMounted(() => {
		timer = setInterval(() => {
			now.value = new Date()
		}, 60_000)
	})

	onBeforeUnmount(() => {
		if (timer !== undefined) {
			clearInterval(timer)
		}
	})

	return computed(() => greetingFor(now.value, hostname))
}
