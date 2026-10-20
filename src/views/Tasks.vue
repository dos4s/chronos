<script setup lang="ts">
import type { Task, TaskWritePayload } from '../types/index.ts'
import type { TaskFilter } from '../utils/filter.ts'
import type { TaskNode } from '../utils/tasks.ts'

import { n, t } from '@nextcloud/l10n'
import { computed, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import ConfirmDialog from '../components/ConfirmDialog.vue'
import FilterBar from '../components/FilterBar.vue'
import ProjectHeading from '../components/ProjectHeading.vue'
import ProjectSettingsDialog from '../components/ProjectSettingsDialog.vue'
import PromoteDialog from '../components/PromoteDialog.vue'
import TaskComposer from '../components/TaskComposer.vue'
import TaskDetailDialog from '../components/TaskDetailDialog.vue'
import TaskRow from '../components/TaskRow.vue'
import { useModulesStore } from '../stores/modules.ts'
import { useProjectsStore } from '../stores/projects.ts'
import { useTasksStore } from '../stores/tasks.ts'
import { useWorkflowsStore } from '../stores/workflows.ts'
import { countNodes, filterTree, NO_FILTER } from '../utils/filter.ts'

const modules = useModulesStore()
const projects = useProjectsStore()
const tasks = useTasksStore()
const workflows = useWorkflowsStore()

const editing = ref<Task | null>(null)
/** The task a *Delete* is waiting on an answer about (§14.2). */
const deleting = ref<Task | null>(null)
const subtaskParent = ref<TaskNode | null>(null)
/** The subproject being turned into a list of its own (F2, §6). */
const promoting = ref<TaskNode | null>(null)
const configuring = ref(false)

/**
 * What is worth looking at: by default the unfinished work, because two
 * thirds of a real list is usually done (§14.2).
 */
const filter = ref<TaskFilter>({ ...NO_FILTER })

const visible = computed(() => filterTree(tasks.tree, filter.value))
const hidden = computed(() => Math.max(0, tasks.tasks.length - countNodes(visible.value)))
const searching = computed(() => filter.value.query.trim() !== '')

/** The project the sidebar is working in (§14.2). */
const project = computed(() => projects.currentProject)

const readOnly = computed(() => project.value !== null && !project.value.writable)

watch(project, (selected) => {
	subtaskParent.value = null
	tasks.select(selected?.uri ?? null)
}, { immediate: true })

onMounted(async () => {
	if (projects.projects.length === 0) {
		await projects.refresh()
	}
	// Statuses are needed to draw a single row, so they are loaded with the
	// view rather than when a dialog first asks for them.
	await workflows.ensureLoaded()
})

/**
 * Settings can have given the project a workflow, which gives its tasks a
 * status, so the list is read again.
 */
async function settingsClosed(): Promise<void> {
	configuring.value = false
	await tasks.refresh()
}

/**
 * A promoted subproject has left this list, and there is a new project in the
 * picker — so both the projects and the tasks are read again (F2).
 */
async function onPromoted(): Promise<void> {
	promoting.value = null
	await projects.refresh()
	await tasks.refresh()
}

/** The list's own composer, which adds to the list the sidebar is working in. */
async function addTask(summary: string): Promise<void> {
	if (await tasks.create(summary)) {
		subtaskParent.value = null
	}
}

async function addSubtask(summary: string): Promise<void> {
	const parent = subtaskParent.value
	if (parent !== null && await tasks.create(summary, parent.task.uid)) {
		subtaskParent.value = null
	}
}

async function confirmDelete(): Promise<void> {
	const task = deleting.value
	if (task === null) {
		return
	}

	if (await tasks.remove(task)) {
		deleting.value = null
		if (editing.value?.uid === task.uid) {
			editing.value = null
		}
	}
}

async function saveDetails(changes: Partial<TaskWritePayload>) {
	const task = editing.value
	if (task === null) {
		return
	}

	if (await tasks.save(task, changes)) {
		editing.value = null
	}
}
</script>

<template>
	<div :class="$style.view">
		<div class="chronos-view-head">
			<h2 class="chronos-section-title">
				{{ t('chronos', 'Tasks') }}
			</h2>
			<span v-if="tasks.tasks.length" :class="$style.count">
				{{ n('chronos', '%n open task', '%n open tasks', tasks.openCount) }}
			</span>
		</div>

		<p class="chronos-view-why">
			{{ t('chronos', 'Everything in this list, as a tree. Tick one off, rename it in place, give it a subtask, or promote a subproject to a list of its own.') }}
		</p>

		<div :class="$style.picker">
			<ProjectHeading />
			<div :class="$style.spacer" />
			<NcButton
				v-if="project && (modules.projects || modules.workflow)"
				variant="tertiary"
				:aria-label="t('chronos', 'Project settings')"
				@click="configuring = true">
				{{ t('chronos', 'Settings') }}
			</NcButton>
		</div>

		<FilterBar
			v-if="project"
			v-model="filter"
			:hidden="hidden" />

		<NcNoteCard v-if="readOnly" type="info">
			{{ t('chronos', 'This list is shared with you read-only, so its tasks cannot be changed here.') }}
		</NcNoteCard>

		<TaskComposer
			v-if="project && !readOnly"
			:busy="tasks.loading"
			@submit="addTask" />

		<div v-if="tasks.initialLoading" :class="$style.loading">
			<NcLoadingIcon :size="32" />
		</div>

		<ul v-else-if="visible.length" :class="$style.list">
			<TaskRow
				v-for="node in visible"
				:key="node.task.uid"
				:node="node"
				:depth="0"
				:readOnly="readOnly"
				@edit="editing = $event.task"
				@addSubtask="subtaskParent = $event"
				@promote="promoting = $event"
				@remove="deleting = $event.task" />
		</ul>

		<NcEmptyContent
			v-else-if="project && searching"
			:class="$style.empty"
			:name="t('chronos', 'Nothing matches “{query}”', { query: filter.query })"
			:description="t('chronos', 'Try a shorter word, or tick “Show completed” — finished tasks are hidden.')">
			<template #action>
				<NcButton variant="secondary" @click="filter = { ...filter, query: '' }">
					{{ t('chronos', 'Clear the search') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<NcEmptyContent
			v-else-if="project && tasks.tasks.length"
			:class="$style.empty"
			:name="t('chronos', 'All done here')"
			:description="t('chronos', 'Every task in this list is finished. Tick “Show completed” to see them.')" />

		<NcEmptyContent
			v-else-if="project"
			:class="$style.empty"
			:name="t('chronos', 'Nothing to do here')"
			:description="t('chronos', 'Tasks you add appear here, and in the Tasks app and on your phone.')" />

		<NcEmptyContent
			v-else
			:class="$style.empty"
			:name="t('chronos', 'No task lists yet')"
			:description="t('chronos', 'Create a list in the Tasks app, and it becomes a project here.')" />

		<div v-if="subtaskParent" :class="$style.subtask">
			<div :class="$style.subtaskLabel">
				{{ t('chronos', 'Subtask of “{parent}”', { parent: subtaskParent.task.summary || t('chronos', 'Untitled task') }) }}
			</div>
			<TaskComposer
				:placeholder="t('chronos', 'Add a subtask…')"
				:busy="tasks.loading"
				autofocus
				@submit="addSubtask"
				@cancel="subtaskParent = null" />
		</div>

		<TaskDetailDialog
			v-if="editing"
			:task="editing"
			:deletable="!readOnly"
			@close="editing = null"
			@save="saveDetails"
			@remove="deleting = editing" />

		<ConfirmDialog
			v-if="deleting"
			:name="t('chronos', 'Delete this task?')"
			:message="t('chronos', '“{name}” will be deleted from this list, and from the Tasks app and your phone with it. This cannot be undone.', { name: deleting.summary || t('chronos', 'Untitled task') })"
			:busy="tasks.loading"
			@close="deleting = null"
			@confirm="confirmDelete" />

		<PromoteDialog
			v-if="promoting && project"
			:node="promoting"
			:project="project.uri"
			@close="promoting = null"
			@promoted="onPromoted" />

		<ProjectSettingsDialog
			v-if="configuring && project"
			:project="project"
			@close="settingsClosed" />
	</div>
</template>

<style module>
.view {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.count {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.picker {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}

.spacer {
	flex: 1;
}

.loading {
	display: flex;
	justify-content: center;
	padding: 32px;
}

.list {
	padding: 0;
	margin: 0;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	overflow: hidden;
}

.empty {
	padding: 24px 0;
}

.subtask {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 16px;
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.subtaskLabel {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}
</style>
