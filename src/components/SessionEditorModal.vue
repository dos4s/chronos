<script setup lang="ts">
import type { Session, SessionUpdatePayload } from '../types/index.ts'

import { showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { reactive, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcModal from '@nextcloud/vue/components/NcModal'
import { fromTimeInput, toTimeInput } from '../utils/timeInput.ts'

const props = defineProps<{ session: Session }>()
const emit = defineEmits<{ close: [], save: [payload: SessionUpdatePayload] }>()

interface PauseRow {
	key: number
	start: string
	end: string
}

let nextKey = 0

const form = reactive({
	start: '',
	end: '',
})
const pauses = ref<PauseRow[]>([])

watch(() => props.session, (session) => {
	form.start = toTimeInput(session.startAt)
	form.end = session.endAt === null ? '' : toTimeInput(session.endAt)
	pauses.value = session.pauses
		.filter((pause) => pause.endAt !== null)
		.map((pause) => ({
			key: nextKey++,
			start: toTimeInput(pause.startAt),
			end: toTimeInput(pause.endAt!),
		}))
}, { immediate: true })

function addPause() {
	pauses.value.push({ key: nextKey++, start: '', end: '' })
}

function removePause(key: number) {
	pauses.value = pauses.value.filter((pause) => pause.key !== key)
}

function save() {
	const day = props.session.startAt
	const startAt = fromTimeInput(form.start, day)
	if (startAt === null) {
		showError(t('chronos', 'Enter a start time'))
		return
	}

	const endAt = form.end === '' ? props.session.endAt : fromTimeInput(form.end, day)
	if (form.end !== '' && endAt === null) {
		showError(t('chronos', 'Enter a valid end time'))
		return
	}
	if (endAt !== null && endAt <= startAt) {
		showError(t('chronos', 'The end time has to come after the start time'))
		return
	}

	const parsed = pauses.value
		.map((pause) => ({
			startAt: fromTimeInput(pause.start, day),
			endAt: fromTimeInput(pause.end, day),
		}))
		.filter((pause): pause is SessionUpdatePayload['pauses'][number] => pause.startAt !== null && pause.endAt !== null)

	// The server clips and merges these as well; doing it here too means the
	// user sees the problem before saving instead of a surprise afterwards.
	if (parsed.some((pause) => pause.endAt !== null && pause.endAt <= pause.startAt)) {
		showError(t('chronos', 'A break has to end after it starts'))
		return
	}

	emit('save', {
		startAt,
		endAt,
		pauses: parsed,
		note: props.session.note,
	})
}
</script>

<template>
	<NcModal :name="t('chronos', 'Edit session')" @close="emit('close')">
		<div :class="$style.content">
			<h3 :class="$style.title">
				{{ session.projectName || t('chronos', 'No project') }}
			</h3>

			<div :class="$style.field">
				<label for="chronos-start">{{ t('chronos', 'Start time') }}</label>
				<input id="chronos-start" v-model="form.start" type="time">
			</div>

			<div :class="$style.field">
				<label for="chronos-end">{{ t('chronos', 'End time') }}</label>
				<input
					id="chronos-end"
					v-model="form.end"
					type="time"
					:disabled="session.endAt === null">
				<small v-if="session.endAt === null">{{ t('chronos', 'This session is still running.') }}</small>
			</div>

			<div :class="$style.pauses">
				<div :class="$style.pausesHeader">
					<h4 :class="$style.subtitle">
						{{ t('chronos', 'Breaks') }}
					</h4>
					<NcButton variant="tertiary" size="small" @click="addPause">
						<template #icon>
							<svg
								viewBox="0 0 24 24"
								width="18"
								height="18"
								aria-hidden="true">
								<path d="M19 13H13V19H11V13H5V11H11V5H13V11H19V13Z" fill="currentColor" />
							</svg>
						</template>
						{{ t('chronos', 'Add break') }}
					</NcButton>
				</div>

				<div v-for="pause in pauses" :key="pause.key" :class="$style.pauseRow">
					<input v-model="pause.start" type="time" :aria-label="t('chronos', 'Break start')">
					<span>—</span>
					<input v-model="pause.end" type="time" :aria-label="t('chronos', 'Break end')">
					<NcButton
						variant="tertiary"
						size="small"
						:aria-label="t('chronos', 'Remove break')"
						@click="removePause(pause.key)">
						<template #icon>
							<svg
								viewBox="0 0 24 24"
								width="18"
								height="18"
								aria-hidden="true">
								<path
									d="M19,4H15.5L14.5,3H9.5L8.5,4H5V6H19M6,19A2,2 0 0,0 8,21H16A2,2 0 0,0 18,19V7H6V19Z"
									fill="currentColor" />
							</svg>
						</template>
					</NcButton>
				</div>
				<div v-if="pauses.length === 0" :class="$style.noPauses">
					{{ t('chronos', 'No breaks recorded.') }}
				</div>
			</div>

			<div :class="$style.actions">
				<NcButton variant="secondary" @click="emit('close')">
					{{ t('chronos', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary" @click="save">
					{{ t('chronos', 'Save changes') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<style module>
.content {
	padding: 24px;
	min-width: 380px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.title {
	margin: 0 0 4px;
	font-size: 1.1rem;
	color: var(--color-main-text);
}

.subtitle {
	margin: 0;
	font-size: 0.95rem;
	color: var(--color-main-text);
}

.field {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.field label {
	font-weight: 600;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.field input[type="time"],
.pauseRow input[type="time"] {
	padding: 8px 12px;
	border-radius: 6px;
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-family: inherit;
	font-size: 1rem;
	width: 100%;
}

.field input[type="time"]:disabled {
	opacity: 0.5;
}

.pauses {
	border-top: 1px solid var(--color-border);
	padding-top: 16px;
}

.pausesHeader {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 10px;
}

.pauseRow {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 8px;
}

.pauseRow span {
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.noPauses {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
	font-style: italic;
	text-align: center;
	padding: 12px 0;
}

.actions {
	display: flex;
	justify-content: flex-end;
	gap: 10px;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
}
</style>
