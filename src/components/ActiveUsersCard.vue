<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import IconAccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import SectionCard from './SectionCard.vue'
import type { ActiveUsers } from '../types.ts'

const props = defineProps<{
	activeUsers: ActiveUsers
	totalUsers: number
}>()

interface Bucket {
	key: keyof ActiveUsers
	label: string
	count: number
}

const buckets = computed<Bucket[]>(() => [
	{ key: 'last5minutes', label: t('serverinfo', '5 min'), count: props.activeUsers.last5minutes ?? 0 },
	{ key: 'last1hour', label: t('serverinfo', '1 hour'), count: props.activeUsers.last1hour ?? 0 },
	{ key: 'last24hours', label: t('serverinfo', '24 hours'), count: props.activeUsers.last24hours ?? 0 },
	{ key: 'last7days', label: t('serverinfo', '7 days'), count: props.activeUsers.last7days ?? 0 },
	{ key: 'last1month', label: t('serverinfo', '30 days'), count: props.activeUsers.last1month ?? 0 },
])

const maxCount = computed(() => Math.max(1, ...buckets.value.map((b) => b.count)))

const percentOfAll = (count: number): number => {
	if (props.totalUsers <= 0) {
		return 0
	}
	return Math.round((count / props.totalUsers) * 100 * 10) / 10
}
</script>

<template>
	<SectionCard>
		<template #header>
			<div class="title-with-icon">
				<IconAccountGroup :size="18" />
				<span>{{ t('serverinfo', 'Active users') }}</span>
			</div>
		</template>

		<div :class="$style.bars">
			<div
				v-for="bucket in buckets"
				:key="bucket.key"
				:class="$style.row">
				<div :class="$style.label">{{ bucket.label }}</div>
				<div :class="$style.track">
					<div
						:class="$style.fill"
						:style="{ width: `${(bucket.count / maxCount) * 100}%` }" />
				</div>
				<div :class="$style.count">{{ bucket.count.toLocaleString() }}</div>
				<div :class="$style.pct">{{ percentOfAll(bucket.count) }}%</div>
			</div>
		</div>

		<div :class="$style.total">
			{{ t('serverinfo', 'Total registered users') }}
			<strong>{{ totalUsers.toLocaleString() }}</strong>
		</div>
	</SectionCard>
</template>

<style module lang="scss">
.bars {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.row {
	display: grid;
	grid-template-columns: 60px 1fr 50px 50px;
	gap: 10px;
	align-items: center;
	font-size: 0.85em;
}

.label {
	color: var(--color-text-maxcontrast);
}

.track {
	height: 8px;
	border-radius: 999px;
	background-color: var(--color-background-darker);
	overflow: hidden;
}

.fill {
	height: 100%;
	background: linear-gradient(90deg,
		var(--color-primary-element),
		color-mix(in srgb, var(--color-primary-element) 60%, transparent));
	border-radius: 999px;
	transition: width 0.5s cubic-bezier(0.4, 0, 0.2, 1);
}

.count {
	font-variant-numeric: tabular-nums;
	font-weight: 600;
	color: var(--color-main-text);
	text-align: right;
}

.pct {
	font-variant-numeric: tabular-nums;
	color: var(--color-text-maxcontrast);
	text-align: right;
	font-size: 0.92em;
}

.total {
	display: flex;
	justify-content: space-between;
	align-items: baseline;
	color: var(--color-text-maxcontrast);
	padding-top: 6px;
	border-top: 1px solid var(--color-border);
	font-size: 0.85em;

	strong {
		color: var(--color-main-text);
		font-variant-numeric: tabular-nums;
		font-size: 1.05em;
	}
}
</style>
