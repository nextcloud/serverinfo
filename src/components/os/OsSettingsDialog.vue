<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog
		:name="t('serverinfo', 'Alert settings')"
		:open="true"
		size="normal"
		@update:open="$emit('close')">
		<form class="os-settings" @submit.prevent="save">
			<p class="os-muted">
				{{ t('serverinfo', 'How many days something may stay pending before this page, the overview in the administration settings and the dashboard warn about it. Below the warning threshold it is only listed.') }}
			</p>

			<fieldset v-for="group in groups" :key="group.title" class="os-settings__group">
				<legend>{{ group.title }}</legend>
				<div class="os-settings__fields">
					<NcTextField
						v-for="field in group.fields"
						:key="field.key"
						v-model="form[field.key]"
						type="number"
						min="0"
						:max="limits.maxDays"
						:label="field.label"
						:error="invalid === field.key"
						:helperText="invalid === field.key ? errorText : ''" />
				</div>
			</fieldset>

			<NcCheckboxRadioSwitch v-model="notify" type="switch">
				{{ t('serverinfo', 'Notify administrators when a warning or error appears') }}
			</NcCheckboxRadioSwitch>

			<NcNoteCard v-if="failed" type="error">
				{{ t('serverinfo', 'The settings could not be saved.') }}
			</NcNoteCard>
		</form>

		<template #actions>
			<NcButton variant="tertiary" @click="reset">
				{{ t('serverinfo', 'Restore defaults') }}
			</NcButton>
			<NcButton variant="primary" :disabled="busy" @click="save">
				{{ t('serverinfo', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script setup lang="ts">
import type { OsPayload, OsThresholds } from '../../types.ts'

import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import { confirmPassword } from '@nextcloud/password-confirmation'
import { generateUrl } from '@nextcloud/router'
import { computed, reactive, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { PLAIN } from '../../osUtils.ts'

import '@nextcloud/password-confirmation/style.css'

const props = defineProps<{
	thresholds: OsThresholds
	notifications: boolean
	defaults: OsPayload['settingsDefaults']
	limits: OsPayload['thresholdLimits']
}>()

const emit = defineEmits<{ close: [], saved: [payload: OsPayload] }>()

type Key = keyof OsThresholds

const form = reactive<Record<Key, string>>(Object.fromEntries(Object.entries(props.thresholds).map(([key, value]) => [key, String(value)])) as Record<Key, string>)
const notify = ref(props.notifications)
const busy = ref(false)
const failed = ref(false)
const invalid = ref<Key | null>(null)

const groups = computed<Array<{ title: string, fields: Array<{ key: Key, label: string }> }>>(() => [
	{
		// TRANSLATORS: Group of settings for pending security updates
		title: t('serverinfo', 'Security updates pending'),
		fields: [
			{ key: 'securityWarning', label: t('serverinfo', 'Warning after (days)') },
			{ key: 'securityError', label: t('serverinfo', 'Error after (days)') },
		],
	},
	{
		// TRANSLATORS: Group of settings for a reboot that is pending
		title: t('serverinfo', 'Reboot pending'),
		fields: [
			{ key: 'rebootWarning', label: t('serverinfo', 'Warning after (days)') },
			{ key: 'rebootError', label: t('serverinfo', 'Error after (days)') },
		],
	},
	{
		// TRANSLATORS: Group of settings for services that need a restart
		title: t('serverinfo', 'Service restart pending'),
		fields: [
			{ key: 'restartWarning', label: t('serverinfo', 'Warning after (days)') },
		],
	},
	{
		// TRANSLATORS: Group of settings for package lists that were not refreshed
		title: t('serverinfo', 'Package lists not refreshed'),
		fields: [
			{ key: 'listsWarning', label: t('serverinfo', 'Warning after (days)') },
			{ key: 'listsError', label: t('serverinfo', 'Error after (days)') },
		],
	},
	{
		// TRANSLATORS: Group of settings for the end of the release's security support
		title: t('serverinfo', 'End of security support'),
		fields: [
			{ key: 'eolWarning', label: t('serverinfo', 'Warning before (days)') },
		],
	},
])

const errorText = computed(() => {
	if (invalid.value === null) {
		return ''
	}
	const value = Number(form[invalid.value])
	return Number.isInteger(value) && value >= 0 && value <= props.limits.maxDays
		? t('serverinfo', 'The error threshold cannot come before the warning threshold.')
		: t('serverinfo', 'Enter a whole number of days between 0 and {max}.', { max: props.limits.maxDays }, PLAIN)
})

/**
 * Finds the first field the server would reject, so it can be pointed out
 * before the request is made.
 */
function validate(): Key | null {
	for (const key of Object.keys(props.defaults.thresholds) as Key[]) {
		const value = Number(form[key])
		if (form[key].trim() === '' || !Number.isInteger(value) || value < 0 || value > props.limits.maxDays) {
			return key
		}
	}
	for (const [warning, error] of props.limits.pairs) {
		if (Number(form[error]) < Number(form[warning])) {
			return error
		}
	}
	return null
}

function reset() {
	for (const [key, value] of Object.entries(props.defaults.thresholds)) {
		form[key as Key] = String(value)
	}
	notify.value = props.defaults.notifications
	invalid.value = null
}

async function save() {
	invalid.value = validate()
	if (invalid.value !== null) {
		return
	}

	busy.value = true
	failed.value = false
	try {
		await confirmPassword()
	} catch {
		// Closing the password prompt leaves the form as it was.
		busy.value = false
		return
	}

	try {
		const thresholds = Object.fromEntries(Object.entries(form).map(([key, value]) => [key, Number(value)])) as unknown as OsThresholds
		const response = await axios.put<OsPayload>(generateUrl('/apps/serverinfo/os/settings'), { thresholds, notifications: notify.value })
		emit('saved', response.data)
		emit('close')
	} catch (caught) {
		const field = axios.isAxiosError(caught) ? caught.response?.data?.field : undefined
		if (typeof field === 'string' && field in props.defaults.thresholds) {
			invalid.value = field as Key
		} else {
			failed.value = true
		}
	} finally {
		busy.value = false
	}
}
</script>

<style scoped>
.os-settings {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.os-settings p {
	margin: 0;
}

.os-settings__group {
	margin: 0;
	padding: 0;
	border: none;
}

.os-settings__group legend {
	font-weight: bold;
	margin-block-end: 6px;
}

.os-settings__fields {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(min(180px, 100%), 1fr));
	gap: 8px 12px;
}
</style>
