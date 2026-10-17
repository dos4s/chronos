import type { PlanBar } from '../types/index.ts'
import type { Scale } from '../utils/scale.ts'

import { onBeforeUnmount, ref } from 'vue'
import { asSeconds } from '../api/time.ts'
import { duration, sameBar, shift } from '../utils/planDates.ts'
import { dayAfter } from '../utils/scale.ts'

/**
 * Dragging on a timeline (§15.1, §15.4).
 *
 * The board's engine deals in lanes and positions; a timeline deals in a
 * continuous axis, so this is its own composable with the same manners: one
 * code path for mouse, touch and pen through pointer events with
 * `setPointerCapture`, a 4 px threshold so a click stays a click, a long press
 * before a finger starts dragging so the chart can still be scrolled, one
 * reactive update per frame at most, autoscroll at the edges, escape to
 * cancel, and a keyboard drag that does everything a pointer can (§15.7).
 *
 * Four things can be dragged, and they are different enough to name:
 *
 * - **move** — the whole bar, keeping its length;
 * - **start** and **end** — one edge, keeping the other;
 * - **link** — out of a bar's handle and onto another row, which makes a
 *   dependency rather than moving anything.
 *
 * Nothing here writes: it reports where the bar *would* be, which is what the
 * view draws as it drags — the bar itself, the tooltip, and the ghost bars of
 * whatever a cascade would take with it (§15.3).
 */

export type DragKind = 'move' | 'start' | 'end' | 'link'

export interface TimeDragOptions {
	/** The scrolling element the chart sits in, for autoscroll. */
	container: () => HTMLElement | null
	scale: () => Scale
	barOf: (uid: string) => PlanBar | null
	/** False for a summary row, a read-only list, or a row with no bar. */
	canDrag: (uid: string) => boolean
	/** Which row a pointer at this page position is over, for linking. */
	rowAt: (clientY: number) => string | null
	onMove: (uid: string, kind: DragKind, bar: PlanBar) => unknown
	onLink: (predecessorUid: string, successorUid: string) => unknown
	describe?: (uid: string, kind: DragKind, bar: PlanBar) => string
}

/** Movement before a drag starts, so a click stays a click (§15.1). */
const THRESHOLD = 4

/** A finger has to rest this long, or it is a scroll (§15.1). */
const LONG_PRESS = 250

/** How close to an edge autoscroll starts, and how fast it goes. */
const EDGE = 64
const SCROLL_STEP = 18

