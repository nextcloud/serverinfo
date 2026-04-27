<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { ref } from 'vue'
import IconPin from 'vue-material-design-icons/Pin.vue'
import IconPinOutline from 'vue-material-design-icons/PinOutline.vue'
import IconDrag from 'vue-material-design-icons/DragVertical.vue'
import { useLayout, type CardId } from '../composables/useLayout.ts'

const props = defineProps<{
	id: CardId
}>()

const { togglePin, isPinned, moveBefore } = useLayout()

const dragOver = ref(false)

const onDragStart = (e: DragEvent): void => {
	if (!e.dataTransfer) return
	e.dataTransfer.setData('text/x-serverinfo-card', props.id)
	e.dataTransfer.effectAllowed = 'move'
}

const onDragOver = (e: DragEvent): void => {
	if (!e.dataTransfer) return
	const types = e.dataTransfer.types
	if (!types || !types.includes('text/x-serverinfo-card')) return
	e.preventDefault()
	e.dataTransfer.dropEffect = 'move'
	dragOver.value = true
}

const onDragLeave = (): void => {
	dragOver.value = false
}

const onDrop = (e: DragEvent): void => {
	dragOver.value = false
	if (!e.dataTransfer) return
	const sourceId = e.dataTransfer.getData('text/x-serverinfo-card') as CardId
	if (!sourceId || sourceId === props.id) return
	e.preventDefault()
	moveBefore(sourceId, props.id)
}
</script>

<template>
	<div
		:class="[$style.wrap, dragOver && $style.dragOver, isPinned(id) && $style.pinned]"
		@dragover="onDragOver"
		@dragleave="onDragLeave"
		@drop="onDrop">
		<div :class="$style.controls">
			<button
				type="button"
				:class="[$style.pinBtn, isPinned(id) && $style.pinBtn_active]"
				:title="isPinned(id) ? t('serverinfo', 'Unpin from top') : t('serverinfo', 'Pin to top')"
				@click="togglePin(id)">
				<component :is="isPinned(id) ? IconPin : IconPinOutline" :size="14" />
			</button>
			<span
				:class="$style.dragHandle"
				draggable="true"
				:title="t('serverinfo', 'Drag to reorder')"
				@dragstart="onDragStart">
				<IconDrag :size="14" />
			</span>
		</div>
		<slot />
	</div>
</template>

<style module lang="scss">
.wrap {
	position: relative;
	transition: outline 0.15s ease, transform 0.18s ease;
}

.wrap.dragOver {
	outline: 2px dashed var(--color-primary-element);
	outline-offset: 4px;
	border-radius: var(--border-radius-large);
}

.wrap.pinned::before {
	content: '';
	position: absolute;
	left: -8px;
	top: 12px;
	bottom: 12px;
	width: 3px;
	border-radius: 2px;
	background: linear-gradient(180deg,
		var(--color-primary-element),
		color-mix(in srgb, var(--color-primary-element) 40%, transparent));
}

.controls {
	position: absolute;
	top: 8px;
	right: 8px;
	display: flex;
	gap: 4px;
	opacity: 0;
	transition: opacity 0.15s ease;
	z-index: 5;
}

.wrap:hover .controls,
.controls:focus-within {
	opacity: 1;
}

.pinBtn, .dragHandle {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 24px;
	height: 24px;
	border-radius: 50%;
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	padding: 0;
	box-shadow: 0 2px 6px rgba(0, 0, 0, 0.06);
}

.pinBtn:hover, .dragHandle:hover {
	color: var(--color-primary-element);
	border-color: var(--color-primary-element);
}

.dragHandle { cursor: grab; }
.dragHandle:active { cursor: grabbing; }

.pinBtn_active {
	color: var(--color-primary-element);
	border-color: var(--color-primary-element);
	background-color: color-mix(in srgb, var(--color-primary-element) 12%, var(--color-main-background));
}

.wrap.pinned .pinBtn { opacity: 1; }
.wrap.pinned .controls { opacity: 1; }
</style>
