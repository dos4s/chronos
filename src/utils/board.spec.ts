import type { Board, BoardCard, BoardColumn, CardSubtasks } from '../types/index.ts'

import { describe, expect, it } from 'vitest'
import { asSeconds } from '../api/time.ts'
import {
	allowsMove,
	applyDrop,
	cardShapes,
	columnOf,
	editsFor,
	effectiveRules,
	indexForPointer,
	inverseDrops,
	inverseOfCascade,
	isUnrestricted,
	needsComment,
	neighboursAt,
	openSubtasksElsewhere,
	ruleFor,
	rulesAfterEdit,
} from './board.ts'

function card(uid: string, parentUid: string | null = null, subtasks: CardSubtasks | null = null): BoardCard {
	return {
		parent: parentUid === null ? null : { uid: parentUid, summary: parentUid, type: 'task', column: 'todo' },
		subtasks,
		id: 1,
		calendarUri: 'work',
		objectUri: `${uid}.ics`,
		etag: `etag-${uid}`,
		uid,
		type: 'task',
		summary: uid,
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
		updatedAt: asSeconds(0),
		parentUid,
	}
}

function column(key: string, uids: string[], wipLimit: number | null = null): BoardColumn {
	return {
		statusId: key === 'todo' ? 1 : key === 'doing' ? 2 : 3,
		key,
		name: key,
		color: null,
		vtodoStatus: key === 'done' ? 'COMPLETED' : key === 'doing' ? 'IN-PROCESS' : 'NEEDS-ACTION',
		isInitial: key === 'todo',
		isFinal: key === 'done',
		wipLimit,
		count: uids.length,
		overWipLimit: wipLimit !== null && uids.length > wipLimit,
		cards: uids.map((uid) => card(uid)),
	}
}

function board(columns: BoardColumn[], rules: Board['rules'] = []): Board {
	return {
		project: {
			uri: 'principals/users/alice/work',
			calendarUri: 'work',
			name: 'Work',
			color: null,
			writable: true,
		},
		workflowId: 42,
		rules,
		columns,
	}
}

describe('neighboursAt', () => {
	const cards = [card('a'), card('b'), card('c')]

	it('has no card above it at the top of a column', () => {
		expect(neighboursAt(cards, 0)).toEqual({ afterUid: null, beforeUid: 'a' })
	})

	it('has no card below it at the bottom', () => {
		expect(neighboursAt(cards, 3)).toEqual({ afterUid: 'c', beforeUid: null })
	})

	it('names the cards on either side in the middle', () => {
		expect(neighboursAt(cards, 2)).toEqual({ afterUid: 'b', beforeUid: 'c' })
	})

	/**
	 * The position counts the column without the cards being moved, because
	 * that is what it will look like when they arrive.
	 */
	it('ignores the cards that are being moved', () => {
		expect(neighboursAt(cards, 1, ['b'])).toEqual({ afterUid: 'a', beforeUid: 'c' })
	})

	it('clamps a position past the end of the column', () => {
		expect(neighboursAt(cards, 99)).toEqual({ afterUid: 'c', beforeUid: null })
	})

	it('has no neighbours at all in an empty column', () => {
		expect(neighboursAt([], 0)).toEqual({ afterUid: null, beforeUid: null })
	})
})

