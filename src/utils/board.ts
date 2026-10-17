import type { Board, BoardCard, BoardColumn, BoardRule } from '../types/index.ts'

/**
 * The board's own arithmetic, kept out of the components (§15.6).
 *
 * A drop is applied in the browser before the request is sent, and reverted if
 * the server refuses it, so the same numbers are worked out in two directions:
 * where the cards are going, and where they came from.
 */

/** Where a drop lands: a column, and the two cards it landed between. */
export interface DropSpec {
	column: string
	uids: string[]
	/** The card above the drop; null is the top of the column. */
	afterUid: string | null
	/** The card below it; null is the bottom. */
	beforeUid: string | null
}

export function columnByKey(board: Board, key: string): BoardColumn | null {
	return board.columns.find((column) => column.key === key) ?? null
}

/** The column a card is currently in. */
export function columnOf(board: Board, uid: string): BoardColumn | null {
	return board.columns.find((column) => column.cards.some((card) => card.uid === uid)) ?? null
}

export function cardByUid(board: Board, uid: string): BoardCard | null {
	for (const column of board.columns) {
		const card = column.cards.find((candidate) => candidate.uid === uid)
		if (card !== undefined) {
			return card
		}
	}
	return null
}

export function ruleFor(board: Board, from: string, to: string): BoardRule | null {
	return board.rules.find((rule) => rule.from === from && rule.to === to) ?? null
}

/**
 * Whether the workflow allows this move. No rules at all means every move is
 * allowed, and a card dropped where it already is has not moved (§10.3).
 */
export function allowsMove(board: Board, from: string, to: string): boolean {
	if (board.rules.length === 0 || from === to) {
		return true
	}
	return ruleFor(board, from, to) !== null
}

/** Whether a drop needs a note before the server will take it. */
export function needsComment(board: Board, from: string, to: string): boolean {
	return from !== to && ruleFor(board, from, to)?.requiresComment === true
}

/**
 * The cards a drop landed between, for a column and a position in it.
 *
 * The position counts the column *without* the cards being moved, because
 * that is what the column will look like when they arrive. The neighbours are
 * sent instead of the position itself: a card somebody else has moved in the
 * meantime changes what position 3 means, but not what "under this card"
 * means (§15.6).
 */
export function neighboursAt(cards: BoardCard[], index: number, movedUids: string[] = []): {
	afterUid: string | null
	beforeUid: string | null
} {
	const staying = cards.filter((card) => !movedUids.includes(card.uid))
	const at = Math.max(0, Math.min(index, staying.length))

	return {
		afterUid: staying[at - 1]?.uid ?? null,
		beforeUid: staying[at]?.uid ?? null,
	}
}

/**
 * The board as it will look once the drop is accepted.
 *
 * Applied before the request is sent, so the card is under the cursor where it
 * was dropped rather than a round trip later; the store puts the board back if
 * the server refuses it.
 */
export function applyDrop(board: Board, uids: string[], columnKey: string, index: number): Board {
	const moving = uids
		.map((uid) => cardByUid(board, uid))
		.filter((card): card is BoardCard => card !== null)

	if (moving.length === 0) {
		return board
	}

	const target = columnByKey(board, columnKey)
	const columns = board.columns.map((column) => {
		const kept = column.cards.filter((card) => !uids.includes(card.uid))
		if (column.key !== columnKey) {
			return withCards(column, kept)
		}

		const at = Math.max(0, Math.min(index, kept.length))
		return withCards(column, [...kept.slice(0, at), ...moved(moving, target), ...kept.slice(at)])
	})

	return { ...board, columns }
}

/**
 * Where the cards came from, so an undo can send the inverse drop (§15.6).
 *
 * One drop can take cards out of more than one column, and putting them back
 * is then one move per column they came from. The neighbours recorded are the
 * cards that are *not* moving, so the undo lands between what will still be
 * there.
 */
export function inverseDrops(board: Board, uids: string[]): DropSpec[] {
	const specs: DropSpec[] = []

	for (const column of board.columns) {
		const mine = column.cards.filter((card) => uids.includes(card.uid)).map((card) => card.uid)
		if (mine.length === 0) {
			continue
		}

		const first = column.cards.findIndex((card) => card.uid === mine[0])
		specs.push({
			column: column.key,
			uids: mine,
			...neighboursAt(column.cards, first, uids),
		})
	}

	return specs
}

/**
 * How a card is drawn, now that a board has a tree in it (§15.9).
 */
export interface CardShape {
	/** Drawn as part of its parent's group, which is directly above it. */
	indented: boolean
	/** Says whose subtask it is, because its parent is not above it. */
	showsParent: boolean
}

/**
 * Which cards of a column are drawn as part of a group, and which have to say
 * whose they are.
 *
 * A column nobody has arranged comes from the server as a tree, so a subtask
 * is usually sitting right under its parent and an indent says everything. A
 * column somebody has arranged may have put it anywhere — the ranks are the
 * user's arrangement and nesting never overrides them (§15.9) — and a card
 * away from its parent is anonymous unless it carries its parent's name.
 *
 * So the rule is local: a subtask is indented when the card above it is its
 * parent, or a sibling that is itself indented. Otherwise it names its parent.
 */
