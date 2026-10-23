<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="os-command">
		<span v-if="label" class="os-command__label">{{ label }}</span>
		<div class="os-command__box">
			<code class="os-command__code">{{ command }}</code>
			<NcButton
				variant="tertiary"
				:aria-label="copied ? t('serverinfo', 'Copied') : t('serverinfo', 'Copy command')"
				:title="copied ? t('serverinfo', 'Copied') : t('serverinfo', 'Copy command')"
				@click="copy">
				<template #icon>
					<Check v-if="copied" :size="20" />
					<ContentCopy v-else :size="20" />
				</template>
			</NcButton>
		</div>
		<p v-if="copyFailed" class="os-command__note">
			<!-- TRANSLATORS: Shown when the browser refused clipboard access, which it does over plain HTTP -->
			{{ t('serverinfo', 'Could not copy to clipboard. Select the command manually instead.') }}
		</p>
	</div>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import Check from 'vue-material-design-icons/Check.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'

const props = defineProps<{
	command: string
	label?: string
}>()

const copied = ref(false)
const copyFailed = ref(false)

/**
 * Copies the command, falling back to a hint when the browser refuses
 * clipboard access (it does over plain HTTP).
 */
async function copy() {
	try {
		await navigator.clipboard.writeText(props.command)
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
.os-command {
	display: flex;
	flex-direction: column;
	gap: 4px;
	min-inline-size: 0;
}

.os-command__label {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.os-command__box {
	display: flex;
	align-items: center;
	gap: 4px;
	padding-inline-start: 12px;
	border-radius: var(--border-radius-large);
	background: var(--color-background-dark);
}

.os-command__code {
	flex: 1 1 auto;
	min-inline-size: 0;
	/* Wrapped rather than scrolled, so a long command can be read whole. */
	white-space: pre-wrap;
	overflow-wrap: anywhere;
	font-family: var(--font-face-monospace, monospace);
	font-size: 0.9rem;
	padding-block: 6px;
}

.os-command__note {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}
</style>
