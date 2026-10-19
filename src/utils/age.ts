import type { UnixSeconds } from '../api/time.ts'

import { n, t } from '@nextcloud/l10n'

/**
 * Work item age: how long a card has sat where it is (§12.9).
 *
 * One of the four measures the Kanban Guide makes mandatory, and the only one
 * that belongs on the card rather than in a chart. It is deliberately *not* the
 * time since the task was created: a task made in January and started yesterday
 * is one day old, which is the number that tells you whether to do something
 * about it.
 *
 * The thresholds follow the convention the board tools settled on — a quiet
 * first week, a warning at two, an alarm at three — and a card below the first
 * one says nothing at all, because a board where every card carries a badge is
 * a board where none of them means anything.
 */

/** Days at which a card starts to look old, then worrying, then wrong. */
export const AGE_LEVELS = [7, 14, 21] as const

const DAY = 86400

export type AgeLevel = 0 | 1 | 2 | 3

/** Whole days since `enteredAt`, or null when nothing recorded the arrival. */
export function ageInDays(enteredAt: number | null | undefined, now: UnixSeconds): number | null {
	if (enteredAt === null || enteredAt === undefined || enteredAt <= 0) {
		return null
	}

	const seconds = now - enteredAt
	if (seconds < 0) {
		// A clock disagreeing with the server is not an age.
		return null
	}

	return Math.floor(seconds / DAY)
}

/**
 * Which of the three thresholds an age has passed: 0 is "nothing to see".
 */
export function ageLevel(days: number | null, levels: readonly number[] = AGE_LEVELS): AgeLevel {
	if (days === null) {
		return 0
	}

	let level = 0
	for (const threshold of levels) {
		if (days >= threshold) {
			level += 1
		}
	}

	return Math.min(3, level) as AgeLevel
}

/** The badge itself: short, because it sits on a card. */
export function ageLabel(days: number | null): string | null {
	if (days === null) {
		return null
	}
	if (days < 7) {
		return t('chronos', '{days}d', { days: String(days) })
	}

	const weeks = Math.floor(days / 7)
	return t('chronos', '{weeks}w', { weeks: String(weeks) })
}

/** What the badge means, spelled out for a title and a screen reader. */
export function ageTitle(days: number | null): string | null {
	if (days === null) {
		return null
	}

	return n('chronos', 'In this column for %n day', 'In this column for %n days', days)
}
