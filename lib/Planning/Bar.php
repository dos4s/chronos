<?php

declare(strict_types=1);

namespace OCA\Chronos\Planning;

use JsonSerializable;

/**
 * One bar on a timeline: where a task sits, and what kind of dates put it
 * there (§12.5).
 *
 * `endAt` is **exclusive** — the instant the bar stops covering, which for an
 * all-day task is the start of the day after its `DUE` date (§8.4). Keeping it
 * that way means every comparison in `Schedule` is a plain one, and the one
 * conversion back to an inclusive `DUE` happens where a write is prepared.
 *
 * A bar is immutable: a cascade produces new bars rather than editing the ones
 * it was given, so the plan it started from is still there to compare against.
 */
final class Bar implements JsonSerializable {

	public function __construct(
		public readonly string $uid,
		public readonly int $startAt,
		/** Exclusive: the instant after the bar, not the last one in it. */
		public readonly int $endAt,
		public readonly bool $startIsDate,
		public readonly bool $dueIsDate,
		/**
		 * True when one of the two ends is the plan's guess rather than the
		 * task's own date — a task with only a due date, or a summary row.
		 * Nothing is written for an inferred bar until the user moves it.
		 */
		public readonly bool $inferred = false,
		public readonly bool $isMilestone = false,
	) {
	}

	public function duration(): int {
		return max(0, $this->endAt - $this->startAt);
	}

	/**
	 * An all-day bar is one whose both ends are dates; a mixed pair is not
	 * something iCalendar allows on one task (RFC 5545 §3.8.2), and if a
	 * client writes one anyway it is treated as timed rather than guessed at.
	 */
	public function isAllDay(): bool {
		return $this->startIsDate && $this->dueIsDate;
	}

	/**
	 * The same bar between two instants. Only `PlanDates` calls this, because
	 * the two instants have to have been worked out with the right kind of
	 * arithmetic for the kind of bar it is.
	 */
	public function at(int $startAt, int $endAt): self {
		return new self(
			$this->uid,
			$startAt,
			$endAt,
			$this->startIsDate,
			$this->dueIsDate,
			$this->inferred,
			$this->isMilestone,
		);
	}

	public function sameAs(self $other): bool {
		return $this->startAt === $other->startAt && $this->endAt === $other->endAt;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'startAt' => $this->startAt,
			'endAt' => $this->endAt,
			'startIsDate' => $this->startIsDate,
			'dueIsDate' => $this->dueIsDate,
			'inferred' => $this->inferred,
			'isMilestone' => $this->isMilestone,
		];
	}
}
