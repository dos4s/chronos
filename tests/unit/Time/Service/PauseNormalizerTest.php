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

	public function testIsIdempotent(): void {
		$once = $this->normalizer->normalize([
			['start' => 500, 'end' => 1000],
			['start' => 950, 'end' => 1200],
			['start' => 1800, 'end' => 2500],
		], 900, 2000);

		self::assertSame($once, $this->normalizer->normalize($once, 900, 2000));
	}
}
