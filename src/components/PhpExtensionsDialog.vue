<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog :name="t('serverinfo', 'PHP extensions')" size="normal" @closing="$emit('close')">
		<div v-if="extensions" class="server-info-table">
			<table>
				<thead>
					<tr>
						<th>{{ t('serverinfo', 'Extension') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="ext in extensions" :key="ext">
						<td>{{ ext }}</td>
					</tr>
				</tbody>
			</table>
		</div>
		<template v-else>
			{{ t('serverinfo', 'Unable to list extensions') }}
		</template>
	</NcDialog>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import NcDialog from '@nextcloud/vue/components/NcDialog'

defineProps<{
	/** Loaded extensions, or null when the platform would not list them */
	extensions: string[] | null
}>()

defineEmits<{ close: [] }>()
</script>
