<?php

declare(strict_types=1);

namespace OCA\Chronos\Tests\Unit\Planning;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Chronos\Core\Dav\ParsedLink;
use OCA\Chronos\Planning\Bar;
use OCA\Chronos\Planning\Conflict;
use OCA\Chronos\Planning\Dependency;
use OCA\Chronos\Planning\PlanDates;
use OCA\Chronos\Planning\Schedule;
use OCA\Chronos\Planning\WorkingDays;
use PHPUnit\Framework\TestCase;

/**
 * What the dependencies say, and what a move does to them (§12.5, §15.4).
 *
 * The same cases are in `src/utils/schedule.spec.ts`, because the ghost bars a
 * drag shows are worked out in the browser and the write is worked out here:
 * if the two disagree, the preview is a lie.
 */
class ScheduleTest extends TestCase {

	private PlanDates $dates;

	protected function setUp(): void {
		parent::setUp();
		$this->dates = new PlanDates(new DateTimeZone('UTC'));
	}

	public function testASuccessorStartingBeforeItsPredecessorEndsIsAConflict(): void {
		$schedule = $this->schedule(
			[
				$this->bar('a', '2026-10-05', '2026-10-09'),
				$this->bar('b', '2026-10-07', '2026-10-09'),
			],
			[$this->link('a', 'b', 'FINISHTOSTART')],
		);

		$conflicts = $schedule->conflicts();

		self::assertCount(1, $conflicts);
		self::assertSame(Conflict::TOO_EARLY, $conflicts[0]->kind);
		self::assertSame('a', $conflicts[0]->predecessorUid);
		self::assertSame($this->day('2026-10-10'), $conflicts[0]->requiredAt);
		self::assertSame($this->day('2026-10-07'), $conflicts[0]->actualAt);
	}

	/** Slack is not an error: a task may always sit later than it has to. */
	public function testASuccessorThatSitsLaterThanItHasToIsNotAConflict(): void {
		$schedule = $this->schedule(
			[
				$this->bar('a', '2026-10-05', '2026-10-09'),
				$this->bar('b', '2026-10-20', '2026-10-21'),
			],
			[$this->link('a', 'b', 'FINISHTOSTART')],
		);

		self::assertSame([], $schedule->conflicts());
	}

	public function testACascadePushesTheDependentExactlyFarEnough(): void {
		$schedule = $this->schedule(
			[
				$this->bar('a', '2026-10-05', '2026-10-09'),
				$this->bar('b', '2026-10-12', '2026-10-14'),
			],
			[$this->link('a', 'b', 'FINISHTOSTART')],
		);

		// A moves two days later, so B must follow by two days.
		$result = $schedule->reschedule($this->bar('a', '2026-10-07', '2026-10-13'), true, 50);

		self::assertSame(['a', 'b'], $result->movedUids());
		self::assertSame($this->day('2026-10-14'), $result->bars['b']->startAt);
		// Its length is its own business and does not change.
		self::assertSame(3 * 86400, $result->bars['b']->duration());
		self::assertSame([], $result->conflicts);
	}

	/**
	 * A cascade never pulls work forward into the space a predecessor left
	 * behind: dates somebody set are not the scheduler's to tighten.
	 */
	public function testACascadeNeverPullsADependentEarlier(): void {
		$schedule = $this->schedule(
			[
				$this->bar('a', '2026-10-05', '2026-10-09'),
				$this->bar('b', '2026-10-20', '2026-10-21'),
			],
			[$this->link('a', 'b', 'FINISHTOSTART')],
		);

		$result = $schedule->reschedule($this->bar('a', '2026-10-01', '2026-10-02'), true, 50);

		self::assertSame(['a'], $result->movedUids());
	}

	public function testACascadeFollowsTheWholeChain(): void {
		$schedule = $this->schedule(
			[
				$this->bar('a', '2026-10-05', '2026-10-06'),
				$this->bar('b', '2026-10-07', '2026-10-08'),
				$this->bar('c', '2026-10-09', '2026-10-12'),
			],
			[$this->link('a', 'b', 'FINISHTOSTART'), $this->link('b', 'c', 'FINISHTOSTART')],
		);

		$result = $schedule->reschedule($this->bar('a', '2026-10-12', '2026-10-13'), true, 50);

		self::assertSame(['a', 'b', 'c'], $result->movedUids());
		self::assertSame($this->day('2026-10-14'), $result->bars['b']->startAt);
		self::assertSame($this->day('2026-10-16'), $result->bars['c']->startAt);
	}