describe('applyDrop', () => {
	it('moves a card to the position it was dropped at', () => {
		const after = applyDrop(board([column('todo', ['a', 'b']), column('doing', ['c'])]), ['a'], 'doing', 0)

		expect(after.columns[0].cards.map((it) => it.uid)).toEqual(['b'])
		expect(after.columns[1].cards.map((it) => it.uid)).toEqual(['a', 'c'])
	})

	it('gives the card the status of the column it landed in', () => {
		const after = applyDrop(board([column('todo', ['a']), column('done', [])]), ['a'], 'done', 0)
		const moved = after.columns[1].cards[0]

		expect(moved.statusId).toBe(3)
		expect(moved.vtodoStatus).toBe('COMPLETED')
	})

	it('reorders within one column', () => {
		const after = applyDrop(board([column('todo', ['a', 'b', 'c'])]), ['c'], 'todo', 0)

		expect(after.columns[0].cards.map((it) => it.uid)).toEqual(['c', 'a', 'b'])
	})

	it('keeps the order the cards were dragged in', () => {
		const after = applyDrop(board([column('todo', ['a', 'b', 'c']), column('doing', [])]), ['c', 'a'], 'doing', 0)

		expect(after.columns[1].cards.map((it) => it.uid)).toEqual(['c', 'a'])
		expect(after.columns[0].cards.map((it) => it.uid)).toEqual(['b'])
	})

	it('keeps each column’s count and WIP warning in step', () => {
		const after = applyDrop(board([column('todo', ['a']), column('doing', ['b'], 1)]), ['a'], 'doing', 0)

		expect(after.columns[1].count).toBe(2)
		expect(after.columns[1].overWipLimit).toBe(true)
		expect(after.columns[0].count).toBe(0)
		expect(after.columns[0].overWipLimit).toBe(false)
	})

	it('leaves the board alone when the card is not on it', () => {
		const original = board([column('todo', ['a'])])

		expect(applyDrop(original, ['gone'], 'todo', 0)).toBe(original)
	})

	it('does not change the board it was given', () => {
		const original = board([column('todo', ['a']), column('doing', [])])
		applyDrop(original, ['a'], 'doing', 0)

		expect(original.columns[0].cards.map((it) => it.uid)).toEqual(['a'])
		expect(original.columns[1].cards).toEqual([])
	})
})

describe('inverseDrops', () => {
	it('puts a card back between the cards that stay', () => {
		const before = board([column('todo', ['a', 'b', 'c'])])

		expect(inverseDrops(before, ['b'])).toEqual([
			{ column: 'todo', uids: ['b'], afterUid: 'a', beforeUid: 'c' },
		])
	})

	it('is one move per column the cards came from', () => {
		const before = board([column('todo', ['a', 'b']), column('doing', ['c'])])
		const undo = inverseDrops(before, ['a', 'c'])

		expect(undo).toEqual([
			{ column: 'todo', uids: ['a'], afterUid: null, beforeUid: 'b' },
			{ column: 'doing', uids: ['c'], afterUid: null, beforeUid: null },
		])
	})

	it('has nothing to undo for cards that are not on the board', () => {
		expect(inverseDrops(board([column('todo', ['a'])]), ['gone'])).toEqual([])
	})

	/**
	 * Applying a drop and then its inverse is where it started: the undo in
	 * the toast has to restore the position, not only the status.
	 */
	it('undoes a drop exactly', () => {
		const before = board([column('todo', ['a', 'b', 'c']), column('doing', ['d'])])
		const undo = inverseDrops(before, ['b'])
		const dropped = applyDrop(before, ['b'], 'doing', 1)

		expect(dropped.columns[1].cards.map((it) => it.uid)).toEqual(['d', 'b'])

		const restored = applyDrop(
			dropped,
			undo[0].uids,
			undo[0].column,
			dropped.columns[0].cards.findIndex((it) => it.uid === undo[0].beforeUid),
		)

		expect(restored.columns[0].cards.map((it) => it.uid)).toEqual(['a', 'b', 'c'])
		expect(restored.columns[1].cards.map((it) => it.uid)).toEqual(['d'])
	})
})

describe('allowsMove', () => {
	const columns = [column('todo', []), column('doing', []), column('done', [])]

	it('allows everything when the workflow has no rules', () => {
		expect(allowsMove(board(columns), 'todo', 'done')).toBe(true)
	})

	it('allows only the moves with a rule', () => {
		const withRules = board(columns, [{ from: 'todo', to: 'doing', requiresComment: false }])

		expect(allowsMove(withRules, 'todo', 'doing')).toBe(true)
		expect(allowsMove(withRules, 'todo', 'done')).toBe(false)
	})

	it('allows a card dropped where it already is', () => {
		const withRules = board(columns, [{ from: 'todo', to: 'doing', requiresComment: false }])

		expect(allowsMove(withRules, 'done', 'done')).toBe(true)
	})

	it('knows which moves need a note', () => {
		const withRules = board(columns, [
			{ from: 'todo', to: 'doing', requiresComment: false },
			{ from: 'doing', to: 'done', requiresComment: true },
		])

		expect(needsComment(withRules, 'doing', 'done')).toBe(true)
		expect(needsComment(withRules, 'todo', 'doing')).toBe(false)
		expect(needsComment(withRules, 'done', 'done')).toBe(false)
	})
})

