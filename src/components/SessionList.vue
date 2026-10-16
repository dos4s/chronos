<script setup lang="ts">
import type { Session } from '../types/index.ts'

import { getDialogBuilder } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import { computed } from 'vue'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { useNow } from '../composables/useNow.ts'
import { useProjectsStore } from '../stores/projects.ts'
import { useTimeStore } from '../stores/time.ts'
import { formatDate, formatDuration, formatTimeOfDay } from '../utils/format.ts'
import { workedSeconds } from '../utils/sessions.ts'

const { now } = useNow()
const time = useTimeStore()
const projects = useProjectsStore()

const sessions = computed(() => time.sessions.filter((session) => projects.matches(session.projectUri)))

async function confirmDelete(session: Session) {
	const confirmed = await new Promise<boolean>((resolve) => {
		const dialog = getDialogBuilder(t('chronos', 'Delete session?'))
			.setText(session.note
				? t('chronos', 'This will permanently delete "{note}". This cannot be undone.', { note: session.note })
				: t('chronos', 'This will permanently delete this session. This cannot be undone.'))
			.setButtons([
				{ label: t('chronos', 'Cancel'), variant: 'secondary', callback: () => resolve(false) },
				{ label: t('chronos', 'Delete'), variant: 'error', callback: () => resolve(true) },
			])
			.build()
		dialog.show().catch(() => resolve(false))
	})

	if (confirmed) {
		await time.remove(session.id)
	}
}
</script>

<template>
	<section :class="$style.log">
		<div class="chronos-header">
			<h2 class="chronos-section-title">
				{{ t('chronos', 'Recent sessions') }}
			</h2>
			<span v-if="sessions.length" :class="$style.count">
				{{ n('chronos', '%n entry', '%n entries', sessions.length) }}
			</span>
		</div>

		<div v-if="time.initialLoading" :class="$style.loading">
			<NcLoadingIcon :size="32" />
		</div>

		<ul v-else-if="sessions.length" :class="$style.list">
			<li
				v-for="session in sessions"
				:key="session.id"
				:class="[$style.row, { [$style.rowActive]: session.endAt === null }]">
				<span
					class="chronos-dot"
					:style="{ background: projects.colorFor(session.projectUri) || 'var(--color-text-maxcontrast)' }"
					:title="session.projectName || t('chronos', 'No project')" />
				<div :class="$style.main">
					<div :class="$style.note">
						{{ session.note || t('chronos', 'Untitled session') }}
					</div>
					<div :class="$style.meta">
						<span v-if="session.projectName" :class="$style.project">
							{{ session.projectName }}
						</span>
						<span v-if="session.projectName"> · </span>
						{{ formatDate(session.startAt) }}
						· {{ formatTimeOfDay(session.startAt) }}
						<template v-if="session.endAt">
							– {{ formatTimeOfDay(session.endAt) }}
						</template>
						<template v-else>
							· <span :class="$style.live">
								{{ session.pausedSinceAt ? t('chronos', 'paused') : t('chronos', 'live') }}
							</span>
						</template>
					</div>
				</div>
				<div :class="$style.duration">
					{{ formatDuration(workedSeconds(session, now)) }}
				</div>
				<button
					v-if="session.endAt !== null"
					:class="$style.delete"
					:disabled="time.loading"
					:aria-label="t('chronos', 'Delete session')"
					:title="t('chronos', 'Delete session')"
					@click="confirmDelete(session)">
					<svg
						viewBox="0 0 24 24"
						width="18"
						height="18"
						aria-hidden="true">
						<path
							d="M19,4H15.5L14.5,3H9.5L8.5,4H5V6H19M6,19A2,2 0 0,0 8,21H16A2,2 0 0,0 18,19V7H6V19Z"
							fill="currentColor" />
					</svg>
				</button>
			</li>
		</ul>

		<NcEmptyContent
			v-else
			:class="$style.empty"
			:name="t('chronos', 'No sessions yet')"
			:description="t('chronos', 'Your first check-in will appear here.')" />
	</section>
</template>

<style module>
.log {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.count {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.loading {
	display: flex;
	justify-content: center;
	padding: 32px;
}

.list {
	list-style: none;
	padding: 0;
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 1px;
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	overflow: hidden;
}

.row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 14px 20px;
	background: var(--color-main-background);
	transition: background 0.15s ease;
}

.row:hover {
	background: var(--color-background-hover);
}

.row:hover .delete {
	opacity: 1;
}

.rowActive {
	background: color-mix(in srgb, var(--color-primary-element) 12%, var(--color-main-background));
}

.main {
	flex: 1;
	min-width: 0;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.note {
	font-size: 14px;
	font-weight: 500;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.meta {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.project {
	font-weight: 600;
	color: var(--color-main-text);
}

.duration {
	font-variant-numeric: tabular-nums;
	font-weight: 600;
	font-size: 15px;
}

.live {
	color: var(--color-success);
	font-weight: 600;
	text-transform: uppercase;
	font-size: 10px;
	letter-spacing: 0.08em;
}

.delete {
	display: flex;
	align-items: center;
	justify-content: center;
	border: none;
	background: transparent;
	color: var(--color-text-maxcontrast);
	padding: 6px;
	border-radius: 50%;
	cursor: pointer;
	opacity: 0;
	transition: opacity 0.15s ease, background 0.15s ease, color 0.15s ease;
}

.delete:hover,
.delete:focus-visible {
	opacity: 1;
	background: color-mix(in srgb, var(--color-error) 20%, transparent);
	color: var(--color-error);
	outline: none;
}

.delete:disabled {
	opacity: 0.3;
	cursor: not-allowed;
}

.empty {
	padding: 24px 0;
}
</style>
