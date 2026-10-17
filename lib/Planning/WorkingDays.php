<?php

declare(strict_types=1);

namespace OCA\Chronos\Planning;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Chronos\Core\Support\TaskDates;

/**
 * Which days work, for the overlay and for the cascade (§15.4).
 *
 * Business calendars with holidays belong to the SLA module and arrive in M6
 * (§10.6); until then a project says which weekdays it works, which is the part
 * a timeline actually needs — the stripes behind the bars, and where a pushed
 * task is allowed to land.
 *
 * Two deliberate limits, both stated rather than hidden:
 *
 * - A cascade moves a task's **start** onto a working day. It does not stretch
 *   a bar over a weekend: that would mean holding a duration in working days
 *   rather than in time, which is a different model and a bigger one.
 * - A project with no working days configured works every day. Otherwise a
 *   misconfigured project would push every cascade forward for ever.
 */
final class WorkingDays {

	/** Monday to Friday, which is what a project gets until it says otherwise. */
	public const DEFAULT_DAYS = [1, 2, 3, 4, 5];

	/** @var list<int> ISO-8601 weekdays, 1 = Monday */
	public readonly array $days;

	/**
	 * @param list<int> $days
	 */
	public function __construct(
		array $days,
		private DateTimeZone $zone,
	) {
		$valid = [];
		foreach ($days as $day) {
			if ($day >= 1 && $day <= 7 && !in_array($day, $valid, true)) {
				$valid[] = $day;
			}
		}
		sort($valid);

		$this->days = $valid;
	}

	/**
	 * Whether an instant falls on a working day. An all-day date is read in
	 * UTC, because that is the frame it is stored in (§8.4); a timed one in the
	 * viewer's zone, because that is the day they are looking at.
	 */
	public function isWorking(int $at, bool $isDate): bool {
		if ($this->days === [] || count($this->days) === 7) {
			return true;
		}

		$zone = $isDate ? new DateTimeZone('UTC') : $this->zone;
		$weekday = (int)(new DateTimeImmutable('@' . $at))->setTimezone($zone)->format('N');

		return in_array($weekday, $this->days, true);
	}

	/**
	 * The same instant, or the same time on the next working day.
	 */
	public function nextWorking(int $at, bool $isDate): int {
		if ($this->days === []) {
			return $at;
		}

		$moved = $at;
		// Seven steps reach every weekday there is; a set that works on none
		// is impossible, because an empty set means every day works.
		for ($step = 0; $step < 7; $step++) {
			if ($this->isWorking($moved, $isDate)) {
				return $moved;
			}
			$moved = $isDate
				? TaskDates::addDays($moved, 1)
				: TaskDates::addDaysIn($moved, 1, $this->zone);
		}

		return $at;
	}

	/**
	 * @return list<int>
	 */
	public function toArray(): array {
		return $this->days;
	}
}
