<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="database-view">
		<div class="section">
			<DatabaseLiveCharts :active="active" />

			<div class="database-view__heading">
				<SectionHeading :icon="Database" :title="t('serverinfo', 'Database checks')" />
				<NcButton
					variant="tertiary"
					:title="t('serverinfo', 'Database connection settings')"
					:aria-label="t('serverinfo', 'Database connection settings')"
					@click="settingsOpen = true">
					<template #icon>
						<Cog :size="20" />
					</template>
				</NcButton>
			</div>

			<SectionSkeleton v-if="loading" />

			<NcNoteCard v-else-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<NcNoteCard v-else-if="check && !check.supported" type="info">
				<template v-if="check.reason === 'flavour'">
					<!-- TRANSLATORS: Shown when the database is neither MySQL, MariaDB nor PostgreSQL -->
					{{ t('serverinfo', 'These checks only cover MySQL, MariaDB and PostgreSQL. This server uses a different database.') }}
				</template>
				<template v-else>
					{{ check.message }}
				</template>
			</NcNoteCard>

			<template v-else-if="check?.supported">
				<nav class="database-view__filters" :aria-label="t('serverinfo', 'Filter checks by status')">
					<button
						v-for="option in filters"
						:key="option.id"
						type="button"
						class="database-view__filter"
						:class="{ 'database-view__filter--active': statusFilter === option.id }"
						:aria-pressed="statusFilter === option.id"
						@click="statusFilter = option.id">
						{{ option.label }}
						<span class="database-view__filter-count">{{ option.count }}</span>
					</button>
				</nav>

				<section v-for="group in groups" :key="group.category" class="database-view__group">
					<h3 class="database-view__group-title">
						{{ group.category }}
						<span v-if="group.failCount > 0" class="database-view__group-fails">
							{{ n('serverinfo', '%n failing', '%n failing', group.failCount) }}
						</span>
					</h3>
					<div class="database-view__cards">
						<DatabaseRuleCard
							v-for="rule in group.rules"
							:key="rule.id"
							:rule="rule"
							@snippet="snippetRule = $event" />
					</div>
				</section>

				<p v-if="groups.length === 0" class="database-view__empty">
					<!-- TRANSLATORS: Shown when the chosen filter matches none of the database checks -->
					{{ t('serverinfo', 'No checks match this filter.') }}
				</p>
			</template>
		</div>

		<DatabaseSnippetDialog v-if="snippetRule" :rule="snippetRule" @close="snippetRule = null" />
		<DatabaseSettingsDialog
			v-if="settingsOpen && override"
			:override="override"
			@close="settingsOpen = false"
			@saved="reload" />
	</div>
</template>

<script setup lang="ts">
import type { DatabaseCheck, DatabaseOverride, DatabaseRule } from '../types.ts'

import axios from '@nextcloud/axios'
import { n, t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { computed, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import Cog from 'vue-material-design-icons/Cog.vue'
import Database from 'vue-material-design-icons/Database.vue'
import DatabaseLiveCharts from '../components/DatabaseLiveCharts.vue'
import DatabaseRuleCard from '../components/DatabaseRuleCard.vue'
import DatabaseSettingsDialog from '../components/DatabaseSettingsDialog.vue'
import DatabaseSnippetDialog from '../components/DatabaseSnippetDialog.vue'
import SectionHeading from '../components/SectionHeading.vue'
import SectionSkeleton from '../components/SectionSkeleton.vue'

const props = defineProps<{ active: boolean }>()

const check = ref<DatabaseCheck | null>(null)
const override = ref<DatabaseOverride | null>(null)
const loading = ref(false)
const error = ref('')
const settingsOpen = ref(false)
const snippetRule = ref<DatabaseRule | null>(null)
const statusFilter = ref<'all' | 'fail' | 'ok' | 'skipped'>('fail')

const rules = computed<DatabaseRule[]>(() => (check.value?.supported ? check.value.results : []))

const filters = computed(() => [
	// TRANSLATORS: Filter showing only the database checks that found a problem
	{ id: 'fail' as const, label: t('serverinfo', 'Failing'), count: rules.value.filter((r) => r.status === 'fail').length },
	// TRANSLATORS: Filter showing only the database checks that found nothing wrong
	{ id: 'ok' as const, label: t('serverinfo', 'Passing'), count: rules.value.filter((r) => r.status === 'ok').length },
	// TRANSLATORS: Filter showing only the database checks that could not run
	{ id: 'skipped' as const, label: t('serverinfo', 'Skipped'), count: rules.value.filter((r) => r.status === 'skipped').length },
	// TRANSLATORS: Filter showing every database check regardless of outcome
	{ id: 'all' as const, label: t('serverinfo', 'All'), count: rules.value.length },
])

const groups = computed(() => {
	const visible = statusFilter.value === 'all'
		? rules.value
		: rules.value.filter((rule) => rule.status === statusFilter.value)

	const byCategory = new Map<string, DatabaseRule[]>()
	for (const rule of visible) {
		const bucket = byCategory.get(rule.category)
		if (bucket) {
			bucket.push(rule)
		} else {
			byCategory.set(rule.category, [rule])
		}
	}

	return [...byCategory.entries()].map(([category, categoryRules]) => ({
		category,
		rules: categoryRules,
		failCount: categoryRules.filter((rule) => rule.status === 'fail').length,
	}))
})

/**
 * Runs the checks and reads back the stored connection.
 *
 * A snapshot plus the rule evaluations queries the database, so this is not
 * something to repeat on a timer: it runs once, when the page is first opened.
 */
async function reload() {
	loading.value = true
	error.value = ''
	try {
		const [checkResponse, settingsResponse] = await Promise.all([
			axios.get(generateUrl('/apps/serverinfo/database/check')),
			axios.get(generateUrl('/apps/serverinfo/database/settings')),
		])
		check.value = checkResponse.data
		override.value = settingsResponse.data.override
	} catch (caught) {
		// TRANSLATORS: Shown when the database checks could not be run at all
		error.value = t('serverinfo', 'The database checks could not be run.') + ' ' + String(caught)
	} finally {
		loading.value = false
	}
}

// The page is kept mounted so that switching away and back does not throw away
// the results, so the trigger is the first time it actually becomes visible.
watch(() => props.active, (active) => {
	if (active && check.value === null && !loading.value) {
		reload()
	}
}, { immediate: true })
</script>

<style scoped>
.database-view__heading {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
}

.database-view__filters {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin: 12px 0;
	/* Sits the pills on a sunken strip spanning the page, so the group reads as
	   one control rather than four loose buttons. */
	padding: 4px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius-large);
}

.database-view__filter {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	/* On the sunken strip an outlined white pill reads as a raised chip, so the
	   inactive ones stay flat and only the selected one is filled. */
	border: none;
	border-radius: var(--border-radius-pill);
	background: transparent;
	color: var(--color-main-text);
	padding: 4px 14px;
}

.database-view__filter:hover {
	background: var(--color-background-hover);
}

.database-view__filter--active {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.database-view__filter-count {
	color: inherit;
	opacity: 0.75;
}

.database-view__group {
	margin-block-start: 20px;
}

.database-view__group-title {
	display: flex;
	align-items: center;
	gap: 10px;
	font-weight: bold;
	margin: 0 0 8px;
}

.database-view__group-fails {
	font-weight: normal;
	font-size: 0.85rem;
	color: var(--color-error-text, var(--color-error));
}

.database-view__cards {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.database-view__empty {
	color: var(--color-text-maxcontrast);
}
</style>
