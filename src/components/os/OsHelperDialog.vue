<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog
		:name="t('serverinfo', 'Set up the root helper')"
		:open="true"
		size="normal"
		@update:open="$emit('close')">
		<div class="os-helper-dialog">
			<p>
				{{ t('serverinfo', 'The helper is a short shell script that ships with this app. Run by root every few hours, it writes a report that this page reads. It only reads the system: it never installs, upgrades, restarts or reboots anything.') }}
			</p>

			<ol class="os-helper-dialog__steps">
				<li>
					<strong>{{ t('serverinfo', 'Install it from a fresh copy of this app') }}</strong>
					<p class="os-muted">
						{{ t('serverinfo', 'Do not copy it from the Nextcloud directory: the web server can change the files there. Download and extract this version of the app, or of Nextcloud, and run this in its serverinfo directory:') }}
					</p>
					<OsCommand :command="installCommand" />
				</li>
				<li>
					<strong>{{ t('serverinfo', 'Run it every six hours') }}</strong>
					<OsCommand :command="cronCommand" />
				</li>
				<li>
					<strong>{{ t('serverinfo', 'Run it once now') }}</strong>
					<OsCommand :command="runCommand" />
					<p class="os-muted">
						{{ t('serverinfo', 'Then use "Check now" on this page.') }}
					</p>
				</li>
			</ol>

			<p class="os-muted">
				{{ t('serverinfo', 'Install it again the same way after updating this app. To remove it, delete {script} and {cron}.', { script: helper.installPath, cron: '/etc/cron.d/serverinfo-os-report' }, PLAIN) }}
			</p>
		</div>

		<template #actions>
			<NcButton variant="tertiary" @click="$emit('close')">
				{{ t('serverinfo', 'Close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script setup lang="ts">
import type { OsPayload } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import OsCommand from './OsCommand.vue'
import { PLAIN, shellQuote } from '../../osUtils.ts'

const props = defineProps<{
	helper: OsPayload['helper']
}>()

defineEmits<{ close: [] }>()

const DEFAULT_REPORT = '/var/lib/serverinfo/os-report.json'

/** Only a non-default report path needs spelling out, and it is validated server-side. */
const outputOption = computed(() => (props.helper.reportPath !== DEFAULT_REPORT ? ` --output ${shellQuote(props.helper.reportPath)}` : ''))

const installCommand = computed(() => `sudo install -o root -g root -m 0755 bin/serverinfo-os-report ${shellQuote(props.helper.installPath)}`)
// The install and report paths are restricted to plain characters, so the
// cron line needs no quoting inside its own single quotes.
const cronCommand = computed(() => `echo '17 */6 * * * root ${props.helper.installPath} --refresh${outputOption.value}' | sudo tee /etc/cron.d/serverinfo-os-report`)
const runCommand = computed(() => `sudo ${shellQuote(props.helper.installPath)} --refresh${outputOption.value}`)
</script>

<style scoped>
.os-helper-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.os-helper-dialog p {
	margin: 0;
}

.os-helper-dialog__steps {
	display: flex;
	flex-direction: column;
	gap: 16px;
	margin: 0;
	padding-inline-start: 20px;
	list-style: decimal;
}

.os-helper-dialog__steps li {
	display: flex;
	flex-direction: column;
	gap: 6px;
}
</style>
