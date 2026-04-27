<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import IconServer from 'vue-material-design-icons/Server.vue'
import SectionCard from './SectionCard.vue'
import { formatMegabytes } from '../composables/useFormat.ts'
import type { CpuInfo, MemoryInfo } from '../types.ts'

const props = defineProps<{
	hostname: string
	osname: string
	cpu: CpuInfo
	memory: MemoryInfo
	now: Date
}>()

const formattedTime = computed(() => {
	try {
		return new Intl.DateTimeFormat(undefined, {
			year: 'numeric',
			month: 'short',
			day: '2-digit',
			hour: '2-digit',
			minute: '2-digit',
			second: '2-digit',
		}).format(props.now)
	} catch {
		return props.now.toISOString()
	}
})

const memoryFormatted = computed(() =>
	props.memory.total > 0 ? formatMegabytes(props.memory.total) : '–',
)
</script>

<template>
	<SectionCard :title="t('serverinfo', 'System')">
		<template #header>
			<h2 class="sr-only">{{ t('serverinfo', 'System') }}</h2>
			<div class="title-with-icon">
				<IconServer :size="18" />
				<span>{{ t('serverinfo', 'System') }}</span>
			</div>
		</template>
		<dl :class="$style.list">
			<div :class="$style.row">
				<dt>{{ t('serverinfo', 'Hostname') }}</dt>
				<dd>{{ hostname }}</dd>
			</div>
			<div :class="$style.row">
				<dt>{{ t('serverinfo', 'Operating system') }}</dt>
				<dd>{{ osname }}</dd>
			</div>
			<div :class="$style.row">
				<dt>{{ t('serverinfo', 'CPU') }}</dt>
				<dd>
					{{ cpu.name }}
					<span :class="$style.muted">
						({{ t('serverinfo', '{count} threads', { count: cpu.threads }) }})
					</span>
				</dd>
			</div>
			<div :class="$style.row">
				<dt>{{ t('serverinfo', 'Memory') }}</dt>
				<dd>{{ memoryFormatted }}</dd>
			</div>
			<div :class="$style.row">
				<dt>{{ t('serverinfo', 'Server time') }}</dt>
				<dd>{{ formattedTime }}</dd>
			</div>
		</dl>
	</SectionCard>
</template>

<style module lang="scss">
.list {
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 0;
}

.row {
	display: grid;
	grid-template-columns: minmax(110px, 28%) 1fr;
	gap: 10px;
	padding: 6px 0;
	border-bottom: 1px solid var(--color-border);
	font-size: 0.88em;

	&:last-child {
		border-bottom: 0;
	}

	dt {
		color: var(--color-text-maxcontrast);
		font-weight: 500;
		display: flex;
		align-items: center;
		gap: 6px;
	}

	dd {
		margin: 0;
		color: var(--color-main-text);
		word-break: break-word;
		font-variant-numeric: tabular-nums;
	}
}

.muted {
	color: var(--color-text-maxcontrast);
	margin-inline-start: 4px;
}
</style>
