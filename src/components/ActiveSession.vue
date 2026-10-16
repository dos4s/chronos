<script setup lang="ts">
import { computed } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { t } from '@nextcloud/l10n'
import { formatClock, formatTimeOfDay } from '../utils/format.ts'
import { workedSeconds } from '../utils/sessions.ts'
import { useNow } from '../composables/useNow.ts'
import { useProjectsStore } from '../stores/projects.ts'
import { useTimeStore } from '../stores/time.ts'
import type { Session } from '../types/index.ts'

const props = defineProps<{ session: Session }>()

const { now } = useNow()
const time = useTimeStore()
const projects = useProjectsStore()

const isPaused = computed(() => props.session.pausedSinceAt !== null)
const elapsed = computed(() => formatClock(workedSeconds(props.session, now.value)))
const projectColor = computed(
	() => projects.colorFor(props.session.projectUri) ?? 'var(--color-primary-element)',
)
</script>

<template>
	<section :class="['chronos-panel', $style.panel, isPaused ? $style.paused : $style.running]">
		<div :class="[$style.pulse, { [$style.pulsePaused]: isPaused }]" />
		<div :class="$style.state">
			{{ isPaused ? t('chronos', 'Paused') : t('chronos', 'Running') }}
		</div>
		<div :class="$style.timer">
			{{ elapsed }}
		</div>
		<div :class="$style.startedAt">
			{{ t('chronos', 'Started {time}', { time: formatTimeOfDay(session.startAt) }) }}
		</div>
		<div v-if="session.projectName" :class="$style.project">
			<span class="chronos-dot" :style="{ background: projectColor }" />
			{{ session.projectName }}
		</div>
		<div v-if="session.note" :class="$style.note">
			"{{ session.note }}"
		</div>
		<div :class="$style.actions">
			<NcButton
				v-if="!isPaused"
				variant="secondary"
				:disabled="time.loading"
				:aria-label="t('chronos', 'Pause')"
				@click="time.pause()">
				<template v-if="time.loading" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('chronos', 'Pause') }}
			</NcButton>
			<NcButton
				v-else
				variant="primary"
				:disabled="time.loading"
				:aria-label="t('chronos', 'Resume')"
				@click="time.resume()">
				<template v-if="time.loading" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('chronos', 'Resume') }}
			</NcButton>
			<NcButton
				variant="error"
				:disabled="time.loading"
				:aria-label="t('chronos', 'Check out')"
				@click="time.checkOut()">
				{{ t('chronos', 'Check out') }}
			</NcButton>
		</div>
	</section>
</template>

<style module>
.panel {
	position: relative;
}

.running {
	border-color: var(--color-primary-element);
	box-shadow:
		0 8px 32px -20px rgba(0, 0, 0, 0.4),
		0 0 0 1px color-mix(in srgb, var(--color-primary-element) 30%, transparent),
		0 0 40px -10px color-mix(in srgb, var(--color-primary-element) 40%, transparent);
}

.paused {
	border-color: var(--color-warning);
	box-shadow:
		0 8px 32px -20px rgba(0, 0, 0, 0.4),
		0 0 40px -16px color-mix(in srgb, var(--color-warning) 50%, transparent);
}

.pulse {
	width: 10px;
	height: 10px;
	border-radius: 50%;
	background: var(--color-success);
	box-shadow: 0 0 0 0 var(--color-success);
	animation: pulse 1.8s infinite;
}

.pulsePaused {
	background: var(--color-warning);
	box-shadow: none;
	animation: none;
}

@keyframes pulse {
	0% { box-shadow: 0 0 0 0 var(--color-success); }
	70% { box-shadow: 0 0 0 12px transparent; }
	100% { box-shadow: 0 0 0 0 transparent; }
}

.state {
	font-size: 11px;
	text-transform: uppercase;
	letter-spacing: 0.12em;
	font-weight: 700;
	color: var(--color-text-maxcontrast);
}

.timer {
	font-size: 64px;
	font-weight: 300;
	font-variant-numeric: tabular-nums;
	letter-spacing: -0.02em;
	line-height: 1;
}

.startedAt {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.project {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 14px;
	font-weight: 500;
}

.note {
	font-style: italic;
	color: var(--color-text-lighter);
	text-align: center;
	max-width: 420px;
	padding: 8px 16px;
	background: color-mix(in srgb, var(--color-main-text) 6%, transparent);
	border-radius: var(--border-radius);
}

.actions {
	display: flex;
	gap: 12px;
	margin-top: 4px;
	flex-wrap: wrap;
	justify-content: center;
}
</style>
