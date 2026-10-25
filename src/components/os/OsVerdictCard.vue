<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section class="os-verdict" :class="`os-verdict--${level}`" :aria-label="t('serverinfo', 'Operating system summary')">
		<div class="os-verdict__badge" aria-hidden="true">
			<component :is="badgeIcon" :size="36" />
		</div>

		<div class="os-verdict__body">
			<h2 class="os-verdict__headline">
				{{ headline(level) }}
			</h2>
			<p class="os-verdict__system">
				<span v-if="status.release">{{ status.release.prettyName }}</span>
				<span class="os-verdict__dot" aria-hidden="true">·</span>
				<span>{{ t('serverinfo', 'Kernel {kernel}', { kernel: status.kernel }, PLAIN) }}</span>
				<span class="os-verdict__dot" aria-hidden="true">·</span>
				<span>{{ environmentLabel(status.environment) }}</span>
			</p>

			<ul v-if="concerns.length" class="os-verdict__chips">
				<li
					v-for="concern in concerns"
					:key="concern.id"
					class="os-verdict__chip"
					:class="`os-verdict__chip--${concern.level}`">
					<OsLevelIcon :level="concern.level" :size="16" />
					{{ concern.title }}
				</li>
			</ul>

			<p class="os-verdict__meta">
				<!-- TRANSLATORS: Followed by a relative time such as "12 minutes ago" -->
				{{ t('serverinfo', 'Checked') }}
				<NcDateTime :timestamp="status.collectedAt * 1000" ignoreSeconds />
				<template v-if="status.helper.state === 'ok' && status.helper.generatedAt">
					<span class="os-verdict__dot" aria-hidden="true">·</span>
					<!-- TRANSLATORS: Followed by a relative time; the root helper is an optional script run by root -->
					{{ t('serverinfo', 'Root helper report from') }}
					<NcDateTime :timestamp="status.helper.generatedAt * 1000" ignoreSeconds />
				</template>
			</p>
		</div>

		<div class="os-verdict__actions">
			<NcButton :disabled="refreshing || !canRefresh" @click="$emit('refresh')">
				<template #icon>
					<NcLoadingIcon v-if="refreshing" :size="20" />
					<Refresh v-else :size="20" />
				</template>
				<!-- TRANSLATORS: Button that makes the server check its operating system again now -->
				{{ refreshing ? t('serverinfo', 'Checking…') : t('serverinfo', 'Check now') }}
			</NcButton>
			<NcButton
				variant="tertiary"
				:title="t('serverinfo', 'Alert settings')"
				:aria-label="t('serverinfo', 'Alert settings')"
				@click="$emit('settings')">
				<template #icon>
					<Cog :size="20" />
				</template>
			</NcButton>
		</div>
	</section>
</template>

<script setup lang="ts">
import type { OsConcern, OsLevel, OsStatus } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTime from '@nextcloud/vue/components/NcDateTime'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import Cog from 'vue-material-design-icons/Cog.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import ShieldAlert from 'vue-material-design-icons/ShieldAlert.vue'
import ShieldCheck from 'vue-material-design-icons/ShieldCheck.vue'
import ShieldOff from 'vue-material-design-icons/ShieldOffOutline.vue'
import OsLevelIcon from './OsLevelIcon.vue'
import { environmentLabel, headline, PLAIN } from '../../osUtils.ts'

const props = defineProps<{
	level: OsLevel
	status: OsStatus
	concerns: OsConcern[]
	refreshing: boolean
	/** False when background jobs do not run from system cron, which runs the check */
	canRefresh: boolean
}>()

defineEmits<{ refresh: [], settings: [] }>()

const badgeIcon = computed(() => {
	switch (props.level) {
		case 'ok':
		case 'info':
			return ShieldCheck
		case 'unknown':
			return ShieldOff
		default:
			return ShieldAlert
	}
})
</script>

<style scoped>
.os-verdict {
	display: flex;
	align-items: flex-start;
	gap: 20px;
	padding: 20px 24px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-container-large, var(--border-radius-large));
	background: var(--color-main-background);
	box-shadow: 0 1px 4px var(--color-box-shadow);
}

.os-verdict__badge {
	display: flex;
	align-items: center;
	justify-content: center;
	flex: 0 0 auto;
	inline-size: 64px;
	block-size: 64px;
	border-radius: 50%;
	color: var(--color-main-background);
	background: var(--color-text-maxcontrast);
}

.os-verdict--ok .os-verdict__badge {
	background: var(--color-success-text, var(--color-success));
}

/* Something is pending, just not long enough to warn about: not green. */
.os-verdict--info .os-verdict__badge {
	background: var(--color-info-text, var(--color-primary-element));
}

.os-verdict--warning .os-verdict__badge {
	background: var(--color-warning-text, var(--color-warning));
}

.os-verdict--error .os-verdict__badge {
	background: var(--color-error-text, var(--color-error));
}

.os-verdict__body {
	flex: 1 1 auto;
	min-inline-size: 0;
}

/* settings.scss styles h2 inside .section with a margin we do not want here. */
.os-verdict__headline {
	margin: 0;
	font-size: 1.4rem;
	font-weight: bold;
	line-height: 1.3;
}

.os-verdict__system,
.os-verdict__meta {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 4px 6px;
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
}

.os-verdict__meta {
	margin-block-start: 12px;
	font-size: 0.9rem;
}

.os-verdict__chips {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	margin: 12px 0 0;
	padding: 0;
	list-style: none;
}

.os-verdict__chip {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 2px 12px 2px 8px;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
	font-size: 0.9rem;
}

.os-verdict__chip--error {
	background: var(--color-error-hover, var(--color-background-dark));
}

.os-verdict__chip--warning {
	background: var(--color-warning-hover, var(--color-background-dark));
}

.os-verdict__actions {
	display: flex;
	align-items: center;
	gap: 4px;
	flex: 0 0 auto;
}

@media (width <= 900px) {
	.os-verdict {
		flex-wrap: wrap;
	}

	.os-verdict__actions {
		inline-size: 100%;
	}
}
</style>
