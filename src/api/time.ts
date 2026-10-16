/**
 * The one place where seconds meet JavaScript's milliseconds (§8.5).
 *
 * The API speaks UNIX seconds only. `UnixSeconds` is a branded type, so a raw
 * millisecond value cannot be passed where a timestamp is expected without
 * going through one of these helpers.
 */

declare const secondsBrand: unique symbol

export type UnixSeconds = number & { readonly [secondsBrand]: 'seconds' }

/** Seconds of duration; unbranded, because arithmetic on durations is normal. */
export type Seconds = number

export function asSeconds(value: number): UnixSeconds {
	return Math.floor(value) as UnixSeconds
}

export function toDate(seconds: UnixSeconds): Date {
	return new Date(seconds * 1000)
}

export function fromDate(date: Date): UnixSeconds {
	return asSeconds(date.getTime() / 1000)
}

export function nowSeconds(): UnixSeconds {
	return asSeconds(Date.now() / 1000)
}

/** Local midnight of the day a timestamp falls in. */
export function startOfDay(seconds: UnixSeconds): UnixSeconds {
	const date = toDate(seconds)
	date.setHours(0, 0, 0, 0)
	return fromDate(date)
}

/** Local midnight `days` days away from the day a timestamp falls in. */
export function addDays(seconds: UnixSeconds, days: number): UnixSeconds {
	const date = toDate(seconds)
	date.setDate(date.getDate() + days)
	return fromDate(date)
}

/** `YYYY-MM-DD` in the browser's zone, matching the server's day buckets. */
export function toDateKey(seconds: UnixSeconds): string {
	const date = toDate(seconds)
	const month = String(date.getMonth() + 1).padStart(2, '0')
	const day = String(date.getDate()).padStart(2, '0')
	return `${date.getFullYear()}-${month}-${day}`
}

/** The browser's IANA zone, which analytics requests send to the server. */
export function browserTimezone(): string {
	try {
		return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'
	} catch {
		return 'UTC'
	}
}
