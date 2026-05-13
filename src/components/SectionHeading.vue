<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<h2>
		<component :is="icon" class="icon" :size="20" />
		{{ title }}
	</h2>
</template>

<script setup lang="ts">
import type { Component } from 'vue'

defineProps<{ icon: Component, title: string }>()
</script>

<style scoped>
.icon {
	display: inline-flex;
	vertical-align: middle;
	margin-right: 8px;
}
</style>
