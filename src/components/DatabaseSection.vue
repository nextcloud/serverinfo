<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<h2>
		<Database class="infoicon" :size="20" />
		{{ t('serverinfo', 'Database') }}
	</h2>
	<div class="server-info-table">
		<table>
			<tbody>
				<tr>
					<td>{{ t('serverinfo', 'Type:') }}</td>
					<td class="info">
						{{ database.type }}
					</td>
				</tr>
				<tr>
					<td>{{ t('serverinfo', 'Version:') }}</td>
					<td class="info">
						{{ database.version }}
					</td>
				</tr>
				<tr>
					<td>{{ t('serverinfo', 'Size:') }}</td>
					<td class="info">
						{{ formatBytes(database.size) }}
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import Database from 'vue-material-design-icons/Database.vue'
import { formatBytes } from '../utils.ts'

defineProps<{
	database: { type: string, version: string, size: number }
}>()
</script>
