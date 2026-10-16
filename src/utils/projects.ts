import type { Area, Project } from '../types/index.ts'

/** A project with the label the pickers show: "Area / Project" (§6). */
export interface LabelledProject extends Project {
	label: string
}

export interface AreaGroup {
	area: Area | null
	projects: Project[]
}

export function areaOf(project: Project, areas: Area[]): Area | null {
	if (project.areaId === null) {
		return null
	}
	return areas.find((area) => area.id === project.areaId) ?? null
}

/**
 * Projects labelled with their area and sorted by that label, so a picker
 * reads as the hierarchy does without needing option groups.
 */
export function labelProjects(projects: Project[], areas: Area[]): LabelledProject[] {
	return projects
		.map((project) => {
			const area = areaOf(project, areas)
			return {
				...project,
				label: area === null ? project.name : `${area.name} / ${project.name}`,
			}
		})
		.sort((a, b) => a.label.localeCompare(b.label))
}

/**
 * Projects by area, areas in the user's own order, the ones in no area last.
 * An empty area is left out: it has nothing to show.
 */
export function groupByArea(projects: Project[], areas: Area[]): AreaGroup[] {
	const groups: AreaGroup[] = areas.map((area) => ({
		area,
		projects: projects.filter((project) => project.areaId === area.id),
	}))

	const loose = projects.filter((project) => areaOf(project, areas) === null)
	if (loose.length > 0) {
		groups.push({ area: null, projects: loose })
	}

	return groups.filter((group) => group.projects.length > 0)
}
