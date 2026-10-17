<script setup lang="ts">
import type { Task } from '../types/index.ts'

import { n, t } from '@nextcloud/l10n'
import { computed } from 'vue'
import { useNow } from '../composables/useNow.ts'
import { formatDate, formatTimeOfDay } from '../utils/format.ts'
import { isDueToday, isOverdue } from '../utils/tasks.ts'

/**
 * One card on the board: a task, as much of it as is worth seeing at a glance.
 *
 * The whole card is the drag handle, which is why `touch-action: none` sits on
 * it — a finger on a card drags the card, a finger beside one scrolls the
 * column (§15.1). It is also focusable, because every drag has to work from
 * the keyboard (§15.7).
 */
const props = defineProps<{
	card: Task
	selected: boolean
	dragging: boolean
	readOnly: boolean
	subtaskCount?: number
}>()

const emit = defineEmits<{
	pick: [event: PointerEvent]
	grab: [event: KeyboardEvent]
	open: []
	select: [additive: boolean]
}>()

const { now } = useNow()

const overdue = computed(() => isOverdue(props.card, now.value))
const dueToday = computed(() => isDueToday(props.card, now.value))

const dueLabel = computed(() => {
	const due = props.card.dueAt
	if (due === null) {
		return null
	}
	if (props.card.dueIsDate) {
		return dueToday.value ? t('chronos', 'Today') : formatDate(due)
	}
	return dueToday.value ? formatTimeOfDay(due) : `${formatDate(due)} ${formatTimeOfDay(due)}`
})

/** `PRIORITY` 1–4 is high in iCalendar; 5 is normal (§7). */
const highPriority = computed(() => props.card.priority > 0 && props.card.priority <= 4)

function onPointerDown(event: PointerEvent): void {
	emit('select', event.shiftKey || event.ctrlKey || event.metaKey)
	if (!props.readOnly) {
		emit('pick', event)
	}
}
</script>

<template>
	<li
		:class="[$style.card, {
			[$style.selected]: selected,
			[$style.ghost]: dragging,
			[$style.overdue]: overdue,
		}]"
		:tabindex="readOnly ? -1 : 0"
		role="listitem"
		:aria-grabbed="dragging ? 'true' : 'false'"
		:aria-label="card.summary || t('chronos', 'Untitled task')"
		@pointerdown="onPointerDown"
		@keydown.space="emit('grab', $event)"
		@keydown.enter.exact="emit('open')"
		@dblclick="emit('open')">
		<div :class="$style.summary">
			{{ card.summary || t('chronos', 'Untitled task') }}
		</div>

		<div v-if="dueLabel || highPriority || card.assignee || card.percent !== null || subtaskCount" :class="$style.meta">
			<span v-if="dueLabel" :class="[$style.chip, overdue ? $style.late : '']">
				{{ dueLabel }}
			</span>
			<span v-if="highPriority" :class="[$style.chip, $style.priority]">
				{{ t('chronos', 'High') }}
			</span>
			<span v-if="card.percent !== null && card.percent > 0" :class="$style.chip">
				{{ card.percent }}%
			</span>
			<span v-if="subtaskCount" :class="$style.chip">
				{{ n('chronos', '%n subtask', '%n subtasks', subtaskCount) }}
			</span>
			<span v-if="card.assignee" :class="$style.chip" :title="card.assignee">
				{{ card.assignee }}
			</span>
		</div>
	</li>
</template>

<style module>
.card {
	list-style: none;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 10px 12px;
	display: flex;
	flex-direction: column;
	gap: 6px;
	cursor: grab;
	/* A finger on a card drags it; the browser must not also scroll (§15.1). */
	touch-action: none;
	user-select: none;
}

.card:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

.selected {
	border-color: var(--color-primary-element);
	box-shadow: inset 0 0 0 1px var(--color-primary-element);
}

/* The card stays in place, faded, while its copy follows the pointer. */
.ghost {
	opacity: 0.35;
}

.overdue {
	border-inline-start: 3px solid var(--color-error);
}

.summary {
	font-size: 14px;
	line-height: 1.35;
	overflow-wrap: anywhere;
}

.meta {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
}

.chip {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	padding: 1px 6px;
	max-width: 140px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.late {
	color: var(--color-error-text);
}

.priority {
	color: var(--color-warning-text);
}
</style>
