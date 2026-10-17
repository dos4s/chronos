import type { Roadmap } from '../types/index.ts'

import { get } from './client.ts'

/**
 * The one read that is deliberately not scoped to a project (§12.6): the point
 * of a roadmap is the other projects. One request draws every line on it.
 */
export function fetchRoadmap(archived = false) {
	return get<Roadmap>('/roadmap', { archived: archived ? 'yes' : 'no' })
}
