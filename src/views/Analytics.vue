<script setup lang="ts">
import { n, t } from '@nextcloud/l10n'
import { onBeforeUnmount, onMounted, watch } from 'vue'
import DailyChart from '../components/DailyChart.vue'
import ProjectBreakdown from '../components/ProjectBreakdown.vue'
import { useAnalyticsStore } from '../stores/analytics.ts'
import { useTimeStore } from '../stores/time.ts'
import { formatDuration } from '../utils/format.ts'

/**
 * Totals come from the server, so a running session's minutes appear at the
 * next refresh rather than ticking. One minute keeps that close enough without
 * polling for its own sake.
 */
const REFRESH_INTERVAL = 60_000

const analytics = useAnalyticsStore()
const time = useTimeStore()

let timer: ReturnType<typeof setInterval> | null = null

onMounted(() => {
	analytics.refresh()
	timer = setInterval(() => {
		if (time.active !== null) {
			analytics.refresh()
		}
	}, REFRESH_INTERVAL)
})

onBeforeUnmount(() => {
	if (timer !== null) {
		clearInterval(timer)
	}
})

// A check-in, check-out or edit changes the totals.
watch(() => time.sessions, () => analytics.refresh())
</script>

<template>
	<div :class="$style.view">
		<div class="chronos-view-head">
			<h2 class="chronos-section-title">
				{{ t('chronos', 'Analytics') }}
			</h2>
		</div>

		<p class="chronos-view-why">
			{{ t('chronos', 'Where your time went: today, the last seven days, and which project it went to. It counts checked-in sessions, not tasks.') }}
		</p>

		<div :class="$style.stats">
			<!--
				Two figures of the same kind, so one size and one shape: the
				left card used to be 36px and the right one 28px, side by side
				(§15.11). The second line is always there, so the pair matches
				whether or not there is anything to say.
			-->
			<div class="chronos-card">
				<div class="chronos-label">
					{{ t('chronos', 'Today') }}
				</div>
				<div :class="$style.value">
					{{ formatDuration(analytics.todaySeconds) }}
				</div>
				<div :class="$style.meta">
					{{ t('chronos', 'Since midnight') }}
				</div>
			</div>
			<div class="chronos-card">
				<div class="chronos-label">
					{{ t('chronos', 'Last 7 days') }}
				</div>
				<div :class="$style.value">
					{{ formatDuration(analytics.windowSeconds) }}
				</div>
				<div :class="$style.meta">
					{{ n('chronos', '%n session', '%n sessions', analytics.sessionCount) }}
				</div>
			</div>
		</div>

		<div :class="$style.charts">
			<DailyChart />
			<ProjectBreakdown />
		</div>
	</div>
</template>

<style module>
.view {
	display: flex;
	flex-direction: column;
	gap: 24px;
}

.stats {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
	gap: 16px;
}

.value {
	font-size: var(--chronos-text-display);
	font-weight: 700;
	font-variant-numeric: tabular-nums;
	letter-spacing: -0.02em;
}

.meta {
	font-size: var(--chronos-text-small);
	color: var(--color-text-maxcontrast);
}

.charts {
	display: grid;
	grid-template-columns: 1fr 340px;
	gap: 16px;
	align-items: start;
}

@media (max-width: 800px) {
	.charts {
		grid-template-columns: 1fr;
	}
}
</style>