describe('columnOf', () => {
	it('finds the column a card is in', () => {
		expect(columnOf(board([column('todo', ['a']), column('doing', ['b'])]), 'b')?.key).toBe('doing')
	})

	it('is null for a card that is not on the board', () => {
		expect(columnOf(board([column('todo', ['a'])]), 'gone')).toBeNull()
	})
})

describe('indexForPointer', () => {
	// Midpoints measured once at drag start, so no layout is read per move.
	const midpoints = [50, 150, 250]

	it('is the top of the column above the first card', () => {
		expect(indexForPointer(midpoints, 10)).toBe(0)
	})

	it('is after a card once the pointer passes its middle', () => {
		expect(indexForPointer(midpoints, 60)).toBe(1)
		expect(indexForPointer(midpoints, 160)).toBe(2)
	})

	it('is the end of the column below the last card', () => {
		expect(indexForPointer(midpoints, 400)).toBe(3)
	})

	it('is the only position there is in an empty column', () => {
		expect(indexForPointer([], 400)).toBe(0)
	})
})

describe('transition rule editing', () => {
	const columns = [column('todo', []), column('doing', []), column('done', [])]

	/**
	 * Ticking one box must not turn "everything is allowed" into "only this
	 * is", so the matrix is written out in full before it is edited (D28).
	 */
	it('writes out every move when the workflow has no rules', () => {
		const rules = effectiveRules(board(columns))

		expect(rules).toHaveLength(6)
		expect(rules.every((rule) => !rule.requiresComment)).toBe(true)
		expect(rules.some((rule) => rule.from === rule.to)).toBe(false)
	})

	it('keeps the rules a workflow already has', () => {
		const existing = [{ from: 'todo', to: 'doing', requiresComment: true }]

		expect(effectiveRules(board(columns, existing))).toEqual(existing)
	})

	it('offers every other column as a move, all allowed when there are no rules', () => {
		const edits = editsFor(board(columns), 'todo')

		expect(edits.map((edit) => edit.to)).toEqual(['doing', 'done'])
		expect(edits.every((edit) => edit.allowed)).toBe(true)
	})

	it('offers only the moves a restricted workflow has', () => {
		const withRules = board(columns, [{ from: 'todo', to: 'doing', requiresComment: true }])
		const edits = editsFor(withRules, 'todo')

		expect(edits).toEqual([
			{ to: 'doing', allowed: true, requiresComment: true },
			{ to: 'done', allowed: false, requiresComment: false },
		])
	})

	it('restricts one column and leaves the others as they were', () => {
		const rules = rulesAfterEdit(board(columns), 'todo', [
			{ to: 'doing', allowed: true, requiresComment: false },
			{ to: 'done', allowed: false, requiresComment: false },
		])

		expect(rules.filter((rule) => rule.from === 'todo')).toEqual([
			{ from: 'todo', to: 'doing', requiresComment: false },
		])
		// Everything else still allows what it allowed before.
		expect(rules.filter((rule) => rule.from === 'doing')).toHaveLength(2)
		expect(rules.filter((rule) => rule.from === 'done')).toHaveLength(2)
	})

	it('can ask for a note on a move', () => {
		const rules = rulesAfterEdit(board(columns), 'doing', [
			{ to: 'todo', allowed: true, requiresComment: false },
			{ to: 'done', allowed: true, requiresComment: true },
		])

		expect(ruleFor({ ...board(columns), rules }, 'doing', 'done')?.requiresComment).toBe(true)
	})

	it('collapses a matrix that allows everything back to no rules at all', () => {
		expect(isUnrestricted(board(columns), effectiveRules(board(columns)))).toBe(true)
	})

	it('is restricted once a move is taken away or needs a note', () => {
		const restricted = rulesAfterEdit(board(columns), 'todo', [
			{ to: 'doing', allowed: true, requiresComment: false },
			{ to: 'done', allowed: false, requiresComment: false },
		])
		expect(isUnrestricted(board(columns), restricted)).toBe(false)

		const noted = rulesAfterEdit(board(columns), 'todo', [
			{ to: 'doing', allowed: true, requiresComment: true },
			{ to: 'done', allowed: true, requiresComment: false },
		])
		expect(isUnrestricted(board(columns), noted)).toBe(false)
	})
})

