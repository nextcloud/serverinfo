<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<h2>
		<Monitor class="infoicon" :size="20" />
		{{ hostname }}
	</h2>
	<div class="server-info-table">
		<table>
			<tbody>
				<tr>
					<td>{{ t('serverinfo', 'Operating System:') }}</td>
					<td class="info">
						{{ osname }}
					</td>
				</tr>
				<tr>
					<td>{{ t('serverinfo', 'CPU:') }}</td>
					<td class="info">
						{{ cpu.name }} ({{ cpu.threads }} {{ t('serverinfo', 'threads') }})
					</td>
				</tr>
				<tr v-if="memTotal > 0">
					<td>{{ t('serverinfo', 'Memory:') }}</td>
					<td class="info">
						{{ formatMegabytes(memTotal) }}
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
import Monitor from 'vue-material-design-icons/Monitor.vue'
import { formatMegabytes } from '../utils.ts'

defineProps<{
	hostname: string
	osname: string
	cpu: { name: string, threads: number }
	memTotal: number
	servertime: string
	uptime: string
}>()
</script>
