/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import OsHelperDialog from './OsHelperDialog.vue'
import { payload } from '../../testing/osPayload.ts'

const DialogStub = { template: '<div><slot /><slot name="actions" /></div>' }

function commands(helper = payload().helper) {
	return mount(OsHelperDialog, { props: { helper }, global: { stubs: { NcDialog: DialogStub } } })
		.findAll('.os-command__code')
		.map((code) => code.text())
}

describe('OsHelperDialog', () => {
	it('installs the script from a private copy of the release archive, not the app directory, and schedules it', () => {
		expect(commands()).toEqual([
			'd=$(mktemp -d) && tar -xjf nextcloud-<version>.tar.bz2 -C "$d" nextcloud/apps/serverinfo/bin/serverinfo-os-report && sudo install -o root -g root -m 0755 "$d"/nextcloud/apps/serverinfo/bin/serverinfo-os-report /usr/local/sbin/serverinfo-os-report && rm -r "$d"',
			"echo '17 */6 * * * root /usr/local/sbin/serverinfo-os-report --refresh --random-delay' | sudo tee /etc/cron.d/serverinfo-os-report",
			'sudo /usr/local/sbin/serverinfo-os-report --refresh',
		])
	})

	it('passes a custom report path', () => {
		const [, cron, run] = commands({
			...payload().helper,
			reportPath: '/srv/reports/os.json',
		})
		expect(cron).toContain('--refresh --random-delay --output /srv/reports/os.json\'')
		expect(run).toBe('sudo /usr/local/sbin/serverinfo-os-report --refresh --output /srv/reports/os.json')
	})
})