	/** In warn mode nothing follows, and the broken link is what is reported. */
	public function testWarnModeMovesOnlyTheTaskAndReportsWhatItBroke(): void {
		$schedule = $this->schedule(
			[
				$this->bar('a', '2026-10-05', '2026-10-06'),
				$this->bar('b', '2026-10-07', '2026-10-08'),
			],
			[$this->link('a', 'b', 'FINISHTOSTART')],
		);

		$result = $schedule->reschedule($this->bar('a', '2026-10-12', '2026-10-13'), false, 50);

		self::assertSame(['a'], $result->movedUids());
		self::assertCount(1, $result->conflicts);
		self::assertSame('b', $result->conflicts[0]->successorUid);
	}

	public function testALagWaitsAndALeadOverlaps(): void {
		$lag = $this->schedule(
			[$this->bar('a', '2026-10-05', '2026-10-06'), $this->bar('b', '2026-10-07', '2026-10-08')],
			[$this->link('a', 'b', 'FINISHTOSTART', 2 * 86400)],
		);
		$lead = $this->schedule(
			[$this->bar('a', '2026-10-05', '2026-10-06'), $this->bar('b', '2026-10-07', '2026-10-08')],
			[$this->link('a', 'b', 'FINISHTOSTART', -86400)],
		);

		// Two days of lag: B may not start before the 9th.
		self::assertCount(1, $lag->conflicts());
		self::assertSame($this->day('2026-10-09'), $lag->conflicts()[0]->requiredAt);
		// A day of lead: B may start on the 6th, so the 7th is fine.
		self::assertSame([], $lead->conflicts());
	}

	public function testStartToStartConstrainsTheStartAgainstTheStart(): void {
		$schedule = $this->schedule(
			[$this->bar('a', '2026-10-05', '2026-10-09'), $this->bar('b', '2026-10-05', '2026-10-06')],
			[$this->link('a', 'b', 'STARTTOSTART', 86400)],
		);

		$result = $schedule->reschedule($this->bar('a', '2026-10-05', '2026-10-09'), true, 50);

		self::assertSame($this->day('2026-10-06'), $result->bars['b']->startAt);
	}

	public function testFinishToFinishConstrainsTheEnd(): void {
		$schedule = $this->schedule(
			[$this->bar('a', '2026-10-05', '2026-10-09'), $this->bar('b', '2026-10-05', '2026-10-07')],
			[$this->link('a', 'b', 'FINISHTOFINISH')],
		);

		$result = $schedule->reschedule($this->bar('a', '2026-10-05', '2026-10-09'), true, 50);

		// B must not end before A does, so its end moves to the 10th
		// exclusive — two days later, taking its start with it.
		self::assertSame($this->day('2026-10-10'), $result->bars['b']->endAt);
		self::assertSame($this->day('2026-10-07'), $result->bars['b']->startAt);
	}

	public function testStartToFinishConstrainsTheEndAgainstTheStart(): void {
		$schedule = $this->schedule(
			[$this->bar('a', '2026-10-10', '2026-10-12'), $this->bar('b', '2026-10-05', '2026-10-06')],
			[$this->link('a', 'b', 'STARTTOFINISH')],
		);

		$result = $schedule->reschedule($this->bar('a', '2026-10-10', '2026-10-12'), true, 50);

		self::assertSame($this->day('2026-10-10'), $result->bars['b']->endAt);
	}

	/** `DEPENDS-ON` says what blocks what, not when (RFC 9253 §5). */
	public function testADependsOnLinkIsDrawnAndNeverScheduled(): void {
		$schedule = $this->schedule(
			[$this->bar('a', '2026-10-05', '2026-10-09'), $this->bar('b', '2026-10-01', '2026-10-02')],
			[$this->link('a', 'b', ParsedLink::RELTYPE_DEPENDS_ON)],
		);

		self::assertSame([], $schedule->conflicts());
		self::assertSame(['a'], $schedule->reschedule($this->bar('a', '2026-10-06', '2026-10-10'), true, 50)->movedUids());
	}

