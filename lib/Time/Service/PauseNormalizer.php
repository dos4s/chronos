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
	 * Accounts for pause time that a legacy total recorded but no pause did.
	 *
	 * Sessions from before 0.2.0 kept a `paused_duration` total alongside a
	 * `pauses_blob`, and the two could disagree: the quick check-out added to
	 * the total without recording a pause (D2), and sessions older than the
	 * blob column have a total and no pauses at all. Recomputing purely from
	 * the pauses would silently turn that time into worked time, so the
	 * difference is added as a pause.
	 *
	 * Only the duration was ever recorded, never when it happened, so the time
	 * is placed as late in the session as there is room for — it is the total
	 * that carries meaning.
	 *
	 * @param list<PauseShape> $pauses already normalised
	 * @return list<PauseShape>
	 */
	public function withLegacyTotal(
		array $pauses,
		int $legacySeconds,
		int $sessionStartAt,
		?int $sessionEndAt,
	): array {
		$missing = $legacySeconds - $this->closedSeconds($pauses);
		if ($missing <= 0) {
			return $pauses;
		}

		// The space the pause can occupy ends at the session's end, or at the
		// pause that is still running, whichever comes first.
		$upper = $sessionEndAt ?? $this->openStart($pauses);
		if ($upper === null) {
			// A running session with no open pause: there is no bound to work
			// back from, so the time goes right after the start.
			return $this->normalize(
				[...$pauses, ['start' => $sessionStartAt, 'end' => $sessionStartAt + $missing]],
				$sessionStartAt,
				$sessionEndAt,
			);
		}

		$added = [];
		foreach (array_reverse($this->freeIntervals($pauses, $sessionStartAt, $upper)) as $gap) {
			if ($missing <= 0) {
				break;
			}
			$take = min($missing, $gap['end'] - $gap['start']);
			if ($take <= 0) {
				continue;
			}
			$added[] = ['start' => $gap['end'] - $take, 'end' => $gap['end']];
			$missing -= $take;
		}

		return $this->normalize([...$pauses, ...$added], $sessionStartAt, $sessionEndAt);
	}

	/**
	 * The stretches of a session that no pause covers.
	 *
	 * @param list<PauseShape> $pauses
	 * @return list<array{start: int, end: int}>
	 */
	private function freeIntervals(array $pauses, int $from, int $to): array {
		$free = [];
		$cursor = $from;

		foreach ($pauses as $pause) {
			$end = $pause['end'] ?? $to;
			if ($pause['start'] > $cursor) {
				$free[] = ['start' => $cursor, 'end' => min($pause['start'], $to)];
			}
			$cursor = max($cursor, $end);
		}

		if ($cursor < $to) {
			$free[] = ['start' => $cursor, 'end' => $to];
		}

		return array_values(array_filter($free, static fn (array $gap): bool => $gap['end'] > $gap['start']));
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
