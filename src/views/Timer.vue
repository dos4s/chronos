<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import ActiveSession from '../components/ActiveSession.vue'
import CheckInPanel from '../components/CheckInPanel.vue'
import SessionList from '../components/SessionList.vue'
import { useProjectsStore } from '../stores/projects.ts'
import { useTimeStore } from '../stores/time.ts'

const time = useTimeStore()
const projects = useProjectsStore()
</script>

<template>
	<div :class="$style.view">
		<div class="chronos-view-head">
			<h2 class="chronos-section-title">
				{{ t('chronos', 'Timer') }}
			</h2>
		</div>

		<p class="chronos-view-why">
			{{ t('chronos', 'Check in when you start and out when you stop; what you record here is what the analytics count. A session can name a project and carry a note.') }}
		</p>

		<div v-if="projects.selection.length" :class="$style.filterNotice">
			{{ t('chronos', 'Filtering by project') }}:
			<strong>{{ projects.selection.map((uri: string) => projects.nameFor(uri) ?? uri).join(', ') }}</strong>
		</div>

		<transition name="fade" mode="out-in">
			<ActiveSession v-if="time.active" key="active" :session="time.active" />
			<CheckInPanel v-else key="idle" />
		</transition>

		<SessionList />
	</div>
</template>

<style module>
.view {
	display: flex;
	flex-direction: column;
	gap: 24px;
}

.filterNotice {
	font-size: var(--chronos-text);
	padding: 12px 16px;
	background: var(--color-background-hover);
	border-radius: var(--chronos-radius-card);
	color: var(--color-main-text);
	display: flex;
	align-items: center;
	gap: 6px;
	border: 1px solid var(--color-border);
}
</style>
