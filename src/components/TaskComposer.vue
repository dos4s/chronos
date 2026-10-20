<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { nextTick, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

/**
 * One line that turns a sentence into a task.
 *
 * It knows nothing about where the task goes: the list adds it to the list,
 * a board column adds it to that column, the timeline adds it to the plan.
 * All three say what the placeholder should read and what to do with the
 * words, which is why this is the only composer in the app (§14.2).
 *
 * Typing and pressing Enter leaves the box focused and empty, because adding
 * one task is nearly always adding three.
 */
const props = defineProps<{
	placeholder?: string
	/** A write is in flight, so the box waits rather than queueing another. */
	busy?: boolean
	/** Takes the focus on arrival: a composer opened by a button was asked for. */
	autofocus?: boolean
	/** The small form a board column has room for. */
	compact?: boolean
}>()

const emit = defineEmits<{ submit: [summary: string], cancel: [] }>()

const summary = ref('')
const input = ref<HTMLInputElement | null>(null)

onMounted(async () => {
	if (props.autofocus === true) {
		await nextTick()
		input.value?.focus()
	}
})

function add(): void {
	const value = summary.value.trim()
	if (value === '' || props.busy === true) {
		return
	}

	summary.value = ''
	emit('submit', value)
}

/** Escape gives up on what is half-typed, and closes a composer that opened. */
function abandon(): void {
	summary.value = ''
	emit('cancel')
}
</script>

<template>
	<div :class="[$style.composer, { [$style.compact]: compact }]">
		<input
			ref="input"
			v-model="summary"
			type="text"
			:class="$style.input"
			:placeholder="placeholder || t('chronos', 'Add a task…')"
			:aria-label="placeholder || t('chronos', 'Add a task')"
			:disabled="busy"
			@keydown.enter="add"
			@keydown.esc.stop="abandon">
		<NcButton
			variant="primary"
			:disabled="busy || !summary.trim()"
			@click="add">
			<template v-if="busy" #icon>
				<NcLoadingIcon :size="20" />
			</template>
			{{ t('chronos', 'Add') }}
		</NcButton>
	</div>
</template>

<style module>
.composer {
	display: flex;
	gap: 8px;
	align-items: center;
}

/*
 * Qualified with the element for the same reason the buttons are (§15.10):
 * Nextcloud styles every `input`, `select` and `textarea` through a selector
 * with an element in it, which outranks a single class — so this rule's
 * padding, width and height were being ignored.
 */
input.input {
	flex: 1;
	min-width: 0;
	padding: 10px 16px;
	font-size: var(--chronos-text);
	color: var(--color-main-text);
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 999px;
	outline: none;
}

input.input:focus {
	border-color: var(--color-primary-element);
}

/* Inside a board column there is room for a line of text and little else. */
.compact .input {
	padding: 6px 10px;
	font-size: var(--chronos-text-small);
	border-radius: var(--chronos-radius-card);
}
</style>
