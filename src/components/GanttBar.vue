<script setup lang="ts">
import type { DragKind } from '../composables/useTimeDrag.ts'
import type { PlanBar, PlanRow } from '../types/index.ts'
import type { Scale } from '../utils/scale.ts'

import { t } from '@nextcloud/l10n'
import { computed } from 'vue'

/**
 * One bar on the timeline (§12.5).
 *
 * The bar itself is the handle for moving it, with a grip at each edge for
 * resizing and one on the right for dragging out a dependency. Every one of
 * them is the same drag engine with a different *kind* (§15.1), and the whole
 * bar is focusable because every drag works from the keyboard too (§15.7).
 *
 * Three states say something the user needs to know before they let go: a
 * **ghost** is where a cascade would put a dependent, an **inferred** bar is
 * the plan's guess from a single date rather than something anybody set, and a
 * **conflicted** bar is one a dependency says cannot be there (§15.3).
 */
const props = defineProps<{
	row: PlanRow
	bar: PlanBar
	scale: Scale
	readOnly: boolean
	/** Where a cascade would put this bar, drawn while the drag is in the air. */
	ghost?: boolean
	dragging?: boolean
	conflicted?: boolean
}>()

const emit = defineEmits<{
	pick: [kind: DragKind, event: PointerEvent]
	grab: [event: KeyboardEvent]
	open: []
}>()

const place = computed(() => props.scale.place(props.bar))

/** A summary is derived from its children, so it is not dragged (§12.5). */
const movable = computed(() => !props.readOnly && !props.row.isSummary && !props.ghost)

const label = computed(() => props.row.summary || t('chronos', 'Untitled task'))

const progress = computed(() => {
	const percent = props.row.progress
	return percent === null || percent <= 0 ? null : Math.min(100, percent)
})

function onPointerDown(event: PointerEvent, kind: DragKind): void {
	if (!movable.value) {
		return
	}
	event.stopPropagation()
	emit('pick', kind, event)
}
</script>

<template>
	<div
		v-if="bar.isMilestone"
		:class="[$style.milestone, { [$style.ghost]: ghost, [$style.conflict]: conflicted }]"
		:style="{ insetInlineStart: `${place.x}px` }"
		:tabindex="movable ? 0 : -1"
		:aria-label="t('chronos', 'Milestone: {name}', { name: label })"
		:title="label"
		@pointerdown="onPointerDown($event, 'move')"
		@keydown="emit('grab', $event)"
		@dblclick.stop="emit('open')" />

	<div
		v-else
		:class="[$style.bar, {
			[$style.summary]: row.isSummary,
			[$style.inferred]: bar.inferred,
			[$style.ghost]: ghost,
			[$style.dragging]: dragging,
			[$style.conflict]: conflicted,
		}]"
		:style="{ insetInlineStart: `${place.x}px`, width: `${place.width}px` }"
		:tabindex="movable ? 0 : -1"
		role="button"
		:aria-label="label"
		:title="label"
		@pointerdown="onPointerDown($event, 'move')"
		@keydown="emit('grab', $event)"
		@dblclick.stop="emit('open')">
		<span
			v-if="progress !== null"
			:class="$style.progress"
			:style="{ width: `${progress}%` }"
			aria-hidden="true" />

		<!--
			A bar too short for a word shows none: at a fitted scale a one-day
			task is a dozen pixels wide, and "C…" in a dashed box reads as a
			rendering fault rather than a task (§15.12). The name is still in
			the row beside it, and in the bar's own tooltip.
		-->
		<span v-if="place.width >= 44" :class="$style.text">{{ label }}</span>

		<template v-if="movable">
			<span
				:class="[$style.grip, $style.gripStart]"
				:aria-label="t('chronos', 'Move the start')"
				@pointerdown="onPointerDown($event, 'start')" />
			<span
				:class="[$style.grip, $style.gripEnd]"
				:aria-label="t('chronos', 'Move the end')"
				@pointerdown="onPointerDown($event, 'end')" />
			<span
				:class="$style.linker"
				:aria-label="t('chronos', 'Drag onto another task to make it wait for this one')"
				@pointerdown="onPointerDown($event, 'link')" />
		</template>
	</div>
</template>

<style module>
.bar {
	position: absolute;
	inset-block-start: 5px;
	height: 22px;
	border-radius: var(--chronos-radius-chip);
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	display: flex;
	align-items: center;
	overflow: hidden;
	cursor: grab;
	/* A finger on a bar drags the bar; the browser must not also scroll (§15.1). */
	touch-action: none;
	user-select: none;
	box-sizing: border-box;
}

.bar:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 1px;
}

/* A summary is the span of what is under it, not a date anybody set (§12.5). */
.summary {
	inset-block-start: 9px;
	height: 14px;
	border-radius: 2px;
	background: var(--color-text-maxcontrast);
	cursor: default;
}

/* The plan's guess from a single date: drawn as a suggestion (§12.5). */
.inferred {
	background: transparent;
	border: 1px dashed var(--color-primary-element);
	color: var(--color-main-text);
}

/* Where a cascade would put this bar, while the drag is still in the air. */
.ghost {
	opacity: 0.4;
	border: 1px dashed var(--color-main-text);
	background: transparent;
	color: var(--color-main-text);
	pointer-events: none;
}

.dragging {
	box-shadow: 0 2px 8px rgb(0 0 0 / 30%);
}

.conflict {
	outline: 2px solid var(--color-error-text);
	outline-offset: 1px;
}

.progress {
	position: absolute;
	inset-block: 0;
	inset-inline-start: 0;
	background: rgb(0 0 0 / 25%);
	pointer-events: none;
}

.text {
	position: relative;
	font-size: var(--chronos-text-small);
	padding-inline: 6px;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	pointer-events: none;
}

.grip {
	position: absolute;
	inset-block: 0;
	width: 6px;
	cursor: ew-resize;
	touch-action: none;
}

.gripStart {
	inset-inline-start: 0;
}

.gripEnd {
	inset-inline-end: 0;
}

/* The handle a dependency is dragged out of (§15.1). */
.linker {
	position: absolute;
	inset-inline-end: -5px;
	inset-block-start: 50%;
	transform: translateY(-50%);
	width: 10px;
	height: 10px;
	border-radius: 50%;
	background: var(--color-main-background);
	border: 2px solid var(--color-primary-element);
	cursor: crosshair;
	touch-action: none;
	opacity: 0;
}

.bar:hover .linker,
.bar:focus-visible .linker {
	opacity: 1;
}

.milestone {
	position: absolute;
	inset-block-start: 8px;
	width: 14px;
	height: 14px;
	margin-inline-start: -7px;
	background: var(--color-warning-text);
	transform: rotate(45deg);
	cursor: grab;
	touch-action: none;
}

.milestone:focus-visible {
	outline: 2px solid var(--color-main-text);
}
</style>
