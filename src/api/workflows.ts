import type { StatusChange, Workflow, WorkflowTemplate } from '../types/index.ts'

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
