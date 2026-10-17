import type { Area, Project, ProjectConfig, PromoteResult } from '../types/index.ts'

import { del, get, post, put } from './client.ts'

export const fetchProjects = () => get<Project[]>('/projects')

/** Replaces a project's settings; the server creates its row if it has none. */
export function configureProject(project: string, config: ProjectConfig) {
	return put<Project>('/projects', { project, ...config })
}

export const fetchAreas = () => get<Area[]>('/areas')

export function createArea(name: string, color: string | null) {
	return post<Area>('/areas', { name, color })
}

export function updateArea(id: number, name: string, color: string | null) {
	return put<Area>(`/areas/${id}`, { name, color })
}

/** Deleting an area deletes no project: they simply stop being in one. */
export const deleteArea = (id: number) => del<{ ok: true }>(`/areas/${id}`)

/**
 * Promotes a subproject to a list of its own (F2, §6).
 *
 * One copy and one delete per task, with no transaction to wrap them in, so
 * the answer says how many moved — and running it again moves whatever is
 * left.
 */
export function promoteSubproject(project: string, uid: string, name: string | null) {
	return post<PromoteResult>('/projects/promote', { project, uid, name })
}
