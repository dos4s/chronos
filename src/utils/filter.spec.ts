import type { PlanRow, Task } from '../types/index.ts'
import type { TaskNode } from './tasks.ts'

import { describe, expect, it } from 'vitest'
import { asSeconds } from '../api/time.ts'
import { countNodes, filterRows, filterTree, isFiltering, NO_FILTER, passes } from './filter.ts'

/**
 * Finding a task, and getting the finished ones out of the way.
 *
 * The rule worth testing is about parents: hiding finished work must not hide
 * the unfinished work underneath it, and searching for a subtask must leave
 * enough of the tree to see where it sits.
 */

function task(uid: string, summary: string, done = false, parentUid: string | null = null): Task {
	return {
		id: 1,
		calendarUri: 'work',
		objectUri: `${uid}.ics`,
		etag: `etag-${uid}`,
		uid,
		type: 'task',
		parentUid,
		summary,
		vtodoStatus: done ? 'COMPLETED' : 'NEEDS-ACTION',
		priority: 0,
		percent: null,
		assignee: null,
		startAt: null,
		dueAt: null,
		startIsDate: true,
		dueIsDate: true,
		completedAt: null,
		statusId: null,
		sprintId: null,
		updatedAt: asSeconds(0),
	}
}

function node(own: Task, children: TaskNode[] = []): TaskNode {
	return { task: own, children }
}

function row(own: Task, depth = 0): PlanRow {
	return { ...own, depth, bar: null, isSummary: false, progress: null }
}

describe('what passes a filter', () => {
	it('leaves finished work out unless it is asked for', () => {
		expect(passes(task('a', 'Ship it', true), NO_FILTER)).toBe(false)
		expect(passes(task('a', 'Ship it', true), { query: '', showCompleted: true })).toBe(true)
	})

	/** Cancelled is as finished as completed. */
	it('counts a cancelled task as finished', () => {
		const cancelled = task('a', 'Dropped')
		cancelled.vtodoStatus = 'CANCELLED'

		expect(passes(cancelled, NO_FILTER)).toBe(false)
	})

	it('matches part of a name, in any case', () => {
		const filter = { query: 'DOCS', showCompleted: true }

		expect(passes(task('a', 'Write the docs'), filter)).toBe(true)
		expect(passes(task('b', 'Tag the release'), filter)).toBe(false)
	})

	it('is not filtering when everything is shown', () => {
		expect(isFiltering({ query: '', showCompleted: true })).toBe(false)
		expect(isFiltering(NO_FILTER)).toBe(true)
		expect(isFiltering({ query: 'x', showCompleted: true })).toBe(true)
	})
})

describe('filtering a tree', () => {
	const tree = [
		node(task('ship', 'Ship 0.5'), [
			node(task('docs', 'Write the docs', false, 'ship')),
			node(task('tag', 'Tag the release', true, 'ship')),
		]),
		node(task('old', 'Old thing', true)),
	]

	it('hides finished tasks and keeps the rest', () => {
		const kept = filterTree(tree, NO_FILTER)

		expect(kept.map((it) => it.task.uid)).toEqual(['ship'])
		expect(kept[0].children.map((it) => it.task.uid)).toEqual(['docs'])
	})

	/**
	 * A finished parent with unfinished work under it stays — faded, because
	 * it is there to show where the work sits rather than as a result.
	 */
	it('keeps a finished parent that still has open work under it', () => {
		const withDoneParent = [
			node(task('done-parent', 'Phase one', true), [
				node(task('open-child', 'Still to do', false, 'done-parent')),
			]),
		]

		const kept = filterTree(withDoneParent, NO_FILTER)

		expect(kept.map((it) => it.task.uid)).toEqual(['done-parent'])
		expect(kept[0].faded).toBe(true)
		expect(kept[0].children[0].faded).toBe(false)
	})

	it('keeps the parents of a match so the tree still reads', () => {
		const kept = filterTree(tree, { query: 'docs', showCompleted: true })

		expect(kept.map((it) => it.task.uid)).toEqual(['ship'])
		expect(kept[0].faded).toBe(true)
		expect(kept[0].children.map((it) => it.task.uid)).toEqual(['docs'])
	})

	it('finds nothing when nothing matches', () => {
		expect(filterTree(tree, { query: 'nothing here', showCompleted: true })).toEqual([])
	})

	it('counts what is left, context rows included', () => {
		expect(countNodes(filterTree(tree, { query: 'docs', showCompleted: true }))).toBe(2)
	})
})

describe('filtering the timeline rows', () => {
	const rows = [
		row(task('ship', 'Ship 0.5')),
		row(task('docs', 'Write the docs', false, 'ship'), 1),
		row(task('tag', 'Tag the release', true, 'ship'), 1),
		row(task('old', 'Old thing', true)),
	]

	it('gives back every row when nothing is being filtered', () => {
		const everything = { query: '', showCompleted: true }

		expect(filterRows(rows, everything)).toHaveLength(4)
	})

	it('hides the finished rows and keeps their parents', () => {
		const kept = filterRows(rows, NO_FILTER)

		expect(kept.map((it) => it.uid)).toEqual(['ship', 'docs'])
	})

	it('keeps a matching row and everything above it', () => {
		const kept = filterRows(rows, { query: 'tag', showCompleted: true })

		expect(kept.map((it) => it.uid)).toEqual(['ship', 'tag'])
	})

	/** Two matching children keep each other, not just the first. */
	it('keeps every matching child of the same parent', () => {
		const kept = filterRows(rows, { query: 'the', showCompleted: true })

		expect(kept.map((it) => it.uid)).toEqual(['ship', 'docs', 'tag'])
	})

	/** A loop written by another client must not hang the filter. */
	it('survives a loop in the tree', () => {
		const looped = [
			row({ ...task('a', 'One'), parentUid: 'b' }),
			row({ ...task('b', 'Two'), parentUid: 'a' }),
		]

		expect(filterRows(looped, { query: 'One', showCompleted: true }).length).toBeGreaterThan(0)
	})
})
