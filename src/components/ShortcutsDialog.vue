<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import { groupsFor } from '../utils/shortcuts.ts'

/**
 * Everything the app can be told to do, in one place (§15.7).
 *
 * Opened with `?` from anywhere, or from the button in a view that has
 * gestures worth knowing about. The group for the view you are on comes
 * first, because that is the one being asked about.
 */
const emit = defineEmits<{ close: [] }>()

const route = useRoute()
const groups = computed(() => groupsFor(typeof route.name === 'string' ? route.name : null))
</script>

<template>
	<NcDialog
		:name="t('chronos', 'Keyboard and gestures')"
		:open="true"
		size="normal"
		@update:open="emit('close')">
		<div :class="$style.body">
			<section v-for="group in groups" :key="group.title" :class="$style.group">
				<h3 :class="$style.title">
					{{ group.title }}
				</h3>
				<dl :class="$style.list">
					<template v-for="shortcut in group.shortcuts" :key="shortcut.keys + shortcut.what">
						<dt :class="$style.keys">
							{{ shortcut.keys }}
						</dt>
						<dd :class="$style.what">
							{{ shortcut.what }}
						</dd>
					</template>
				</dl>
			</section>

			<p :class="$style.footer">
				{{ t('chronos', 'Every drag here also works from the keyboard. If one does not, that is a bug worth reporting.') }}
			</p>
		</div>
	</NcDialog>
</template>

<style module>
.body {
	display: flex;
	flex-direction: column;
	gap: 20px;
	padding: 20px;
}

.group {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.title {
	margin: 0;
	font-size: var(--chronos-text);
	font-weight: 600;
}

.list {
	display: grid;
	grid-template-columns: minmax(120px, max-content) 1fr;
	gap: 4px 12px;
	margin: 0;
	align-items: baseline;
}

.keys {
	font-size: var(--chronos-text-small);
	font-family: var(--font-face-monospace, monospace);
	background: var(--color-background-dark);
	border: 1px solid var(--color-border);
	border-radius: var(--chronos-radius-chip);
	padding: 1px 6px;
	white-space: nowrap;
	justify-self: start;
}

.what {
	margin: 0;
	font-size: var(--chronos-text-small);
}

.footer {
	margin: 0;
	font-size: var(--chronos-text-small);
	color: var(--color-text-maxcontrast);
}

@media (max-width: 700px) {
	.list {
		grid-template-columns: 1fr;
		gap: 2px;
	}

	.what {
		margin-block-end: 6px;
	}
}
</style>
