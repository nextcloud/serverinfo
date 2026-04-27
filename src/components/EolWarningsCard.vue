<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import IconClock from 'vue-material-design-icons/CalendarRemoveOutline.vue'
import SectionCard from './SectionCard.vue'
import StatusPill from './StatusPill.vue'
import type { EolEntry, EolInfo, HealthStatus } from '../types.ts'

const props = defineProps<{
	eol: EolInfo
}>()

const overall = computed<HealthStatus>(() => {
	const items = [props.eol.php, props.eol.nextcloud]
	if (items.some((i) => i.status === 'critical')) return 'critical'
	if (items.some((i) => i.status === 'warning')) return 'warning'
	return 'ok'
})

const overallLabel = computed(() => {
	if (overall.value === 'critical') return t('serverinfo', 'Past EOL')
	if (overall.value === 'warning') return t('serverinfo', 'EOL approaching')
	return t('serverinfo', 'Supported')
})

const formatDays = (days: number | null): string => {
	if (days === null) return ''
	if (days < 0) return t('serverinfo', '{n} days past EOL', { n: Math.abs(days) })
	if (days === 0) return t('serverinfo', 'EOL today')
	if (days < 60) return t('serverinfo', '{n} days left', { n: days })
	const months = Math.round(days / 30)
	if (months < 12) return t('serverinfo', '{n} months left', { n: months })
	const years = (days / 365).toFixed(1)
	return t('serverinfo', '{y} years left', { y: years })
}

const safePillStatus = (s: EolEntry['status']): HealthStatus => s === 'unknown' ? 'ok' : s
</script>

<template>
	<SectionCard>
		<template #header>
			<div class="title-with-icon">
				<IconClock :size="18" />
				<span>{{ t('serverinfo', 'End-of-life status') }}</span>
			</div>
		</template>
		<template #actions>
			<StatusPill :status="overall" :label="overallLabel" />
		</template>

		<div :class="$style.grid">
			<div :class="[$style.row, $style[`row_${eol.php.status}`]]">
				<div :class="$style.left">
					<div :class="$style.product">PHP</div>
					<div :class="$style.version">{{ eol.php.version }}</div>
				</div>
				<div :class="$style.right">
					<StatusPill
						:status="safePillStatus(eol.php.status)"
						:label="eol.php.status === 'critical' ? t('serverinfo', 'Past EOL') : eol.php.status === 'warning' ? t('serverinfo', 'EOL soon') : t('serverinfo', 'Supported')" />
					<div :class="$style.eolText">
						<span v-if="eol.php.eol">{{ t('serverinfo', 'until {d}', { d: eol.php.eol }) }}</span>
						<span v-if="eol.php.daysUntilEol !== null"> · {{ formatDays(eol.php.daysUntilEol) }}</span>
					</div>
				</div>
			</div>

			<div :class="[$style.row, $style[`row_${eol.nextcloud.status}`]]">
				<div :class="$style.left">
					<div :class="$style.product">Nextcloud</div>
					<div :class="$style.version">{{ eol.nextcloud.version }}</div>
				</div>
				<div :class="$style.right">
					<StatusPill
						:status="safePillStatus(eol.nextcloud.status)"
						:label="eol.nextcloud.status === 'critical' ? t('serverinfo', 'Past EOL') : eol.nextcloud.status === 'warning' ? t('serverinfo', 'EOL soon') : t('serverinfo', 'Supported')" />
					<div :class="$style.eolText">
						<span v-if="eol.nextcloud.eol">{{ t('serverinfo', 'until {d}', { d: eol.nextcloud.eol }) }}</span>
						<span v-if="eol.nextcloud.daysUntilEol !== null"> · {{ formatDays(eol.nextcloud.daysUntilEol) }}</span>
					</div>
				</div>
			</div>
		</div>
	</SectionCard>
</template>

<style module lang="scss">
.grid {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 10px 14px;
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
	border-left: 3px solid var(--color-success);
}

.row_warning { border-left-color: var(--color-warning); }
.row_critical { border-left-color: var(--color-error); }

.left {
	min-width: 0;
}

.product {
	font-size: 0.72em;
	text-transform: uppercase;
	letter-spacing: 0.06em;
	font-weight: 700;
	color: var(--color-text-maxcontrast);
}

.version {
	font-size: 1.1em;
	font-weight: 700;
	color: var(--color-main-text);
	font-variant-numeric: tabular-nums;
}

.right {
	display: flex;
	flex-direction: column;
	align-items: flex-end;
	gap: 4px;
	text-align: end;
}

.eolText {
	font-size: 0.78em;
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
}
</style>
