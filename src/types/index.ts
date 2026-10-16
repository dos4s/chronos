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

/** A grouping above projects, which exists only in Chronos (§6). */
export interface Area {
	id: number
	name: string
	color: string | null
	sort: number
}

/**
 * A project: a task list, plus what Chronos has been told about it. A project
 * nobody has configured still appears here, with the defaults.
 */
export interface Project {
	/** The reference a time session stores, `principals/users/<uid>/<uri>`. */
	uri: string
	/** The bare calendar URI, which is what a task row carries. */
	calendarUri: string
	name: string
	color: string | null
	/** A list someone else owns and shared with this user. */
	shared: boolean
	/** False for a list shared read-only: a project to look at, not to edit. */
	writable: boolean
	areaId: number | null
	parentUri: string | null
	workflowId: number | null
	archived: boolean
	sort: number
}

/**
 * A project's Chronos settings. Like a task write, this replaces all of them,
 * so the caller sends the configuration as it should end up.
 */
export interface ProjectConfig {
	areaId: number | null
	parentUri: string | null
	archived: boolean
	sort: number
}

export type TaskType = 'task' | 'subproject' | 'milestone'

export type VTodoStatus = 'NEEDS-ACTION' | 'IN-PROCESS' | 'COMPLETED' | 'CANCELLED'

/** A task as the index reports it. */
export interface Task {
	id: number
	calendarUri: string
	objectUri: string
	etag: string
	uid: string
	type: TaskType
	parentUid: string | null
	summary: string | null
	vtodoStatus: VTodoStatus | null
	priority: number
	percent: number | null
	assignee: string | null
	startAt: UnixSeconds | null
	dueAt: UnixSeconds | null
	startIsDate: boolean
	dueIsDate: boolean
	completedAt: UnixSeconds | null
	statusId: number | null
	sprintId: number | null
	updatedAt: UnixSeconds
}

/**
 * The fields Chronos manages on a task. A write replaces all of them, so the
 * caller sends the task as it should end up, not only what changed.
 */
export interface TaskWritePayload {
	summary: string
	status: VTodoStatus | null
	priority: number
	startAt: UnixSeconds | null
	startIsDate: boolean
	dueAt: UnixSeconds | null
	dueIsDate: boolean
	percent: number | null
	parentUid: string | null
	type: TaskType
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
