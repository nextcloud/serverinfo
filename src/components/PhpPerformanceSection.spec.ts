/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { DatabaseRule, PhpCheck } from '../types.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import DatabaseSnippetDialog from './DatabaseSnippetDialog.vue'
import PhpPerformanceSection from './PhpPerformanceSection.vue'

const get = vi.fn()

vi.mock('@nextcloud/axios', () => ({ default: { get: (...args: unknown[]) => get(...args) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url: string) => url }))

function rule(id: string, status: DatabaseRule['status'], severity: DatabaseRule['severity'] = 'warning', extra: Partial<DatabaseRule> = {}): DatabaseRule {
	return {
		id,
		name: `Rule ${id}`,
		category: 'PHP-FPM',
		severity,
		status,
		issue: `Issue of ${id}`,
		recommendation: `Recommendation of ${id}`,
		justification: status === 'fail' ? `Justification of ${id}` : null,
		value: 1,
		skipReason: status === 'skipped' ? `Skipped ${id}` : null,
		apply: null,
		details: null,
		docUrl: null,
		...extra,
	}
}

function payload(results: DatabaseRule[]): PhpCheck {
	return { sapi: 'fpm-fcgi', pool: 'nextcloud', ranAt: 1761300000, results }
}

describe('PhpPerformanceSection', () => {
	beforeEach(() => {
		get.mockReset()
	})

	it('asks the PHP check endpoint once', async () => {
		get.mockResolvedValue({ data: payload([]) })
		mount(PhpPerformanceSection)
		await flushPromises()

		expect(get).toHaveBeenCalledTimes(1)
		expect(get).toHaveBeenCalledWith('/apps/serverinfo/php/check')
	})

	it('shows an all-good state when nothing fails', async () => {
		get.mockResolvedValue({ data: payload([rule('a', 'ok'), rule('b', 'skipped')]) })
		const wrapper = mount(PhpPerformanceSection)
		await flushPromises()

		expect(wrapper.text()).toContain('No bottleneck found')
		expect(wrapper.find('details summary').text()).toBe('Show 2 passing or skipped checks')
		expect(wrapper.find('details').text()).toContain('Skipped b')
	})

	it('lists failing rules first, the most severe first, and keeps the rest folded away', async () => {
		get.mockResolvedValue({
			data: payload([
				rule('hint', 'fail', 'notice'),
				rule('passing', 'ok'),
				rule('warning', 'fail', 'warning'),
				rule('alert', 'fail', 'alert'),
			]),
		})
		const wrapper = mount(PhpPerformanceSection)
		await flushPromises()

		expect(wrapper.text()).not.toContain('No bottleneck found')
		const shown = wrapper.findAll('.php-performance__cards')[0].findAll('h4').map((title) => title.text())
		expect(shown).toEqual(['Rule alert', 'Rule warning', 'Rule hint'])
		expect(wrapper.find('details summary').text()).toBe('Show 1 passing or skipped check')
	})

	it('says when the checks could not run', async () => {
		get.mockRejectedValue(new Error('Request failed with status code 500'))
		const wrapper = mount(PhpPerformanceSection)
		await flushPromises()

		expect(wrapper.text()).toContain('The PHP performance checks could not be run.')
		expect(wrapper.text()).toContain('status code 500')
	})

	it('offers the snippet of a rule with a recommended value', async () => {
		const apply = { variable: 'pm.max_children', recommendedValue: '40', runtimeWritable: false, configKey: 'pm.max_children', configFile: 'php-fpm.conf', note: null }
		get.mockResolvedValue({ data: payload([rule('php.fpm_max_children', 'fail', 'warning', { apply })]) })
		const wrapper = mount(PhpPerformanceSection)
		await flushPromises()

		const button = wrapper.findAll('button').find((candidate) => candidate.text().includes('Show config snippet'))
		expect(button).toBeDefined()
		await button!.trigger('click')

		const dialog = wrapper.findComponent(DatabaseSnippetDialog)
		expect(dialog.exists()).toBe(true)
		expect(dialog.props('section')).toBe('nextcloud')
	})
})

describe('DatabaseSnippetDialog', () => {
	function snippetOf(configFile: string, section?: string): string {
		const apply = { variable: 'x', recommendedValue: '7', runtimeWritable: false, configKey: 'x.key', configFile, note: null }
		const wrapper = mount(DatabaseSnippetDialog, { props: { rule: rule('r', 'fail', 'warning', { apply }), section } })
		return (wrapper.vm as unknown as { snippet: string }).snippet
	}

	it('writes each configuration file in its own syntax', () => {
		expect(snippetOf('my.cnf')).toBe('# my.cnf\n[mysqld]\nx.key = 7')
		expect(snippetOf('postgresql.conf')).toBe('# postgresql.conf\nx.key = 7')
		expect(snippetOf('php.ini')).toBe('; php.ini\nx.key = 7')
		expect(snippetOf('php-fpm.conf', 'nextcloud')).toBe('; php-fpm.conf\n[nextcloud]\nx.key = 7')
		expect(snippetOf('php-fpm.conf')).toBe('; php-fpm.conf\n[www]\nx.key = 7')
	})
})
