<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import IconClock from 'vue-material-design-icons/ClockOutline.vue'
import IconAjax from 'vue-material-design-icons/Web.vue'
import IconWebcron from 'vue-material-design-icons/EarthArrowRight.vue'
import IconCron from 'vue-material-design-icons/CalendarClockOutline.vue'
import IconArrow from 'vue-material-design-icons/OpenInNew.vue'
import IconCog from 'vue-material-design-icons/CogOutline.vue'
import IconSetupCheck from 'vue-material-design-icons/Stethoscope.vue'
import QuickActions from './QuickActions.vue'
import SectionCard from './SectionCard.vue'
import StatusPill from './StatusPill.vue'
import type { CronStatus } from '../types.ts'

const props = defineProps<{
	cron: CronStatus
	settingsUrl: string
	overviewUrl: string
}>()

const modeMeta = computed(() => {
	switch (props.cron.mode) {
	case 'cron':
		return {
			icon: IconCron,
			label: t('serverinfo', 'System cron'),
			hint: t('serverinfo', 'Recommended. Runs every 5 minutes.'),
		}
	case 'webcron':
		return {
			icon: IconWebcron,
			label: t('serverinfo', 'Webcron'),
			hint: t('serverinfo', 'External service triggers cron.php periodically.'),
		}
	default:
		return {
			icon: IconAjax,
			label: t('serverinfo', 'AJAX'),
			hint: t('serverinfo', 'Triggered when users access pages. Not recommended for active instances.'),
		}
	}
})

const statusLabel = computed(() => {
	switch (props.cron.status) {
	case 'critical':
		return t('serverinfo', 'Cron not running')
	case 'warning':
		return t('serverinfo', 'Cron is delayed')
	default:
		return t('serverinfo', 'Healthy')
	}
})

const formatRelative = (seconds: number): string => {
	if (seconds < 0) {
		return t('serverinfo', 'never')
	}
	if (seconds < 60) {
		return t('serverinfo', '{n} seconds ago', { n: seconds })
	}
	if (seconds < 3600) {
		return t('serverinfo', '{n} minutes ago', { n: Math.round(seconds / 60) })
	}
	if (seconds < 86400) {
		return t('serverinfo', '{n} hours ago', { n: Math.round(seconds / 3600) })
	}
	return t('serverinfo', '{n} days ago', { n: Math.round(seconds / 86400) })
}

const lastRunRelative = computed(() => formatRelative(props.cron.secondsSince))

const lastRunAbsolute = computed(() => {
	if (props.cron.lastRun <= 0) {
		return ''
	}
	try {
		return new Intl.DateTimeFormat(undefined, {
			dateStyle: 'medium',
			timeStyle: 'short',
		}).format(new Date(props.cron.lastRun * 1000))
	} catch {
		return ''
	}
})
</script>

<template>
	<SectionCard>
		<template #header>
			<div class="title-with-icon">
				<IconClock :size="18" />
				<span>{{ t('serverinfo', 'Background jobs') }}</span>
			</div>
		</template>
		<template #actions>
			<StatusPill :status="cron.status" :label="statusLabel" />
		</template>

		<div :class="$style.body">
			<div :class="$style.modeBlock">
				<div :class="$style.modeIcon">
					<component :is="modeMeta.icon" :size="22" />
				</div>
				<div :class="$style.modeText">
					<div :class="$style.modeLabel">{{ t('serverinfo', 'Mode') }}</div>
					<div :class="$style.modeName">{{ modeMeta.label }}</div>
					<div :class="$style.modeHint">{{ modeMeta.hint }}</div>
				</div>
			</div>

			<div :class="$style.lastRun">
				<div :class="$style.lrLabel">{{ t('serverinfo', 'Last run') }}</div>
				<div :class="$style.lrValue">{{ lastRunRelative }}</div>
				<div v-if="lastRunAbsolute" :class="$style.lrAbs">{{ lastRunAbsolute }}</div>
			</div>
		</div>

		<QuickActions :actions="[
			{ id: 'settings', label: t('serverinfo', 'Configure'), icon: IconCog, href: settingsUrl },
			{ id: 'overview', label: t('serverinfo', 'Setup checks'), icon: IconSetupCheck, href: overviewUrl },
		]" />

		<a :href="settingsUrl" :class="$style.link" hidden>
			{{ t('serverinfo', 'Configure background jobs') }}
			<IconArrow :size="13" />
		</a>
	</SectionCard>
</template>

<style module lang="scss">
.body {
	display: grid;
	grid-template-columns: 1fr auto;
	gap: 16px;
	align-items: center;
}

.modeBlock {
	display: flex;
	align-items: center;
	gap: 12px;
	min-width: 0;
}

.modeIcon {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 38px;
	height: 38px;
	border-radius: 10px;
	background-color: color-mix(in srgb, var(--color-primary-element) 14%, transparent);
	color: var(--color-primary-element);
	flex-shrink: 0;
}

.modeText {
	min-width: 0;
}

.modeLabel {
	font-size: 0.7em;
	text-transform: uppercase;
	letter-spacing: 0.06em;
	color: var(--color-text-maxcontrast);
	font-weight: 600;
}

.modeName {
	font-size: 1em;
	font-weight: 700;
	color: var(--color-main-text);
	line-height: 1.2;
}

.modeHint {
	font-size: 0.78em;
	color: var(--color-text-maxcontrast);
	margin-top: 2px;
}

.lastRun {
	text-align: end;
	display: flex;
	flex-direction: column;
	gap: 1px;
}

.lrLabel {
	font-size: 0.7em;
	text-transform: uppercase;
	letter-spacing: 0.06em;
	color: var(--color-text-maxcontrast);
	font-weight: 600;
}

.lrValue {
	font-size: 0.95em;
	font-weight: 600;
	color: var(--color-main-text);
	font-variant-numeric: tabular-nums;
}

.lrAbs {
	font-size: 0.75em;
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
}

.link {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	color: var(--color-primary-element);
	text-decoration: none;
	font-size: 0.85em;

	&:hover {
		text-decoration: underline;
	}
}

@media (max-width: 480px) {
	.body {
		grid-template-columns: 1fr;
	}
	.lastRun {
		text-align: start;
	}
}
</style>
