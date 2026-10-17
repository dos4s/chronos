import type { Board, BoardColumn, BoardMoveResult, Task } from '../types/index.ts'

import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { asSeconds } from '../api/time.ts'

const fetchBoard = vi.fn()
const moveCards = vi.fn()

vi.mock('../api/board.ts', () => ({
	fetchBoard: (project: string) => fetchBoard(project),
	moveCards: (payload: unknown) => moveCards(payload),
}))

vi.mock('@nextcloud/dialogs', () => ({
	showError: vi.fn(),
	showSuccess: vi.fn(),
}))

/**
 * The HTTP client reaches for `window` as it loads — it is built for a browser
 * — so the test takes the two things the store uses from it and leaves the
 * rest alone.
 */
vi.mock('../api/client.ts', () => {
	class MockedApiError extends Error {
		readonly code: string
		readonly status: number

		constructor(code: string, status: number) {
			super(code)
			this.name = 'ApiError'
			this.code = code
			this.status = status
		}
	}

	return {
		ApiError: MockedApiError,
		messageFor: (error: unknown) => error instanceof MockedApiError ? error.code : 'failed',
	}
})

const { useBoardStore } = await import('./board.ts')
const { ApiError } = await import('../api/client.ts')

function card(uid: string): Task {
	return {
		id: 1,
		calendarUri: 'work',
		objectUri: `${uid}.ics`,
		etag: `etag-${uid}`,
		uid,
		type: 'task',
		parentUid: null,
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
		statusId: 1,
		sprintId: null,
		updatedAt: asSeconds(0),
	}
}

function column(key: string, uids: string[], statusId: number): BoardColumn {
	return {
		statusId,
		key,
		name: key,
		color: null,
		vtodoStatus: key === 'doing' ? 'IN-PROCESS' : 'NEEDS-ACTION',
		isInitial: key === 'todo',
		isFinal: false,
		wipLimit: null,
		count: uids.length,
		overWipLimit: false,
		cards: uids.map(card),
	}
}

function board(): Board {
	return {
		project: {
			uri: 'principals/users/alice/work',
			calendarUri: 'work',
			name: 'Work',
			color: null,
			writable: true,
		},
		workflowId: 42,
		rules: [],
		columns: [column('todo', ['a', 'b'], 1), column('doing', ['c'], 2)],
	}
}

function moved(uid: string, statusId: number): BoardMoveResult {
	return {
		cards: [{ ...card(uid), statusId, etag: 'etag-written', rank: 'i' }],
		counts: { todo: 1, doing: 2 },
	}
}

async function loaded() {
	const store = useBoardStore()
	fetchBoard.mockResolvedValue(board())
	await store.select('principals/users/alice/work')
	return store
}

beforeEach(() => {
	setActivePinia(createPinia())
	fetchBoard.mockReset()
	moveCards.mockReset()
})

describe('selecting a project', () => {
	it('reads its board', async () => {
		const store = await loaded()

		expect(fetchBoard).toHaveBeenCalledWith('principals/users/alice/work')
		expect(store.columns.map((it) => it.key)).toEqual(['todo', 'doing'])
		expect(store.cardCount).toBe(3)
	})

	it('reads nothing at all without a project', async () => {
		const store = useBoardStore()
		await store.select(null)

		expect(fetchBoard).not.toHaveBeenCalled()
		expect(store.board).toBeNull()
	})
})

