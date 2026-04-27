<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import IconShield from 'vue-material-design-icons/ShieldAlertOutline.vue'
import SectionCard from './SectionCard.vue'
import StatusPill from './StatusPill.vue'
import type { HealthStatus, LoginStats } from '../types.ts'

const props = defineProps<{
	logins: LoginStats
}>()

const status = computed<HealthStatus>(() => {
	if (props.logins.bruteforceAttempts1h > 50) return 'critical'
	if (props.logins.bruteforceAttempts1h > 5 || props.logins.bruteforceAttempts24h > 100) return 'warning'
	return 'ok'
})

const statusLabel = computed(() => {
	if (props.logins.bruteforceAttempts1h > 50) return t('serverinfo', 'Under attack')
	if (props.logins.bruteforceAttempts1h > 5) return t('serverinfo', 'Suspicious')
	if (!props.logins.available) return t('serverinfo', 'Not tracked')
	return t('serverinfo', 'Calm')
})
</script>

<template>
	<SectionCard>
		<template #header>
			<div class="title-with-icon">
				<IconShield :size="18" />
				<span>{{ t('serverinfo', 'Login security') }}</span>
			</div>
		</template>
		<template #actions>
			<StatusPill :status="status" :label="statusLabel" />
		</template>

		<div :class="$style.kpis">
			<div :class="$style.kpi">
				<div :class="$style.value">{{ logins.bruteforceAttempts1h.toLocaleString() }}</div>
				<div :class="$style.label">{{ t('serverinfo', 'Failed in 1 h') }}</div>
			</div>
			<div :class="$style.kpi">
				<div :class="$style.value">{{ logins.bruteforceAttempts24h.toLocaleString() }}</div>
				<div :class="$style.label">{{ t('serverinfo', 'Failed in 24 h') }}</div>
			</div>
			<div :class="$style.kpi">
				<div :class="$style.value">{{ logins.bruteforceTotal.toLocaleString() }}</div>
				<div :class="$style.label">{{ t('serverinfo', 'All-time tracked') }}</div>
			</div>
		</div>

		<div v-if="logins.topIps.length > 0">
			<div :class="$style.subLabel">{{ t('serverinfo', 'Top offending IPs (last 24 h)') }}</div>
			<ul :class="$style.ipList">
				<li v-for="ip in logins.topIps" :key="ip.ip" :class="$style.ipRow">
					<code :class="$style.ip">{{ ip.ip }}</code>
					<span :class="$style.ipCount">{{ ip.count.toLocaleString() }}</span>
				</li>
			</ul>
		</div>
	</SectionCard>
</template>

<style module lang="scss">
.kpis {
	display: grid;
	grid-template-columns: repeat(3, 1fr);
	gap: 8px;
}

.kpi {
	padding: 10px 12px;
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
}

.value {
	font-size: 1.4em;
	font-weight: 700;
	color: var(--color-main-text);
	font-variant-numeric: tabular-nums;
	line-height: 1.1;
}

.label {
	font-size: 0.72em;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.05em;
	font-weight: 600;
	margin-top: 2px;
}

.subLabel {
	font-size: 0.7em;
	text-transform: uppercase;
	letter-spacing: 0.06em;
	font-weight: 700;
	color: var(--color-text-maxcontrast);
	margin-bottom: 6px;
}

.ipList {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 3px;
}

.ipRow {
	display: flex;
	justify-content: space-between;
	font-size: 0.85em;
	padding: 4px 8px;
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
}

.ip {
	font-family: var(--font-face-monospace, monospace);
	color: var(--color-main-text);
}

.ipCount {
	font-variant-numeric: tabular-nums;
	color: var(--color-error);
	font-weight: 600;
}
</style>
