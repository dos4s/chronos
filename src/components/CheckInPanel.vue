<script setup lang="ts">
import type { CheckInPayload } from '../types/index.ts'
import type { LabelledProject } from '../utils/projects.ts'

import { t } from '@nextcloud/l10n'
import { ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { useProjectsStore } from '../stores/projects.ts'
import { useTimeStore } from '../stores/time.ts'

const time = useTimeStore()
const projects = useProjectsStore()

const note = ref('')
/** True once the user has picked a project themselves. */
const touched = ref(false)

/**
 * Which project this session is logged against. It is an input to an action,
 * not a view's scope — so it stays a picker — but it starts on the project the
 * sidebar is working in, which is nearly always the one you are about to work
 * on, and it can still be cleared or changed (§14.2).
 */
const selected = ref<LabelledProject | null>(projects.currentProject)

watch(() => projects.currentProject, (project) => {
	// Follows the sidebar until the user has said otherwise themselves.
	if (!touched.value) {
		selected.value = project
	}
})

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
			{{ t('chronos', 'Pick a project and add a note if you want to, then press Check in.') }}
		</div>
		<div :class="$style.field">
			<NcSelect
				v-model="selected"
				:options="projects.offered"
				label="label"
				:reduce="(option: LabelledProject) => option"
				:clearable="true"
				:inputLabel="t('chronos', 'Project (optional)')"
				:placeholder="t('chronos', 'No project')"
				:disabled="time.loading"
				@update:modelValue="touched = true" />
		</div>
		<input
			v-model="note"
			type="text"
			:class="$style.note"
			:placeholder="t('chronos', 'What are you working on?')"
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
	font-size: var(--chronos-text-title);
	font-weight: 700;
	letter-spacing: -0.01em;
}

.hint {
	font-size: var(--chronos-text);
	color: var(--color-text-maxcontrast);
	margin-bottom: 8px;
	text-align: center;
}

/*
 * One width for the three controls. The picker was 370px, the note 600 and the
 * button 600, stacked — three edges where there should be one (§15.11).
 */
.field {
	width: 100%;
	max-width: 420px;
}

.panel :global(.button-vue) {
	max-width: 420px;
}

/*
 * Qualified with the element for the same reason the buttons are (§15.10):
 * Nextcloud styles every `input`, `select` and `textarea` through a selector
 * with an element in it, which outranks a single class — so this rule's
 * padding, width and height were being ignored.
 */
input.note {
	width: 100%;
	max-width: 420px;
	min-height: var(--chronos-control);
	padding: var(--chronos-space-2) var(--chronos-space-4);
	font-size: var(--chronos-text);
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

input.note:focus {
	border-color: var(--color-primary-element);
}

input.note:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}
</style>
