/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { OsPayload } from '../types.ts'

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import OsView from './OsView.vue'
import { concern, payload } from '../testing/osPayload.ts'

const state = { data: ref<OsPayload | null>(null) }

vi.mock('../composables/useOsStatus.ts', () => ({
	useOsStatus: () => ({
		data: state.data,
		loading: ref(false),
		refreshing: ref(false),
		error: ref(null),
		refreshError: ref(null),
		load: vi.fn(),
		refresh: vi.fn(),
	}),
}))

function withFreshness(level: 'ok' | 'warning', systemCron: boolean): OsPayload {
	const base = payload()
	return {
		...base,
		refresh: { systemCron, running: false },
		verdict: {
			level: 'warning',
			concerns: [
				...(base.verdict?.concerns ?? []),
				concern('freshness', level, { systemCron }, 'The operating system was last checked 3 days ago', 'Check that the system cron job for Nextcloud runs.'),
			],
		},
	}
}

describe('OsView', () => {
	it('says when the shown status is old and what to do', () => {
		state.data.value = withFreshness('warning', true)
		const wrapper = mount(OsView, { props: { active: false } })

		expect(wrapper.text()).toContain('The operating system was last checked 3 days ago. Check that the system cron job for Nextcloud runs.')
		expect(wrapper.text()).not.toContain('occ serverinfo:os-status --refresh')
	})

	it('without system cron, points to occ in the same note', () => {
		state.data.value = withFreshness('warning', false)
		const wrapper = mount(OsView, { props: { active: false } })

		expect(wrapper.findAll('.os-view__notice')).toHaveLength(1)
		expect(wrapper.text()).toContain('occ serverinfo:os-status --refresh')
	})

	it('shows no note for a current status', () => {
		state.data.value = withFreshness('ok', true)
		const wrapper = mount(OsView, { props: { active: false } })

		expect(wrapper.find('.os-view__notice').exists()).toBe(false)
	})
})
