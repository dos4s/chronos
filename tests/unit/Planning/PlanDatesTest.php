<?php

declare(strict_types=1);

namespace OCA\Chronos\Tests\Unit\Planning;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Chronos\Core\Dav\ParsedTask;
use OCA\Chronos\Planning\PlanDates;
use PHPUnit\Framework\TestCase;

/**
 * The arithmetic a timeline stands on (§8.4, §15.4).
 *
 * Two of these are M4's acceptance criteria word for word: a task due Friday
 * renders through Friday, and dragging an all-day task across a daylight-saving
 * change keeps its dates.
 */
class PlanDatesTest extends TestCase {

	private PlanDates $dates;

	protected function setUp(): void {
		parent::setUp();
		// Madrid, because it changes its clocks and is an hour off UTC, so a
		// wrong frame shows up as a visible hour rather than as nothing.
		$this->dates = new PlanDates(new DateTimeZone('Europe/Madrid'));
	}

	public function testATaskDueFridayIsCoveredThroughFriday(): void {
		$friday = $this->day('2026-10-30');

		$bar = $this->dates->bar('a', $this->day('2026-10-28'), true, $friday, true);

		self::assertNotNull($bar);
		// The bar stops at the start of Saturday, which is what makes Friday
		// part of it.
		self::assertSame($this->day('2026-10-31'), $bar->endAt);
		self::assertSame($friday, $this->dates->inclusiveDue($bar->endAt, true));
	}

	public function testATimedBarEndsExactlyWhenItIsDue(): void {
		$due = $this->instant('2026-10-30 18:00');

		$bar = $this->dates->bar('a', $this->instant('2026-10-30 09:00'), false, $due, false);

		self::assertNotNull($bar);
		self::assertSame($due, $bar->endAt);
		self::assertSame($due, $this->dates->inclusiveDue($bar->endAt, false));
		self::assertFalse($bar->isAllDay());
	}

	/**
	 * Almost nothing in a real task list has both dates, so a task with one
	 * gets a bar anyway, marked as the plan's guess rather than the user's
	 * date. Nothing is written until they move it.
	 */
	public function testATaskWithOnlyADueDateGetsAOneDayBarEndingThere(): void {
		$bar = $this->dates->bar('a', null, true, $this->day('2026-10-30'), true);

		self::assertNotNull($bar);
		self::assertSame($this->day('2026-10-30'), $bar->startAt);
		self::assertSame($this->day('2026-10-31'), $bar->endAt);
		self::assertTrue($bar->inferred);
	}

	public function testATaskWithOnlyAStartGetsAOneDayBarBeginningThere(): void {
		$bar = $this->dates->bar('a', $this->instant('2026-10-30 09:00'), false, null, false);

		self::assertNotNull($bar);
		self::assertSame($this->instant('2026-10-30 09:00'), $bar->startAt);
		self::assertSame($this->instant('2026-10-31 09:00'), $bar->endAt);
		self::assertTrue($bar->inferred);
	}

	public function testATaskWithNoDatesHasNoBar(): void {
		self::assertNull($this->dates->bar('a', null, true, null, true));
	}

	/** A milestone is a moment, not a span (§6). */
	public function testAMilestoneIsItsDueDateAndNothingElse(): void {
		$bar = $this->dates->bar(
			'a',
			null,
			false,
			$this->instant('2026-10-30 12:00'),
			false,
			ParsedTask::TYPE_MILESTONE,
		);

		self::assertNotNull($bar);
		self::assertTrue($bar->isMilestone);
		self::assertSame(0, $bar->duration());
	}

	/** Somebody else's data, not a bar: the due date is what is drawn. */
	public function testADueDateBeforeTheStartFallsBackToTheDueDate(): void {
		$bar = $this->dates->bar('a', $this->day('2026-10-30'), true, $this->day('2026-10-20'), true);

		self::assertNotNull($bar);
		self::assertSame($this->day('2026-10-20'), $bar->startAt);
		self::assertSame($this->day('2026-10-21'), $bar->endAt);
		self::assertTrue($bar->inferred);
	}

