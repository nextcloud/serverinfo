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
					<strong>{{ t('serverinfo', 'Install it outside the Nextcloud directory') }}</strong>
					<p class="os-muted">
						{{ t('serverinfo', 'The web server can write to the Nextcloud directory. Running the script from there would let anyone who takes over the web server run code as root, so it is copied to a place only root can change.') }}
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
				{{ t('serverinfo', 'Install it again after updating this app, so the helper stays current. To remove it, delete {script} and {cron}.', { script: helper.installPath, cron: '/etc/cron.d/serverinfo-os-report' }) }}
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
import { shellQuote } from '../../osUtils.ts'

const props = defineProps<{
	helper: OsPayload['helper']
}>()

defineEmits<{ close: [] }>()

const DEFAULT_REPORT = '/var/lib/serverinfo/os-report.json'

/** Only a non-default report path needs spelling out, and it is validated server-side. */
const outputOption = computed(() => (props.helper.reportPath !== DEFAULT_REPORT ? ` --output ${shellQuote(props.helper.reportPath)}` : ''))

const installCommand = computed(() => `sudo install -o root -g root -m 0755 ${shellQuote(props.helper.scriptPath)} ${shellQuote(props.helper.installPath)}`)
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
