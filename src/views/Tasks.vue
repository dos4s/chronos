<script setup lang="ts">
import type { Task, TaskList, TaskWritePayload } from '../types/index.ts'
import type { TaskNode } from '../utils/tasks.ts'

import { n, t } from '@nextcloud/l10n'
import { computed, onMounted, ref, watch } from 'vue'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import TaskComposer from '../components/TaskComposer.vue'
import TaskDetailDialog from '../components/TaskDetailDialog.vue'
import TaskRow from '../components/TaskRow.vue'
import { useProjectsStore } from '../stores/projects.ts'
import { useTasksStore } from '../stores/tasks.ts'

const projects = useProjectsStore()
const tasks = useTasksStore()

const project = ref<TaskList | null>(null)
const editing = ref<Task | null>(null)
const subtaskParent = ref<TaskNode | null>(null)

const readOnly = computed(() => project.value !== null && !project.value.writable)

watch(project, (selected) => {
	subtaskParent.value = null
	tasks.select(selected?.uri ?? null)
})

onMounted(async () => {
	if (projects.taskLists.length === 0) {
		await projects.refresh()
	}
	project.value ??= projects.taskLists[0] ?? null
})

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
				:options="projects.taskLists"
				label="name"
				:reduce="(list: TaskList) => list"
				:clearable="false"
				:inputLabel="t('chronos', 'Project')"
				:placeholder="t('chronos', 'Pick a project')" />
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
	max-width: 420px;
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
