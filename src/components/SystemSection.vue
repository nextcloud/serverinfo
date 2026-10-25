<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<SectionHeading :icon="Monitor" :title="hostname" />
	<div class="server-info-table">
		<table>
			<tbody>
				<tr>
					<td>{{ t('serverinfo', 'Operating System:') }}</td>
					<td class="info">
						{{ osname }}
						<a
							v-if="osLevel === 'warning' || osLevel === 'error'"
							href="#os"
							class="system-section__os-link"
							:class="`system-section__os-link--${osLevel}`">
							<AlertCircle :size="16" />
							<!-- TRANSLATORS: Link to the operating system page when updates or a reboot are pending -->
							{{ t('serverinfo', 'Needs attention') }}
						</a>
					</td>
				</tr>
				<tr>
					<td>{{ t('serverinfo', 'CPU:') }}</td>
					<td class="info">
						{{ cpu.threads > 0
							? t('serverinfo', '{name} ({threads} threads)', { name: cpu.name, threads: cpu.threads })
							: cpu.name }}
					</td>
				</tr>
				<tr>
					<td>{{ t('serverinfo', 'Server time:') }}</td>
					<td><span class="info">{{ servertime }}</span></td>
				</tr>
				<tr>
					<td>{{ t('serverinfo', 'Uptime:') }}</td>
					<td><span class="info">{{ uptime }}</span></td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import Monitor from 'vue-material-design-icons/Monitor.vue'
import SectionHeading from './SectionHeading.vue'

defineProps<{
	hostname: string
	osname: string
	/** Overall level of the operating system page, null before its first check */
	osLevel?: string | null
	cpu: { name: string, threads: number }
	servertime: string
	uptime: string
}>()
</script>

<style scoped>
.system-section__os-link {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	margin-inline-start: 8px;
	padding: 0 10px 0 6px;
	border-radius: var(--border-radius-pill);
	font-weight: normal;
	font-size: 0.9rem;
	white-space: nowrap;
}

.system-section__os-link--warning {
	background: var(--color-warning-hover, var(--color-background-dark));
	color: var(--color-warning-text, var(--color-warning));
}

.system-section__os-link--error {
	background: var(--color-error-hover, var(--color-background-dark));
	color: var(--color-error-text, var(--color-error));
}
</style>
