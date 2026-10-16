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
		<div class="chronos-header">
			<h2 class="chronos-section-title">
				{{ t('chronos', 'Analytics overview') }}
			</h2>
		</div>

		<div :class="$style.stats">
			<div class="chronos-card">
				<div class="chronos-label">
					{{ t('chronos', 'Today') }}
				</div>
				<div :class="$style.valueLarge">
					{{ formatDuration(analytics.todaySeconds) }}
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
	font-size: 28px;
	font-weight: 700;
	font-variant-numeric: tabular-nums;
}

.valueLarge {
	font-size: 36px;
	font-weight: 700;
	font-variant-numeric: tabular-nums;
	letter-spacing: -0.02em;
}

.meta {
	font-size: 13px;
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
