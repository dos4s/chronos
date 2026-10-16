<script setup lang="ts">
import type { Project } from '../types/index.ts'

import { t } from '@nextcloud/l10n'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { useModulesStore } from '../stores/modules.ts'
import { useProjectsStore } from '../stores/projects.ts'
import { useWorkflowsStore } from '../stores/workflows.ts'

const props = defineProps<{ project: Project }>()
const emit = defineEmits<{ close: [] }>()

const modules = useModulesStore()
const projects = useProjectsStore()
const workflows = useWorkflowsStore()

const form = reactive({
	areaId: '' as number | '',
	parentUri: '',
	archived: false,
	workflowId: '' as number | '',
})

/** The template a new workflow is started from, while one is being added. */
const newWorkflowTemplate = ref<string | null>(null)

onMounted(() => {
	void workflows.ensureLoaded()
})

/** The new-area name, shown only while the user is adding one. */
const newArea = ref<string | null>(null)
const renaming = ref(false)
const areaName = ref('')
const saving = ref(false)

watch(() => props.project, (project) => {
	form.areaId = project.areaId ?? ''
	form.parentUri = project.parentUri ?? ''
	form.archived = project.archived
	form.workflowId = project.workflowId ?? ''
	newArea.value = null
	newWorkflowTemplate.value = null
	renaming.value = false
}, { immediate: true })

const selectedArea = computed(() => form.areaId === '' ? null : projects.areas.find((area) => area.id === form.areaId) ?? null)

/** Every other project, as candidates for the parent (§6, promote to list). */
const parents = computed(() => projects.projects.filter((project) => project.uri !== props.project.uri))

async function addArea(): Promise<void> {
	const name = (newArea.value ?? '').trim()
	if (name === '') {
		newArea.value = null
		return
	}

	const area = await projects.addArea(name, null)
	if (area !== null) {
		form.areaId = area.id
	}
	newArea.value = null
}

async function rename(): Promise<void> {
	const area = selectedArea.value
	const name = areaName.value.trim()
	if (area === null || name === '') {
		renaming.value = false
		return
	}

	await projects.renameArea(area.id, name, area.color)
	renaming.value = false
}

async function removeArea(): Promise<void> {
	const area = selectedArea.value
	if (area === null) {
		return
	}

	if (await projects.removeArea(area.id)) {
		form.areaId = ''
	}
}

function startRenaming(): void {
	areaName.value = selectedArea.value?.name ?? ''
	renaming.value = true
}

async function addWorkflow(): Promise<void> {
	const template = newWorkflowTemplate.value
	if (template === null || template === '') {
		newWorkflowTemplate.value = null
		return
	}

	const workflow = await workflows.createFromTemplate(template, null)
	if (workflow !== null) {
		form.workflowId = workflow.id
	}
	newWorkflowTemplate.value = null
}

async function save(): Promise<void> {
	saving.value = true

	const wanted = form.workflowId === '' ? null : Number(form.workflowId)
	// The workflow belongs to its own module, so it is set through its own
	// endpoint — which is also what gives the tasks already in the project a
	// status (§12.1).
	const workflowSaved = !modules.workflow || (props.project.workflowId ?? null) === wanted
		? true
		: await workflows.assign(props.project.uri, wanted)

	// With the projects module off there is nothing of its to save, and its
	// endpoint would refuse the request anyway (§13).
	const ok = workflowSaved && (!modules.projects || await projects.configure(props.project.uri, {
		areaId: form.areaId === '' ? null : Number(form.areaId),
		parentUri: form.parentUri === '' ? null : form.parentUri,
		archived: form.archived,
		sort: props.project.sort,
	}))
	saving.value = false

	if (ok) {
		emit('close')
	}
}
</script>

