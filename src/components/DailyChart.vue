<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { formatDuration, formatWeekday } from '../utils/format.ts'
import { useAnalyticsStore } from '../stores/analytics.ts'
import { useProjectsStore } from '../stores/projects.ts'

const analytics = useAnalyticsStore()
const projects = useProjectsStore()
</script>

<template>
	<div class="chronos-card">
		<div class="chronos-label">
			{{ t('chronos', 'Last 7 days') }}
		</div>
		<div :class="$style.chart">
			<div v-for="day in analytics.days" :key="day.date" :class="$style.column">
				<div :class="$style.bars" :title="formatDuration(day.seconds)">
					<div
						v-for="project in day.projects"
						:key="project.projectUri ?? '__none__'"
						:class="$style.segment"
						:style="{
							height: ((project.seconds / analytics.dayMaxSeconds) * 100) + '%',
							background: projects.colorFor(project.projectUri) || 'var(--color-text-maxcontrast)',
						}"
						:title="formatDuration(project.seconds)" />
				</div>
				<div :class="$style.label">
					{{ formatWeekday(day.at) }}
				</div>
			</div>
		</div>
	</div>
</template>

<style module>
.chart {
	display: flex;
	align-items: flex-end;
	height: 220px;
	gap: 8px;
	padding-top: 10px;
}

.column {
	flex: 1;
	display: flex;
	flex-direction: column;
	justify-content: flex-end;
	align-items: center;
	height: 100%;
	gap: 8px;
}

.bars {
	width: 100%;
	max-width: 40px;
	flex: 1;
	display: flex;
	flex-direction: column;
	justify-content: flex-end;
	background: color-mix(in srgb, var(--color-main-text) 4%, transparent);
	border-radius: 4px;
	overflow: hidden;
}

.segment {
	width: 100%;
	transition: height 0.4s ease;
	min-height: 2px;
}

.label {
	font-size: 11px;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
	font-weight: 600;
}
</style>
