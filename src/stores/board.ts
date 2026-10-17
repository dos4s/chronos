import type { Board, Task } from '../types/index.ts'
import type { DropSpec } from '../utils/board.ts'

import { showError } from '@nextcloud/dialogs'
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import * as api from '../api/board.ts'
import { ApiError, messageFor } from '../api/client.ts'
import { applyDrop, cardByUid, columnByKey, columnOf, inverseDrops, neighboursAt } from '../utils/board.ts'

/** HTTP 412: the card changed since the page last read it (§15.6). */
const PRECONDITION_FAILED = 412

/**
 * One project's board (§15.6).
 *
 * A drop is applied here first and sent afterwards, so the card is where it
 * was dropped rather than a round trip later. If the server refuses it — a
 * move the workflow does not allow, a card somebody else has edited — the
 * board goes back to exactly what it was and says why.
 *
 * Every drop leaves behind the inverse drop, which is what *Undo* sends: the
 * column the cards came from and the cards they sat between, so undoing
 * restores the position and not only the status.
 */
export const useBoardStore = defineStore('board', () => {
	const board = ref<Board | null>(null)
	const projectRef = ref<string | null>(null)
	const initialLoading = ref(false)
	const saving = ref(false)

	/** Cards picked with shift or ctrl, which drag together (§15.6). */
	const selection = ref<string[]>([])

	/** The inverse of the last drop, while it is still worth offering. */
	const undoable = ref<DropSpec[] | null>(null)

	const columns = computed(() => board.value?.columns ?? [])
	const writable = computed(() => board.value?.project.writable ?? false)
	const cardCount = computed(() => columns.value.reduce((total, column) => total + column.cards.length, 0))

	async function select(ref: string | null): Promise<void> {
		projectRef.value = ref
		board.value = null
		selection.value = []
		undoable.value = null

		if (ref === null) {
			return
		}

		initialLoading.value = true
		await refresh()
		initialLoading.value = false
	}

	async function refresh(): Promise<void> {
		const ref = projectRef.value
		if (ref === null) {
			return
		}

		try {
			board.value = await api.fetchBoard(ref)
		} catch (error) {
			showError(messageFor(error))
		}
	}

	/**
	 * One drop: the cards, the column they landed in, and the position in it.
	 *
	 * The position counts the column without the cards being moved, which is
	 * how the engine reports it and what the server's two neighbours mean.
	 */
	async function drop(uids: string[], columnKey: string, index: number, comment: string | null = null): Promise<boolean> {
		const current = board.value
		const ref = projectRef.value
		if (current === null || ref === null || uids.length === 0) {
			return false
		}

		const target = columnByKey(current, columnKey)
		if (target === null) {
			return false
		}

		const { afterUid, beforeUid } = neighboursAt(target.cards, index, uids)
		if (isWhereItAlreadyIs(current, uids, columnKey, afterUid, beforeUid)) {
			return true
		}

		const inverse = inverseDrops(current, uids)
		const cards = uids.map((uid) => ({ uid, etag: cardByUid(current, uid)?.etag ?? null }))

		// Optimistic: the board shows the drop, then the request goes (§15.6).
		board.value = applyDrop(current, uids, columnKey, index)
		saving.value = true

		try {
			const result = await api.moveCards({
				project: ref,
				column: columnKey,
				cards,
				afterUid,
				beforeUid,
				comment,
			})

			reconcile(result.cards, result.counts)
			undoable.value = inverse
			selection.value = []
			return true
		} catch (error) {
			// Back to exactly what it was: a card that did not move must not
			// be left looking as though it had.
			board.value = current
			showError(messageFor(error))

			if (error instanceof ApiError && error.status === PRECONDITION_FAILED) {
				await refresh()
			}

			return false
		} finally {
			saving.value = false
		}
	}

	/**
	 * Sends the inverse of the last drop (§15.6). One drop can empty cards out
	 * of several columns, so putting them back is one request per column.
	 */
	async function undo(): Promise<boolean> {
		const specs = undoable.value
		const ref = projectRef.value
		if (specs === null || specs.length === 0 || ref === null) {
			return false
		}

		undoable.value = null
		saving.value = true

		try {
			for (const spec of specs) {
				// The etags are the ones the drop wrote, which the board now
				// holds; the ones from before it would be refused.
				const current = board.value
				await api.moveCards({
					project: ref,
					column: spec.column,
					cards: spec.uids.map((uid) => ({
						uid,
						etag: current === null ? null : cardByUid(current, uid)?.etag ?? null,
					})),
					afterUid: spec.afterUid,
					beforeUid: spec.beforeUid,
				})
			}

			await refresh()
			return true
		} catch (error) {
			showError(messageFor(error))
			await refresh()
			return false
		} finally {
			saving.value = false
		}
	}

	function forgetUndo(): void {
		undoable.value = null
	}

	/**
	 * Shift or ctrl-click adds a card to the selection, so a drag moves all of
	 * them in one request; a plain click selects the one card.
	 */
	function toggleSelection(uid: string, additive: boolean): void {
		if (!additive) {
			selection.value = selection.value.length === 1 && selection.value[0] === uid ? [] : [uid]
			return
		}

		selection.value = selection.value.includes(uid)
			? selection.value.filter((selected) => selected !== uid)
			: [...selection.value, uid]
	}

	function isSelected(uid: string): boolean {
		return selection.value.includes(uid)
	}

	/**
	 * The cards as the server wrote them — above all their new etags, without
	 * which the next write of the same card would be refused — and the column
	 * sizes it counted, for the WIP warnings.
	 */
	function reconcile(cards: Task[], counts: Record<string, number>): void {
		const current = board.value
		if (current === null) {
			return
		}

		const written = new Map(cards.map((card) => [card.uid, card]))

		current.columns = current.columns.map((column) => ({
			...column,
			cards: column.cards.map((card) => written.get(card.uid) ?? card),
			count: counts[column.key] ?? column.cards.length,
			overWipLimit: column.wipLimit !== null
				&& column.wipLimit > 0
				&& (counts[column.key] ?? column.cards.length) > column.wipLimit,
		}))
	}

	/** A drop that changes nothing is not worth a request. */
	function isWhereItAlreadyIs(
		current: Board,
		uids: string[],
		columnKey: string,
		afterUid: string | null,
		beforeUid: string | null,
	): boolean {
		if (uids.length !== 1) {
			return false
		}

		const column = columnOf(current, uids[0])
		if (column === null || column.key !== columnKey) {
			return false
		}

		const at = column.cards.findIndex((card) => card.uid === uids[0])
		return (column.cards[at - 1]?.uid ?? null) === afterUid
			&& (column.cards[at + 1]?.uid ?? null) === beforeUid
	}

	return {
		board,
		projectRef,
		initialLoading,
		saving,
		selection,
		undoable,
		columns,
		writable,
		cardCount,
		select,
		refresh,
		drop,
		undo,
		forgetUndo,
		toggleSelection,
		isSelected,
	}
})
