<?php

declare(strict_types=1);

namespace OCA\Chronos\Tests\Unit\Core\Dav;

use OCA\Chronos\Core\Dav\IcalDuration;
use PHPUnit\Framework\TestCase;

/**
 * `GAP` on a dependency, both ways (RFC 5545 §3.3.6, RFC 9253).
 *
 * A gap that only survives in one direction is a lag that quietly disappears
 * the first time the link is edited, so the pair is tested together.
 */
class IcalDurationTest extends TestCase {

	/**
	 * @return list<array{0: int, 1: string}>
	 */
	public static function durations(): array {
		return [
			[0, 'PT0S'],
			[86400, 'P1D'],
			[2 * 86400, 'P2D'],
			[3600, 'PT1H'],
			[4 * 3600 + 30 * 60, 'PT4H30M'],
			[86400 + 6 * 3600, 'P1DT6H'],
			[90, 'PT1M30S'],
			[-86400, '-P1D'],
			[-4 * 3600, '-PT4H'],
		];
	}

	/**
	 * @dataProvider durations
	 */
	public function testSecondsAndDurationsAgreeInBothDirections(int $seconds, string $duration): void {
		self::assertSame($duration, IcalDuration::format($seconds));
		self::assertSame($seconds, IcalDuration::parse($duration));
	}

	public function testAWeekIsReadAsSevenDays(): void {
		self::assertSame(7 * 86400, IcalDuration::parse('P1W'));
	}

	/** An unreadable gap is no gap, which leaves the link itself intact. */
	public function testNonsenseIsNoGapRatherThanAnError(): void {
		self::assertSame(0, IcalDuration::parse('tomorrow'));
		self::assertSame(0, IcalDuration::parse(''));
		self::assertSame(0, IcalDuration::parse(null));
	}
}
