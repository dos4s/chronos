<?php

declare(strict_types=1);

namespace OCA\Chronos\Tests\Unit\Planning;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Chronos\Planning\WorkingDays;
use PHPUnit\Framework\TestCase;

/**
 * Which days a project works (§15.4): the stripes behind the bars, and where a
 * cascade is allowed to put a task.
 */
class WorkingDaysTest extends TestCase {

	public function testSaturdayAndSundayAreNotWorkingDaysByDefault(): void {
		$days = $this->days(WorkingDays::DEFAULT_DAYS);

		self::assertTrue($days->isWorking($this->day('2026-10-30'), true));
		self::assertFalse($days->isWorking($this->day('2026-10-31'), true));
		self::assertFalse($days->isWorking($this->day('2026-11-01'), true));
		self::assertTrue($days->isWorking($this->day('2026-11-02'), true));
	}

	public function testAWeekendIsPushedToTheMondayAfterIt(): void {
		$days = $this->days(WorkingDays::DEFAULT_DAYS);

		self::assertSame($this->day('2026-11-02'), $days->nextWorking($this->day('2026-10-31'), true));
		self::assertSame($this->day('2026-11-02'), $days->nextWorking($this->day('2026-11-01'), true));
	}

	/** A timed task keeps the time of day it is pushed from. */
	public function testPushingATimedTaskKeepsItsTimeOfDay(): void {
		$days = $this->days(WorkingDays::DEFAULT_DAYS);
		$saturday = $this->instant('2026-10-31 14:30');

		$pushed = $days->nextWorking($saturday, false);

		self::assertSame($this->instant('2026-11-02 14:30'), $pushed);
	}

	/**
	 * A project that has not said which days it works, works every day: a
	 * misconfigured one that worked on none would push every cascade forward
	 * for ever.
	 */
	public function testAProjectWithNoWorkingDaysSetWorksEveryDay(): void {
		$days = $this->days([]);

		self::assertTrue($days->isWorking($this->day('2026-10-31'), true));
		self::assertSame($this->day('2026-10-31'), $days->nextWorking($this->day('2026-10-31'), true));
	}

	public function testSevenWorkingDaysMeansNothingIsEverPushed(): void {
		$days = $this->days([1, 2, 3, 4, 5, 6, 7]);

		self::assertTrue($days->isWorking($this->day('2026-11-01'), true));
		self::assertSame($this->day('2026-11-01'), $days->nextWorking($this->day('2026-11-01'), true));
	}

	public function testNonsenseWeekdaysAreDroppedAndTheRestAreKeptInOrder(): void {
		$days = $this->days([5, 0, 9, 1, 1]);

		self::assertSame([1, 5], $days->toArray());
	}

	/**
	 * An all-day date lives in UTC and a timed one in the viewer's zone (§8.4),
	 * so half past midnight on Monday in Madrid is a working Monday — while the
	 * same instant read as a stored all-day date is still Sunday in UTC.
	 */
	public function testTheDayIsReadInTheFrameTheDateIsStoredIn(): void {
		$days = $this->days(WorkingDays::DEFAULT_DAYS);
		$earlyMonday = $this->instant('2026-11-02 00:30');

		self::assertTrue($days->isWorking($earlyMonday, false));
		self::assertFalse($days->isWorking($earlyMonday, true));
	}

	/**
	 * @param list<int> $days
	 */
	private function days(array $days): WorkingDays {
		return new WorkingDays($days, new DateTimeZone('Europe/Madrid'));
	}

	private function day(string $date): int {
		return (new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC')))->getTimestamp();
	}

	private function instant(string $local): int {
		return (new DateTimeImmutable($local, new DateTimeZone('Europe/Madrid')))->getTimestamp();
	}
}
