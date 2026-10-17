<?php

declare(strict_types=1);

namespace OCA\Chronos\Planning;

use DateTimeZone;
use OCA\Chronos\Core\Dav\ParsedTask;
use OCA\Chronos\Core\Support\TaskDates;

/**
 * The arithmetic a timeline needs, in one place (§8.4, §15.4).
 *
 * Three rules from §8.4 decide everything here, and every one of them is a bug
 * waiting to happen if it is written out twice:
 *
 * - **An all-day date is a day, not an instant.** It is stored as midnight UTC
 *   purely so it sorts, and `DUE` on an all-day task is the *last* day of the
 *   bar: a task due Friday is worked on through Friday. So a bar's end is kept
 *   here as an **exclusive** instant — the start of the day after — and
 *   converted back to the inclusive stored value whenever one is written. Every
 *   comparison in `Schedule` is then a plain `>=` with no off-by-a-day.
 * - **Day arithmetic is calendar arithmetic.** Adding a day to an all-day date
 *   adds a day; adding 86,400 seconds adds a day except when it adds 23 or 25
 *   hours, which is the acceptance criterion M4 was given.
 * - **A timed task keeps its wall-clock time.** Dragging a bar a week to the
 *   right across a daylight-saving change should leave a 9 am task at 9 am, so
 *   whole days are added in the viewer's time zone and only the remainder is
 *   added as seconds.
 */
final class PlanDates {

	/** A bar with only one date gets this much length, so it can be seen. */
	public const DEFAULT_DURATION = 86400;

	public function __construct(
		private DateTimeZone $zone,
	) {
	}

	/**
	 * The bar a task draws, or null when it has no dates at all.
	 *
	 * A task with one date still gets a bar: a Gantt that only showed tasks
	 * with both ends would show almost nothing of a real list, and the one
	 * date the user did set is the one worth seeing. The bar is marked
	 * `inferred` so the view can draw it as a suggestion, and nothing is
	 * written until the user drags it.
	 */
	public function bar(
		string $uid,
		?int $startAt,
		bool $startIsDate,
		?int $dueAt,
		bool $dueIsDate,
		string $type = ParsedTask::TYPE_TASK,
	): ?Bar {
		if ($startAt === null && $dueAt === null) {
			return null;
		}

		// A milestone is a moment, not a span (§6): it has `DUE` and no length.
		if ($type === ParsedTask::TYPE_MILESTONE && $dueAt !== null) {
			return new Bar($uid, $dueAt, $this->exclusiveEnd($dueAt, $dueIsDate), $dueIsDate, $dueIsDate, false, true);
		}

		if ($startAt !== null && $dueAt !== null) {
			$end = $this->exclusiveEnd($dueAt, $dueIsDate);
			// A due date before the start is somebody else's data, not a bar:
			// it is drawn as the one-day bar the due date describes.
			return $end <= $startAt
				? new Bar($uid, $dueAt, $end, $dueIsDate, $dueIsDate, true, false)
				: new Bar($uid, $startAt, $end, $startIsDate, $dueIsDate, false, false);
		}

		if ($dueAt !== null) {
			$end = $this->exclusiveEnd($dueAt, $dueIsDate);

			return new Bar($uid, $this->back($end, $dueIsDate), $end, $dueIsDate, $dueIsDate, true, false);
		}

		// Only a start: one default-length bar beginning there.
		return new Bar(
			$uid,
			$startAt,
			$this->forward($startAt, $startIsDate),
			$startIsDate,
			$startIsDate,
			true,
			false,
		);
	}

	/**
	 * A bar's end as an exclusive instant: the same instant for a timed task,
	 * and the start of the next day for an all-day one.
	 */
	public function exclusiveEnd(int $dueAt, bool $isDate): int {
		return $isDate ? TaskDates::addDays($dueAt, 1) : $dueAt;
	}

	/**
	 * The `DUE` value to write for a bar that ends at this exclusive instant —
	 * the inverse of `exclusiveEnd()`, and the only place a bar turns back
	 * into a date somebody else will read.
	 */
	public function inclusiveDue(int $endAt, bool $isDate): int {
		return $isDate ? TaskDates::addDays($endAt, -1) : $endAt;
	}

	/**
	 * The bar moved by this many seconds, keeping its length.
	 *
	 * Whole days move as days — in UTC for an all-day bar, whose dates are
	 * stored there, and in the viewer's zone for a timed one, so a 9 am task
	 * dragged across a daylight-saving change is still at 9 am.
	 */
	public function shift(Bar $bar, int $seconds): Bar {
		if ($bar->isAllDay()) {
			$days = (int)round($seconds / 86400);

			return $bar->at(TaskDates::addDays($bar->startAt, $days), TaskDates::addDays($bar->endAt, $days));
		}

		$days = intdiv($seconds, 86400);
		$rest = $seconds - $days * 86400;

		return $bar->at(
			TaskDates::addDaysIn($bar->startAt, $days, $this->zone) + $rest,
			TaskDates::addDaysIn($bar->endAt, $days, $this->zone) + $rest,
		);
	}

	/**
	 * The same bar starting at an instant, with its length unchanged — what a
	 * cascade does to a dependent (§15.4).
	 */
	public function startingAt(Bar $bar, int $startAt): Bar {
		return $this->shift($bar, $startAt - $bar->startAt);
	}

	/**
	 * The same bar ending at an exclusive instant, length unchanged.
	 */
	public function endingAt(Bar $bar, int $endAt): Bar {
		return $this->shift($bar, $endAt - $bar->endAt);
	}

	/**
	 * The span a summary row draws: everything under it, from the earliest
	 * start to the latest end (§12.5).
	 *
	 * @param list<Bar> $bars
	 */
	public function summary(string $uid, array $bars): ?Bar {
		$start = null;
		$end = null;
		$allDay = true;

		foreach ($bars as $bar) {
			$start = $start === null ? $bar->startAt : min($start, $bar->startAt);
			$end = $end === null ? $bar->endAt : max($end, $bar->endAt);
			$allDay = $allDay && $bar->isAllDay();
		}

		if ($start === null || $end === null) {
			return null;
		}

		return new Bar($uid, $start, $end, $allDay, $allDay, true, false);
	}

	private function forward(int $at, bool $isDate): int {
		return $isDate ? TaskDates::addDays($at, 1) : $at + self::DEFAULT_DURATION;
	}

	private function back(int $at, bool $isDate): int {
		return $isDate ? TaskDates::addDays($at, -1) : $at - self::DEFAULT_DURATION;
	}
}
