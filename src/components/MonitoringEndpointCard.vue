<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import IconChartBox from 'vue-material-design-icons/ChartBoxOutline.vue'
import IconClipboard from 'vue-material-design-icons/ContentCopy.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import SectionCard from './SectionCard.vue'

const props = defineProps<{
	endpoint: string
}>()

const formatJson = ref(false)
const skipApps = ref(true)
const skipUpdate = ref(true)

const finalUrl = computed(() => {
	const params: string[] = []
	if (formatJson.value) {
		params.push('format=json')
	}
	if (skipApps.value) {
		params.push('skipApps=true')
	}
	if (skipUpdate.value) {
		params.push('skipUpdate=true')
	}
	if (params.length === 0) {
		return props.endpoint
	}
	return `${props.endpoint}?${params.join('&')}`
})

const copy = async () => {
	try {
		await navigator.clipboard.writeText(finalUrl.value)
		showSuccess(t('serverinfo', 'Endpoint URL copied to clipboard'))
	} catch {
		showError(t('serverinfo', 'Could not copy URL'))
	}
}
</script>

<template>
	<SectionCard>
		<template #header>
			<div class="title-with-icon">
				<IconChartBox :size="18" />
				<span>{{ t('serverinfo', 'External monitoring tool') }}</span>
			</div>
		</template>

		<p :class="$style.description">
			{{ t('serverinfo', 'Use this endpoint to connect an external monitoring tool such as Prometheus or a custom dashboard.') }}
		</p>

		<div :class="$style.urlRow">
			<input
				:value="finalUrl"
				readonly
				:class="$style.urlInput"
				@focus="($event.target as HTMLInputElement).select()">
			<NcButton variant="secondary" @click="copy">
				<template #icon>
					<IconClipboard :size="18" />
				</template>
				{{ t('serverinfo', 'Copy') }}
			</NcButton>
		</div>

		<div :class="$style.options">
			<NcCheckboxRadioSwitch
				v-model="formatJson"
				type="switch">
				{{ t('serverinfo', 'Output in JSON') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch
				v-model="skipApps"
				type="switch">
				{{ t('serverinfo', 'Skip apps section') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch
				v-model="skipUpdate"
				type="switch">
				{{ t('serverinfo', 'Skip server update check') }}
			</NcCheckboxRadioSwitch>
		</div>

		<details :class="$style.details">
			<summary>{{ t('serverinfo', 'Authenticate with an access token') }}</summary>
			<p>
				{{ t('serverinfo', 'Generate a token then set it via:') }}
			</p>
			<pre :class="$style.code">occ config:app:set serverinfo token --value yourtoken</pre>
			<p>
				{{ t('serverinfo', 'Pass the token using the "NC-Token" header when querying the URL above.') }}
			</p>
		</details>
	</SectionCard>
</template>

<style module lang="scss">
.description {
	color: var(--color-text-maxcontrast);
	margin: 0;
	font-size: 0.85em;
}

.urlRow {
	display: flex;
	gap: 6px;
	flex-wrap: wrap;
	align-items: center;
}

.urlInput {
	flex: 1;
	min-width: 200px;
	font-family: var(--font-face-monospace, monospace);
	font-size: 0.62em !important;
	padding: 2px 6px !important;
	height: 20px !important;
	min-height: 20px !important;
	line-height: 1.1 !important;
}

.options {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.details {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;

	summary {
		cursor: pointer;
		padding: 2px 0;
		color: var(--color-main-text);
	}

	p {
		margin: 6px 0;
	}
}

.code {
	margin: 6px 0;
	padding: 8px 10px;
	background-color: var(--color-background-dark);
	border-radius: var(--border-radius);
	font-family: var(--font-face-monospace, monospace);
	font-size: 0.85em;
	overflow-x: auto;
}
</style>
