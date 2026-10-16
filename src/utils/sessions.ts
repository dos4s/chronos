import type { Seconds, UnixSeconds } from '../api/time.ts'
import type { Session } from '../types/index.ts'

/**
 * Worked time in a session: its span, less the pauses.
 *
 * A running pause is subtracted up to `now`, which is why the figure ticks
 * while a session is paused.
 */
export function workedSeconds(session: Session, now: UnixSeconds): Seconds {
	const end = session.endAt ?? now
	let worked = end - session.startAt - session.pausedSeconds

	if (session.pausedSinceAt !== null && session.endAt === null) {
		worked -= now - session.pausedSinceAt
	}

	return Math.max(0, worked)
}

export interface SessionChunk {
	startAt: UnixSeconds
	endAt: UnixSeconds
}

/**
 * The stretches a session was actually being worked, split by its pauses.
 *
 * The session editor draws one block per stretch; the pauses show as the gaps
 * between them.
 */
export function sessionChunks(session: Session, endAt: UnixSeconds): SessionChunk[] {
	const chunks: SessionChunk[] = []
	const pauses = [...session.pauses]
		.filter((pause) => pause.endAt !== null)
		.sort((a, b) => a.startAt - b.startAt)

	let cursor = session.startAt
	for (const pause of pauses) {
		if (pause.startAt > cursor) {
			chunks.push({ startAt: cursor, endAt: pause.startAt })
		}
		cursor = Math.max(cursor, pause.endAt as UnixSeconds) as UnixSeconds
	}
	if (cursor < endAt) {
		chunks.push({ startAt: cursor, endAt })
	}

	return chunks
}
