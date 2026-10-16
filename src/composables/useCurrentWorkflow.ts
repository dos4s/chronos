import type { Workflow, WorkflowStatus } from '../types/index.ts'

import { computed } from 'vue'
import { useModulesStore } from '../stores/modules.ts'
import { useProjectsStore } from '../stores/projects.ts'
import { useTasksStore } from '../stores/tasks.ts'
import { useWorkflowsStore } from '../stores/workflows.ts'

/**
 * The workflow of the project the task views are showing, if it has one and
 * the module is on (§12.1, §13).
 *
 * Kept in one place so a row, a dialog and later a board all agree on which
 * statuses exist without passing them down through every component.
 */
export function useCurrentWorkflow() {
	const modules = useModulesStore()
	const projects = useProjectsStore()
	const tasks = useTasksStore()
	const workflows = useWorkflowsStore()

	const workflowId = computed<number | null>(() => {
		if (!modules.workflow) {
			return null
		}
		return projects.find(tasks.projectRef)?.workflowId ?? null
	})

	const workflow = computed<Workflow | null>(() => workflows.find(workflowId.value))

	const statuses = computed<WorkflowStatus[]>(() => workflow.value?.statuses ?? [])

	return { workflowId, workflow, statuses }
}
