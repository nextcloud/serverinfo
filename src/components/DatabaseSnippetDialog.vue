<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog
		v-if="rule"
		:name="t('serverinfo', 'Configuration snippet')"
		:open="true"
		size="normal"
		@update:open="$emit('close')">
		<div class="snippet">
			<p>
				{{ instruction }}
			</p>

			<pre class="snippet__code"><code>{{ snippet }}</code></pre>

			<NcButton variant="primary" @click="copy">
				<template #icon>
					<Check v-if="copied" :size="20" />
					<ContentCopy v-else :size="20" />
				</template>
				<!-- TRANSLATORS: Button that copies the configuration snippet -->
				{{ copied ? t('serverinfo', 'Copied') : t('serverinfo', 'Copy to clipboard') }}
			</NcButton>

			<p v-if="copyFailed" class="snippet__note">
				<!-- TRANSLATORS: Shown when the browser refused clipboard access, which it does over plain HTTP -->
				{{ t('serverinfo', 'Could not copy to clipboard. Select the text above manually instead.') }}
			</p>
			<p v-if="rule.apply?.note" class="snippet__note">
				{{ rule.apply.note }}
			</p>
		</div>

		<template #actions>
			<NcButton variant="tertiary" @click="$emit('close')">
				{{ t('serverinfo', 'Close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script setup lang="ts">
import type { DatabaseRule } from '../types.ts'

import { t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import Check from 'vue-material-design-icons/Check.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'

const props = defineProps<{
	rule: DatabaseRule | null
	/** The PHP-FPM pool a php-fpm.conf directive belongs to */
	section?: string
}>()

defineEmits<{ close: [] }>()

const copied = ref(false)
const copyFailed = ref(false)

const pool = computed(() => props.section || 'www')

const instruction = computed(() => {
	const file = props.rule?.apply?.configFile ?? ''
	switch (file) {
		case 'php.ini':
			// TRANSLATORS: Instruction above a block of PHP configuration text
			return t('serverinfo', 'Add the following to the php.ini that PHP-FPM or the web server loads, then restart it.')
		case 'php-fpm.conf':
			// TRANSLATORS: Instruction above a block of PHP-FPM configuration text. {pool} is the name of a PHP-FPM pool such as www.
			return t('serverinfo', 'Add the following to the configuration of the PHP-FPM pool {pool}, then reload PHP-FPM.', { pool: pool.value })
		default:
			// TRANSLATORS: Instruction above a block of config text. {file} is a file name such as my.cnf.
			return t('serverinfo', 'Add the following to your {file}, then restart the database.', { file })
	}
})

// Postgres and php.ini take bare directives; MySQL and MariaDB need the
// [mysqld] section header and PHP-FPM the pool's, so that pasting the block
// into a fresh file is enough on its own. PHP-FPM reopens a pool section
// that appears twice, so the header is also safe at the end of the pool's file.
const snippet = computed(() => {
	const apply = props.rule?.apply
	if (!apply) {
		return ''
	}

	const directive = `${apply.configKey} = ${apply.recommendedValue}`

	switch (apply.configFile) {
		case 'postgresql.conf':
			return `# ${apply.configFile}\n${directive}`
		case 'php.ini':
			return `; ${apply.configFile}\n${directive}`
		case 'php-fpm.conf':
			return `; ${apply.configFile}\n[${pool.value}]\n${directive}`
		default:
			return `# ${apply.configFile}\n[mysqld]\n${directive}`
	}
})

/**
 * Copies the snippet, falling back to a message when the browser refuses
 * clipboard access (it does over plain HTTP).
 */
async function copy() {
	try {
		await navigator.clipboard.writeText(snippet.value)
		copied.value = true
		copyFailed.value = false
		setTimeout(() => {
			copied.value = false
		}, 1800)
	} catch {
		copyFailed.value = true
	}
}
</script>

<style scoped>
.snippet {
	display: flex;
	flex-direction: column;
	gap: 12px;
	align-items: flex-start;
}

.snippet__code {
	inline-size: 100%;
	overflow-x: auto;
	padding: 12px;
	border-radius: var(--border-radius);
	background: var(--color-background-dark);
}

.snippet__note {
	color: var(--color-text-maxcontrast);
	margin: 0;
}
</style>
