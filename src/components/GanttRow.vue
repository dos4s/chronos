<script setup lang="ts">
import type { UnixSeconds } from '../api/time.ts'
import type { PlanRow } from '../types/index.ts'

import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import { formatDate, formatTimeOfDay } from '../utils/format.ts'
import { inclusiveDue } from '../utils/planDates.ts'

/**
 * One line of the timeline's table: a task's name, when it runs, and what can
 * be done to it (§12.5).
 *
 * The chart beside it can move a bar and draw a dependency; this is where the
 * things a chart has no gesture for live — naming a task, giving it a subtask,
 * taking its dates off so it leaves the chart, deleting it (§14.2).
 *
 * The row is placed by the view, which owns the row height and the index, so
 * nothing here is ever measured (§15.2).
 */
const props = defineProps<{ row: PlanRow, readOnly: boolean }>()

const emit = defineEmits<{
	open: []
	addSubtask: []
	clearDates: []
	remove: []
}>()

const name = computed(() => props.row.summary || t('chronos', 'Untitled task'))

function when(at: UnixSeconds, isDate: boolean): string {
	return isDate ? formatDate(at, true) : `${formatDate(at)} ${formatTimeOfDay(at)}`
}

/** The dates in the frame they belong to: a day is a day, an instant is one (§8.4). */
const dates = computed(() => {
	const bar = props.row.bar
	if (bar === null) {
		return t('chronos', 'No dates')
	}

	const from = when(bar.startAt, bar.startIsDate)
	if (bar.isMilestone) {
		return from
	}

	return `${from} → ${when(inclusiveDue(bar.endAt, bar.dueIsDate), bar.dueIsDate)}`
})
</script>

<template>
	<div
		:class="[$style.row, { [$style.summaryRow]: row.isSummary }]"
		:style="{ paddingInlineStart: `${8 + row.depth * 14}px` }"
		@dblclick="emit('open')">
		<div :class="$style.text">
			<span :class="$style.name" :title="name">{{ name }}</span>
			<span :class="$style.when">{{ dates }}</span>
		</div>

		<div v-if="!readOnly" :class="$style.menu">
			<NcActions :aria-label="t('chronos', 'Task actions')">
				<NcActionButton @click="emit('open')">
					{{ t('chronos', 'Edit details') }}
				</NcActionButton>
				<NcActionButton @click="emit('addSubtask')">
					{{ t('chronos', 'Add subtask') }}
				</NcActionButton>
				<NcActionButton v-if="row.bar && !row.isSummary" @click="emit('clearDates')">
					{{ t('chronos', 'Take its dates off') }}
				</NcActionButton>
				<NcActionButton @click="emit('remove')">
					{{ t('chronos', 'Delete') }}
				</NcActionButton>
			</NcActions>
		</div>
	</div>
</template>

<style module>
.row {
	position: absolute;
	inset-inline: 0;
	display: flex;
	align-items: center;
	gap: 4px;
	padding-inline-end: 2px;
	box-sizing: border-box;
	border-block-end: 1px solid var(--color-border-dark);
	cursor: default;
}

.row:hover {
	background: var(--color-background-hover);
}

.text {
	flex: 1;
	min-width: 0;
	display: flex;
	flex-direction: column;
	justify-content: center;
}

.summaryRow .name {
	font-weight: 600;
}

.name {
	font-size: 13px;
	line-height: 1.1;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.when {
	font-size: 10px;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

/* Quiet until the row is pointed at, so a plan reads as a list of names. */
.menu {
	flex-shrink: 0;
	opacity: 0;
	transition: opacity 0.1s ease;
}

.row:hover .menu,
.row:focus-within .menu {
	opacity: 1;
}

@media (hover: none) {
	.menu {
		opacity: 1;
	}
}

/* Narrow: the dates have nowhere to go, and the name matters more (§14.2). */
@media (max-width: 700px) {
	.when {
		display: none;
	}
}
</style>
