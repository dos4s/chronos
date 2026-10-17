import type { Plan, PlanMovePayload, PlanMoveResult, PlanSettings } from '../types/index.ts'

import { del, get, post, put } from './client.ts'

/**
 * A plan is always read for one project (§16): a timeline is one screen, and
 * the cross-project view is the roadmap's own.
 */
export const fetchPlan = (project: string) => get<Plan>('/plan', { project })

/**
 * One bar, dragged or resized (§15.6): both of its dates, the etag the page
 * had for it, and the mode the drag previewed — so the ghost bars the user
 * accepted are the ones that get written.
 */
export const movePlanBar = (payload: PlanMovePayload) => post<PlanMoveResult>('/plan/move', payload)

/**
 * A dependency. The answer is the whole plan again, because one link changes
 * which others are in conflict.
 */
export function createLink(payload: {
	project: string
	predecessorUid: string
	successorUid: string
	reltype?: string
	gapSeconds?: number
}) {
	return post<Plan>('/plan/link', payload)
}

export const deleteLink = (project: string, id: number) => del<Plan>(`/plan/link/${id}`, { project })

export function savePlanSettings(payload: { project: string } & PlanSettings) {
	return put<PlanSettings>('/plan/settings', payload)
}
