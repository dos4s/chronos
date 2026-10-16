<?php

declare(strict_types=1);

namespace OCA\Chronos\Tests\Unit\Core\Support;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Chronos\Core\Support\TaskDates;
use PHPUnit\Framework\TestCase;

class TaskDatesTest extends TestCase {

	private DateTimeZone $madrid;

	protected function setUp(): void {
		parent::setUp();
		$this->madrid = new DateTimeZone('Europe/Madrid');
	}

	public function testAnAllDayDateBecomesMidnightUtc(): void {
		$parsed = TaskDates::parse('20261030', 'DATE', null, $this->madrid);

		self::assertNotNull($parsed);
		self::assertTrue($parsed['isDate']);
		self::assertSame('2026-10-30T00:00:00+00:00', $this->iso($parsed['at']));
	}

	/**
	 * The zone must not move an all-day date: a task due on the 30th is due on
	 * the 30th in Madrid, in Tokyo and in the index (§8.4).
	 */
	public function testAnAllDayDateIgnoresTheTimeZone(): void {
		$madrid = TaskDates::parse('20261030', 'DATE', null, $this->madrid);
		$tokyo = TaskDates::parse('20261030', 'DATE', null, new DateTimeZone('Asia/Tokyo'));
		$withTzid = TaskDates::parse('20261030', 'DATE', 'America/New_York', $this->madrid);

		self::assertSame($madrid, $tokyo);
		self::assertSame($madrid, $withTzid);
	}

	public function testADateTimeWithATimeZoneBecomesThatInstant(): void {
		// Madrid is UTC+1 on 30 October 2026, after summer time ends.
		$parsed = TaskDates::parse('20261030T180000', 'DATE-TIME', 'Europe/Madrid', $this->madrid);

		self::assertNotNull($parsed);
		self::assertFalse($parsed['isDate']);
		self::assertSame('2026-10-30T17:00:00+00:00', $this->iso($parsed['at']));
	}

	public function testASummerDateTimeUsesTheSummerOffset(): void {
		// Two hours ahead in July, one in October: the same wall time is a
		// different instant.
		$july = TaskDates::parse('20260715T180000', 'DATE-TIME', 'Europe/Madrid', $this->madrid);
		$october = TaskDates::parse('20261030T180000', 'DATE-TIME', 'Europe/Madrid', $this->madrid);

		self::assertSame('2026-07-15T16:00:00+00:00', $this->iso($july['at']));
		self::assertSame('2026-10-30T17:00:00+00:00', $this->iso($october['at']));
	}

	public function testATrailingZMeansUtcWhateverTheTzidSays(): void {
		$parsed = TaskDates::parse('20261030T180000Z', 'DATE-TIME', 'Asia/Tokyo', $this->madrid);

		self::assertSame('2026-10-30T18:00:00+00:00', $this->iso($parsed['at']));
		self::assertFalse($parsed['isDate']);
	}

	public function testAFloatingDateTimeIsReadInTheUsersZone(): void {
		$parsed = TaskDates::parse('20261030T180000', 'DATE-TIME', null, $this->madrid);

		self::assertSame('2026-10-30T17:00:00+00:00', $this->iso($parsed['at']));
		self::assertFalse($parsed['isDate']);
	}

	public function testAnUnknownZoneFallsBackToTheUsersRatherThanFailing(): void {
		$parsed = TaskDates::parse('20261030T180000', 'DATE-TIME', 'Mars/Olympus_Mons', $this->madrid);

		self::assertNotNull($parsed);
		self::assertSame('2026-10-30T17:00:00+00:00', $this->iso($parsed['at']));
	}

	public function testUnreadableValuesAreReportedAsSuch(): void {
		self::assertNull(TaskDates::parse('', 'DATE', null, $this->madrid));
		self::assertNull(TaskDates::parse('   ', 'DATE-TIME', null, $this->madrid));
		self::assertNull(TaskDates::parse('not-a-date', 'DATE', null, $this->madrid));
	}

	public function testValuesRoundTrip(): void {
		$allDay = TaskDates::parse('20261030', 'DATE', null, $this->madrid);
		$timed = TaskDates::parse('20261030T180000Z', 'DATE-TIME', null, $this->madrid);

		self::assertSame('20261030', TaskDates::toDateValue($allDay['at']));
		self::assertSame('20261030T180000Z', TaskDates::toDateTimeValue($timed['at']));
	}

	/**
	 * The reason calendar-day arithmetic exists: 25 October 2026 is when
	 * European summer time ends, so that day is 25 hours long.
	 */
	public function testAddingADayAcrossADaylightSavingChangeKeepsTheDate(): void {
		$before = TaskDates::parse('20261024', 'DATE', null, $this->madrid);
		$after = TaskDates::addDays($before['at'], 2);

		self::assertSame('20261026', TaskDates::toDateValue($after));
		self::assertSame('2026-10-26T00:00:00+00:00', $this->iso($after));
	}

	public function testAddingDaysWalksBackwardsToo(): void {
		$start = TaskDates::parse('20261001', 'DATE', null, $this->madrid);

		self::assertSame('20260930', TaskDates::toDateValue(TaskDates::addDays($start['at'], -1)));
	}

	private function iso(int $at): string {
		return (new DateTimeImmutable('@' . $at))->format('c');
	}
}
