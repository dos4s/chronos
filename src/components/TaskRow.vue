<script setup lang="ts">
import type { FilteredNode } from '../utils/filter.ts'

import { t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import { useCurrentWorkflow } from '../composables/useCurrentWorkflow.ts'
import { useNow } from '../composables/useNow.ts'
import { useModulesStore } from '../stores/modules.ts'
import { useTasksStore } from '../stores/tasks.ts'
import { useWorkflowsStore } from '../stores/workflows.ts'
import { formatDate, formatTimeOfDay } from '../utils/format.ts'
import { isCompleted, isDueToday, isOverdue } from '../utils/tasks.ts'

const props = defineProps<{ node: FilteredNode, depth: number, readOnly: boolean }>()
const emit = defineEmits<{
	edit: [node: FilteredNode]
	addSubtask: [node: FilteredNode]
	promote: [node: FilteredNode]
	remove: [node: FilteredNode]
}>()

const tasks = useTasksStore()
const modules = useModulesStore()
const workflows = useWorkflowsStore()
const { workflowId } = useCurrentWorkflow()
const { now } = useNow()

const editing = ref(false)
const draft = ref('')

const task = computed(() => props.node.task)
const done = computed(() => isCompleted(task.value))
const overdue = computed(() => isOverdue(task.value, now.value))
const dueToday = computed(() => isDueToday(task.value, now.value))

/** The workflow status, which is more exact than the standard one (§12.1). */
const status = computed(() => workflows.statusOf(task.value, workflowId.value))

const dueLabel = computed(() => {
	const due = task.value.dueAt
	if (due === null) {
		return null
	}
	if (task.value.dueIsDate) {
		return dueToday.value ? t('chronos', 'Today') : formatDate(due, true)
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
		<div
			:class="[$style.row, { [$style.done]: done, [$style.context]: node.faded }]"
			:style="{ paddingInlineStart: (depth * 24) + 'px' }">
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

				<!--
					One line, one voice: the facts about a task read as a
					sentence separated by dots, rather than as five chips in
					four shapes (§15.11). Only a state — overdue, due today —
					takes a colour, so the colour means something.
				-->
				<div :class="$style.meta">
					<span
						v-if="status"
						:class="$style.status"
						:style="status.color ? { borderColor: status.color, color: status.color } : undefined">
						{{ status.name }}
					</span>
					<span v-if="task.type === 'milestone'">{{ t('chronos', 'Milestone') }}</span>
					<span v-if="task.type === 'subproject'">{{ t('chronos', 'Subproject') }}</span>
					<span
						v-if="dueLabel"
						:class="[$style.due, { [$style.overdue]: overdue, [$style.today]: dueToday && !overdue }]">
						{{ overdue ? t('chronos', 'Overdue {due}', { due: dueLabel }) : dueLabel }}
					</span>
					<span v-if="task.priority > 0">
						{{ t('chronos', 'Priority {priority}', { priority: task.priority }) }}
					</span>
					<span v-if="task.percent !== null && !done">{{ task.percent }}% {{ t('chronos', 'done') }}</span>
					<span v-if="task.assignee">{{ task.assignee }}</span>
				</div>
			</div>

			<NcActions v-if="!readOnly" :aria-label="t('chronos', 'Task actions')">
				<NcActionButton closeAfterClick @click="emit('edit', node)">
					{{ t('chronos', 'Edit details') }}
				</NcActionButton>
				<NcActionButton closeAfterClick @click="emit('addSubtask', node)">
					{{ t('chronos', 'Add subtask') }}
				</NcActionButton>
				<!--
					A subproject is the right shape until it holds forty tasks,
					and then it wants to be a list of its own (F2, §6).
				-->
				<NcActionButton
					v-if="task.type === 'subproject' && modules.projects"
					closeAfterClick
					@click="emit('promote', node)">
					{{ t('chronos', 'Promote to a list') }}
				</NcActionButton>
				<NcActionButton closeAfterClick @click="emit('remove', node)">
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
				@addSubtask="emit('addSubtask', $event)"
				@promote="emit('promote', $event)"
				@remove="emit('remove', $event)" />
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
	gap: var(--chronos-space-3);
	padding: var(--chronos-space-2) var(--chronos-space-3);
	border-bottom: 1px solid var(--color-border);
}

.row:hover {
	background: var(--color-background-hover);
}

/*
 * A row kept only to show where its children are: there to be read past
 * rather than read (§14.2).
 */
.context {
	opacity: 0.55;
}

.done .summary {
	text-decoration: line-through;
	color: var(--color-text-maxcontrast);
}

/*
 * Qualified with the element for the same reason the buttons are (§15.10):
 * Nextcloud styles every `input`, `select` and `textarea` through a selector
 * with an element in it, which outranks a single class — so this rule's
 * padding, width and height were being ignored.
 */
input.check {
	flex-shrink: 0;
	/* The server's own small target: 18px was smaller than anything else. */
	width: var(--chronos-control-small);
	height: var(--chronos-control-small);
	cursor: pointer;
}

.main {
	flex: 1;
	min-width: 0;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

/*
 * Qualified with the element on purpose: Nextcloud styles every plain button as
 * a filled pill through `button:not(.button-vue, …)`, which outranks a single
 * class, so a bespoke control styled by its class alone came out as a green box
 * (§15.10).
 */
button.summary {
	background: transparent;
	border: none;
	border-radius: 0;
	padding: 0;
	margin: 0;
	min-height: 0;
	width: auto;
	max-width: 100%;
	font-size: var(--chronos-text);
	font-weight: 500;
	color: var(--color-main-text);
	text-align: start;
	cursor: text;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

input.rename {
	width: 100%;
	max-width: 480px;
	padding: 4px 8px;
	font-size: var(--chronos-text);
	color: var(--color-main-text);
	background: var(--color-main-background);
	border: 1px solid var(--color-primary-element);
	border-radius: var(--chronos-radius-chip);
}

.meta {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: var(--chronos-space-2);
	font-size: var(--chronos-text-small);
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
}

/* A dot between the facts, never before the first one. */
.meta > * + *::before {
	content: '·';
	margin-inline-end: var(--chronos-space-2);
	color: var(--color-border-dark);
}

.status {
	padding: 1px 6px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--chronos-radius-chip);
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.overdue {
	color: var(--color-error-text);
}

.today {
	color: var(--color-primary-element);
}

.children {
	padding: 0;
	margin: 0;
}
</style>