/**
 * How a flat board tells the truth about a tree (§15.9): a subtask sitting
 * under its parent is drawn as part of it, and one that has been dragged away
 * from its parent names it instead.
 */
describe('how a card is drawn', () => {
	it('draws a subtask under its parent as part of it', () => {
		const shapes = cardShapes([card('parent'), card('child', 'parent')])

		expect(shapes.parent).toEqual({ indented: false, showsParent: false })
		expect(shapes.child).toEqual({ indented: true, showsParent: false })
	})

	it('keeps a run of siblings in the group', () => {
		const shapes = cardShapes([
			card('parent'),
			card('one', 'parent'),
			card('two', 'parent'),
			card('after'),
		])

		expect(shapes.one.indented).toBe(true)
		expect(shapes.two.indented).toBe(true)
		expect(shapes.after.indented).toBe(false)
	})

	/**
	 * The ranks are the user's arrangement and nesting never overrides them,
	 * so a subtask can be sitting anywhere in its column — and then the only
	 * thing that can say whose it is is the card itself.
	 */
	it('makes a subtask away from its parent name it', () => {
		const shapes = cardShapes([card('child', 'parent'), card('other'), card('parent')])

		expect(shapes.child).toEqual({ indented: false, showsParent: true })
	})

	it('does not carry the group across a card from somewhere else', () => {
		const shapes = cardShapes([
			card('parent'),
			card('one', 'parent'),
			card('stranger'),
			card('two', 'parent'),
		])

		expect(shapes.one.indented).toBe(true)
		expect(shapes.two).toEqual({ indented: false, showsParent: true })
	})

	/**
	 * A task in a subproject is not a subtask of it (§6): it is one of the
	 * project's own cards that happens to live in a sublist, so it says where
	 * it lives and is never drawn as part of anything.
	 */
	it('says which sublist a card lives in, without indenting it', () => {
		const inside = card('inside')
		inside.parentUid = 'container'
		inside.parent = { uid: 'container', summary: 'Phase one', type: 'subproject', column: null }

		expect(cardShapes([inside]).inside).toEqual({ indented: false, showsParent: true })
	})

	it('has nothing to say about a card with no parent', () => {
		expect(cardShapes([card('alone')]).alone).toEqual({ indented: false, showsParent: false })
	})
})

describe('the subtasks a drop would leave behind', () => {
	const parent = card('parent', null, { total: 4, done: 1, open: { todo: 2, doing: 1 } })

	it('counts the unfinished ones that are not already in the target column', () => {
		expect(openSubtasksElsewhere(parent, 'done')).toBe(3)
		expect(openSubtasksElsewhere(parent, 'doing')).toBe(2)
	})

	it('counts none for a card with no subtasks', () => {
		expect(openSubtasksElsewhere(card('alone'), 'done')).toBe(0)
	})
})

/**
 * Undoing a cascade: the page never dragged the subtasks, so the only thing it
 * knows about where they were is what the server told it (§15.9).
 */
describe('the inverse of a cascade', () => {
	it('sends each card the page did not drag back to the column it came from', () => {
		const moved: BoardCard[] = [
			{ ...card('parent'), fromColumn: 'todo' },
			{ ...card('one', 'parent'), fromColumn: 'todo' },
			{ ...card('two', 'parent'), fromColumn: 'doing' },
		]

		expect(inverseOfCascade(moved, ['parent'])).toEqual([
			{ column: 'todo', uids: ['one'], afterUid: null, beforeUid: null },
			{ column: 'doing', uids: ['two'], afterUid: null, beforeUid: null },
		])
	})

	/** A parent sent back because its progress changed has not moved at all. */
	it('ignores a card that did not change column', () => {
		const moved: BoardCard[] = [
			{ ...card('child', 'parent'), fromColumn: 'todo' },
			{ ...card('parent'), fromColumn: null },
		]

		expect(inverseOfCascade(moved, ['child'])).toEqual([])
	})
})
