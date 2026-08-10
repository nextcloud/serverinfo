<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<span
		class="job-status"
		:class="`job-status--${status.toLowerCase()}`"
		:title="dotOnly ? label : undefined">
		<span class="job-status__dot" aria-hidden="true" />
		<!-- Colour never carries meaning alone: the word stays for assistive
			 tech and for the tooltip -->
		<span :class="dotOnly && 'hidden-visually'">{{ label }}</span>
	</span>
</template>

<script setup lang="ts">
import type { JobStatus } from '../types.ts'

import { t } from '@nextcloud/l10n'
import { computed } from 'vue'

const props = defineProps<{
	status: JobStatus
	/** Narrow table columns only have room for the dot */
	dotOnly?: boolean
}>()

const label = computed(() => {
	switch (props.status) {
		case 'SUCCEEDED':
			// TRANSLATORS: Outcome of a background job run: it finished without an error
			return t('serverinfo', 'Succeeded')
		case 'FAILED':
			// TRANSLATORS: Outcome of a background job run: it threw an error
			return t('serverinfo', 'Failed')
		case 'CRASHED':
			// TRANSLATORS: Outcome of a background job run: it took the whole PHP process down with it
			return t('serverinfo', 'Crashed')
		default:
			// TRANSLATORS: Outcome of a background job run: it has not finished yet
			return t('serverinfo', 'Running')
	}
})
</script>

<style scoped>
.job-status {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	white-space: nowrap;
}

.job-status__dot {
	flex: 0 0 auto;
	width: 10px;
	height: 10px;
	border-radius: 10px;
	background-color: var(--color-text-maxcontrast);
}

.job-status--succeeded .job-status__dot {
	background-color: var(--color-success);
}

/* Failed and crashed share the dot; the label tells them apart */
.job-status--failed .job-status__dot,
.job-status--crashed .job-status__dot {
	background-color: var(--color-error);
}
</style>
