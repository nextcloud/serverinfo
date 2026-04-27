<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import IconLanguagePhp from 'vue-material-design-icons/LanguagePhp.vue'
import IconDatabase from 'vue-material-design-icons/Database.vue'
import IconWorker from 'vue-material-design-icons/CogOutline.vue'
import IconChevron from 'vue-material-design-icons/ChevronDown.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import SectionCard from './SectionCard.vue'
import { formatBytes } from '../composables/useFormat.ts'
import type { DatabaseInfo, FpmInfo, PhpInfo } from '../types.ts'

const props = defineProps<{
	php: PhpInfo
	fpm: FpmInfo | false
	database: DatabaseInfo
	phpinfoEnabled: boolean
	phpinfoUrl: string
}>()

const COLLAPSED_LIMIT = 30
const showAllExtensions = ref(false)

const visibleExtensions = computed(() => {
	const all = props.php.extensions ?? []
	if (showAllExtensions.value || all.length <= COLLAPSED_LIMIT) {
		return all
	}
	return all.slice(0, COLLAPSED_LIMIT)
})

const fpmRows = computed(() => {
	if (!props.fpm) {
		return []
	}
	const f = props.fpm
	return [
		{ label: t('serverinfo', 'Pool name'), value: f.pool },
		{ label: t('serverinfo', 'Pool type'), value: f['process-manager'] },
		{ label: t('serverinfo', 'Start time'), value: String(f['start-time']) },
		{ label: t('serverinfo', 'Accepted connections'), value: f['accepted-conn'].toLocaleString() },
		{ label: t('serverinfo', 'Active processes'), value: `${f['active-processes']} / ${f['total-processes']}` },
		{ label: t('serverinfo', 'Idle processes'), value: f['idle-processes'].toLocaleString() },
		{ label: t('serverinfo', 'Listen queue'), value: `${f['listen-queue']} (max ${f['max-listen-queue']})` },
		{ label: t('serverinfo', 'Slow requests'), value: f['slow-requests'].toLocaleString() },
		{ label: t('serverinfo', 'Max children reached'), value: f['max-children-reached'].toLocaleString() },
	]
})
</script>

