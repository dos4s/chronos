import type { UnixSeconds } from '../api/time.ts'

import { fromDate, toDate } from '../api/time.ts'

/**
 * `<input type="time">` values, which are local wall-clock strings, converted
 * against the day a session belongs to.
 *
 * The day comes from a timestamp rather than a formatted date string, so the
 * conversion cannot land on the wrong day in zones behind UTC.
 */

export function toTimeInput(at: UnixSeconds): string {
	const date = toDate(at)
	return [date.getHours(), date.getMinutes()]
		.map((part) => String(part).padStart(2, '0'))
		.join(':')
}

export function fromTimeInput(value: string, dayAt: UnixSeconds): UnixSeconds | null {
	const match = /^(\d{1,2}):(\d{2})$/.exec(value.trim())
	if (match === null) {
		return null
	}

	const hours = Number(match[1])
	const minutes = Number(match[2])
	if (hours > 23 || minutes > 59) {
		return null
	}

	const date = toDate(dayAt)
	date.setHours(hours, minutes, 0, 0)
	return fromDate(date)
}
