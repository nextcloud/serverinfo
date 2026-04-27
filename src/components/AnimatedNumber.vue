<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { computed, toRef } from 'vue'
import { useAnimatedNumber } from '../composables/useAnimatedNumber.ts'

const props = withDefaults(defineProps<{
	value: number
	suffix?: string
	prefix?: string
	fractionDigits?: number
	duration?: number
	formatter?: (n: number) => string
}>(), {
	suffix: '',
	prefix: '',
	fractionDigits: 0,
	duration: 700,
	formatter: undefined,
})

const animated = useAnimatedNumber(toRef(props, 'value'), props.duration)

const formatted = computed(() => {
	if (props.formatter) {
		return props.formatter(animated.value)
	}
	if (!Number.isFinite(animated.value)) {
		return '–'
	}
	return animated.value.toFixed(props.fractionDigits)
})
</script>

<template><span>{{ prefix }}{{ formatted }}{{ suffix }}</span></template>
