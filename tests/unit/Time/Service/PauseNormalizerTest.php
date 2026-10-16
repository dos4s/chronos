<?php

declare(strict_types=1);

namespace OCA\Chronos\Tests\Unit\Time\Service;

use OCA\Chronos\Time\Service\PauseNormalizer;
use PHPUnit\Framework\TestCase;

class PauseNormalizerTest extends TestCase {

	private PauseNormalizer $normalizer;

	protected function setUp(): void {
		parent::setUp();
		$this->normalizer = new PauseNormalizer();
	}

	public function testKeepsPausesInsideTheSession(): void {
		$pauses = [
			['start' => 1000, 'end' => 1100],
			['start' => 1200, 'end' => 1300],
		];

		self::assertSame($pauses, $this->normalizer->normalize($pauses, 900, 2000));
	}

	public function testSortsPauses(): void {
		$result = $this->normalizer->normalize([
			['start' => 1200, 'end' => 1300],
			['start' => 1000, 'end' => 1100],
		], 900, 2000);

		self::assertSame([
			['start' => 1000, 'end' => 1100],
			['start' => 1200, 'end' => 1300],
		], $result);
	}

	public function testMergesOverlappingPausesSoTimeIsNotCountedTwice(): void {
		$result = $this->normalizer->normalize([
			['start' => 1000, 'end' => 1200],
			['start' => 1100, 'end' => 1300],
		], 900, 2000);

		self::assertSame([['start' => 1000, 'end' => 1300]], $result);
		self::assertSame(300, $this->normalizer->closedSeconds($result));
	}

	public function testMergesTouchingPauses(): void {
		$result = $this->normalizer->normalize([
			['start' => 1000, 'end' => 1100],
			['start' => 1100, 'end' => 1200],
		], 900, 2000);

		self::assertSame([['start' => 1000, 'end' => 1200]], $result);
	}

	public function testMergesAPauseFullyInsideAnother(): void {
		$result = $this->normalizer->normalize([
			['start' => 1000, 'end' => 1500],
			['start' => 1100, 'end' => 1200],
		], 900, 2000);

		self::assertSame([['start' => 1000, 'end' => 1500]], $result);
	}

	public function testClipsPausesToTheSessionBounds(): void {
		$result = $this->normalizer->normalize([
			['start' => 500, 'end' => 1000],
			['start' => 1800, 'end' => 2500],
		], 900, 2000);

		self::assertSame([
			['start' => 900, 'end' => 1000],
			['start' => 1800, 'end' => 2000],
		], $result);
	}

	public function testDropsPausesOutsideTheSession(): void {
		$result = $this->normalizer->normalize([
			['start' => 100, 'end' => 200],
			['start' => 2100, 'end' => 2200],
		], 900, 2000);

		self::assertSame([], $result);
	}

	public function testDropsEmptyPauses(): void {
		$result = $this->normalizer->normalize([
			['start' => 1000, 'end' => 1000],
			['start' => 1200, 'end' => 1100],
		], 900, 2000);

		self::assertSame([], $result);
	}

	public function testKeepsAnOpenPauseInARunningSession(): void {
		$result = $this->normalizer->normalize([
			['start' => 1000, 'end' => 1100],
			['start' => 1500, 'end' => null],
		], 900, null);

		self::assertSame([
			['start' => 1000, 'end' => 1100],
			['start' => 1500, 'end' => null],
		], $result);
		self::assertSame(100, $this->normalizer->closedSeconds($result));
		self::assertSame(1500, $this->normalizer->openStart($result));
	}

	public function testClosesAnOpenPauseWhenTheSessionEnds(): void {
		$result = $this->normalizer->normalize([
			['start' => 1500, 'end' => null],
		], 900, 2000);

		self::assertSame([['start' => 1500, 'end' => 2000]], $result);
		self::assertNull($this->normalizer->openStart($result));
		self::assertSame(500, $this->normalizer->closedSeconds($result));
	}

	public function testKeepsOnlyOneOpenPause(): void {
		$result = $this->normalizer->normalize([
			['start' => 1700, 'end' => null],
			['start' => 1500, 'end' => null],
		], 900, null);

		self::assertSame([['start' => 1500, 'end' => null]], $result);
	}

	public function testAClosedPauseReachingIntoTheOpenOneBecomesPartOfIt(): void {
		$result = $this->normalizer->normalize([
			['start' => 1400, 'end' => 1600],
			['start' => 1500, 'end' => null],
		], 900, null);

		self::assertSame([['start' => 1400, 'end' => null]], $result);
	}

