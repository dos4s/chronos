import { onBeforeUnmount, ref, shallowRef } from 'vue'

/**
 * The shared drag engine (§15).
 *
 * Kanban cards drag today; Gantt bars, matrix cards and the session editor
 * follow in later milestones, which is why this knows nothing about statuses
 * or tasks. It deals in **lanes** (a column, later a row) and a **position
 * within a lane**, and answers one question: where would the thing being
 * dragged land if it were dropped now?
 *
 * Three rules from §15.2 shape the implementation:
 *
 * - **Nothing reactive moves per pointer move.** The card under the cursor is
 *   a single element whose `transform` is written directly, inside
 *   `requestAnimationFrame`. The reactive target changes only when the drop
 *   position actually changes, which is what redraws the placeholder.
 * - **Measure once.** Lane rectangles and card midpoints are read at drag
 *   start, not on every move; they are read again only when something has
 *   scrolled, which is rare and never per move.
 * - **One code path for mouse, touch and pen.** Pointer events with
 *   `setPointerCapture`, a 4 px threshold so clicks stay clicks, and a
 *   long-press on touch so a column can still be scrolled with a finger.
 *
 * Every drag is also a keyboard drag (§15.7): space picks a card up, the arrow
 * keys move it, space drops it, escape cancels, and each step is announced for
 * a screen reader.
 */

/** Where the thing being dragged would land. */
export interface DragTarget {
	lane: string
	index: number
}

export interface DragLane {
	key: string
	element: HTMLElement
}

export interface DragItem {
	id: string
	element: HTMLElement
}

export interface DragOptions {
	/** The scrolling element the lanes sit in, for autoscroll. */
	container: () => HTMLElement | null
	/** The lanes, in the order they are shown. */
	lanes: () => DragLane[]
	/** The items of one lane, in the order they are shown. */
	items: (lane: string) => DragItem[]
	/** Whether a lane will take what is being dragged (§15.3). */
	canDrop: (lane: string) => boolean
	/** Applies the drop. The engine is done once this is called. */
	onDrop: (ids: string[], target: DragTarget) => unknown
	/** What to say about a position, for the live region. */
	describe?: (target: DragTarget, ids: string[]) => string
}

/** Movement before a drag starts, so a click stays a click (§15.1). */
const THRESHOLD = 4

/** A finger has to rest this long, or it is a scroll (§15.1). */
const LONG_PRESS = 250

/** How close to an edge autoscroll starts, and how fast it goes. */
const EDGE = 64
const SCROLL_STEP = 18

interface Measurement {
	lanes: { key: string, left: number, right: number }[]
	/** Lane key → the vertical middle of each of its items. */
	midpoints: Record<string, number[]>
	/** Lane key → the ids of its items, the moved ones left out. */
	ids: Record<string, string[]>
	scrollLeft: number
	scrollTop: number
}

