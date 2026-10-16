import type { Seconds, UnixSeconds } from '../api/time.ts'

export interface Pause {
	id: number
	entryId: number
	startAt: UnixSeconds
	endAt: UnixSeconds | null
}

/** A time session, as the API returns it. */
export interface Session {
	id: number
	userId: string
	startAt: UnixSeconds
	endAt: UnixSeconds | null
	note: string | null
	/** Set while the session is paused; the open pause's start. */
	pausedSinceAt: UnixSeconds | null
	/** Total of the finished pauses. */
	pausedSeconds: Seconds
	projectUri: string | null
	projectName: string | null
	pauses: Pause[]
}

export interface TaskList {
	uri: string
	name: string
	color: string | null
}

export interface CheckInPayload {
	note?: string
	projectUri?: string
	projectName?: string
}

export interface SessionUpdatePayload {
	startAt: UnixSeconds
	endAt: UnixSeconds | null
	pauses: { startAt: UnixSeconds, endAt: UnixSeconds | null }[]
	note: string | null
}

export interface DayTotal {
	date: string
	seconds: Seconds
	projects: { projectUri: string | null, seconds: Seconds }[]
}

export interface ProjectTotal {
	projectUri: string | null
	projectName: string | null
	seconds: Seconds
	sessionCount: number
}

/** The server's aggregation over a date range (§16). */
export interface AnalyticsSummary {
	fromAt: UnixSeconds
	toAt: UnixSeconds
	timezone: string
	totalSeconds: Seconds
	sessionCount: number
	days: DayTotal[]
	projects: ProjectTotal[]
}

export type ModuleName = 'core' | 'time' | 'projects' | 'workflow' | 'kanban'
	| 'fields' | 'sla' | 'gantt' | 'roadmap' | 'sprints' | 'matrix'
