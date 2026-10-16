import type { UnixSeconds } from '../api/time.ts'

import { asSeconds, toDate } from '../api/time.ts'

/**
 * `<input type="date">` and `<input type="time">` values, converted with the
 * kind of date in mind (§8.4).
 *
 * An all-day date is stored as midnight **UTC** of that date and is never
 * shifted by a time zone — it is a date, not a moment. A timed value is a
 * moment, read and written in the viewer's own zone.
 */

export function toDateInput(at: UnixSeconds, isDate: boolean): string {
	const date = toDate(at)

	if (isDate) {
		return [
			date.getUTCFullYear(),
			pad(date.getUTCMonth() + 1),
			pad(date.getUTCDate()),
		].join('-')
	}

	return [date.getFullYear(), pad(date.getMonth() + 1), pad(date.getDate())].join('-')
}

export function toTimeInput(at: UnixSeconds): string {
	const date = toDate(at)
	return [pad(date.getHours()), pad(date.getMinutes())].join(':')
}

/**
 * @param date `YYYY-MM-DD`
 * @param isDate whether this is an all-day date rather than a moment
 * @param time `HH:MM`, used only when the value is not all-day
 */
export function fromDateInput(date: string, isDate: boolean, time = '00:00'): UnixSeconds | null {
	const parts = /^(\d{4})-(\d{2})-(\d{2})$/.exec(date.trim())
	if (parts === null) {
		return null
	}

	const year = Number(parts[1])
	const month = Number(parts[2])
	const day = Number(parts[3])
	if (month < 1 || month > 12 || day < 1 || day > 31) {
		return null
	}

	if (isDate) {
		return asSeconds(Date.UTC(year, month - 1, day, 0, 0, 0) / 1000)
	}

	const clock = /^(\d{1,2}):(\d{2})$/.exec(time.trim())
	const hours = clock === null ? 0 : Number(clock[1])
	const minutes = clock === null ? 0 : Number(clock[2])
	if (hours > 23 || minutes > 59) {
		return null
	}

	return asSeconds(new Date(year, month - 1, day, hours, minutes, 0, 0).getTime() / 1000)
}

function pad(value: number): string {
	return String(value).padStart(2, '0')
}
