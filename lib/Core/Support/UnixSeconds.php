<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Support;

use OCA\Chronos\Core\Error\ValidationFailedException;

/**
 * Guards the one unit rule (§8.1): everything crossing the API is UNIX seconds.
 */
final class UnixSeconds {

	/**
	 * Year 5138 in seconds. Any millisecond value since 1973 is larger, so a
	 * stale client still sending milliseconds is rejected rather than stored
	 * as a timestamp 50,000 years from now (§8.5).
	 */
	public const MAX = 100_000_000_000;

	/**
	 * Milliseconds below this are before 1973 — treated as seconds already.
	 * Used by the one-off migration, not by the API.
	 */
	public const MS_THRESHOLD = 100_000_000_000;

	/**
	 * @throws ValidationFailedException if the value looks like milliseconds
	 */
	public static function assert(?int $value): void {
		if ($value !== null && abs($value) > self::MAX) {
			throw new ValidationFailedException('timestamp_unit');
		}
	}

	/**
	 * Converts a legacy value to seconds, leaving values that are already in
	 * seconds untouched.
	 */
	public static function fromLegacy(int $value): int {
		if (abs($value) < self::MS_THRESHOLD) {
			return $value;
		}
		return intdiv($value, 1000);
	}
}
