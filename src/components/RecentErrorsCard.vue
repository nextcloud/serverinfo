<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import IconErrors from 'vue-material-design-icons/AlertCircleOutline.vue'
import IconLog from 'vue-material-design-icons/FileDocumentOutline.vue'
import QuickActions from './QuickActions.vue'
import SectionCard from './SectionCard.vue'
import StatusPill from './StatusPill.vue'
import type { HealthStatus, RecentErrors } from '../types.ts'

const props = defineProps<{
	data: RecentErrors
	logUrl: string
}>()

const formatTime = (iso: string): string => {
	if (!iso) return ''
	try {
		const d = new Date(iso)
		return new Intl.DateTimeFormat(undefined, { month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' }).format(d)
	} catch {
		return iso
	}
}

const levelLabel = (l: number): string => ['DEBUG', 'INFO', 'WARN', 'ERROR', 'FATAL', 'EXCEPTION'][l] ?? `L${l}`

const levelStatus = (l: number): HealthStatus => {
	if (l >= 3) return 'critical'
	if (l >= 2) return 'warning'
	return 'ok'
}

const status = computed<HealthStatus>(() => {
	if (!props.data.available) return 'ok'
	if (props.data.entries.some((e) => e.level >= 3)) return 'critical'
	if (props.data.entries.length > 0) return 'warning'
	return 'ok'
})

const statusLabel = computed(() => {
	if (!props.data.available) return t('serverinfo', 'Unavailable')
	if (props.data.entries.length === 0) return t('serverinfo', 'Quiet')
	return t('serverinfo', '{n} recent', { n: props.data.entries.length })
})
</script>

<template>
	<SectionCard>
		<template #header>
			<div class="title-with-icon">
				<IconErrors :size="18" />
				<span>{{ t('serverinfo', 'Recent log entries') }}</span>
			</div>
		</template>
		<template #actions>
			<StatusPill :status="status" :label="statusLabel" />
		</template>

		<p v-if="!data.available" :class="$style.note">
			{{ data.reason === 'log_type_not_file'
				? t('serverinfo', 'Log type is not "file" — cannot tail nextcloud.log here.')
				: t('serverinfo', 'Log file not readable. Check file permissions and the "logfile" config value.') }}
		</p>
		<p v-else-if="data.entries.length === 0" :class="$style.empty">
			{{ t('serverinfo', 'No warnings or errors in the recent log window.') }}
		</p>
		<ul v-else :class="$style.list">
			<li v-for="(e, idx) in data.entries" :key="idx" :class="[$style.entry, $style[`level_${levelStatus(e.level)}`]]">
				<div :class="$style.head">
					<span :class="$style.level">{{ levelLabel(e.level) }}</span>
					<span :class="$style.app">{{ e.app || '–' }}</span>
					<span :class="$style.time">{{ formatTime(e.time) }}</span>
				</div>
				<div :class="$style.msg">{{ e.message }}</div>
			</li>
		</ul>

		<QuickActions :actions="[
			{ id: 'log', label: t('serverinfo', 'Open log viewer'), icon: IconLog, href: logUrl },
		]" />
	</SectionCard>
</template>

<style module lang="scss">
.note, .empty {
	margin: 0;
	padding: 10px 12px;
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.entry {
	padding: 8px 10px;
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
	border-left: 3px solid var(--color-border);
	font-size: 0.85em;
}

.level_warning { border-left-color: var(--color-warning); }
.level_critical { border-left-color: var(--color-error); }

.head {
	display: flex;
	gap: 8px;
	font-size: 0.78em;
	color: var(--color-text-maxcontrast);
	margin-bottom: 2px;
}

.level {
	font-weight: 700;
	letter-spacing: 0.04em;
}

.app {
	font-family: var(--font-face-monospace, monospace);
}

.time {
	margin-left: auto;
	font-variant-numeric: tabular-nums;
}

.msg {
	color: var(--color-main-text);
	word-break: break-word;
}
</style>
