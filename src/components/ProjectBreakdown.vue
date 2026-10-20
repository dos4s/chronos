<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import { useGoals } from '../composables/useGoals.ts'
import { useAnalyticsStore } from '../stores/analytics.ts'
import { useProjectsStore } from '../stores/projects.ts'
import { formatDuration } from '../utils/format.ts'

const analytics = useAnalyticsStore()
const projects = useProjectsStore()
const { goals, keyFor, goalHours, progress } = useGoals()

const colorOf = (uri: string | null) => projects.colorFor(uri) ?? 'var(--color-text-maxcontrast)'

const donut = computed(() => {
	const total = analytics.projects.reduce((sum, project) => sum + project.seconds, 0)
	if (total === 0) {
		return 'conic-gradient(var(--color-border) 0 100%)'
	}

	const stops: string[] = []
	let degree = 0
	for (const project of analytics.projects) {
		const slice = (project.seconds / total) * 360
		stops.push(`${colorOf(project.projectUri)} ${degree}deg ${degree + slice}deg`)
		degree += slice
	}
	return `conic-gradient(${stops.join(', ')})`
})
</script>

<template>
	<div class="chronos-card">
		<div class="chronos-label">
			{{ t('chronos', 'By project (7 days) and goals') }}
		</div>

		<!-- An empty ring beside "no sessions" says the same thing twice. -->
		<div v-if="analytics.projects.length" :class="$style.donutWrap">
			<div :class="$style.donut" :style="{ background: donut }">
				<div :class="$style.hole" />
			</div>
		</div>

		<ul v-if="analytics.projects.length" :class="$style.list">
			<li
				v-for="project in analytics.projects"
				:key="project.projectUri ?? '__none__'"
				:class="$style.item">
				<div :class="$style.row">
					<span class="chronos-dot" :style="{ background: colorOf(project.projectUri) }" />
					<div :class="$style.name">
						{{ project.projectName ?? projects.nameFor(project.projectUri) ?? t('chronos', 'No project') }}
					</div>
					<div :class="$style.duration">
						{{ formatDuration(project.seconds) }}
					</div>
				</div>

				<div :class="$style.goalRow">
					<input
						v-model.number="goals[keyFor(project.projectUri)]"
						type="number"
						:class="$style.goalInput"
						:placeholder="t('chronos', 'Goal (h)')"
						:aria-label="t('chronos', 'Weekly goal in hours')"
						min="0"
						step="1">
					<div v-if="goalHours(project.projectUri) > 0" :class="$style.goalProgress">
						{{ progress(project.projectUri, project.seconds) }}%
					</div>
				</div>

				<div v-if="goalHours(project.projectUri) > 0" :class="$style.bar">
					<div
						:class="$style.barFill"
						:style="{
							width: progress(project.projectUri, project.seconds) + '%',
							background: projects.colorFor(project.projectUri) || 'var(--color-success)',
						}" />
				</div>
			</li>
		</ul>
		<div v-else :class="$style.empty">
			{{ t('chronos', 'No sessions in the last 7 days.') }}
		</div>
	</div>
</template>

<style module>
.donutWrap {
	display: flex;
	justify-content: center;
	padding: 10px 0;
}

.donut {
	width: 140px;
	height: 140px;
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
	transition: background 0.5s ease;
}

.hole {
	width: 90px;
	height: 90px;
	background: var(--color-main-background);
	border-radius: 50%;
}

.list {
	list-style: none;
	padding: 0 4px 0 0;
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 10px;
	max-height: 380px;
	overflow-y: auto;
}

.item {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding-bottom: 12px;
	border-bottom: 1px solid color-mix(in srgb, var(--color-border) 40%, transparent);
}

.item:last-child {
	border-bottom: none;
	padding-bottom: 0;
}

.row {
	display: grid;
	grid-template-columns: 10px 1fr auto;
	align-items: center;
	gap: 8px;
}

.name {
	font-size: var(--chronos-text-small);
	font-weight: 500;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.duration {
	font-size: var(--chronos-text-small);
	font-variant-numeric: tabular-nums;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.goalRow {
	display: flex;
	align-items: center;
	gap: 12px;
	padding-inline-start: 18px;
}

/*
 * Qualified with the element for the same reason the buttons are (§15.10):
 * Nextcloud styles every `input`, `select` and `textarea` through a selector
 * with an element in it, which outranks a single class — so this rule's
 * padding, width and height were being ignored.
 */
input.goalInput {
	width: 80px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 4px;
	padding: 4px 6px;
	font-size: var(--chronos-text-small);
	color: var(--color-main-text);
}

.goalProgress {
	font-size: var(--chronos-text-small);
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.bar {
	height: 3px;
	width: 100%;
	background: color-mix(in srgb, var(--color-main-text) 8%, transparent);
	border-radius: 2px;
	overflow: hidden;
}

.barFill {
	height: 100%;
	border-radius: 2px;
	transition: width 0.4s ease;
	min-width: 2px;
}

.empty {
	font-size: var(--chronos-text-small);
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
