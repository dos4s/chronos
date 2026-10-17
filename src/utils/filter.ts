import type { PlanRow, Task } from '../types/index.ts'
import type { TaskNode } from './tasks.ts'

import { isCompleted } from './tasks.ts'

/**
 * Finding a task, and getting the finished ones out of the way.
 *
 * Two thirds of a real list is usually done — 54 of 83 on the list this was
 * written against — and every one of those was being drawn, struck through,
 * for ever. So: the finished work is hidden unless it is asked for, and there
 * is a box to type a name into.
 *
 * The rule that matters is about **parents**. Hiding a finished task must not
 * hide the unfinished work underneath it, and searching for a subtask must
 * leave enough of the tree to see where it sits — so a task is kept when it
 * passes the filter *or* when anything below it does. A task kept only for
 * what is under it is marked `faded`, so the view can draw it as context
 * rather than as a result.
 */

export interface TaskFilter {
	/** What was typed into the box; matched case-insensitively. */
	query: string
	/** Whether finished work is drawn at all. */
	showCompleted: boolean
}

export const NO_FILTER: TaskFilter = { query: '', showCompleted: false }

export function isFiltering(filter: TaskFilter): boolean {
	return filter.query.trim() !== '' || !filter.showCompleted
}

/** Whether a task passes on its own account. */
export function passes(task: Task, filter: TaskFilter): boolean {
	if (!filter.showCompleted && isCompleted(task)) {
		return false
	}

	const query = filter.query.trim().toLowerCase()
	if (query === '') {
		return true
	}

	return (task.summary ?? '').toLowerCase().includes(query)
}

/** A node of a filtered tree: the task, what is left under it, and why. */
export interface FilteredNode {
	task: Task
	children: FilteredNode[]
	/** True when it is only here to show where its children are. */
	faded: boolean
}

/**
 * The tree with everything the filter leaves out taken out of it — and
 * everything above what it leaves in kept, faded.
 */
export function filterTree(nodes: TaskNode[], filter: TaskFilter): FilteredNode[] {
	const kept: FilteredNode[] = []

	for (const node of nodes) {
		const children = filterTree(node.children, filter)
		const own = passes(node.task, filter)

		if (own || children.length > 0) {
			kept.push({ task: node.task, children, faded: !own })
		}
	}

	return kept
}

/** How many tasks a filtered tree holds, context rows included. */
export function countNodes(nodes: FilteredNode[]): number {
	return nodes.reduce((total, node) => total + 1 + countNodes(node.children), 0)
}

/**
 * The same for the timeline, whose rows are flat with a depth: a row is kept
 * when it passes or when anything below it does, and the rows above a kept one
 * come with it so the tree still reads.
 */
export function filterRows(rows: PlanRow[], filter: TaskFilter): PlanRow[] {
	if (!isFiltering(filter)) {
		return rows
	}

	const children = new Map<string, string[]>()
	for (const row of rows) {
		const parentUid = row.parentUid
		if (parentUid !== null && parentUid !== row.uid) {
			children.set(parentUid, [...children.get(parentUid) ?? [], row.uid])
		}
	}

	const byUid = new Map(rows.map((row) => [row.uid, row]))
	const keep = new Set<string>()

	/** Whether this row, or anything under it, passes. */
	function walk(uid: string, seen: Set<string>): boolean {
		if (seen.has(uid)) {
			return false
		}
		seen.add(uid)

		const row = byUid.get(uid)
		if (row === undefined) {
			return false
		}

		// Every child is walked, not just until one passes: a kept child keeps
		// its own subtree, and stopping early would drop the rest.
		let below = false
		for (const child of children.get(uid) ?? []) {
			below = walk(child, seen) || below
		}

		const own = passes(row, filter)
		if (own || below) {
			keep.add(uid)
			return true
		}

		return false
	}

	for (const row of rows) {
		walk(row.uid, new Set())
	}

	return rows.filter((row) => keep.has(row.uid))
}
