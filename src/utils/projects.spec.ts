import type { Area, Project } from '../types/index.ts'

import { describe, expect, it } from 'vitest'
import { areaOf, groupByArea, labelProjects } from './projects.ts'

function project(name: string, areaId: number | null = null, archived = false): Project {
	return {
		uri: `principals/users/alice/${name.toLowerCase()}`,
		calendarUri: name.toLowerCase(),
		name,
		color: null,
		shared: false,
		writable: true,
		areaId,
		parentUri: null,
		workflowId: null,
		archived,
		sort: 0,
	}
}

const areas: Area[] = [
	{ id: 1, name: 'Work', color: null, sort: 0 },
	{ id: 2, name: 'Personal', color: null, sort: 1 },
]

describe('areaOf', () => {
	it('finds the area a project is in', () => {
		expect(areaOf(project('Chronos', 1), areas)?.name).toBe('Work')
	})

	it('is null for a project in no area', () => {
		expect(areaOf(project('Loose'), areas)).toBeNull()
	})

	it('is null when the area is gone', () => {
		expect(areaOf(project('Orphan', 99), areas)).toBeNull()
	})
})

describe('labelProjects', () => {
	it('prefixes the area and sorts by the label', () => {
		const labelled = labelProjects(
			[project('Chronos', 1), project('Garden', 2), project('Inbox')],
			areas,
		)

		expect(labelled.map((entry) => entry.label)).toEqual([
			'Inbox',
			'Personal / Garden',
			'Work / Chronos',
		])
	})

	it('leaves a project in no area with its own name', () => {
		expect(labelProjects([project('Inbox')], areas)[0].label).toBe('Inbox')
	})

	it('keeps every field of the project', () => {
		const [labelled] = labelProjects([project('Chronos', 1)], areas)

		expect(labelled.uri).toBe('principals/users/alice/chronos')
		expect(labelled.writable).toBe(true)
	})
})

describe('groupByArea', () => {
	it('groups by area, in the user’s order, with no area last', () => {
		const groups = groupByArea(
			[project('Inbox'), project('Garden', 2), project('Chronos', 1)],
			areas,
		)

		expect(groups.map((group) => group.area?.name ?? null)).toEqual(['Work', 'Personal', null])
		expect(groups[0].projects.map((entry) => entry.name)).toEqual(['Chronos'])
	})

	it('leaves out an area with nothing in it', () => {
		const groups = groupByArea([project('Chronos', 1)], areas)

		expect(groups).toHaveLength(1)
		expect(groups[0].area?.name).toBe('Work')
	})

	it('counts a project whose area is gone as being in no area', () => {
		const groups = groupByArea([project('Orphan', 99)], areas)

		expect(groups).toHaveLength(1)
		expect(groups[0].area).toBeNull()
	})
})
