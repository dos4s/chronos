<script setup lang="ts">
import type { PlanSettings } from '../types/index.ts'

import { t } from '@nextcloud/l10n'
import { ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

/**
 * What a project says about its own timeline (§10.2, §12.5): what happens to
 * the dependents when a task moves, and which days it works.
 *
 * Both belong to the project rather than to the person looking at it — a
 * cascade rewrites tasks, and which days a project works is a fact about the
 * project.
 */
const props = defineProps<{ settings: PlanSettings, saving: boolean }>()
const emit = defineEmits<{ close: [], save: [settings: PlanSettings] }>()

const mode = ref<'warn' | 'cascade'>(props.settings.mode)
const days = ref<number[]>([...props.settings.workingDays])

watch(() => props.settings, (next) => {
	mode.value = next.mode
	days.value = [...next.workingDays]
})

/** Monday first, as the ISO week has it and as the API sends it. */
const WEEK = [
	{ day: 1, name: t('chronos', 'Monday') },
	{ day: 2, name: t('chronos', 'Tuesday') },
	{ day: 3, name: t('chronos', 'Wednesday') },
	{ day: 4, name: t('chronos', 'Thursday') },
	{ day: 5, name: t('chronos', 'Friday') },
	{ day: 6, name: t('chronos', 'Saturday') },
	{ day: 7, name: t('chronos', 'Sunday') },
]

function toggle(day: number): void {
	days.value = days.value.includes(day)
		? days.value.filter((kept) => kept !== day)
		: [...days.value, day].sort((a, b) => a - b)
}
</script>

<template>
	<NcModal :name="t('chronos', 'Timeline settings')" @close="emit('close')">
		<div :class="$style.body">
			<fieldset :class="$style.field">
				<legend :class="$style.label">
					{{ t('chronos', 'When a task moves') }}
				</legend>
				<label :class="$style.choice">
					<input v-model="mode" type="radio" value="warn">
					<span>
						<strong>{{ t('chronos', 'Warn me') }}</strong>
						{{ t('chronos', '— the task moves and the dependencies it breaks turn red.') }}
					</span>
				</label>
				<label :class="$style.choice">
					<input v-model="mode" type="radio" value="cascade">
					<span>
						<strong>{{ t('chronos', 'Move what depends on it') }}</strong>
						{{ t('chronos', '— everything waiting on it follows, in the same request. The bars show where they will land before you let go.') }}
					</span>
				</label>
			</fieldset>

			<fieldset :class="$style.field">
				<legend :class="$style.label">
					{{ t('chronos', 'Working days') }}
				</legend>
				<div :class="$style.week">
					<label v-for="entry in WEEK" :key="entry.day" :class="$style.day">
						<input
							type="checkbox"
							:checked="days.includes(entry.day)"
							@change="toggle(entry.day)">
						{{ entry.name }}
					</label>
				</div>
				<p :class="$style.hint">
					{{ t('chronos', 'The days nobody works are shaded on the chart, and a task pushed by a dependency lands on a working day. It never shortens a task that already spans a weekend.') }}
				</p>
				<NcNoteCard v-if="days.length === 0" type="info">
					{{ t('chronos', 'With nothing ticked the project works every day, which is also what it does before you choose.') }}
				</NcNoteCard>
			</fieldset>

			<div :class="$style.actions">
				<NcButton variant="tertiary" @click="emit('close')">
					{{ t('chronos', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="saving"
					@click="emit('save', { mode, workingDays: days })">
					{{ t('chronos', 'Save') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<style module>
.body {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 20px;
}

.field {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin: 0;
}

.label {
	font-size: 13px;
	font-weight: 600;
}

.choice {
	display: flex;
	gap: 8px;
	align-items: flex-start;
	font-size: 13px;
}

.week {
	display: flex;
	flex-wrap: wrap;
	gap: 10px;
}

.day {
	display: flex;
	align-items: center;
	gap: 4px;
	font-size: 13px;
}

.hint {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>
