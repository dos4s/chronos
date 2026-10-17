<script setup lang="ts">
import type { Scale } from '../utils/scale.ts'

import { computed } from 'vue'

/**
 * The header above the chart: the months it spans, and a tick for every
 * column the zoom draws (§15.4).
 *
 * Like everything else on the timeline, the positions are worked out from the
 * scale rather than measured, so this stays in step with the bars under it
 * however far the chart is scrolled.
 */
const props = defineProps<{ scale: Scale }>()

const bands = computed(() => props.scale.bands())
const ticks = computed(() => props.scale.ticks())
</script>

<template>
	<div :class="$style.head" :style="{ width: `${scale.width}px` }">
		<div :class="$style.bands">
			<span
				v-for="band in bands"
				:key="band.label"
				:class="$style.band"
				:style="{ insetInlineStart: `${band.x}px`, width: `${band.width}px` }">
				{{ band.label }}
			</span>
		</div>
		<div :class="$style.ticks">
			<span
				v-for="tick in ticks"
				:key="tick.at"
				:class="[$style.tick, { [$style.major]: tick.major }]"
				:style="{ insetInlineStart: `${tick.x}px` }">
				{{ tick.label }}
			</span>
		</div>
	</div>
</template>

<style module>
.head {
	position: relative;
	height: 44px;
	flex: 0 0 auto;
}

.bands,
.ticks {
	position: relative;
	height: 22px;
}

.band,
.tick {
	position: absolute;
	font-size: 11px;
	line-height: 22px;
	white-space: nowrap;
	overflow: hidden;
	padding-inline-start: 4px;
	box-sizing: border-box;
	border-inline-start: 1px solid var(--color-border);
	color: var(--color-text-maxcontrast);
}

.band {
	font-weight: 600;
	color: var(--color-main-text);
}

.major {
	color: var(--color-main-text);
	font-weight: 600;
}
</style>