<template>
	<div :class="$style.grid">
		<SectionCard>
			<template #header>
				<div class="title-with-icon">
					<IconLanguagePhp :size="18" />
					<span>{{ t('serverinfo', 'PHP') }}</span>
				</div>
			</template>
			<dl :class="$style.list">
				<div :class="$style.row">
					<dt>{{ t('serverinfo', 'Version') }}</dt>
					<dd>{{ php.version }}</dd>
				</div>
				<div :class="$style.row">
					<dt>{{ t('serverinfo', 'Memory limit') }}</dt>
					<dd>{{ formatBytes(php.memory_limit) }}</dd>
				</div>
				<div :class="$style.row">
					<dt>{{ t('serverinfo', 'Max execution time') }}</dt>
					<dd>{{ php.max_execution_time }} {{ t('serverinfo', 's') }}</dd>
				</div>
				<div :class="$style.row">
					<dt>{{ t('serverinfo', 'Upload max size') }}</dt>
					<dd>{{ formatBytes(php.upload_max_filesize) }}</dd>
				</div>
				<div :class="$style.row">
					<dt>{{ t('serverinfo', 'OPcache revalidate') }}</dt>
					<dd>{{ php.opcache_revalidate_freq }} {{ t('serverinfo', 's') }}</dd>
				</div>
			</dl>

			<div :class="$style.extBlock">
				<div :class="$style.extHeader">
					<span :class="$style.extLabel">
						{{ t('serverinfo', 'Loaded PHP modules') }}
					</span>
					<span v-if="php.extensions && php.extensions.length > 0" :class="$style.extCount">
						{{ php.extensions.length }}
					</span>
				</div>
				<div v-if="php.extensions && php.extensions.length > 0" :class="$style.tags">
					<span v-for="ext in visibleExtensions" :key="ext" :class="$style.tag">
						{{ ext }}
					</span>
				</div>
				<p v-else :class="$style.extEmpty">
					{{ php.extensions === null
						? t('serverinfo', 'PHP forbids enumeration of loaded modules on this server (get_loaded_extensions is disabled).')
						: t('serverinfo', 'No loaded modules reported by PHP. This is unusual — check your php.ini disable_functions setting.') }}
				</p>
				<NcButton
					v-if="php.extensions && php.extensions.length > COLLAPSED_LIMIT"
					variant="tertiary"
					@click="showAllExtensions = !showAllExtensions">
					<template #icon>
						<IconChevron :size="18" :style="showAllExtensions ? 'transform: rotate(180deg)' : ''" />
					</template>
					{{ showAllExtensions
						? t('serverinfo', 'Show fewer')
						: t('serverinfo', 'Show all {count}', { count: php.extensions.length }) }}
				</NcButton>
			</div>

			<a
				v-if="phpinfoEnabled"
				:href="phpinfoUrl"
				target="_blank"
				rel="noopener noreferrer"
				:class="$style.link">
				{{ t('serverinfo', 'Show full phpinfo') }} →
			</a>
		</SectionCard>

		<SectionCard>
			<template #header>
				<div class="title-with-icon">
					<IconDatabase :size="18" />
					<span>{{ t('serverinfo', 'Database') }}</span>
				</div>
			</template>
			<dl :class="$style.list">
				<div :class="$style.row">
					<dt>{{ t('serverinfo', 'Type') }}</dt>
					<dd>{{ database.type }}</dd>
				</div>
				<div :class="$style.row">
					<dt>{{ t('serverinfo', 'Version') }}</dt>
					<dd>{{ database.version }}</dd>
				</div>
				<div :class="$style.row">
					<dt>{{ t('serverinfo', 'Size') }}</dt>
					<dd>{{ formatBytes(database.size) }}</dd>
				</div>
			</dl>
		</SectionCard>

		<SectionCard v-if="fpm">
			<template #header>
				<div class="title-with-icon">
					<IconWorker :size="18" />
					<span>{{ t('serverinfo', 'FPM worker pool') }}</span>
				</div>
			</template>
			<dl :class="$style.list">
				<div v-for="row in fpmRows" :key="row.label" :class="$style.row">
					<dt>{{ row.label }}</dt>
					<dd>{{ row.value }}</dd>
				</div>
			</dl>
		</SectionCard>
	</div>
</template>

<style module lang="scss">
.grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
	gap: 12px;
}

.list {
	margin: 0;
}

.row {
	display: grid;
	grid-template-columns: minmax(120px, 40%) 1fr;
	gap: 10px;
	padding: 5px 0;
	border-bottom: 1px solid var(--color-border);
	font-size: 0.85em;

	&:last-child {
		border-bottom: 0;
	}

	dt {
		color: var(--color-text-maxcontrast);
	}

	dd {
		margin: 0;
		color: var(--color-main-text);
		word-break: break-word;
		font-variant-numeric: tabular-nums;
		font-weight: 500;
	}
}

.extBlock {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding-top: 10px;
	margin-top: 4px;
	border-top: 1px solid var(--color-border);
}

.extHeader {
	display: flex;
	align-items: center;
	gap: 8px;
}

.extLabel {
	color: var(--color-main-text);
	font-size: 0.85em;
	font-weight: 600;
}

.extCount {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 22px;
	padding: 1px 7px;
	border-radius: 999px;
	background-color: color-mix(in srgb, var(--color-primary-element) 18%, transparent);
	color: var(--color-primary-element);
	font-size: 0.75em;
	font-weight: 700;
	font-variant-numeric: tabular-nums;
}

.muted {
	color: var(--color-text-maxcontrast);
	text-transform: none;
	font-size: 0.95em;
	letter-spacing: 0;
	font-weight: 400;
}

.tags {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
}

.extEmpty {
	margin: 0;
	padding: 8px 10px;
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
	color: var(--color-text-maxcontrast);
	font-size: 0.82em;
	line-height: 1.4;
}

.tag {
	display: inline-block;
	padding: 2px 9px;
	border-radius: 999px;
	background-color: var(--color-background-hover);
	color: var(--color-main-text);
	font-size: 0.82em;
	font-family: var(--font-face-monospace, monospace);
	border: 1px solid var(--color-border);
}

.link {
	color: var(--color-primary-element);
	text-decoration: none;
	font-size: 0.85em;

	&:hover {
		text-decoration: underline;
	}
}
</style>