export function cardShapes(cards: BoardCard[]): Record<string, CardShape> {
	const shapes: Record<string, CardShape> = {}

	cards.forEach((card, index) => {
		const parent = card.parent
		if (parent === null) {
			shapes[card.uid] = { indented: false, showsParent: false }
			return
		}

		// A task inside a subproject is not a subtask of it (§6): it is one of
		// the project's own cards, which happens to live in a sublist.
		if (parent.type === 'subproject') {
			shapes[card.uid] = { indented: false, showsParent: true }
			return
		}

		const above = cards[index - 1]
		const indented = above !== undefined && (
			above.uid === parent.uid
			|| (above.parent?.uid === parent.uid && shapes[above.uid]?.indented === true)
		)

		shapes[card.uid] = { indented, showsParent: !indented }
	})

	return shapes
}

/**
 * How many unfinished subtasks a card has somewhere other than the column it
 * is being dropped into — which is the question behind the cascade (§15.9).
 */
export function openSubtasksElsewhere(card: BoardCard, columnKey: string): number {
	const open = card.subtasks?.open ?? {}

	return Object.entries(open).reduce(
		(total, [key, count]) => key === columnKey ? total : total + count,
		0,
	)
}

/**
 * The inverse drops for the cards a move took along that the page never
 * dragged: the subtasks of a cascade (§15.9).
 *
 * Each one goes back to the column the server says it came from. It goes to
 * the *end* of that column rather than to the place it had, because the page
 * never saw where that was — which is the one way an undo is less exact than
 * the drop it undoes.
 */
export function inverseOfCascade(moved: BoardCard[], dragged: string[]): DropSpec[] {
	const byColumn = new Map<string, string[]>()

	for (const card of moved) {
		const from = card.fromColumn
		if (from === null || from === undefined || dragged.includes(card.uid)) {
			continue
		}
		byColumn.set(from, [...byColumn.get(from) ?? [], card.uid])
	}

	return [...byColumn].map(([column, uids]) => ({
		column,
		uids,
		afterUid: null,
		beforeUid: null,
	}))
}

/**
 * The position a pointer at `y` means, given the cards' vertical midpoints.
 *
 * Measured once at drag start (§15.2), so a drag does no layout reads per
 * pointer move: a card is dropped above the first card whose middle the
 * pointer has not passed.
 */
export function indexForPointer(midpoints: number[], y: number): number {
	for (let index = 0; index < midpoints.length; index++) {
		if (y < midpoints[index]) {
			return index
		}
	}

	return midpoints.length
}

/** The standard four columns' names, which only the client knows (§12.1). */
export function isStandardColumn(column: BoardColumn): boolean {
	return column.statusId === null
}

function moved(cards: BoardCard[], target: BoardColumn | null): BoardCard[] {
	if (target === null) {
		return cards
	}

	// The card shows its new status immediately, because that is what the drop
	// means; the server's answer replaces it with the written card.
	return cards.map((card) => ({
		...card,
		statusId: target.statusId,
		vtodoStatus: target.vtodoStatus,
	}))
}

function withCards(column: BoardColumn, cards: BoardCard[]): BoardColumn {
	return {
		...column,
		cards,
		count: cards.length,
		overWipLimit: column.wipLimit !== null && column.wipLimit > 0 && cards.length > column.wipLimit,
	}
}

/**
 * A move out of one column, as the column settings dialog shows it (D28).
 */
export interface RuleEdit {
	to: string
	allowed: boolean
	requiresComment: boolean
}

/**
 * Every move the board allows now, written out.
 *
 * A workflow with no rules at all allows everything (§10.3), which is
 * convenient to store and impossible to edit: ticking one box would turn
 * "everything is allowed" into "only this is". So the matrix is written out in
 * full before it is edited, and collapsed back to nothing if the edit leaves
 * every move allowed.
 */
export function effectiveRules(board: Board): BoardRule[] {
	if (board.rules.length > 0) {
		return board.rules.map((rule) => ({ ...rule }))
	}

	const rules: BoardRule[] = []
	for (const from of board.columns) {
		for (const to of board.columns) {
			if (from.key !== to.key) {
				rules.push({ from: from.key, to: to.key, requiresComment: false })
			}
		}
	}

	return rules
}

/** The rules after the moves out of one column have been changed. */
export function rulesAfterEdit(board: Board, from: string, edits: RuleEdit[]): BoardRule[] {
	const others = effectiveRules(board).filter((rule) => rule.from !== from)
	const mine = edits
		.filter((edit) => edit.allowed && edit.to !== from)
		.map((edit) => ({ from, to: edit.to, requiresComment: edit.requiresComment }))

	return [...others, ...mine]
}

/**
 * Whether a set of rules allows every move there is, and can therefore be
 * stored as no rules at all — which keeps the table clean and keeps a board
 * that nobody has restricted from behaving as though somebody had.
 */
export function isUnrestricted(board: Board, rules: BoardRule[]): boolean {
	for (const from of board.columns) {
		for (const to of board.columns) {
			if (from.key === to.key) {
				continue
			}

			const rule = rules.find((candidate) => candidate.from === from.key && candidate.to === to.key)
			if (rule === undefined || rule.requiresComment) {
				return false
			}
		}
	}

	return true
}

/** The moves out of a column, for the dialog, in the board's own order. */
export function editsFor(board: Board, from: string): RuleEdit[] {
	return board.columns
		.filter((column) => column.key !== from)
		.map((column) => {
			const rule = ruleFor(board, from, column.key)
			return {
				to: column.key,
				allowed: board.rules.length === 0 || rule !== null,
				requiresComment: rule?.requiresComment ?? false,
			}
		})
}
