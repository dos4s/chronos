import type { Seconds, UnixSeconds } from '../api/time.ts'
import type { Task, TaskWritePayload } from '../types/index.ts'

import { addDays, nowSeconds, startOfDay } from '../api/time.ts'

export interface TaskNode {
	task: Task
	children: TaskNode[]
}

export function isCompleted(task: Task): boolean {
	return task.vtodoStatus === 'COMPLETED' || task.vtodoStatus === 'CANCELLED'
}

/**
 * Whether a task's due date has passed.
 *
 * An all-day date is a whole day, not an instant (§8.4): a task due today is
 * due *by the end of* today, so it only turns overdue tomorrow. Getting this
 * wrong would mark half the list red every morning.
 */
export function isOverdue(task: Task, now: UnixSeconds = nowSeconds()): boolean {
	if (task.dueAt === null || isCompleted(task)) {
		return false
	}

	if (task.dueIsDate) {
		// The stored value is midnight UTC of that date; the day it stands for
		// ends at the viewer's next local midnight.
		return startOfDay(now) > task.dueAt
	}

	return task.dueAt < now
}

/** Local midnight today, for "due today" comparisons. */
export function isDueToday(task: Task, now: UnixSeconds = nowSeconds()): boolean {
	if (task.dueAt === null) {
		return false
	}

	const today = startOfDay(now)
	if (task.dueIsDate) {
		return task.dueAt === today
	}

	return task.dueAt >= today && task.dueAt < addDays(today, 1)
}

/**
 * Nests a flat list of tasks by their parent.
 *
 * A task whose parent is not in the list — a subtask of something in another
 * project, or of a task the user cannot see — is shown at the top level rather
 * than hidden.
 */
export function buildTaskTree(tasks: Task[]): TaskNode[] {
	const nodes = new Map<string, TaskNode>()
	for (const task of tasks) {
		nodes.set(task.uid, { task, children: [] })
	}

	const roots: TaskNode[] = []
	for (const node of nodes.values()) {
		const parent = node.task.parentUid === null ? undefined : nodes.get(node.task.parentUid)
		if (parent === undefined || parent === node) {
			roots.push(node)
			continue
		}
		parent.children.push(node)
	}

	sortNodes(roots)
	return roots
}

/**
 * Open work first, then by due date with the undated last, then by name — the
 * order someone scanning a list wants.
 */
function sortNodes(nodes: TaskNode[]): void {
	nodes.sort((a, b) => compare(a.task, b.task))
	for (const node of nodes) {
		sortNodes(node.children)
	}
}

function compare(a: Task, b: Task): number {
	if (isCompleted(a) !== isCompleted(b)) {
		return isCompleted(a) ? 1 : -1
	}

	if (a.dueAt !== b.dueAt) {
		if (a.dueAt === null) {
			return 1
		}
		if (b.dueAt === null) {
			return -1
		}
		return a.dueAt - b.dueAt
	}

	return (a.summary ?? '').localeCompare(b.summary ?? '')
}

export function countOpen(tasks: Task[]): number {
	return tasks.filter((task) => !isCompleted(task)).length
}

/**
 * The task as it stands, ready to be sent back with a change applied: a write
 * replaces every field Chronos manages, so an edit has to carry the rest.
 */
export function toWritePayload(task: Task, changes: Partial<TaskWritePayload> = {}): TaskWritePayload {
	return {
		summary: task.summary ?? '',
		status: task.vtodoStatus,
		priority: task.priority,
		startAt: task.startAt,
		startIsDate: task.startIsDate,
		dueAt: task.dueAt,
		dueIsDate: task.dueIsDate,
		percent: task.percent,
		parentUid: task.parentUid,
		type: task.type,
		...changes,
	}
}

/** Seconds of work a completed task took, when both ends are known. */
export function completionSeconds(task: Task): Seconds | null {
	if (task.completedAt === null || task.startAt === null) {
		return null
	}
	return Math.max(0, task.completedAt - task.startAt)
}
