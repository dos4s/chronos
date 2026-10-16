import type { Task, Workflow, WorkflowStatus, WorkflowTemplate } from '../types/index.ts'

import { showError } from '@nextcloud/dialogs'
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { messageFor } from '../api/client.ts'
import * as api from '../api/workflows.ts'
import { useModulesStore } from './modules.ts'

/**
 * The user's workflows and the templates they can start one from (§12.1).
 *
 * Loaded once, on demand: a user with no workflow never pays for the request.
 */
export const useWorkflowsStore = defineStore('workflows', () => {
	const workflows = ref<Workflow[]>([])
	const templates = ref<WorkflowTemplate[]>([])
	const loaded = ref(false)

	async function refresh(): Promise<void> {
		const modules = useModulesStore()
		if (!modules.workflow) {
			return
		}

		try {
			const response = await api.fetchWorkflows()
			workflows.value = response.workflows
			templates.value = response.templates
			loaded.value = true
		} catch (error) {
			showError(messageFor(error))
		}
	}

	/** Loads the workflows once, for a view that is about to need them. */
	async function ensureLoaded(): Promise<void> {
		if (!loaded.value) {
			await refresh()
		}
	}

	function find(id: number | null): Workflow | null {
		if (id === null) {
			return null
		}
		return workflows.value.find((workflow) => workflow.id === id) ?? null
	}

	function statusById(workflowId: number | null, statusId: number | null): WorkflowStatus | null {
		if (statusId === null) {
			return null
		}
		return find(workflowId)?.statuses.find((status) => status.id === statusId) ?? null
	}

	/** The status a task is in, as its project's workflow names it. */
	function statusOf(task: Task, workflowId: number | null): WorkflowStatus | null {
		return statusById(workflowId, task.statusId)
	}

	const names = computed(() => workflows.value.map((workflow) => workflow.name))

	async function createFromTemplate(template: string, name: string | null): Promise<Workflow | null> {
		try {
			const workflow = await api.createWorkflow(template, name)
			workflows.value.push(workflow)
			return workflow
		} catch (error) {
			showError(messageFor(error))
			return null
		}
	}

	async function rename(id: number, name: string): Promise<boolean> {
		try {
			const saved = await api.renameWorkflow(id, name)
			const index = workflows.value.findIndex((workflow) => workflow.id === id)
			if (index > -1) {
				workflows.value.splice(index, 1, saved)
			}
			return true
		} catch (error) {
			showError(messageFor(error))
			return false
		}
	}

	async function remove(id: number): Promise<boolean> {
		try {
			await api.deleteWorkflow(id)
			workflows.value = workflows.value.filter((workflow) => workflow.id !== id)
			return true
		} catch (error) {
			showError(messageFor(error))
			return false
		}
	}

	/**
	 * Gives a project a workflow, or takes it away. The tasks already in the
	 * project get the status that matches the one they have, which the server
	 * does, so the caller reloads them afterwards.
	 */
	async function assign(project: string, workflowId: number | null): Promise<boolean> {
		try {
			await api.assignWorkflow(project, workflowId)
			return true
		} catch (error) {
			showError(messageFor(error))
			return false
		}
	}

	return {
		workflows,
		templates,
		loaded,
		names,
		refresh,
		ensureLoaded,
		find,
		statusById,
		statusOf,
		createFromTemplate,
		rename,
		remove,
		assign,
	}
})
