import type { BoardColumn } from '../types/index.ts'

import { describe, expect, it } from 'vitest'
import { asSeconds } from '../api/time.ts'
import { blankTask, cardIn, milestoneOn } from './compose.ts'

function column(extra: Partial<BoardColumn> = {}): BoardColumn {
	return {
		statusId: 7,
		key: 'in-review',
		name: 'In review',
		color: null,
		vtodoStatus: 'IN-PROCESS',
		isInitial: false,
		isFinal: false,
		wipLimit: null,
		count: 0,
		overWipLimit: false,
		cards: [],
		...extra,
	}
}

describe('a task made outside the list', () => {
	it('starts open, undated and at no priority', () => {
		expect(blankTask('Write it down')).toEqual({
			summary: 'Write it down',
			status: 'NEEDS-ACTION',
			priority: 0,
			startAt: null,
			startIsDate: true,
			dueAt: null,
			dueIsDate: true,
			percent: null,
			parentUid: null,
			type: 'task',
		})
	})

	it('can be a subtask of the task it was added under', () => {
		expect(blankTask('Check the wiring', 'parent-uid').parentUid).toBe('parent-uid')
	})
})

describe('a card added to a column', () => {
	it('is created in that column under a workflow', () => {
		expect(cardIn(column(), true)).toEqual({
			status: 'IN-PROCESS',
			statusKey: 'in-review',
			percent: null,
		})
	})

	// Without a workflow there are no status keys to name, and sending one is
	// refused outright — the columns are the four standard statuses.
	it('names no status key when the project has no workflow', () => {
		expect(cardIn(column({ statusId: null, key: 'IN-PROCESS' }), false).statusKey).toBeNull()
	})

	it('is finished the moment it exists when the column is a final one', () => {
		expect(cardIn(column({ key: 'done', vtodoStatus: 'COMPLETED', isFinal: true }), true)).toEqual({
			status: 'COMPLETED',
			statusKey: 'done',
			percent: 100,
		})
	})
})

describe('a milestone put on the roadmap', () => {
	const at = asSeconds(Date.parse('2026-11-14T00:00:00Z') / 1000)

	it('is a due date and nothing else', () => {
		expect(milestoneOn(at)).toEqual({
			type: 'milestone',
			startAt: null,
			startIsDate: true,
			dueAt: at,
			dueIsDate: true,
		})
	})

	// A deadline on the 14th is the 14th wherever it is read (§8.4).
	it('is a date rather than an instant', () => {
		expect(milestoneOn(at).dueIsDate).toBe(true)
	})
})