export function useDrag(options: DragOptions) {
	/** The ids being dragged; empty when nothing is. */
	const dragging = ref<string[]>([])
	/** Where they would land. Null while the pointer is over a lane that will not take them. */
	const target = ref<DragTarget | null>(null)
	/** The lane under the pointer, allowed or not, for the dimming (§15.3). */
	const hovering = ref<string | null>(null)
	/** True when the drag is being driven by the keyboard. */
	const byKeyboard = ref(false)
	/** The last thing worth saying out loud (§15.7). */
	const announcement = ref('')

	/** The floating copy of what is being dragged; moved without Vue. */
	const preview = shallowRef<HTMLElement | null>(null)

	let measurement: Measurement | null = null
	let pointerId: number | null = null
	let captured: HTMLElement | null = null
	let longPress: ReturnType<typeof setTimeout> | null = null
	let frame: number | null = null
	let scrolling: number | null = null
	let origin = { x: 0, y: 0 }
	let offset = { x: 0, y: 0 }
	let latest = { x: 0, y: 0 }
	let pending: string[] = []
	let started = false

	function isDragging(): boolean {
		return dragging.value.length > 0
	}

	/**
	 * A pointer went down on something draggable. Nothing is dragged yet: a
	 * mouse has to move past the threshold and a finger has to rest first.
	 */
	function pointerDown(event: PointerEvent, id: string, selected: string[] = []): void {
		if (event.button !== 0 || isDragging()) {
			return
		}

		pending = selected.includes(id) ? [...selected] : [id]
		pointerId = event.pointerId
		origin = { x: event.clientX, y: event.clientY }
		latest = { ...origin }
		started = false

		const element = event.currentTarget
		if (element instanceof HTMLElement) {
			captured = element
			const rect = element.getBoundingClientRect()
			offset = { x: event.clientX - rect.left, y: event.clientY - rect.top }
		}

		window.addEventListener('pointermove', onPointerMove, { passive: false })
		window.addEventListener('pointerup', onPointerUp)
		window.addEventListener('pointercancel', cancel)
		window.addEventListener('keydown', onKeyDown)

		if (event.pointerType === 'touch') {
			longPress = setTimeout(() => begin(), LONG_PRESS)
		}
	}

	/**
	 * Space on a focused card picks it up without a pointer at all (§15.7).
	 */
	function keyDown(event: KeyboardEvent, id: string, selected: string[] = []): void {
		if (event.key !== ' ' && event.key !== 'Enter') {
			return
		}

		event.preventDefault()
		pending = selected.includes(id) ? [...selected] : [id]
		byKeyboard.value = true
		begin(currentPositionOf(id))
		window.addEventListener('keydown', onKeyDown)
	}

	/** Picks the cards up: this is where a drag actually starts. */
	function begin(at: DragTarget | null = null): void {
		if (started || pending.length === 0) {
			return
		}

		started = true
		dragging.value = pending
		measurement = measure()
		target.value = at ?? positionFor(latest.x, latest.y)
		hovering.value = target.value?.lane ?? null
		say()

		if (captured !== null && pointerId !== null) {
			try {
				captured.setPointerCapture(pointerId)
			} catch {
				// A pointer that has already gone is not worth an exception;
				// the drag ends on the next pointerup either way.
			}
		}

		if (!byKeyboard.value) {
			startScrolling()
		}
	}

	function onPointerMove(event: PointerEvent): void {
		if (pointerId !== null && event.pointerId !== pointerId) {
			return
		}

		latest = { x: event.clientX, y: event.clientY }

		if (!started) {
			const far = Math.abs(latest.x - origin.x) > THRESHOLD || Math.abs(latest.y - origin.y) > THRESHOLD
			if (!far) {
				return
			}

			// A finger that moves before the long press is scrolling the
			// column, not dragging a card (§15.1).
			if (longPress !== null) {
				clearTimeout(longPress)
				longPress = null
				release()
				return
			}

			begin()
		}

		// The page must not scroll under a drag in progress.
		event.preventDefault()
		schedule()
	}

	/**
	 * One frame: move the floating card, and work out where it would land.
	 * Nothing here reads layout — the measurement did that once.
	 */
	function schedule(): void {
		if (frame !== null) {
			return
		}

		frame = requestAnimationFrame(() => {
			frame = null
			if (!started) {
				return
			}

			const element = preview.value
			if (element !== null) {
				const x = latest.x - offset.x
				const y = latest.y - offset.y
				element.style.transform = `translate3d(${x}px, ${y}px, 0)`
			}

			const next = positionFor(latest.x, latest.y)
			const lane = laneAt(latest.x)
			if (lane !== hovering.value) {
				hovering.value = lane
			}

			// Reactive state changes when the drop position changes, and not
			// once per pointer move (§15.2).
			if (next?.lane !== target.value?.lane || next?.index !== target.value?.index) {
				target.value = next
				say()
			}
		})
	}

	function onPointerUp(event: PointerEvent): void {
		if (pointerId !== null && event.pointerId !== pointerId) {
			return
		}

		if (!started) {
			release()
			return
		}

		drop()
	}

	function onKeyDown(event: KeyboardEvent): void {
		if (event.key === 'Escape') {
			event.preventDefault()
			cancel()
			return
		}

		if (!started || !byKeyboard.value) {
			return
		}

		if (event.key === ' ' || event.key === 'Enter') {
			event.preventDefault()
			drop()
			return
		}

		const moved = step(event.key)
		if (moved) {
			event.preventDefault()
			say()
		}
	}

	/** Arrow keys move the card one position, or one lane (§15.7). */
	function step(key: string): boolean {
		const current = target.value
		if (current === null || measurement === null) {
			return false
		}

		if (key === 'ArrowUp' || key === 'ArrowDown') {
			const length = measurement.ids[current.lane]?.length ?? 0
			const index = Math.max(0, Math.min(current.index + (key === 'ArrowUp' ? -1 : 1), length))
			if (index === current.index) {
				return false
			}
			target.value = { lane: current.lane, index }
			return true
		}

		if (key !== 'ArrowLeft' && key !== 'ArrowRight') {
			return false
		}

		const keys = measurement.lanes.map((lane) => lane.key)
		const direction = key === 'ArrowLeft' ? -1 : 1
		let at = keys.indexOf(current.lane) + direction

		// A lane the workflow will not take is stepped over, not landed on.
		while (at >= 0 && at < keys.length && !options.canDrop(keys[at])) {
			at += direction
		}

		if (at < 0 || at >= keys.length) {
			return false
		}

		const lane = keys[at]
		target.value = { lane, index: measurement.ids[lane]?.length ?? 0 }
		hovering.value = lane
		return true
	}

	function drop(): void {
		const ids = dragging.value
		const where = target.value
		finish()

		if (where === null || ids.length === 0 || !options.canDrop(where.lane)) {
			return
		}

		options.onDrop(ids, where)
	}

	function cancel(): void {
		if (started) {
			announcement.value = ''
		}
		finish()
	}

	function finish(): void {
		stopScrolling()
		if (frame !== null) {
			cancelAnimationFrame(frame)
			frame = null
		}

		dragging.value = []
		target.value = null
		hovering.value = null
		byKeyboard.value = false
		measurement = null
		started = false
		pending = []
		release()
	}

	function release(): void {
		if (longPress !== null) {
			clearTimeout(longPress)
			longPress = null
		}

		if (captured !== null && pointerId !== null) {
			try {
				captured.releasePointerCapture(pointerId)
			} catch {
				// Already released, which is the state this wanted anyway.
			}
		}

		captured = null
		pointerId = null

		window.removeEventListener('pointermove', onPointerMove)
		window.removeEventListener('pointerup', onPointerUp)
		window.removeEventListener('pointercancel', cancel)
		window.removeEventListener('keydown', onKeyDown)
	}

	/**
	 * Reads every rectangle the drag needs, once (§15.2). The cards being
	 * dragged are left out, because the position is counted in the lane as it
	 * will be when they arrive.
	 */
	function measure(): Measurement {
		const container = options.container()
		const lanes = options.lanes()
		const midpoints: Record<string, number[]> = {}
		const ids: Record<string, string[]> = {}

		for (const lane of lanes) {
			const items = options.items(lane.key).filter((item) => !dragging.value.includes(item.id))
			midpoints[lane.key] = items.map((item) => {
				const rect = item.element.getBoundingClientRect()
				return rect.top + rect.height / 2
			})
			ids[lane.key] = items.map((item) => item.id)
		}

		return {
			lanes: lanes.map((lane) => {
				const rect = lane.element.getBoundingClientRect()
				return { key: lane.key, left: rect.left, right: rect.right }
			}),
			midpoints,
			ids,
			scrollLeft: container?.scrollLeft ?? 0,
			scrollTop: container?.scrollTop ?? 0,
		}
	}

	function laneAt(x: number): string | null {
		if (measurement === null) {
			return null
		}

		for (const lane of measurement.lanes) {
			if (x >= lane.left && x <= lane.right) {
				return lane.key
			}
		}

		return null
	}

	function positionFor(x: number, y: number): DragTarget | null {
		const lane = laneAt(x)
		if (lane === null || measurement === null || !options.canDrop(lane)) {
			return null
		}

		const midpoints = measurement.midpoints[lane] ?? []
		let index = midpoints.length
		for (let at = 0; at < midpoints.length; at++) {
			if (y < midpoints[at]) {
				index = at
				break
			}
		}

		return { lane, index }
	}

	function currentPositionOf(id: string): DragTarget | null {
		for (const lane of options.lanes()) {
			const items = options.items(lane.key)
			const at = items.findIndex((item) => item.id === id)
			if (at > -1) {
				return { lane: lane.key, index: at }
			}
		}

		return null
	}

	/**
	 * Autoscroll near the edges, at a speed that grows with how close the
	 * pointer is to them (§15.2). Scrolling moves the cards, so what was
	 * measured is measured again — once per scroll step, never per move.
	 */
	function startScrolling(): void {
		if (scrolling !== null) {
			return
		}

		scrolling = window.setInterval(() => {
			const container = options.container()
			if (container === null || !started) {
				return
			}

			const rect = container.getBoundingClientRect()
			let moved = false

			if (latest.x < rect.left + EDGE) {
				container.scrollLeft -= speed(rect.left + EDGE - latest.x)
				moved = true
			} else if (latest.x > rect.right - EDGE) {
				container.scrollLeft += speed(latest.x - (rect.right - EDGE))
				moved = true
			}

			if (latest.y < rect.top + EDGE) {
				container.scrollTop -= speed(rect.top + EDGE - latest.y)
				moved = true
			} else if (latest.y > rect.bottom - EDGE) {
				container.scrollTop += speed(latest.y - (rect.bottom - EDGE))
				moved = true
			}

			if (moved) {
				measurement = measure()
				schedule()
			}
		}, 16)
	}

	function speed(distance: number): number {
		return Math.ceil((Math.min(distance, EDGE) / EDGE) * SCROLL_STEP)
	}

	function stopScrolling(): void {
		if (scrolling !== null) {
			window.clearInterval(scrolling)
			scrolling = null
		}
	}

	function say(): void {
		const where = target.value
		if (where === null || options.describe === undefined) {
			return
		}

		announcement.value = options.describe(where, dragging.value)
	}

	onBeforeUnmount(() => {
		stopScrolling()
		release()
	})

	return {
		dragging,
		target,
		hovering,
		byKeyboard,
		announcement,
		preview,
		isDragging,
		pointerDown,
		keyDown,
		cancel,
	}
}
