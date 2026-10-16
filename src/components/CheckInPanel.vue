<script setup lang="ts">
import type { CheckInPayload, TaskList } from '../types/index.ts'

import { t } from '@nextcloud/l10n'
import { ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { useProjectsStore } from '../stores/projects.ts'
import { useTimeStore } from '../stores/time.ts'

const time = useTimeStore()
const projects = useProjectsStore()

const note = ref('')
const selected = ref<TaskList | null>(null)

async function checkIn() {
	const payload: CheckInPayload = {}
	if (note.value.trim() !== '') {
		payload.note = note.value.trim()
	}
	if (selected.value !== null) {
		payload.projectUri = selected.value.uri
		payload.projectName = selected.value.name
	}

	if (await time.checkIn(payload)) {
		note.value = ''
	}
}
</script>

<template>
	<section class="chronos-panel" :class="[$style.panel]">
		<div :class="$style.headline">
			{{ t('chronos', 'Ready when you are') }}
		</div>
		<div :class="$style.hint">
			{{ t('chronos', 'Pick a project (optional), add a note, and press Check in.') }}
		</div>
		<div :class="$style.field">
			<NcSelect
				v-model="selected"
				:options="projects.taskLists"
				label="name"
				:reduce="(list: TaskList) => list"
				:clearable="true"
				:inputLabel="t('chronos', 'Project (optional)')"
				:placeholder="t('chronos', 'No project')"
				:disabled="time.loading" />
		</div>
		<input
			v-model="note"
			type="text"
			:class="$style.note"
			:placeholder="t('chronos', 'What are you working on? (optional)')"
			:disabled="time.loading"
			@keydown.enter="checkIn">
		<NcButton
			variant="primary"
			wide
			:disabled="time.loading"
			:aria-label="t('chronos', 'Check in')"
			@click="checkIn">
			<template v-if="time.loading" #icon>
				<NcLoadingIcon :size="20" />
			</template>
			{{ t('chronos', 'Check in') }}
		</NcButton>
	</section>
</template>

<style module>
.panel {
	position: relative;
}

.headline {
	font-size: 22px;
	font-weight: 600;
	letter-spacing: -0.01em;
}

.hint {
	font-size: 14px;
	color: var(--color-text-maxcontrast);
	margin-bottom: 8px;
	text-align: center;
}

.field {
	width: 100%;
	max-width: 420px;
}

.note {
	width: 100%;
	max-width: 420px;
	padding: 12px 16px;
	font-size: 15px;
	color: var(--color-main-text);
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 999px;
	outline: none;
	transition: border-color 0.2s ease;
	box-sizing: border-box;
}

.note::placeholder {
	color: var(--color-text-maxcontrast);
}

.note:focus {
	border-color: var(--color-primary-element);
}

.note:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}
</style>