<template>
	<NcModal :name="t('chronos', 'Project settings')" @close="emit('close')">
		<div :class="$style.content">
			<h3 :class="$style.title">
				{{ project.name }}
			</h3>

			<NcNoteCard type="info">
				{{ t('chronos', 'The list itself lives in the Tasks app. These settings are Chronos’s own.') }}
			</NcNoteCard>

			<label v-if="modules.projects" :class="$style.field">
				<span :class="$style.legend">{{ t('chronos', 'Area') }}</span>
				<div :class="$style.row">
					<select v-model="form.areaId" :class="$style.grow">
						<option value="">{{ t('chronos', 'No area') }}</option>
						<option v-for="area in projects.areas" :key="area.id" :value="area.id">
							{{ area.name }}
						</option>
					</select>
					<NcButton
						variant="tertiary"
						:aria-label="t('chronos', 'New area')"
						@click="newArea = ''">
						{{ t('chronos', 'New') }}
					</NcButton>
					<NcButton
						v-if="selectedArea"
						variant="tertiary"
						:aria-label="t('chronos', 'Rename area')"
						@click="startRenaming">
						{{ t('chronos', 'Rename') }}
					</NcButton>
					<NcButton
						v-if="selectedArea"
						variant="tertiary"
						:aria-label="t('chronos', 'Delete area')"
						@click="removeArea">
						{{ t('chronos', 'Delete') }}
					</NcButton>
				</div>
				<small v-if="selectedArea">
					{{ t('chronos', 'Deleting an area keeps its projects; they just stop being in one.') }}
				</small>
			</label>

			<label v-if="modules.projects && newArea !== null" :class="$style.field">
				<span :class="$style.legend">{{ t('chronos', 'Name of the new area') }}</span>
				<div :class="$style.row">
					<input
						v-model="newArea"
						type="text"
						:class="$style.grow"
						:placeholder="t('chronos', 'Work, Personal, Homelab…')"
						@keyup.enter="addArea">
					<NcButton variant="secondary" @click="addArea">
						{{ t('chronos', 'Create') }}
					</NcButton>
				</div>
			</label>

			<label v-if="modules.projects && renaming" :class="$style.field">
				<span :class="$style.legend">{{ t('chronos', 'Rename area') }}</span>
				<div :class="$style.row">
					<input
						v-model="areaName"
						type="text"
						:class="$style.grow"
						@keyup.enter="rename">
					<NcButton variant="secondary" @click="rename">
						{{ t('chronos', 'Save name') }}
					</NcButton>
				</div>
			</label>

			<label v-if="modules.projects" :class="$style.field">
				<span :class="$style.legend">{{ t('chronos', 'Part of') }}</span>
				<select v-model="form.parentUri">
					<option value="">{{ t('chronos', 'Nothing — this is a top-level project') }}</option>
					<option v-for="candidate in parents" :key="candidate.uri" :value="candidate.uri">
						{{ candidate.name }}
					</option>
				</select>
				<small>{{ t('chronos', 'For a project split out of a larger one, so the two stay related.') }}</small>
			</label>

			<label v-if="modules.workflow" :class="$style.field">
				<span :class="$style.legend">{{ t('chronos', 'Workflow') }}</span>
				<div :class="$style.row">
					<select v-model="form.workflowId" :class="$style.grow">
						<option value="">{{ t('chronos', 'None — the four standard statuses') }}</option>
						<option v-for="workflow in workflows.workflows" :key="workflow.id" :value="workflow.id">
							{{ workflow.name }}
						</option>
					</select>
					<NcButton
						variant="tertiary"
						:aria-label="t('chronos', 'New workflow')"
						@click="newWorkflowTemplate = ''">
						{{ t('chronos', 'New') }}
					</NcButton>
				</div>
				<small>
					{{ t('chronos', 'Statuses are this project’s own; other apps always see one of the four standard ones.') }}
				</small>
			</label>

			<label v-if="newWorkflowTemplate !== null" :class="$style.field">
				<span :class="$style.legend">{{ t('chronos', 'Start from a template') }}</span>
				<div :class="$style.row">
					<select v-model="newWorkflowTemplate" :class="$style.grow">
						<option value="">{{ t('chronos', 'Pick a template') }}</option>
						<option v-for="template in workflows.templates" :key="template.key" :value="template.key">
							{{ template.name }}
						</option>
					</select>
					<NcButton variant="secondary" @click="addWorkflow">
						{{ t('chronos', 'Create') }}
					</NcButton>
				</div>
			</label>

			<label v-if="modules.projects" :class="$style.checkbox">
				<input v-model="form.archived" type="checkbox">
				<span>
					{{ t('chronos', 'Archived') }}
					<small>{{ t('chronos', 'Kept out of the pickers; nothing is deleted.') }}</small>
				</span>
			</label>

			<div :class="$style.actions">
				<NcButton variant="secondary" @click="emit('close')">
					{{ t('chronos', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary" :disabled="saving" @click="save">
					{{ t('chronos', 'Save') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<style module>
.content {
	padding: 24px;
	min-width: 380px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.title {
	margin: 0;
	font-size: 1.1rem;
}

.legend {
	font-weight: 600;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	font-size: 13px;
}

.field small {
	color: var(--color-text-maxcontrast);
	font-size: 11px;
}

.row {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.grow {
	flex: 1 1 160px;
}

.checkbox {
	display: flex;
	align-items: flex-start;
	gap: 8px;
	font-size: 13px;
}

.checkbox small {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: 11px;
}

.actions {
	display: flex;
	justify-content: flex-end;
	gap: 10px;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
}
</style>
