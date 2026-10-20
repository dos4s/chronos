<script setup lang="ts">
import type { PlanBar, PlanLink } from '../types/index.ts'
import type { Scale } from '../utils/scale.ts'

import { t } from '@nextcloud/l10n'
import { computed } from 'vue'

/**
 * The dependency lines (§12.5, §15.3).
 *
 * Every position here is **computed**, never measured: a row's vertical middle
 * is its index times the row height, and a bar's edges come from the scale. So
 * a project with a thousand links draws without reading layout once, and the
 * lines follow a bar live while it is being dragged because the bars they are
 * given are the preview ones (§15.2).
 *
 * A line turns red when the dates break the dependency it draws — which is the
 * warning in *warn* mode, and what the user sees before they let go.
 */
const props = defineProps<{
	links: PlanLink[]
	/** The bar each task is at, preview bars included. */
	bars: Record<string, PlanBar>
	/** Each task's row index, for the vertical middle of its bar. */
	indexOf: Record<string, number>
	scale: Scale
	rowHeight: number
	/** `predecessor>successor` for every link the dates break. */
	broken: Set<string>
	width: number
	height: number
}>()

const emit = defineEmits<{ remove: [link: PlanLink] }>()

interface Drawn {
	link: PlanLink
	path: string
	arrow: string
	broken: boolean
	label: string
}

/** A stub this long leaves the bar before the line turns. */
const STUB = 10

const drawn = computed<Drawn[]>(() => props.links.flatMap((link) => {
	const from = props.bars[link.predecessorUid]
	const to = props.bars[link.successorUid]
	const fromIndex = props.indexOf[link.predecessorUid]
	const toIndex = props.indexOf[link.successorUid]

	if (from === undefined || to === undefined || fromIndex === undefined || toIndex === undefined) {
		return []
	}

	const fromPlace = props.scale.place(from)
	const toPlace = props.scale.place(to)

	// Which end of each bar the relationship is about (RFC 9253 §4).
	const fromEnd = link.reltype === 'STARTTOSTART' || link.reltype === 'STARTTOFINISH'
		? fromPlace.x
		: fromPlace.x + fromPlace.width
	const toEnd = link.reltype === 'FINISHTOFINISH' || link.reltype === 'STARTTOFINISH'
		? toPlace.x + toPlace.width
		: toPlace.x

	const y1 = fromIndex * props.rowHeight + props.rowHeight / 2
	const y2 = toIndex * props.rowHeight + props.rowHeight / 2
	const outward = fromEnd === fromPlace.x ? -STUB : STUB
	const inward = toEnd === toPlace.x ? -STUB : STUB
	const turn = toEnd + inward

	return [{
		link,
		path: [
			`M ${fromEnd} ${y1}`,
			`H ${fromEnd + outward}`,
			`V ${y2}`,
			`H ${turn}`,
			`L ${toEnd} ${y2}`,
		].join(' '),
		// A small arrowhead pointing the way the dependency runs.
		arrow: inward < 0
			? `${toEnd} ${y2} ${toEnd - 6} ${y2 - 4} ${toEnd - 6} ${y2 + 4}`
			: `${toEnd} ${y2} ${toEnd + 6} ${y2 - 4} ${toEnd + 6} ${y2 + 4}`,
		broken: props.broken.has(`${link.predecessorUid}>${link.successorUid}`),
		label: t('chronos', 'Remove this dependency'),
	}]
}))
</script>

<template>
	<svg
		:class="$style.links"
		:width="width"
		:height="height"
		:viewBox="`0 0 ${width} ${height}`"
		aria-hidden="true">
		<g v-for="item in drawn" :key="`${item.link.predecessorUid}>${item.link.successorUid}`">
			<path
				:class="[$style.line, { [$style.lineBroken]: item.broken, [$style.loose]: !item.link.temporal }]"
				:d="item.path" />
			<polygon
				:class="[$style.head, { [$style.headBroken]: item.broken }]"
				:points="item.arrow" />
			<!--
				A wider invisible copy of the line, so a dependency can be
				clicked away without having to hit two pixels.
			-->
			<path
				:class="$style.hit"
				:d="item.path"
				@click="emit('remove', item.link)" />
		</g>
	</svg>
</template>

<style module>
.links {
	position: absolute;
	inset-block-start: 0;
	inset-inline-start: 0;
	pointer-events: none;
	overflow: visible;
}

.line {
	fill: none;
	stroke: var(--color-text-maxcontrast);
	stroke-width: 1.5;
}

/* `DEPENDS-ON` says what blocks what, not when (RFC 9253 §5). */
.loose {
	stroke-dasharray: 3 3;
}

/*
 * A line is stroked and an arrowhead is filled, and the two cannot share a
 * rule: `fill` on the path closed its L-shape and painted a red wedge across
 * the bars it ran between, which on a plan with a few broken links looked like
 * the chart had been struck through (§15.13).
 */
.lineBroken {
	stroke: var(--color-error-text);
}

.headBroken {
	fill: var(--color-error-text);
}

.head {
	fill: var(--color-text-maxcontrast);
	stroke: none;
}

.hit {
	fill: none;
	stroke: transparent;
	stroke-width: 10;
	pointer-events: stroke;
	cursor: pointer;
}
</style>
