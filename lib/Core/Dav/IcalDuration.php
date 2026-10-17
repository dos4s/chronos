<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Dav;

use DateInterval;
use Sabre\VObject\DateTimeParser;
use Throwable;

/**
 * iCalendar durations, both ways (RFC 5545 §3.3.6).
 *
 * Only one property needs them today — RFC 9253's `GAP`, the lag or lead on a
 * dependency — and it needs them in both directions: read from a task another
 * client wrote, and written back when a link is made in Chronos. Keeping the
 * pair together is what stops a value surviving a round trip by accident
 * rather than by design.
 *
 * A negative duration is a lead: `-PT4H` on a `FINISHTOSTART` link means the
 * successor may start four hours before the predecessor finishes.
 */
final class IcalDuration {

	/**
	 * Seconds from a duration, or 0 when it cannot be read — an unreadable gap
	 * is no gap, which leaves the link itself intact.
	 */
	public static function parse(?string $duration): int {
		if ($duration === null || trim($duration) === '') {
			return 0;
		}

		try {
			$interval = DateTimeParser::parseDuration($duration);
		} catch (Throwable) {
			return 0;
		}

		if (!$interval instanceof DateInterval) {
			return 0;
		}

		$seconds = ((($interval->d * 24) + $interval->h) * 60 + $interval->i) * 60 + $interval->s;

		return $interval->invert === 1 ? -$seconds : $seconds;
	}

	/**
	 * The shortest form that says this many seconds: whole days as `P2D`, a
	 * time of day as `PT4H30M`, and the two together as `P1DT6H`.
	 */
	public static function format(int $seconds): string {
		if ($seconds === 0) {
			return 'PT0S';
		}

		$sign = $seconds < 0 ? '-' : '';
		$left = abs($seconds);

		$days = intdiv($left, 86400);
		$left -= $days * 86400;
		$hours = intdiv($left, 3600);
		$left -= $hours * 3600;
		$minutes = intdiv($left, 60);
		$left -= $minutes * 60;

		$date = $days > 0 ? $days . 'D' : '';
		$time = ($hours > 0 ? $hours . 'H' : '')
			. ($minutes > 0 ? $minutes . 'M' : '')
			. ($left > 0 ? $left . 'S' : '');

		return $sign . 'P' . $date . ($time === '' ? '' : 'T' . $time);
	}
}
