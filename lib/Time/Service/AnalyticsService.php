<?php

declare(strict_types=1);

namespace OCA\Chronos\Time\Service;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use OCA\Chronos\Core\Error\ValidationFailedException;
use OCA\Chronos\Core\Support\UnixSeconds;
use OCA\Chronos\Time\Db\Entry;
use OCA\Chronos\Time\Db\EntryMapper;
use OCA\Chronos\Time\Db\Pause;
use OCA\Chronos\Time\Db\PauseMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;

/**
 * Aggregation over a date range, server-side (§8.6, §16).
 *
 * Replaces the frontend adding up whatever the last 100 sessions happened to
 * be, which quietly under-reported every total (D1).
 */
class AnalyticsService {

	/** Three years: enough for any report, small enough to stay fast. */
	private const MAX_RANGE_SECONDS = 3 * 366 * 86400;

	public function __construct(
		private EntryMapper $entryMapper,
		private PauseMapper $pauseMapper,
		private ITimeFactory $timeFactory,
		private IDateTimeZone $dateTimeZone,
	) {
	}

	/**
	 * Worked seconds in [$fromAt, $toAt), bucketed by day in the user's time
	 * zone and by project. Pauses are excluded; a session crossing local
	 * midnight is split at midnight.
	 *
	 * @throws ValidationFailedException
	 * @return array<string, mixed>
	 */
	public function summary(string $userId, int $fromAt, int $toAt, ?string $timezone): array {
		UnixSeconds::assert($fromAt);
		UnixSeconds::assert($toAt);
		if ($toAt <= $fromAt) {
			throw new ValidationFailedException('invalid_range');
		}
		if ($toAt - $fromAt > self::MAX_RANGE_SECONDS) {
			throw new ValidationFailedException('range_too_large');
		}

		$tz = $this->resolveTimezone($timezone);
		$now = $this->timeFactory->getTime();

		$entries = $this->entryMapper->findOverlapping($userId, $fromAt, $toAt);
		$pausesByEntry = $this->pauseMapper->findForEntries(
			array_map(static fn (Entry $entry): int => $entry->getId(), $entries),
		);

		/** @var array<string, array{seconds: int, projects: array<string, int>}> $days */
		$days = [];
		/** @var array<string, array{projectUri: string|null, projectName: string|null, seconds: int, sessionCount: int}> $projects */
		$projects = [];
		$totalSeconds = 0;
		$sessionCount = 0;

		foreach ($entries as $entry) {
			$intervals = $this->workedIntervals(
				$entry,
				$pausesByEntry[$entry->getId()] ?? [],
				$fromAt,
				$toAt,
				$now,
			);
			if ($intervals === []) {
				continue;
			}

			$projectKey = $entry->getProjectUri() ?? '';
			$projects[$projectKey] ??= [
				'projectUri' => $entry->getProjectUri(),
				'projectName' => $entry->getProjectName(),
				'seconds' => 0,
				'sessionCount' => 0,
			];
			$projects[$projectKey]['sessionCount']++;
			$sessionCount++;

			foreach ($intervals as [$start, $end]) {
				foreach ($this->splitByLocalDay($start, $end, $tz) as $date => $seconds) {
					$days[$date] ??= ['seconds' => 0, 'projects' => []];
					$days[$date]['seconds'] += $seconds;
					$days[$date]['projects'][$projectKey] = ($days[$date]['projects'][$projectKey] ?? 0) + $seconds;
					$projects[$projectKey]['seconds'] += $seconds;
					$totalSeconds += $seconds;
				}
			}
		}

		ksort($days);
		uasort(
			$projects,
			static fn (array $a, array $b): int => $b['seconds'] <=> $a['seconds'],
		);

		return [
			'fromAt' => $fromAt,
			'toAt' => $toAt,
			'timezone' => $tz->getName(),
			'totalSeconds' => $totalSeconds,
			'sessionCount' => $sessionCount,
			'days' => $this->shapeDays($days),
			'projects' => array_values($projects),
		];
	}

	/**
	 * The parts of a session that count as worked time inside the range:
	 * its span, clipped to the range, minus its pauses.
	 *
	 * @param list<Pause> $pauses
	 * @return list<array{0: int, 1: int}>
	 */
	private function workedIntervals(Entry $entry, array $pauses, int $fromAt, int $toAt, int $now): array {
		$spanStart = max($entry->getStartAt(), $fromAt);
		$spanEnd = min($entry->getEndAt() ?? $now, $toAt);
		if ($spanEnd <= $spanStart) {
			return [];
		}

		$blocks = [];
		foreach ($pauses as $pause) {
			$start = max($pause->getStartAt(), $spanStart);
			$end = min($pause->getEndAt() ?? $now, $spanEnd);
			if ($end > $start) {
				$blocks[] = [$start, $end];
			}
		}
		usort($blocks, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

		$intervals = [];
		$cursor = $spanStart;
		foreach ($blocks as [$start, $end]) {
			if ($start > $cursor) {
				$intervals[] = [$cursor, $start];
			}
			$cursor = max($cursor, $end);
		}
		if ($cursor < $spanEnd) {
			$intervals[] = [$cursor, $spanEnd];
		}

		return $intervals;
	}

	/**
	 * Splits an interval at local midnight, so a night shift lands on the two
	 * days it was actually worked (§8.6).
	 *
	 * @return array<string, int> local date (Y-m-d) → seconds
	 */
	private function splitByLocalDay(int $start, int $end, DateTimeZone $tz): array {
		$result = [];
		$cursor = $start;

		while ($cursor < $end) {
			$local = (new DateTimeImmutable('@' . $cursor))->setTimezone($tz);
			$nextMidnight = $local->modify('tomorrow midnight')->getTimestamp();
			$chunkEnd = min($end, $nextMidnight);
			if ($chunkEnd <= $cursor) {
				// Should not happen; never loop forever if it does.
				break;
			}

			$date = $local->format('Y-m-d');
			$result[$date] = ($result[$date] ?? 0) + ($chunkEnd - $cursor);
			$cursor = $chunkEnd;
		}

		return $result;
	}

	/**
	 * @param array<string, array{seconds: int, projects: array<string, int>}> $days
	 * @return list<array<string, mixed>>
	 */
	private function shapeDays(array $days): array {
		$shaped = [];
		foreach ($days as $date => $day) {
			$projects = [];
			foreach ($day['projects'] as $uri => $seconds) {
				$projects[] = [
					'projectUri' => $uri === '' ? null : $uri,
					'seconds' => $seconds,
				];
			}
			usort($projects, static fn (array $a, array $b): int => $b['seconds'] <=> $a['seconds']);

			$shaped[] = [
				'date' => $date,
				'seconds' => $day['seconds'],
				'projects' => $projects,
			];
		}
		return $shaped;
	}

	/**
	 * The client sends its IANA zone; anything unusable falls back to the
	 * user's Nextcloud time zone.
	 */
	private function resolveTimezone(?string $timezone): DateTimeZone {
		if ($timezone !== null && $timezone !== '') {
			try {
				return new DateTimeZone($timezone);
			} catch (Exception) {
				// Fall through to the server-side setting.
			}
		}
		return $this->dateTimeZone->getTimeZone();
	}
}
