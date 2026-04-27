<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { computed } from 'vue'
import { makeFingerprint } from '../composables/useFingerprint.ts'

const props = withDefaults(defineProps<{
	hostname: string
	size?: number
}>(), {
	size: 96,
})

const fp = computed(() => makeFingerprint(props.hostname, props.size, 7))
</script>

<template>
	<svg
		:class="$style.fingerprint"
		:viewBox="`0 0 ${size} ${size}`"
		:width="size"
		:height="size"
		role="img"
		:aria-label="`Visual fingerprint for ${hostname}`">
		<defs>
			<filter :id="`fp-blur-${fp.seed}`">
				<feGaussianBlur stdDeviation="3" />
			</filter>
		</defs>
		<g :filter="`url(#fp-blur-${fp.seed})`">
			<circle
				v-for="(s, i) in fp.shapes"
				:key="i"
				:cx="s.cx"
				:cy="s.cy"
				:r="s.r"
				:fill="`hsl(${s.hue} 70% 55%)`"
				:opacity="s.opacity" />
		</g>
	</svg>
</template>

<style module lang="scss">
.fingerprint {
	display: block;
	border-radius: 14px;
	overflow: hidden;
	background: var(--color-main-background);
}
</style>
