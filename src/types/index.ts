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

/** One status of a workflow, and a kanban column once that view exists. */
export interface WorkflowStatus {
	id: number
	workflowId: number
	key: string
	name: string
	color: string | null
	sort: number
	/** What every other CalDAV client sees instead of this status. */
	vtodoStatus: VTodoStatus
	isInitial: boolean
	isFinal: boolean
	slaPaused: boolean
	autoTimer: boolean
	requiresAssignee: boolean
	wipLimit: number | null
}

export interface WorkflowTransition {
	id: number
	workflowId: number
	fromStatusId: number
	toStatusId: number
	requiresComment: boolean
}

/** A workflow with its statuses in order; no transitions means any move. */
export interface Workflow {
	id: number
	name: string
	isTemplate: boolean
	statuses: WorkflowStatus[]
	transitions: WorkflowTransition[]
}

export interface WorkflowTemplate {
	key: string
	name: string
	statuses: {
		key: string
		name: string
		vtodoStatus: VTodoStatus
		isInitial: boolean
		isFinal: boolean
		slaPaused: boolean
		autoTimer: boolean
		requiresAssignee: boolean
	}[]
}

/** One row of a task's status history. */
export interface StatusChange {
	id: number
	taskUid: string
	fromStatusId: number | null
	toStatusId: number | null
	fromKey: string | null
	toKey: string | null
	fromAssignee: string | null
	toAssignee: string | null
	changedBy: string
	/** Which side of CalDAV the change came from. */
	source: 'chronos' | 'external'
	comment: string | null
	changedAt: UnixSeconds
}

/**
 * One column of a board: a workflow status, or one of the four standard ones
 * when the project has no workflow (§12.1). Kanban columns *are* the statuses
 * — there is no column model (AD7).
 */
export interface BoardColumn {
	/** Null for a standard status, which is not a row in any table. */
	statusId: number | null
	/** How a drop names this column: a workflow key, or a standard status. */
	key: string
	name: string
	color: string | null
	vtodoStatus: VTodoStatus
	isInitial: boolean
	isFinal: boolean
	wipLimit: number | null
	count: number
	/** Advisory: the board says so, the server still accepts the drop. */
	overWipLimit: boolean
	cards: Task[]
}

/** A move the workflow allows, named by column keys; none means any move. */
export interface BoardRule {
	from: string
	to: string
	requiresComment: boolean
}

export interface Board {
	project: {
		uri: string
		calendarUri: string
		name: string
		color: string | null
		writable: boolean
	}
	workflowId: number | null
	rules: BoardRule[]
	columns: BoardColumn[]
}

/** One drop, as the server takes it (§15.6). */
export interface BoardMovePayload {
	project: string
	column: string
	cards: { uid: string, etag?: string | null }[]
	/** The card the drop landed below; null is the top of the column. */
	afterUid?: string | null
	/** The card it landed above; null is the bottom. */
	beforeUid?: string | null
	comment?: string | null
}

/**
 * What a drop changed: the cards as they now are — above all their new etags —
 * and the size of every column, for the WIP warnings.
 */
export interface BoardMoveResult {
	cards: (Task & { rank: string | null })[]
	counts: Record<string, number>
}

/** Which modules this instance offers and this user wants (§13). */
export type ModuleState = Record<string, boolean>

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
	/** A workflow status, by key; left out keeps the one the task is in. */
	statusKey?: string | null
	/** A note on the status change, which a transition may require. */
	comment?: string | null
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
