import type { UnixSeconds } from '../api/time.ts'

import { onBeforeUnmount, onMounted, ref } from 'vue'
import { nowSeconds } from '../api/time.ts'

/**
 * One ticking clock for every component that shows a live duration, so a page
 * with several of them still runs a single interval.
 *
 * Display only: anything sent to the server is a value the server produced
 * (§8.3).
 */
const now = ref<UnixSeconds>(nowSeconds())
let subscribers = 0
let timer: ReturnType<typeof setInterval> | null = null

export function useNow() {
	onMounted(() => {
		subscribers += 1
		if (timer === null) {
			timer = setInterval(() => {
				now.value = nowSeconds()
			}, 1000)
		}
	})

	onBeforeUnmount(() => {
		subscribers -= 1
		if (subscribers <= 0 && timer !== null) {
			clearInterval(timer)
			timer = null
		}
	})

	return { now }
}
