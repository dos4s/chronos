<script setup lang="ts">
import type { Board, BoardColumn } from '../types/index.ts'
import type { RuleEdit } from '../utils/board.ts'

import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { computed, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { messageFor } from '../api/client.ts'
import * as api from '../api/workflows.ts'
import { columnByKey, editsFor, isUnrestricted, rulesAfterEdit } from '../utils/board.ts'

/**
 * What a column can be told: how many cards belong in it, and where its cards
 * are allowed to go.
 *
 * This is where transition rules finally have a face (D28). The API has had
 * them since M2, but nothing drew them, so in practice every move stayed
 * allowed; the board is where they are worth seeing, because a rule is what
 * dims a column while a card is in the air.
 *
 * A board made of the four standard statuses has nothing to configure: those
 * are iCalendar's, not the user's.
 */
const props = defineProps<{ board: Board, column: BoardColumn }>()
const emit = defineEmits<{ close: [], saved: [] }>()

const limit = ref<number | ''>('')
const edits = ref<RuleEdit[]>([])
const saving = ref(false)

const workflowId = computed(() => props.board.workflowId)
const editable = computed(() => workflowId.value !== null && props.column.statusId !== null)

watch([() => props.board, () => props.column], () => {
	limit.value = props.column.wipLimit ?? ''
	edits.value = editsFor(props.board, props.column.key)
}, { immediate: true })

const nameOf = (key: string) => columnByKey(props.board, key)?.name ?? key

/**
 * Taking the last move away would leave a column cards can enter and never
 * leave, which is a workflow nobody wants by accident.
 */
const nothingAllowed = computed(() => edits.value.every((edit) => !edit.allowed))

async function save(): Promise<void> {
	const id = workflowId.value
	const statusId = props.column.statusId
	if (id === null || statusId === null) {
		emit('close')
		return
	}

	saving.value = true

	try {
		const wipLimit = limit.value === '' || Number(limit.value) <= 0 ? null : Number(limit.value)
		if (wipLimit !== (props.column.wipLimit ?? null)) {
			await api.updateStatus(id, statusId, { wipLimit })
		}

		const rules = rulesAfterEdit(props.board, props.column.key, edits.value)
		// A matrix that allows everything is stored as no rules at all, which
		// is what an untouched workflow means (§10.3).
		const toSend = isUnrestricted(props.board, rules)
			? []
			: rules.flatMap((rule) => {
					const from = columnByKey(props.board, rule.from)?.statusId
					const to = columnByKey(props.board, rule.to)?.statusId
					return from === null || from === undefined || to === null || to === undefined
						? []
						: [{ from, to, requiresComment: rule.requiresComment }]
				})

		await api.setTransitions(id, toSend)

		showSuccess(t('chronos', 'Column saved'))
		emit('saved')
	} catch (error) {
		showError(messageFor(error))
	} finally {
		saving.value = false
	}
}
</script>

<template>
	<NcModal :name="t('chronos', 'Column settings')" @close="emit('close')">
		<div :class="$style.body">
			<h2 :class="$style.title">
				{{ column.name }}
			</h2>

			<NcNoteCard v-if="!editable" type="info">
				{{ t('chronos', 'This board uses the four standard statuses, which belong to the calendar format rather than to you. Give the project a workflow in its settings to name your own statuses, limit them and decide which moves are allowed.') }}
			</NcNoteCard>

			<template v-else>
				<label :class="$style.field">
					<span :class="$style.label">{{ t('chronos', 'Cards at a time (WIP limit)') }}</span>
					<input
						v-model="limit"
						type="number"
						min="0"
						:placeholder="t('chronos', 'No limit')">
					<span :class="$style.hint">
						{{ t('chronos', 'The board warns when a column holds more than this. It never refuses a card: a limit is there to be seen, not to stop you finishing something.') }}
					</span>
				</label>

				<fieldset :class="$style.rules">
					<legend :class="$style.label">
						{{ t('chronos', 'Cards in this column may move to') }}
					</legend>

					<div v-for="edit in edits" :key="edit.to" :class="$style.rule">
						<label :class="$style.ruleName">
							<input v-model="edit.allowed" type="checkbox">
							{{ nameOf(edit.to) }}
						</label>
						<label :class="[$style.note, { [$style.noteOff]: !edit.allowed }]">
							<input
								v-model="edit.requiresComment"
								type="checkbox"
								:disabled="!edit.allowed">
							{{ t('chronos', 'needs a note') }}
						</label>
					</div>

					<p :class="$style.hint">
						{{ t('chronos', 'While a card is being dragged, the columns it may not go to are dimmed, and the server refuses the move as well. Leaving every move ticked everywhere is the same as having no rules at all.') }}
					</p>

					<NcNoteCard v-if="nothingAllowed" type="warning">
						{{ t('chronos', 'With nothing ticked, a card in this column can never leave it.') }}
					</NcNoteCard>
				</fieldset>
			</template>

			<div :class="$style.actions">
				<NcButton variant="tertiary" @click="emit('close')">
					{{ t('chronos', 'Cancel') }}
				</NcButton>
				<NcButton
					v-if="editable"
					variant="primary"
					:disabled="saving"
					@click="save">
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

.title {
	margin: 0;
	font-size: 18px;
}

.field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.label {
	font-size: 13px;
	font-weight: 600;
}

.hint {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.rules {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin: 0;
}

.rule {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}

.ruleName {
	display: flex;
	align-items: center;
	gap: 6px;
	flex: 1;
	font-size: 14px;
}

.note {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.noteOff {
	opacity: 0.5;
}

.actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>
