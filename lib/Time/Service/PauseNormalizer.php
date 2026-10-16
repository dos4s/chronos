<?php

declare(strict_types=1);

namespace OCA\Chronos\Time\Service;

/**
 * The rules that keep pauses sane (D4), with no database or clock involved so
 * both SessionService and the seconds migration can use them.
 *
 * A normalised list of pauses: inside its session, sorted, never overlapping or
 * touching, with at most one open pause, which is always last.
 *
 * @psalm-type PauseShape = array{start: int, end: int|null}
 */
final class PauseNormalizer {

	/**
	 * @param list<PauseShape> $pauses
	 * @return list<PauseShape>
	 */
	public function normalize(array $pauses, int $sessionStartAt, ?int $sessionEndAt): array {
		$closed = [];
		$openStart = null;

		foreach ($pauses as $pause) {
			$start = $pause['start'];
			$end = $pause['end'] ?? null;

			// A pause can never start before its session.
			$start = max($start, $sessionStartAt);

			// A finished session closes any pause still running inside it.
			if ($sessionEndAt !== null) {
				if ($start >= $sessionEndAt) {
					continue;
				}
				$end = $end === null ? $sessionEndAt : min($end, $sessionEndAt);
			}

			if ($end === null) {
				$openStart = $openStart === null ? $start : min($openStart, $start);
				continue;
			}

			if ($end <= $start) {
				continue;
			}
			$closed[] = ['start' => $start, 'end' => $end];
		}

		$merged = $this->merge($closed);

		if ($openStart === null) {
			return $merged;
		}

		// Nothing can happen after a pause that is still running: anything
		// reaching into it becomes part of it, anything beyond it is dropped.
		$result = [];
		foreach ($merged as $pause) {
			if ($pause['end'] < $openStart) {
				$result[] = $pause;
				continue;
			}
			$openStart = min($openStart, $pause['start']);
		}
		$result[] = ['start' => $openStart, 'end' => null];

		return $result;
	}

	/**
	 * Seconds spent in finished pauses. The open pause is not counted: it is
	 * represented by the session's `paused_since_at` and ticks client-side.
	 *
	 * @param list<PauseShape> $pauses
	 */
	public function closedSeconds(array $pauses): int {
		$total = 0;
		foreach ($pauses as $pause) {
			if ($pause['end'] !== null) {
				$total += $pause['end'] - $pause['start'];
			}
		}
		return $total;
	}

	/**
	 * The start of the open pause, if the list has one.
	 *
	 * @param list<PauseShape> $pauses
	 */
	public function openStart(array $pauses): ?int {
		foreach ($pauses as $pause) {
			if ($pause['end'] === null) {
				return $pause['start'];
			}
		}
		return null;
	}

	/**
	 * @param list<PauseShape> $closed every pause finished
	 * @return list<PauseShape>
	 */
	private function merge(array $closed): array {
		usort($closed, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

		$merged = [];
		foreach ($closed as $pause) {
			$last = array_pop($merged);
			if ($last === null) {
				$merged[] = $pause;
				continue;
			}
			if ($last['end'] !== null && $pause['end'] !== null && $pause['start'] <= $last['end']) {
				// Overlapping or touching: one pause, not two.
				$merged[] = ['start' => $last['start'], 'end' => max($last['end'], $pause['end'])];
				continue;
			}
			$merged[] = $last;
			$merged[] = $pause;
		}

		return $merged;
	}
}