describe('a drop', () => {
	it('sends the column and the two cards it landed between', async () => {
		const store = await loaded()
		moveCards.mockResolvedValue(moved('a', 2))

		await store.drop(['a'], 'doing', 1)

		expect(moveCards).toHaveBeenCalledWith({
			project: 'principals/users/alice/work',
			column: 'doing',
			cards: [{ uid: 'a', etag: 'etag-a' }],
			afterUid: 'c',
			beforeUid: null,
			comment: null,
		})
	})

	/**
	 * The card is where it was dropped before the request is answered (§15.6),
	 * which is the whole point of doing it this way round.
	 */
	it('shows the move before the server has answered', async () => {
		const store = await loaded()
		let resolve: (value: BoardMoveResult) => void = () => {}
		moveCards.mockReturnValue(new Promise<BoardMoveResult>((done) => {
			resolve = done
		}))

		const pending = store.drop(['a'], 'doing', 0)

		expect(store.columns[1].cards.map((it) => it.uid)).toEqual(['a', 'c'])
		expect(store.columns[0].cards.map((it) => it.uid)).toEqual(['b'])

		resolve(moved('a', 2))
		await pending
	})

	it('takes the etag the server wrote, so the next write is not refused', async () => {
		const store = await loaded()
		moveCards.mockResolvedValue(moved('a', 2))

		await store.drop(['a'], 'doing', 0)

		const card = store.columns[1].cards.find((it) => it.uid === 'a')
		expect(card?.etag).toBe('etag-written')
	})

	it('takes the column sizes the server counted', async () => {
		const store = await loaded()
		moveCards.mockResolvedValue(moved('a', 2))

		await store.drop(['a'], 'doing', 0)

		expect(store.columns[0].count).toBe(1)
		expect(store.columns[1].count).toBe(2)
	})

	/**
	 * A refused drop has to leave the board as it was: a card that did not
	 * move must not be left looking as though it had.
	 */
	it('puts the board back when the server refuses it', async () => {
		const store = await loaded()
		moveCards.mockRejectedValue(new ApiError('transition_not_allowed', 422))

		const done = await store.drop(['a'], 'doing', 0)

		expect(done).toBe(false)
		expect(store.columns[0].cards.map((it) => it.uid)).toEqual(['a', 'b'])
		expect(store.columns[1].cards.map((it) => it.uid)).toEqual(['c'])
	})

	/** A 412 is news, not a failure: the board is read again (§15.6). */
	it('reads the board again when a card has moved on since', async () => {
		const store = await loaded()
		moveCards.mockRejectedValue(new ApiError('precondition_failed', 412))
		fetchBoard.mockResolvedValue(board())

		await store.drop(['a'], 'doing', 0)

		expect(fetchBoard).toHaveBeenCalledTimes(2)
	})

	it('does not send a drop that changes nothing', async () => {
		const store = await loaded()

		// `a` is already the first card of todo, with `b` below it.
		const done = await store.drop(['a'], 'todo', 0)

		expect(done).toBe(true)
		expect(moveCards).not.toHaveBeenCalled()
	})

	it('sends every selected card in one request', async () => {
		const store = await loaded()
		moveCards.mockResolvedValue({ cards: [], counts: {} })

		await store.drop(['a', 'b'], 'doing', 0)

		expect(moveCards).toHaveBeenCalledTimes(1)
		expect(moveCards.mock.calls[0][0].cards).toEqual([
			{ uid: 'a', etag: 'etag-a' },
			{ uid: 'b', etag: 'etag-b' },
		])
	})

	it('carries the note a transition asked for', async () => {
		const store = await loaded()
		moveCards.mockResolvedValue(moved('a', 2))

		await store.drop(['a'], 'doing', 0, 'because it is started')

		expect(moveCards.mock.calls[0][0].comment).toBe('because it is started')
	})
})

describe('undo', () => {
	it('sends the card back to where it came from', async () => {
		const store = await loaded()
		moveCards.mockResolvedValue(moved('a', 2))

		await store.drop(['a'], 'doing', 1)
		expect(store.undoable).not.toBeNull()

		moveCards.mockClear()
		moveCards.mockResolvedValue(moved('a', 1))
		await store.undo()

		expect(moveCards).toHaveBeenCalledTimes(1)
		expect(moveCards.mock.calls[0][0]).toMatchObject({
			column: 'todo',
			afterUid: null,
			beforeUid: 'b',
		})
	})

	it('has nothing to undo before a drop, or after undoing one', async () => {
		const store = await loaded()

		expect(await store.undo()).toBe(false)

		moveCards.mockResolvedValue(moved('a', 2))
		await store.drop(['a'], 'doing', 0)
		await store.undo()

		expect(store.undoable).toBeNull()
	})

	it('forgets the undo when the board is read again', async () => {
		const store = await loaded()
		moveCards.mockResolvedValue(moved('a', 2))
		await store.drop(['a'], 'doing', 0)

		await store.select('principals/users/alice/work')

		expect(store.undoable).toBeNull()
	})
})

describe('selection', () => {
	it('picks one card, and unpicks it when clicked again', async () => {
		const store = await loaded()

		store.toggleSelection('a', false)
		expect(store.isSelected('a')).toBe(true)

		store.toggleSelection('a', false)
		expect(store.selection).toEqual([])
	})

	it('adds to the selection with ctrl or shift', async () => {
		const store = await loaded()

		store.toggleSelection('a', false)
		store.toggleSelection('b', true)

		expect(store.selection).toEqual(['a', 'b'])

		store.toggleSelection('a', true)
		expect(store.selection).toEqual(['b'])
	})

	it('replaces the selection on a plain click', async () => {
		const store = await loaded()

		store.toggleSelection('a', false)
		store.toggleSelection('b', false)

		expect(store.selection).toEqual(['b'])
	})

	it('clears the selection once the cards have been moved', async () => {
		const store = await loaded()
		moveCards.mockResolvedValue({ cards: [], counts: {} })

		store.toggleSelection('a', false)
		store.toggleSelection('b', true)
		await store.drop(['a', 'b'], 'doing', 0)

		expect(store.selection).toEqual([])
	})
})
