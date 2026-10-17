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
/**
 * The task or subproject a card hangs under, as the board sends it (§15.9).
 *
 * A parent in another list, or one the user cannot see, is no parent at all
 * here — the same choice the task tree makes. `column` is null when the parent
 * is a subproject, because a subproject is a container for work rather than a
 * piece of it, and is therefore never a card (§6).
 */
export interface CardParent {
	uid: string
	summary: string | null
	type: TaskType
	column: string | null
}

/**
 * How much of the branch under a card is finished, and where the rest of it is
 * (§15.9). Counted over the whole branch rather than the direct children,
 * because that is what a card stands for when the board is rolled up.
 */
export interface CardSubtasks {
	total: number
	done: number
	/** Column key → how many unfinished subtasks are sitting in it. */
	open: Record<string, number>
}

/**
 * A card on the board: a task, plus what the tree it came out of says about it.
 *
 * `rank` and `fromColumn` are only on the cards a move sends back: the place
 * it was given, and the column it came from, which is how an undo puts back a
 * card the page never dragged.
 */
export type BoardCard = Task & {
	parent: CardParent | null
	subtasks: CardSubtasks | null
	rank?: string | null
	fromColumn?: string | null
}

/** Which cards a board shows: every task, or only the top of each branch. */
export type SubtaskView = 'cards' | 'rolled'

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
	cards: BoardCard[]
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
	/** The view it was dropped on: a rolled-up board counts what it shows. */
	subtasks?: SubtaskView
	/** Take each card's unfinished subtasks along with it (§15.9). */
	cascade?: boolean
}

/**
 * What a drop changed: the cards as they now are — above all their new etags —
 * and the size of every column, for the WIP warnings.
 *
 * Some of those cards are ones the page never dragged: the parents whose
 * progress the drop changed, and the subtasks a cascade took with it (§15.9).
 */
export interface BoardMoveResult {
	cards: BoardCard[]
	counts: Record<string, number>
}

/**
 * One bar on a timeline (§12.5).
 *
 * `endAt` is **exclusive** — the instant after the bar, which for an all-day
 * task is the start of the day after its `DUE` date (§8.4). Keeping it that way
 * is what makes every comparison in the scheduler a plain one.
 */
export interface PlanBar {
	startAt: UnixSeconds
	endAt: UnixSeconds
	startIsDate: boolean
	dueIsDate: boolean
	/** One end is the plan's guess, not a date anybody set. */
	inferred: boolean
	isMilestone: boolean
}

/** One line of a timeline: a task, its depth in the tree, and its bar. */
export type PlanRow = Task & {
	depth: number
	bar: PlanBar | null
	/** The bar is the span of what is under it, so it is not dragged. */
	isSummary: boolean
	/** 0–100, from `PERCENT-COMPLETE` or from how much of a branch is done. */
	progress: number | null
}

/** A dependency, always stored predecessor → successor (§7, RFC 9253). */
export interface PlanLink {
	id: number
	predecessorUid: string
	successorUid: string
	reltype: string
	gapSeconds: number
	/** False for `DEPENDS-ON`, which is drawn and never scheduled. */
	temporal: boolean
}

/** A dependency the dates do not satisfy (§15.3). */
export interface PlanConflict {
	kind: 'too_early' | 'cycle'
	predecessorUid: string
	successorUid: string
	reltype: string
	requiredAt: UnixSeconds
	actualAt: UnixSeconds
}

/** What a project says about its own timeline (§10.2). */
export interface PlanSettings {
	mode: 'warn' | 'cascade'
	/** ISO weekdays, 1 = Monday; empty means every day works. */
	workingDays: number[]
}

export interface Plan {
	project: {
		uri: string
		calendarUri: string
		name: string
		color: string | null
		writable: boolean
	}
	/** The extent of everything that has a bar, or null when nothing has. */
	from: UnixSeconds | null
	to: UnixSeconds | null
	rows: PlanRow[]
	links: PlanLink[]
	conflicts: PlanConflict[]
	settings: PlanSettings
}

/** One bar, dragged or resized (§15.6). */
export interface PlanMovePayload {
	project: string
	uid: string
	startAt: UnixSeconds
	dueAt: UnixSeconds
	startIsDate: boolean
	dueIsDate: boolean
	etag?: string | null
	/** The mode the drag previewed, so the ghosts the user saw are written. */
	mode?: 'warn' | 'cascade'
}

/**
 * What a drag changed: every row that was written — and the ancestors whose
 * summary bars it stretched — plus the conflicts that are left (§15.6).
 */
export interface PlanMoveResult {
	rows: PlanRow[]
	conflicts: PlanConflict[]
	cascaded: boolean
}

/**
 * What promoting a subproject to a list did (F2, §6).
 *
 * `left` is not zero when the move stopped part way: CalDAV is one object at a
 * time, so the answer says how far it got and the operation can be run again.
 */
export interface PromoteResult {
	project: Project
	moved: number
	left: number
	failure: string | null
}

/** A date something has to be true by: a milestone with a `DUE` (§6). */
export interface RoadmapMilestone {
	uid: string
	summary: string | null
	at: UnixSeconds
	isDate: boolean
	done: boolean
}

/** One line of the roadmap: a project, or a subproject inside one (§12.6). */
export interface RoadmapRow {
	/** The project reference, which is what a click opens. */
	ref: string
	name: string
	color: string | null
	depth: number
	areaId: number | null
	/** For a subproject, the container VTODO it is. */
	parentUid: string | null
	span: PlanBar | null
	tasks: number
	open: number
	milestones: RoadmapMilestone[]
}

export interface RoadmapArea {
	id: number | null
	name: string | null
	color: string | null
}

export interface Roadmap {
	areas: RoadmapArea[]
	from: UnixSeconds | null
	to: UnixSeconds | null
	rows: RoadmapRow[]
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