	/**
	 * M4's acceptance criterion: an all-day task dragged across the spring
	 * clock change keeps the dates it is dropped on. Adding 86,400 seconds
	 * seven times would land it an hour before midnight, which reads as the
	 * day before.
	 */
	public function testDraggingAnAllDayBarAcrossADaylightSavingChangeKeepsItsDates(): void {
		// Spain moves its clocks on 29 March 2026.
		$bar = $this->dates->bar('a', $this->day('2026-03-26'), true, $this->day('2026-03-27'), true);
		self::assertNotNull($bar);

		$moved = $this->dates->shift($bar, 7 * 86400);

		self::assertSame($this->day('2026-04-02'), $moved->startAt);
		self::assertSame($this->day('2026-04-03'), $this->dates->inclusiveDue($moved->endAt, true));
		self::assertSame('00:00:00', $this->utc($moved->startAt));
	}

	/**
	 * The same drag on a timed task keeps the time of day the user set, which
	 * means the instant moves by 7 days *minus* the hour the clocks took.
	 */
	public function testDraggingATimedBarAcrossADaylightSavingChangeKeepsTheTimeOfDay(): void {
		$bar = $this->dates->bar('a', $this->instant('2026-03-26 09:00'), false, $this->instant('2026-03-26 17:00'), false);
		self::assertNotNull($bar);

		$moved = $this->dates->shift($bar, 7 * 86400);

		self::assertSame('09:00', $this->local($moved->startAt));
		self::assertSame('17:00', $this->local($moved->endAt));
		self::assertSame(7 * 86400 - 3600, $moved->startAt - $bar->startAt);
	}

	public function testShiftingKeepsTheLength(): void {
		$bar = $this->dates->bar('a', $this->day('2026-10-01'), true, $this->day('2026-10-10'), true);
		self::assertNotNull($bar);

		$moved = $this->dates->shift($bar, 3 * 86400);

		self::assertSame($bar->duration(), $moved->duration());
	}

	public function testStartingAtAndEndingAtKeepTheLength(): void {
		$bar = $this->dates->bar('a', $this->instant('2026-10-01 09:00'), false, $this->instant('2026-10-01 17:00'), false);
		self::assertNotNull($bar);

		$started = $this->dates->startingAt($bar, $this->instant('2026-10-05 09:00'));
		$ended = $this->dates->endingAt($bar, $this->instant('2026-10-05 17:00'));

		self::assertSame($this->instant('2026-10-05 09:00'), $started->startAt);
		self::assertSame($bar->duration(), $started->duration());
		self::assertSame($this->instant('2026-10-05 17:00'), $ended->endAt);
		self::assertSame($bar->duration(), $ended->duration());
	}

	/** A summary row spans everything under it (§12.5). */
	public function testASummarySpansFromTheEarliestStartToTheLatestEnd(): void {
		$one = $this->dates->bar('one', $this->day('2026-10-05'), true, $this->day('2026-10-06'), true);
		$two = $this->dates->bar('two', $this->day('2026-10-01'), true, $this->day('2026-10-03'), true);
		self::assertNotNull($one);
		self::assertNotNull($two);

		$summary = $this->dates->summary('parent', [$one, $two]);

		self::assertNotNull($summary);
		self::assertSame($this->day('2026-10-01'), $summary->startAt);
		self::assertSame($this->day('2026-10-07'), $summary->endAt);
		self::assertTrue($summary->inferred);
	}

	public function testASummaryOfNothingIsNoBar(): void {
		self::assertNull($this->dates->summary('parent', []));
	}

	/** An all-day date is midnight UTC, whatever the viewer's zone (§8.4). */
	private function day(string $date): int {
		return (new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC')))->getTimestamp();
	}

	/** A timed date, written as the user's local wall time. */
	private function instant(string $local): int {
		return (new DateTimeImmutable($local, new DateTimeZone('Europe/Madrid')))->getTimestamp();
	}

	private function local(int $at): string {
		return (new DateTimeImmutable('@' . $at))->setTimezone(new DateTimeZone('Europe/Madrid'))->format('H:i');
	}

	private function utc(int $at): string {
		return (new DateTimeImmutable('@' . $at))->setTimezone(new DateTimeZone('UTC'))->format('H:i:s');
	}
}
