<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="php-performance">
		<SectionHeading :icon="Speedometer" :title="t('serverinfo', 'PHP performance')" />

		<SectionSkeleton v-if="loading" />

		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<template v-else-if="check">
			<NcNoteCard v-if="findings.length === 0" type="success">
				<!-- TRANSLATORS: Shown when none of the PHP-FPM, OPcache and memory checks found a bottleneck -->
				{{ t('serverinfo', 'No bottleneck found: PHP-FPM, OPcache and the memory limit look fine.') }}
			</NcNoteCard>

			<div v-else class="php-performance__cards">
				<DatabaseRuleCard
					v-for="rule in findings"
					:key="rule.id"
					:rule="rule"
					@snippet="snippetRule = $event" />
			</div>

			<details v-if="others.length > 0" class="php-performance__others">
				<summary>
					<!-- TRANSLATORS: Expands the PHP checks that found nothing or could not run -->
					{{ n('serverinfo', 'Show %n passing or skipped check', 'Show %n passing or skipped checks', others.length) }}
				</summary>
				<div class="php-performance__cards">
					<DatabaseRuleCard v-for="rule in others" :key="rule.id" :rule="rule" />
				</div>
			</details>
		</template>

		<DatabaseSnippetDialog
			v-if="snippetRule"
			:rule="snippetRule"
			:section="check?.pool ?? undefined"
			@close="snippetRule = null" />
	</div>
</template>

<script setup lang="ts">
import type { DatabaseRule, PhpCheck } from '../types.ts'

import axios from '@nextcloud/axios'
import { n, t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { computed, onMounted, ref } from 'vue'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import Speedometer from 'vue-material-design-icons/Speedometer.vue'
import DatabaseRuleCard from './DatabaseRuleCard.vue'
import DatabaseSnippetDialog from './DatabaseSnippetDialog.vue'
import SectionHeading from './SectionHeading.vue'
import SectionSkeleton from './SectionSkeleton.vue'

const SEVERITY_ORDER: Record<DatabaseRule['severity'], number> = { alert: 0, warning: 1, notice: 2 }

const check = ref<PhpCheck | null>(null)
const loading = ref(true)
const error = ref('')
const snippetRule = ref<DatabaseRule | null>(null)

/** Failing rules, the most severe first, so that hints come last. */
const findings = computed(() => (check.value?.results ?? [])
	.filter((rule) => rule.status === 'fail')
	.sort((a, b) => SEVERITY_ORDER[a.severity] - SEVERITY_ORDER[b.severity]))

const others = computed(() => (check.value?.results ?? []).filter((rule) => rule.status !== 'fail'))

/**
 * Runs the checks once. They describe the PHP that serves this request,
 * which is why the page asks for them itself rather than a background job.
 */
async function load() {
	loading.value = true
	error.value = ''
	try {
		const response = await axios.get<PhpCheck>(generateUrl('/apps/serverinfo/php/check'))
		check.value = response.data
	} catch (caught) {
		// TRANSLATORS: Shown when the PHP performance checks could not be run at all
		error.value = t('serverinfo', 'The PHP performance checks could not be run.') + ' ' + String(caught)
	} finally {
		loading.value = false
	}
}

onMounted(load)
</script>

<style scoped>
.php-performance__cards {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.php-performance__others {
	margin-block-start: 12px;
}

.php-performance__others summary {
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	margin-block-end: 10px;
}
</style>
