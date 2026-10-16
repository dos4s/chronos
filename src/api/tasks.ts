import type { Task, TaskWritePayload } from '../types/index.ts'

import { del, get, post, put } from './client.ts'

export const fetchTasks = (project: string) => get<Task[]>('/tasks', { project })

export function createTask(project: string, write: TaskWritePayload) {
	return post<Task>('/tasks', { project, ...write })
}

/**
 * `etag` is the version the edit is based on; the server refuses the write with
 * a 412 if the task has moved on since.
 */
export function updateTask(uid: string, write: TaskWritePayload, etag: string) {
	return put<Task>(`/tasks/${encodeURIComponent(uid)}`, { ...write, etag })
}

export function deleteTask(uid: string, etag: string) {
	return del<{ ok: true }>(`/tasks/${encodeURIComponent(uid)}?etag=${encodeURIComponent(etag)}`)
}