	public function testDropsClosedPausesAfterTheOpenOne(): void {
		$result = $this->normalizer->normalize([
			['start' => 1800, 'end' => 1900],
			['start' => 1500, 'end' => null],
		], 900, null);

		self::assertSame([['start' => 1500, 'end' => null]], $result);
	}

	public function testOpenPauseStartingBeforeTheSessionIsClippedToIt(): void {
		$result = $this->normalizer->normalize([
			['start' => 500, 'end' => null],
		], 900, null);

		self::assertSame([['start' => 900, 'end' => null]], $result);
	}

	public function testHandlesNoPauses(): void {
		self::assertSame([], $this->normalizer->normalize([], 900, 2000));
		self::assertSame(0, $this->normalizer->closedSeconds([]));
		self::assertNull($this->normalizer->openStart([]));
	}

	/**
	 * The case a live upgrade turned up: two sessions from before the
	 * `pauses_blob` column had a `paused_duration` and no pauses at all, so
	 * recomputing from the pauses alone moved two and a half hours of pause
	 * time into worked time.
	 */
	public function testRestoresPauseTimeNoPauseDescribed(): void {
		$result = $this->normalizer->withLegacyTotal([], 7911, 1_776_668_795, 1_776_712_023);

		self::assertSame(7911, $this->normalizer->closedSeconds($result));
		self::assertCount(1, $result);
		// Placed as late as the session allows, because only the duration was
		// ever recorded.
		self::assertSame(1_776_712_023, $result[0]['end']);
	}

	public function testRestoresOnlyTheDifference(): void {
		$existing = [['start' => 1000, 'end' => 1300]];

		$result = $this->normalizer->withLegacyTotal($existing, 600, 900, 5000);

		self::assertSame(600, $this->normalizer->closedSeconds($result));
	}

	public function testAddsNothingWhenThePausesAlreadyAccountForTheTotal(): void {
		$existing = [['start' => 1000, 'end' => 1600]];

		self::assertSame($existing, $this->normalizer->withLegacyTotal($existing, 600, 900, 5000));
		self::assertSame($existing, $this->normalizer->withLegacyTotal($existing, 300, 900, 5000));
		self::assertSame($existing, $this->normalizer->withLegacyTotal($existing, 0, 900, 5000));
	}

	public function testRestoredTimeNeverOverlapsAnExistingPause(): void {
		$existing = [['start' => 2000, 'end' => 2500], ['start' => 4000, 'end' => 4200]];

		$result = $this->normalizer->withLegacyTotal($existing, 1500, 1000, 5000);

		self::assertSame(1500, $this->normalizer->closedSeconds($result));
		// Still sorted, still not overlapping: the invariant the whole class is for.
		self::assertSame($result, $this->normalizer->normalize($result, 1000, 5000));
	}

	public function testRestoredTimeCannotExceedTheSession(): void {
		// The old total claims more pause than the session was long.
		$result = $this->normalizer->withLegacyTotal([], 99_999, 1000, 2000);

		self::assertSame(1000, $this->normalizer->closedSeconds($result));
		self::assertSame([['start' => 1000, 'end' => 2000]], $result);
	}

	/**
	 * A session that is paused right now has nowhere to put the restored time
	 * except up against the pause it is already in, so the two become one
	 * pause that started earlier. The time is still accounted for — it is in
	 * the open pause, and lands in the total when the session resumes.
	 */
	public function testRestoringIntoAPausedSessionExtendsThatPauseBackwards(): void {
		$existing = [['start' => 4000, 'end' => null]];

		$result = $this->normalizer->withLegacyTotal($existing, 600, 1000, null);

		self::assertSame([['start' => 3400, 'end' => null]], $result);
		self::assertSame(3400, $this->normalizer->openStart($result));

		// Resuming at 4600 closes it, and the restored 600 seconds are there.
		$resumed = $this->normalizer->normalize([['start' => 3400, 'end' => 4600]], 1000, 5000);
		self::assertSame(1200, $this->normalizer->closedSeconds($resumed));
	}

	public function testRestoresIntoARunningSessionWithNoOpenPause(): void {
		$result = $this->normalizer->withLegacyTotal([], 600, 1000, null);

		self::assertSame(600, $this->normalizer->closedSeconds($result));
		self::assertSame([['start' => 1000, 'end' => 1600]], $result);
	}

	public function testIsIdempotent(): void {
		$once = $this->normalizer->normalize([
			['start' => 500, 'end' => 1000],
			['start' => 950, 'end' => 1200],
			['start' => 1800, 'end' => 2500],
		], 900, 2000);

		self::assertSame($once, $this->normalizer->normalize($once, 900, 2000));
	}
}
