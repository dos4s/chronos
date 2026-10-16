<script setup lang="ts">
import type { Task, TaskWritePayload } from '../types/index.ts'
import type { LabelledProject } from '../utils/projects.ts'
import type { TaskNode } from '../utils/tasks.ts'

import { n, t } from '@nextcloud/l10n'
import { computed, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import ProjectSettingsDialog from '../components/ProjectSettingsDialog.vue'
import TaskComposer from '../components/TaskComposer.vue'
import TaskDetailDialog from '../components/TaskDetailDialog.vue'
import TaskRow from '../components/TaskRow.vue'
import { useModulesStore } from '../stores/modules.ts'
import { useProjectsStore } from '../stores/projects.ts'
import { useTasksStore } from '../stores/tasks.ts'
import { useWorkflowsStore } from '../stores/workflows.ts'

const modules = useModulesStore()
const projects = useProjectsStore()
const tasks = useTasksStore()
const workflows = useWorkflowsStore()

const project = ref<LabelledProject | null>(null)
const editing = ref<Task | null>(null)
const subtaskParent = ref<TaskNode | null>(null)
const showArchived = ref(false)
const configuring = ref(false)

/**
 * The archived projects stay out of the picker until asked for, which is the
 * whole point of archiving one.
 */
const options = computed(() => showArchived.value ? projects.all : projects.active)

const readOnly = computed(() => project.value !== null && !project.value.writable)

watch(project, (selected) => {
	subtaskParent.value = null
	tasks.select(selected?.uri ?? null)
})

// Settings are saved into the store, which replaces the project object; the
// selection follows it rather than holding the version it was picked from.
watch(options, (available) => {
	const current = project.value
	if (current === null) {
		project.value = available[0] ?? null
		return
	}
	project.value = available.find((candidate) => candidate.uri === current.uri) ?? available[0] ?? null
})

onMounted(async () => {
	if (projects.projects.length === 0) {
		await projects.refresh()
	}
	project.value ??= options.value[0] ?? null
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
		<div class="chronos-header">
			<h2 class="chronos-section-title">
				{{ t('chronos', 'Tasks') }}
			</h2>
			<span v-if="tasks.tasks.length" :class="$style.count">
				{{ n('chronos', '%n open task', '%n open tasks', tasks.openCount) }}
			</span>
		</div>

		<div :class="$style.picker">
			<NcSelect
				v-model="project"
				:class="$style.select"
				:options="options"
				label="label"
				:reduce="(option: LabelledProject) => option"
				:clearable="false"
				:inputLabel="t('chronos', 'Project')"
				:placeholder="t('chronos', 'Pick a project')" />
			<NcButton
				v-if="project && (modules.projects || modules.workflow)"
				variant="tertiary"
				:aria-label="t('chronos', 'Project settings')"
				@click="configuring = true">
				{{ t('chronos', 'Settings') }}
			</NcButton>
			<label :class="$style.archived">
				<input v-model="showArchived" type="checkbox">
				{{ t('chronos', 'Show archived') }}
			</label>
		</div>

		<NcNoteCard v-if="readOnly" type="info">
			{{ t('chronos', 'This list is shared with you read-only, so its tasks cannot be changed here.') }}
		</NcNoteCard>

		<TaskComposer v-if="project && !readOnly" @added="subtaskParent = null" />

		<div v-if="tasks.initialLoading" :class="$style.loading">
			<NcLoadingIcon :size="32" />
		</div>

		<ul v-else-if="tasks.tree.length" :class="$style.list">
			<TaskRow
				v-for="node in tasks.tree"
				:key="node.task.uid"
				:node="node"
				:depth="0"
				:readOnly="readOnly"
				@edit="editing = $event.task"
				@addSubtask="subtaskParent = $event" />
		</ul>

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
				:parentUid="subtaskParent.task.uid"
				:placeholder="t('chronos', 'Add a subtask…')"
				@added="subtaskParent = null" />
		</div>

		<TaskDetailDialog
			v-if="editing"
			:task="editing"
			@close="editing = null"
			@save="saveDetails" />

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

.select {
	flex: 0 1 420px;
}

.archived {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
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
