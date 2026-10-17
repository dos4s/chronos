import type { Board, BoardMovePayload, BoardMoveResult, SubtaskView } from '../types/index.ts'

import { get, post } from './client.ts'

/**
 * A board is always read for one project (§16): a column of 500 cards is one
 * screen, every project at once is not.
 *
 * `subtasks` picks which cards it holds: every task, or only the top of each
 * branch (§15.9). The server decides, because it also counts the columns.
 */
export function fetchBoard(project: string, subtasks: SubtaskView = 'cards') {
	return get<Board>('/board', { project, subtasks })
}

/**
 * One drop, one request — however many cards were dragged (§15.6). The cards
 * carry the etags the page had for them, so a card somebody else has edited in
 * the meantime is refused rather than overwritten.
 */
export const moveCards = (payload: BoardMovePayload) => post<BoardMoveResult>('/board/move', payload)
