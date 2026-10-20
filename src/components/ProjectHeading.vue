<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import { useProjectsStore } from '../stores/projects.ts'

/**
 * Which project a single-project view is showing, and how to change it.
 *
 * The sidebar is where a project is chosen (§14.2); this is what the board, the
 * timeline and the task list show instead of a picker of their own — the name
 * of the project, and a switcher that appears only when there is more than one
 * in scope. Switching here moves the view without changing what is in scope,
 * because narrowing the analytics is not what changing the board means.
 */
const projects = useProjectsStore()

const current = computed(() => projects.currentProject)
const others = computed(() => projects.inScope.filter((project) => project.uri !== current.value?.uri))

/** The area it is in, asked for rather than cut back out of the label. */
const area = computed(() => current.value === null ? null : projects.areaFor(current.value))
</script>

<template>
	<div :class="$style.heading">
		<span
			:class="$style.dot"
			:style="{ background: current?.color || 'var(--color-primary-element)' }"
			aria-hidden="true" />
		<span :class="$style.name">
			{{ current?.name ?? t('chronos', 'No project') }}
		</span>
		<span v-if="area" :class="$style.area">
			{{ area.name }}
		</span>

		<NcActions
			v-if="others.length"
			:aria-label="t('chronos', 'Show another project')"
			:title="t('chronos', 'Show another project')">
			<NcActionButton
				v-for="project in others"
				:key="project.uri"
				@click="projects.focus(project.uri)">
				{{ project.label }}
			</NcActionButton>
		</NcActions>
	</div>
</template>

<style module>
.heading {
	display: flex;
	align-items: center;
	gap: 8px;
	min-width: 0;
}

.dot {
	width: 10px;
	height: 10px;
	border-radius: 50%;
	flex-shrink: 0;
}

.name {
	font-size: var(--chronos-text);
	font-weight: 600;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.area {
	font-size: var(--chronos-text-small);
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}
</style>
