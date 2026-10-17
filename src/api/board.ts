import type { Board, BoardMovePayload, BoardMoveResult } from '../types/index.ts'

import { get, post } from './client.ts'

/**
 * A board is always read for one project (§16): a column of 500 cards is one
 * screen, every project at once is not.
 */
export const fetchBoard = (project: string) => get<Board>('/board', { project })

/**
 * One drop, one request — however many cards were dragged (§15.6). The cards
 * carry the etags the page had for them, so a card somebody else has edited in
 * the meantime is refused rather than overwritten.
 */
export const moveCards = (payload: BoardMovePayload) => post<BoardMoveResult>('/board/move', payload)
