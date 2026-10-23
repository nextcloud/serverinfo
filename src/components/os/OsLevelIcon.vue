<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<span class="os-level-icon" :class="`os-level-icon--${level}`">
		<component :is="icon" :size="size" />
		<!-- The colour and shape carry the level, so it is spelled out for assistive technology -->
		<span class="hidden-visually">{{ label }}</span>
	</span>
</template>

<script setup lang="ts">
import type { OsLevel } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import CloseCircle from 'vue-material-design-icons/CloseCircle.vue'
import HelpCircle from 'vue-material-design-icons/HelpCircle.vue'
import Information from 'vue-material-design-icons/Information.vue'

const props = withDefaults(defineProps<{
	level: OsLevel
	size?: number
}>(), { size: 20 })

const icon = computed(() => ({
	ok: CheckCircle,
	info: Information,
	warning: AlertCircle,
	error: CloseCircle,
	unknown: HelpCircle,
})[props.level])

const label = computed(() => ({
	// TRANSLATORS: Read out for a status icon: nothing to do
	ok: t('serverinfo', 'OK'),
	// TRANSLATORS: Read out for a status icon: something to know, no action needed yet
	info: t('serverinfo', 'Information'),
	// TRANSLATORS: Read out for a status icon: something should be done soon
	warning: t('serverinfo', 'Warning'),
	// TRANSLATORS: Read out for a status icon: something needs action now
	error: t('serverinfo', 'Error'),
	// TRANSLATORS: Read out for a status icon: could not be determined
	unknown: t('serverinfo', 'Unknown'),
})[props.level])
</script>

<style scoped>
.os-level-icon {
	display: inline-flex;
	flex: 0 0 auto;
}

/* The -text variants: --color-error/-warning/-success are pale fills in most themes. */
.os-level-icon--ok { color: var(--color-success-text, var(--color-success)); }
.os-level-icon--info { color: var(--color-info-text, var(--color-primary-element)); }
.os-level-icon--warning { color: var(--color-warning-text, var(--color-warning)); }
.os-level-icon--error { color: var(--color-error-text, var(--color-error)); }
.os-level-icon--unknown { color: var(--color-text-maxcontrast); }
</style>
