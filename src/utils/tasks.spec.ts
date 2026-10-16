import type { Task } from '../types/index.ts'

import { describe, expect, it } from 'vitest'
import { asSeconds, fromDate, startOfDay } from '../api/time.ts'
import { buildTaskTree, countOpen, isCompleted, isDueToday, isOverdue, toWritePayload } from './tasks.ts'

const NOW = fromDate(new Date(2026, 9, 16, 14, 30))
const TODAY = startOfDay(NOW)

function task(overrides: Partial<Task> = {}): Task {
	return {
		id: 1,
		calendarUri: 'work',
		objectUri: 'task-1.ics',
		etag: '"e1"',
		uid: 'task-1',
		type: 'task',
		parentUid: null,
		summary: 'A task',
		vtodoStatus: 'NEEDS-ACTION',
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
		updatedAt: NOW,
		...overrides,
	}
}

describe('isCompleted', () => {
	it('counts completed and cancelled as finished', () => {
		expect(isCompleted(task({ vtodoStatus: 'COMPLETED' }))).toBe(true)
		expect(isCompleted(task({ vtodoStatus: 'CANCELLED' }))).toBe(true)
		expect(isCompleted(task({ vtodoStatus: 'IN-PROCESS' }))).toBe(false)
		expect(isCompleted(task({ vtodoStatus: null }))).toBe(false)
	})
})

describe('isOverdue', () => {
	/**
	 * The point of the all-day rule: a task due today is due by the end of
	 * today, so it must not look late at breakfast.
	 */
	it('does not call an all-day task due today overdue', () => {
		expect(isOverdue(task({ dueAt: TODAY, dueIsDate: true }), NOW)).toBe(false)
	})

	it('calls an all-day task due yesterday overdue', () => {
		const yesterday = asSeconds(TODAY - 86400)
		expect(isOverdue(task({ dueAt: yesterday, dueIsDate: true }), NOW)).toBe(true)
	})

	it('compares a timed task against the moment', () => {
		expect(isOverdue(task({ dueAt: asSeconds(NOW - 60), dueIsDate: false }), NOW)).toBe(true)
		expect(isOverdue(task({ dueAt: asSeconds(NOW + 60), dueIsDate: false }), NOW)).toBe(false)
	})

	it('never calls a finished task overdue', () => {
		const late = asSeconds(TODAY - 86400 * 5)
		expect(isOverdue(task({ dueAt: late, vtodoStatus: 'COMPLETED' }), NOW)).toBe(false)
		expect(isOverdue(task({ dueAt: late, vtodoStatus: 'CANCELLED' }), NOW)).toBe(false)
	})

	it('never calls an undated task overdue', () => {
		expect(isOverdue(task(), NOW)).toBe(false)
	})
})

describe('isDueToday', () => {
	it('recognises an all-day task due today', () => {
		expect(isDueToday(task({ dueAt: TODAY, dueIsDate: true }), NOW)).toBe(true)
		expect(isDueToday(task({ dueAt: asSeconds(TODAY + 86400), dueIsDate: true }), NOW)).toBe(false)
	})

	it('recognises a timed task due later today', () => {
		expect(isDueToday(task({ dueAt: asSeconds(NOW + 3600), dueIsDate: false }), NOW)).toBe(true)
	})

	it('does not count a task due tomorrow morning', () => {
		const tomorrowMorning = fromDate(new Date(2026, 9, 17, 9, 0))
		expect(isDueToday(task({ dueAt: tomorrowMorning, dueIsDate: false }), NOW)).toBe(false)
	})
})

