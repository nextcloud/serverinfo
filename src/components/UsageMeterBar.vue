<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="usage-meter-bar">
		<div class="usage-meter-bar__header">
			<span>{{ label }}</span>
			<span class="info">{{ detail }}</span>
		</div>
		<NcProgressBar
			:value="percentage"
			size="medium"
			:color="percentage >= WARNING_AT ? 'var(--color-warning)' : undefined"
			:error="forceError || percentage >= CRITICAL_AT" />
	</div>
</template>

<script setup lang="ts">
import NcProgressBar from '@nextcloud/vue/components/NcProgressBar'

defineProps<{
	label: string
	/** Caption next to the bar, e.g. "3.2 GB of 16 GB" */
	detail: string
	/** Bar fill, 0-100 */
	percentage: number
	/** Forces the error colour regardless of percentage, e.g. when the source itself reports "full" */
	forceError?: boolean
}>()

const WARNING_AT = 75
const CRITICAL_AT = 90
</script>

<style scoped>
.usage-meter-bar__header {
	display: flex;
	justify-content: space-between;
	gap: 8px;
	margin-bottom: 4px;
}
</style>