export function useTimeDrag(options: TimeDragOptions) {
	/** What is being dragged, and in which way; null when nothing is. */
	const dragging = ref<{ uid: string, kind: DragKind } | null>(null)
	/** Where the bar would land. Changes once per snap step, not per pixel. */
	const preview = ref<PlanBar | null>(null)
	/** The row a link is being dragged onto. */
	const linkTarget = ref<string | null>(null)
	/** True when the drag is being driven by the keyboard (§15.7). */
	const byKeyboard = ref(false)
	/** The last thing worth saying out loud (§15.7). */
	const announcement = ref('')

	let pointerId: number | null = null
	let captured: HTMLElement | null = null
	let longPress: ReturnType<typeof setTimeout> | null = null
	let frame: number | null = null
	let scrolling: number | null = null
	let origin = { x: 0, y: 0 }
	let latest = { x: 0, y: 0 }
	let original: PlanBar | null = null
	let pending: { uid: string, kind: DragKind } | null = null
	let started = false
	/** Alt drags without snapping (§15.4); read from the pointer itself. */
	let free = false

	function isDragging(): boolean {
		return dragging.value !== null
	}

	/**
	 * A pointer went down on a bar or one of its handles. Nothing moves yet: a
	 * mouse has to pass the threshold and a finger has to rest first.
	 */
	function pointerDown(event: PointerEvent, uid: string, kind: DragKind): void {
		if (event.button !== 0 || isDragging() || !options.canDrag(uid)) {
			return
		}

		const bar = options.barOf(uid)
		if (bar === null) {
			return
		}

		pending = { uid, kind }
		original = bar
		origin = { x: event.clientX, y: event.clientY }
		latest = { ...origin }
		started = false
		pointerId = event.pointerId

		captured = event.currentTarget instanceof HTMLElement ? event.currentTarget : null
		captured?.setPointerCapture(event.pointerId)

		window.addEventListener('pointermove', onPointerMove)
		window.addEventListener('pointerup', onPointerUp)
		window.addEventListener('pointercancel', cancel)
		window.addEventListener('keydown', onEscape)

		if (event.pointerType !== 'mouse') {
			longPress = setTimeout(() => begin(), LONG_PRESS)
		}
	}

	function begin(): void {
		if (pending === null || original === null || started) {
			return
		}

		started = true
		dragging.value = { ...pending }
		preview.value = original
		linkTarget.value = null
		announce(pending.uid, pending.kind, original)
	}

	function onPointerMove(event: PointerEvent): void {
		if (pending === null || (pointerId !== null && event.pointerId !== pointerId)) {
			return
		}

		latest = { x: event.clientX, y: event.clientY }
		free = event.altKey

		if (!started) {
			const far = Math.abs(latest.x - origin.x) > THRESHOLD || Math.abs(latest.y - origin.y) > THRESHOLD
			if (!far) {
				return
			}
			if (event.pointerType !== 'mouse') {
				// A finger that moves before the long press is a scroll.
				cancel()
				return
			}
			begin()
		}

		event.preventDefault()
		schedule()
		autoscroll()
	}

	/**
	 * One reactive update per frame at most (§15.2), and only when the snapped
	 * position has actually changed — which is what stops a drag re-rendering
	 * a thousand rows for every pixel.
	 */
	function schedule(): void {
		if (frame !== null) {
			return
		}

		frame = requestAnimationFrame(() => {
			frame = null
			const current = dragging.value
			if (current === null || original === null) {
				return
			}

			if (current.kind === 'link') {
				const over = options.rowAt(latest.y)
				linkTarget.value = over === current.uid ? null : over
				return
			}

			const next = positionFor(current.kind, original)
			if (next !== null && (preview.value === null || !sameBar(next, preview.value))) {
				preview.value = next
				announce(current.uid, current.kind, next)
			}
		})
	}

	/** Where the bar sits for the pointer's current position. */
	function positionFor(kind: DragKind, bar: PlanBar): PlanBar | null {
		const scale = options.scale()
		const allDay = bar.startIsDate && bar.dueIsDate
		const unit = scale.snapUnit(bar)
		const container = options.container()
		if (container === null) {
			return null
		}

		const box = container.getBoundingClientRect()
		const x = latest.x - box.left + container.scrollLeft

		if (kind === 'move') {
			const raw = (latest.x - origin.x) / scale.pxPerSecond
			// Alt drags freely; everything else lands on the zoom's unit
			// (§15.4). An all-day bar always lands on a day: it has no time.
			const delta = free && !allDay ? Math.round(raw) : Math.round(raw / unit) * unit

			return shift(bar, delta)
		}

		if (kind === 'start') {
			const at = scale.atOf(x, allDay, !free)
			const latestStart = asSeconds(bar.endAt - unit)

			return { ...bar, startAt: asSeconds(Math.min(at, latestStart)), inferred: false }
		}

		const at = allDay ? dayAfter(scale.atOf(x, true), true) : scale.atOf(x, false, !free)
		const earliestEnd = asSeconds(bar.startAt + unit)

		return { ...bar, endAt: asSeconds(Math.max(at, earliestEnd)), inferred: false }
	}

	function onEscape(event: KeyboardEvent): void {
		if (event.key === 'Escape') {
			cancel()
		}
	}

	function autoscroll(): void {
		const container = options.container()
		if (container === null) {
			return
		}

		const box = container.getBoundingClientRect()
		const left = latest.x - box.left
		const right = box.right - latest.x
		const direction = left < EDGE ? -1 : right < EDGE ? 1 : 0

		if (direction === 0) {
			stopScrolling()
			return
		}
		if (scrolling !== null) {
			return
		}

		scrolling = window.setInterval(() => {
			container.scrollLeft += direction * SCROLL_STEP
			schedule()
		}, 16)
	}

	function stopScrolling(): void {
		if (scrolling !== null) {
			window.clearInterval(scrolling)
			scrolling = null
		}
	}

	function onPointerUp(): void {
		const current = dragging.value
		const bar = preview.value
		const target = linkTarget.value
		const from = original

		release()

		if (current === null) {
			return
		}

		if (current.kind === 'link') {
			if (target !== null) {
				options.onLink(current.uid, target)
			}
			return
		}

		// A drag that ended where it started is not a write.
		if (bar !== null && from !== null && !sameBar(bar, from)) {
			options.onMove(current.uid, current.kind, bar)
		}
	}

	/**
	 * The keyboard drag (§15.7): space picks the bar up, the arrow keys move
	 * it by one snap step, shift and the arrows stretch its end, space drops
	 * it and escape puts it back.
	 */
	function keyDown(event: KeyboardEvent, uid: string): void {
		if (event.key === ' ' || event.key === 'Spacebar') {
			event.preventDefault();
			(dragging.value === null ? pickUp : drop)(uid)
			return
		}

		if (!isDragging() || dragging.value?.uid !== uid) {
			return
		}

		if (event.key === 'Escape') {
			event.preventDefault()
			cancel()
			return
		}

		const step = event.key === 'ArrowLeft' ? -1 : event.key === 'ArrowRight' ? 1 : 0
		if (step === 0) {
			return
		}

		event.preventDefault()
		const bar = preview.value
		if (bar === null) {
			return
		}

		const unit = options.scale().snapUnit(bar)
		const next = event.shiftKey
			// Shift stretches the end rather than moving the bar.
			? { ...bar, endAt: asSeconds(Math.max(bar.startAt + unit, bar.endAt + step * unit)), inferred: false }
			: shift(bar, step * unit)

		preview.value = next
		announce(uid, dragging.value?.kind ?? 'move', next)
	}

	function pickUp(uid: string): void {
		const bar = options.barOf(uid)
		if (bar === null || !options.canDrag(uid)) {
			return
		}

		original = bar
		dragging.value = { uid, kind: 'move' }
		preview.value = bar
		byKeyboard.value = true
		window.addEventListener('keydown', onEscape)
		announce(uid, 'move', bar)
	}

	function drop(uid: string): void {
		const bar = preview.value
		const from = original
		release()

		if (bar !== null && from !== null && !sameBar(bar, from)) {
			options.onMove(uid, 'move', bar)
		}
	}

	function cancel(): void {
		release()
		announcement.value = ''
	}

	function release(): void {
		if (longPress !== null) {
			clearTimeout(longPress)
			longPress = null
		}
		if (frame !== null) {
			cancelAnimationFrame(frame)
			frame = null
		}
		stopScrolling()

		if (captured !== null && pointerId !== null && captured.hasPointerCapture(pointerId)) {
			captured.releasePointerCapture(pointerId)
		}

		window.removeEventListener('pointermove', onPointerMove)
		window.removeEventListener('pointerup', onPointerUp)
		window.removeEventListener('pointercancel', cancel)
		window.removeEventListener('keydown', onEscape)

		captured = null
		pointerId = null
		pending = null
		original = null
		started = false
		free = false
		dragging.value = null
		preview.value = null
		linkTarget.value = null
		byKeyboard.value = false
	}

	function announce(uid: string, kind: DragKind, bar: PlanBar): void {
		if (options.describe !== undefined) {
			announcement.value = options.describe(uid, kind, bar)
		}
	}

	onBeforeUnmount(release)

	return {
		dragging,
		preview,
		linkTarget,
		byKeyboard,
		announcement,
		isDragging,
		pointerDown,
		keyDown,
		cancel,
		/** The length of what is being dragged, for the tooltip. */
		previewDuration: (): number => preview.value === null ? 0 : duration(preview.value),
	}
}