describe('buildTaskTree', () => {
	it('nests subtasks under their parent', () => {
		const tree = buildTaskTree([
			task({ uid: 'parent', summary: 'Parent' }),
			task({ uid: 'child', parentUid: 'parent', summary: 'Child' }),
		])

		expect(tree).toHaveLength(1)
		expect(tree[0].task.uid).toBe('parent')
		expect(tree[0].children.map((node) => node.task.uid)).toEqual(['child'])
	})

	it('nests several levels deep', () => {
		const tree = buildTaskTree([
			task({ uid: 'a' }),
			task({ uid: 'b', parentUid: 'a' }),
			task({ uid: 'c', parentUid: 'b' }),
		])

		expect(tree[0].children[0].children[0].task.uid).toBe('c')
	})

	/**
	 * A subtask whose parent lives in another project, or in a list the user
	 * cannot see, must still be visible.
	 */
	it('shows a task whose parent is missing at the top level', () => {
		const tree = buildTaskTree([task({ uid: 'orphan', parentUid: 'somewhere-else' })])

		expect(tree.map((node) => node.task.uid)).toEqual(['orphan'])
	})

	it('does not lose a task that claims to be its own parent', () => {
		const tree = buildTaskTree([task({ uid: 'loop', parentUid: 'loop' })])

		expect(tree).toHaveLength(1)
		expect(tree[0].children).toEqual([])
	})

	it('puts open work before finished work', () => {
		const tree = buildTaskTree([
			task({ uid: 'done', vtodoStatus: 'COMPLETED', summary: 'Done' }),
			task({ uid: 'open', summary: 'Open' }),
		])

		expect(tree.map((node) => node.task.uid)).toEqual(['open', 'done'])
	})

	it('sorts by due date, with undated tasks last', () => {
		const tree = buildTaskTree([
			task({ uid: 'undated' }),
			task({ uid: 'later', dueAt: asSeconds(TODAY + 86400 * 3) }),
			task({ uid: 'sooner', dueAt: TODAY }),
		])

		expect(tree.map((node) => node.task.uid)).toEqual(['sooner', 'later', 'undated'])
	})

	it('falls back to the name when due dates match', () => {
		const tree = buildTaskTree([
			task({ uid: 'b', summary: 'Beta' }),
			task({ uid: 'a', summary: 'Alpha' }),
		])

		expect(tree.map((node) => node.task.uid)).toEqual(['a', 'b'])
	})

	it('sorts subtasks the same way', () => {
		const tree = buildTaskTree([
			task({ uid: 'parent' }),
			task({ uid: 'second', parentUid: 'parent', summary: 'Second' }),
			task({ uid: 'first', parentUid: 'parent', summary: 'First' }),
		])

		expect(tree[0].children.map((node) => node.task.uid)).toEqual(['first', 'second'])
	})

	it('handles an empty list', () => {
		expect(buildTaskTree([])).toEqual([])
	})
})

describe('countOpen', () => {
	it('counts only unfinished tasks', () => {
		expect(countOpen([
			task({ uid: 'a' }),
			task({ uid: 'b', vtodoStatus: 'COMPLETED' }),
			task({ uid: 'c', vtodoStatus: 'IN-PROCESS' }),
		])).toBe(2)
	})
})

describe('toWritePayload', () => {
	it('carries every managed field, because a write replaces them all', () => {
		const payload = toWritePayload(task({
			summary: 'Keep me',
			priority: 3,
			dueAt: TODAY,
			percent: 50,
			parentUid: 'parent',
		}))

		expect(payload).toEqual({
			summary: 'Keep me',
			status: 'NEEDS-ACTION',
			priority: 3,
			startAt: null,
			startIsDate: true,
			dueAt: TODAY,
			dueIsDate: true,
			percent: 50,
			parentUid: 'parent',
			type: 'task',
		})
	})

	it('applies the change on top', () => {
		const payload = toWritePayload(task({ summary: 'Before' }), { status: 'COMPLETED' })

		expect(payload.summary).toBe('Before')
		expect(payload.status).toBe('COMPLETED')
	})

	it('treats a task with no summary as an empty one', () => {
		expect(toWritePayload(task({ summary: null })).summary).toBe('')
	})
})
