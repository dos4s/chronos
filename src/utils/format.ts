import { t } from '@nextcloud/l10n'
import type { Seconds, UnixSeconds } from '../api/time.ts'
import { toDate } from '../api/time.ts'

/**
 * The single duration formatter (D16): one implementation for the app and the
 * dashboard widget, taking seconds like everything else.
 */
export function formatDuration(seconds: Seconds): string {
	const total = Math.max(0, Math.floor(seconds))
	if (total < 60) {
		return t('chronos', '{seconds}s', { seconds: total })
	}

	const hours = Math.floor(total / 3600)
	const minutes = Math.floor((total % 3600) / 60)
	if (hours === 0) {
		return t('chronos', '{minutes}m', { minutes })
	}
	if (minutes === 0) {
		return t('chronos', '{hours}h', { hours })
	}
	return t('chronos', '{hours}h {minutes}m', { hours, minutes })
}

/** `HH:MM:SS`, for the running timer. */
export function formatClock(seconds: Seconds): string {
	const total = Math.max(0, Math.floor(seconds))
	const pad = (value: number) => String(value).padStart(2, '0')
	return [
		pad(Math.floor(total / 3600)),
		pad(Math.floor((total % 3600) / 60)),
		pad(total % 60),
	].join(':')
}

export function formatTimeOfDay(at: UnixSeconds): string {
	return toDate(at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

export function formatDate(at: UnixSeconds): string {
	return toDate(at).toLocaleDateString([], { day: '2-digit', month: 'short', year: 'numeric' })
}

export function formatWeekday(at: UnixSeconds): string {
	return toDate(at).toLocaleDateString([], { weekday: 'short' })
}
