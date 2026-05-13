<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="section section-skeleton" aria-busy="true">
		<div class="skeleton-bar skeleton-title" />
		<div class="skeleton-bar skeleton-chart" />
	</div>
</template>

<style scoped lang="scss">
.section-skeleton {
	.skeleton-bar {
		background: var(--color-background-dark, #ededed);
		border-radius: var(--border-radius, 3px);
		animation: skeleton-pulse 1.5s ease-in-out infinite;
	}

	.skeleton-title {
		height: 24px;
		width: 40%;
		margin-bottom: 20px;
	}

	.skeleton-chart {
		height: 200px;
		margin-bottom: 16px;
	}
}

@keyframes skeleton-pulse {
	50% {
		opacity: 0.5;
	}
}
</style>
