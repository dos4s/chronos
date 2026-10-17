import type { StatusChange, Workflow, WorkflowStatus, WorkflowTemplate } from '../types/index.ts'

import { del, get, post, put } from './client.ts'

interface WorkflowsResponse {
	workflows: Workflow[]
	templates: WorkflowTemplate[]
}

export const fetchWorkflows = () => get<WorkflowsResponse>('/workflows')

export function createWorkflow(template: string, name: string | null) {
	return post<Workflow>('/workflows', { template, name })
}

export const renameWorkflow = (id: number, name: string) => put<Workflow>(`/workflows/${id}`, { name })

export const deleteWorkflow = (id: number) => del<{ ok: true }>(`/workflows/${id}`)

/** Which workflow a project uses; null takes it away. */
export function assignWorkflow(project: string, workflowId: number | null) {
	return put<{ workflow: Workflow | null }>('/workflows/project', { project, workflowId })
}

export function fetchHistory(uid: string) {
	return get<StatusChange[]>(`/tasks/${encodeURIComponent(uid)}/history`)
}

/**
 * A status's own settings. Its key and its standard-status mapping are fixed
 * for life — the index and every other client read those (§12.1) — so only the
 * things a board cares about can change here.
 */
export function updateStatus(workflowId: number, statusId: number, changes: {
	name?: string
	color?: string | null
	wipLimit?: number | null
	autoTimer?: boolean
	requiresAssignee?: boolean
	slaPaused?: boolean
}) {
	return put<WorkflowStatus>(`/workflows/${workflowId}/statuses/${statusId}`, changes)
}

/**
 * Replaces every transition rule of a workflow. An empty list allows every
 * move again, which is what a workflow with no rules at all means (§10.3).
 */
export function setTransitions(workflowId: number, rules: {
	from: number
	to: number
	requiresComment?: boolean
}[]) {
	return put<Workflow>(`/workflows/${workflowId}/transitions`, { rules })
}
