<script setup lang="ts">
import type { TaskNode } from '../utils/tasks.ts'

import { t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import { useNow } from '../composables/useNow.ts'
import { useTasksStore } from '../stores/tasks.ts'
import { formatDate, formatTimeOfDay } from '../utils/format.ts'
import { isCompleted, isDueToday, isOverdue } from '../utils/tasks.ts'

const props = defineProps<{ node: TaskNode, depth: number, readOnly: boolean }>()
const emit = defineEmits<{ edit: [node: TaskNode], addSubtask: [node: TaskNode] }>()

const tasks = useTasksStore()
const { now } = useNow()

const editing = ref(false)
const draft = ref('')

const task = computed(() => props.node.task)
const done = computed(() => isCompleted(task.value))
const overdue = computed(() => isOverdue(task.value, now.value))
const dueToday = computed(() => isDueToday(task.value, now.value))

const dueLabel = computed(() => {
	const due = task.value.dueAt
	if (due === null) {
		return null
	}
	if (task.value.dueIsDate) {
		return dueToday.value ? t('chronos', 'Today') : formatDate(due)
	}
	return dueToday.value ? formatTimeOfDay(due) : `${formatDate(due)} ${formatTimeOfDay(due)}`
})

function startEditing() {
	if (props.readOnly) {
		return
	}
	draft.value = task.value.summary ?? ''
	editing.value = true
}

async function commit() {
	const summary = draft.value.trim()
	editing.value = false

	if (summary === '' || summary === (task.value.summary ?? '')) {
		return
	}

	await tasks.save(task.value, { summary })
}
</script>

<template>
	<li :class="$style.item">
		<div :class="[$style.row, { [$style.done]: done }]" :style="{ paddingInlineStart: (depth * 24) + 'px' }">
			<!--
				The browser is not allowed to tick this by itself: the state
				follows the saved task, so a write that fails or is refused
				leaves the box showing what is actually stored.
			-->
			<input
				type="checkbox"
				:class="$style.check"
				:checked="done"
				:disabled="readOnly || tasks.loading"
				:aria-label="done ? t('chronos', 'Reopen task') : t('chronos', 'Complete task')"
				@click.prevent="tasks.toggleComplete(task)">

			<div :class="$style.main">
				<input
					v-if="editing"
					v-model="draft"
					type="text"
					:class="$style.rename"
					:aria-label="t('chronos', 'Task name')"
					@keydown.enter="commit"
					@keydown.esc="editing = false"
					@blur="commit">
				<button
					v-else
					:class="$style.summary"
					:title="t('chronos', 'Rename')"
					@click="startEditing">
					{{ task.summary || t('chronos', 'Untitled task') }}
				</button>

				<div :class="$style.meta">
					<span v-if="task.type === 'milestone'" :class="$style.tag">{{ t('chronos', 'Milestone') }}</span>
					<span v-if="task.type === 'subproject'" :class="$style.tag">{{ t('chronos', 'Subproject') }}</span>
					<span
						v-if="dueLabel"
						:class="[$style.due, { [$style.overdue]: overdue, [$style.today]: dueToday && !overdue }]">
						{{ overdue ? t('chronos', 'Overdue: {due}', { due: dueLabel }) : dueLabel }}
					</span>
					<span v-if="task.priority > 0" :class="$style.priority">
						{{ t('chronos', 'Priority {priority}', { priority: task.priority }) }}
					</span>
					<span v-if="task.percent !== null && !done" :class="$style.percent">{{ task.percent }}%</span>
					<span v-if="task.assignee" :class="$style.assignee">{{ task.assignee }}</span>
				</div>
			</div>

			<NcActions v-if="!readOnly" :aria-label="t('chronos', 'Task actions')">
				<NcActionButton @click="emit('edit', node)">
					{{ t('chronos', 'Edit details') }}
				</NcActionButton>
				<NcActionButton @click="emit('addSubtask', node)">
					{{ t('chronos', 'Add subtask') }}
				</NcActionButton>
				<NcActionButton @click="tasks.remove(task)">
					{{ t('chronos', 'Delete') }}
				</NcActionButton>
			</NcActions>
		</div>

		<ul v-if="node.children.length" :class="$style.children">
			<TaskRow
				v-for="child in node.children"
				:key="child.task.uid"
				:node="child"
				:depth="depth + 1"
				:readOnly="readOnly"
				@edit="emit('edit', $event)"
				@addSubtask="emit('addSubtask', $event)" />
		</ul>
	</li>
</template>

<style module>
.item {
	list-style: none;
}

.row {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 10px 16px;
	border-bottom: 1px solid color-mix(in srgb, var(--color-border) 50%, transparent);
}

.row:hover {
	background: var(--color-background-hover);
}

.done .summary {
	text-decoration: line-through;
	color: var(--color-text-maxcontrast);
}

.check {
	flex-shrink: 0;
	width: 18px;
	height: 18px;
	cursor: pointer;
}

.main {
	flex: 1;
	min-width: 0;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.summary {
	background: none;
	border: none;
	padding: 0;
	margin: 0;
	font-size: 14px;
	font-weight: 500;
	color: var(--color-main-text);
	text-align: start;
	cursor: text;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.rename {
	width: 100%;
	max-width: 480px;
	padding: 4px 8px;
	font-size: 14px;
	color: var(--color-main-text);
	background: var(--color-main-background);
	border: 1px solid var(--color-primary-element);
	border-radius: var(--border-radius);
}

.meta {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.tag {
	text-transform: uppercase;
	letter-spacing: 0.06em;
	font-weight: 600;
	font-size: 10px;
}

.due {
	font-variant-numeric: tabular-nums;
}

.overdue {
	color: var(--color-error);
	font-weight: 600;
}

.today {
	color: var(--color-primary-element);
	font-weight: 600;
}

.priority,
.percent,
.assignee {
	font-variant-numeric: tabular-nums;
}

.children {
	padding: 0;
	margin: 0;
}
</style>
