<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Support;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Sabre\VObject\Parameter;
use Sabre\VObject\Property;

/**
 * The one place that converts between iCalendar's three kinds of date and the
 * index's integer seconds (§8.4).
 *
 * A task can carry an all-day `DATE`, an exact `DATE-TIME` with a zone, or a
 * floating `DATE-TIME` with none. Chronos keeps which kind each task uses, and
 * never silently turns one into another.
 */
final class TaskDates {

	public const VALUE_DATE = 'DATE';

	/**
	 * @param string $rawValue the property's value, e.g. `20261030` or `20261030T180000`
	 * @param string $valueType `DATE` or `DATE-TIME`
	 * @param string|null $tzid the property's TZID, if it has one
	 * @param DateTimeZone $userTimezone used only for floating date-times
	 * @return array{at: int, isDate: bool}|null null when the value cannot be read
	 */
	public static function parse(
		string $rawValue,
		string $valueType,
		?string $tzid,
		DateTimeZone $userTimezone,
	): ?array {
		$value = trim($rawValue);
		if ($value === '') {
			return null;
		}

		if (strtoupper($valueType) === self::VALUE_DATE) {
			// An all-day date is encoded as midnight UTC purely so it sorts and
			// filters; it is never a moment in time and never shifted by a zone.
			$date = self::build('Ymd', substr($value, 0, 8), new DateTimeZone('UTC'));
			return $date === null ? null : ['at' => $date->getTimestamp(), 'isDate' => true];
		}

		// A trailing Z means UTC, whatever the TZID says.
		if (str_ends_with($value, 'Z')) {
			$date = self::build('Ymd\THis', substr($value, 0, 15), new DateTimeZone('UTC'));
			return $date === null ? null : ['at' => $date->getTimestamp(), 'isDate' => false];
		}

		$zone = $userTimezone;
		if ($tzid !== null && $tzid !== '') {
			try {
				$zone = new DateTimeZone($tzid);
			} catch (Exception) {
				// An unknown zone falls back to the user's, rather than failing.
				$zone = $userTimezone;
			}
		}

		$date = self::build('Ymd\THis', $value, $zone);
		return $date === null ? null : ['at' => $date->getTimestamp(), 'isDate' => false];
	}

	/**
	 * @return array{at: int, isDate: bool}|null
	 */
	public static function fromProperty(?Property $property, DateTimeZone $userTimezone): ?array {
		if ($property === null) {
			return null;
		}

		/** @var mixed $value */
		$value = $property->getValue();
		if (!is_scalar($value)) {
			return null;
		}

		$tzid = null;
		if ($property->offsetExists('TZID')) {
			/** @var mixed $parameter */
			$parameter = $property['TZID'];
			/** @var mixed $raw */
			$raw = $parameter instanceof Parameter ? $parameter->getValue() : $parameter;
			$tzid = is_scalar($raw) ? (string)$raw : null;
		}

		return self::parse(
			(string)$value,
			$property->getValueType(),
			$tzid,
			$userTimezone,
		);
	}

	/**
	 * The value to write back for an all-day date.
	 */
	public static function toDateValue(int $at): string {
		return (new DateTimeImmutable('@' . $at))
			->setTimezone(new DateTimeZone('UTC'))
			->format('Ymd');
	}

	/**
	 * The value to write back for an exact instant, in UTC.
	 */
	public static function toDateTimeValue(int $at): string {
		return (new DateTimeImmutable('@' . $at))
			->setTimezone(new DateTimeZone('UTC'))
			->format('Ymd\THis\Z');
	}

	/**
	 * Calendar-day arithmetic for all-day dates: adding a day adds a day, even
	 * across a daylight-saving change, where adding 86,400 seconds would not
	 * (§8.4).
	 */
	public static function addDays(int $at, int $days): int {
		$sign = $days < 0 ? '-' : '+';
		$moved = (new DateTimeImmutable('@' . $at))
			->setTimezone(new DateTimeZone('UTC'))
			->modify($sign . abs($days) . ' day');

		// modify() only fails on a relative string it cannot read, which this
		// one never is; the check keeps the type honest.
		return $moved === false ? $at : $moved->getTimestamp();
	}

	/**
	 * The same, in a given time zone: adding a day keeps the wall-clock time,
	 * so a task at 9 am is still at 9 am on the other side of a daylight-saving
	 * change (§8.4). Used for timed dates, where a day is a local day.
	 */
	public static function addDaysIn(int $at, int $days, DateTimeZone $zone): int {
		if ($days === 0) {
			return $at;
		}

		$sign = $days < 0 ? '-' : '+';
		$moved = (new DateTimeImmutable('@' . $at))
			->setTimezone($zone)
			->modify($sign . abs($days) . ' day');

		return $moved === false ? $at : $moved->getTimestamp();
	}

	/**
	 * The value to write back for an instant in a given zone, without the
	 * trailing `Z`: what a `DATE-TIME` with a `TZID` looks like, and what a
	 * floating one looks like in the zone it is floating in (§8.4).
	 */
	public static function toDateTimeValueIn(int $at, DateTimeZone $zone): string {
		return (new DateTimeImmutable('@' . $at))
			->setTimezone($zone)
			->format('Ymd\THis');
	}

	/**
	 * Builds a date, returning null instead of throwing on a malformed value.
	 */
	private static function build(string $format, string $value, DateTimeZone $zone): ?DateTimeImmutable {
		$date = DateTimeImmutable::createFromFormat('!' . $format, $value, $zone);
		return $date === false ? null : $date;
	}
}