	/** A cascade lands a task on a day the project works (§15.4). */
	public function testACascadePushesAStartOffAWeekend(): void {
		$schedule = $this->schedule(
			// Friday 30 October 2026, ending Saturday.
			[$this->bar('a', '2026-10-29', '2026-10-30'), $this->bar('b', '2026-10-30', '2026-11-02')],
			[$this->link('a', 'b', 'FINISHTOSTART')],
			WorkingDays::DEFAULT_DAYS,
		);

		// A now ends on Friday, so B would start on Saturday the 31st.
		$result = $schedule->reschedule($this->bar('a', '2026-10-29', '2026-10-30'), true, 50);

		self::assertSame($this->day('2026-11-02'), $result->bars['b']->startAt);
	}

	/**
	 * A loop cannot be satisfied by any dates. Chronos refuses to create one,
	 * but another client can write one, and a cascade must not spin on it.
	 */
	public function testALoopIsReportedAndDoesNotHang(): void {
		$schedule = $this->schedule(
			[$this->bar('a', '2026-10-05', '2026-10-06'), $this->bar('b', '2026-10-07', '2026-10-08')],
			[$this->link('a', 'b', 'FINISHTOSTART'), $this->link('b', 'a', 'FINISHTOSTART')],
		);

		$result = $schedule->reschedule($this->bar('a', '2026-10-09', '2026-10-10'), true, 50);
		$kinds = array_map(static fn (Conflict $conflict): string => $conflict->kind, $result->conflicts);

		self::assertContains(Conflict::CYCLE, $kinds);
	}

	/**
	 * A chain longer than one request may carry is not half moved: the caller
	 * is told, and refuses the whole thing.
	 */
	public function testACascadeBiggerThanTheLimitSaysSoInsteadOfMovingPartOfIt(): void {
		$bars = [$this->bar('t0', '2026-10-01', '2026-10-02')];
		$links = [];
		for ($i = 1; $i < 10; $i++) {
			$bars[] = $this->bar('t' . $i, '2026-10-0' . min(9, $i + 1), '2026-10-0' . min(9, $i + 2));
			$links[] = $this->link('t' . ($i - 1), 't' . $i, 'FINISHTOSTART');
		}

		$result = $this->schedule($bars, $links)
			->reschedule($this->bar('t0', '2026-10-20', '2026-10-21'), true, 4);

		self::assertTrue($result->overflowed);
		self::assertLessThanOrEqual(4, count($result->bars));
	}

	public function testALinkToATaskThatIsNotOnThePlanIsIgnored(): void {
		$schedule = $this->schedule(
			[$this->bar('a', '2026-10-05', '2026-10-06')],
			[$this->link('a', 'somewhere-else', 'FINISHTOSTART')],
		);

		self::assertSame([], $schedule->conflicts());
		self::assertSame(['a'], $schedule->reschedule($this->bar('a', '2026-10-09', '2026-10-10'), true, 50)->movedUids());
	}

	/**
	 * @param list<Bar> $bars
	 * @param list<Dependency> $links
	 * @param list<int> $workingDays
	 */
	private function schedule(array $bars, array $links, array $workingDays = []): Schedule {
		$byUid = [];
		foreach ($bars as $bar) {
			$byUid[$bar->uid] = $bar;
		}

		return new Schedule(
			$byUid,
			$links,
			$this->dates,
			new WorkingDays($workingDays, new DateTimeZone('UTC')),
		);
	}

	/** An all-day bar from one date through another, inclusive. */
	private function bar(string $uid, string $from, string $through): Bar {
		$bar = $this->dates->bar($uid, $this->day($from), true, $this->day($through), true);
		self::assertNotNull($bar);

		return $bar;
	}

	private function link(string $from, string $to, string $reltype, int $gap = 0): Dependency {
		return new Dependency(0, $from, $to, $reltype, $gap);
	}

	private function day(string $date): int {
		return (new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC')))->getTimestamp();
	}
}
