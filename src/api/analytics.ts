import type { AnalyticsSummary } from '../types/index.ts'
import type { UnixSeconds } from './time.ts'

import { get } from './client.ts'
import { browserTimezone } from './time.ts'

/**
 * Totals for a date range, aggregated by the server over every session in it
 * rather than by the browser over the newest few (D1).
 */
export function fetchSummary(fromAt: UnixSeconds, toAt: UnixSeconds) {
	return get<AnalyticsSummary>('/analytics/summary', {
		fromAt,
		toAt,
		timezone: browserTimezone(),
	})
}
