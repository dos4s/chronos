<?php

declare(strict_types=1);

namespace OCA\Chronos\Planning;

/**
 * What the dependencies say about the dates, and what moving one task does to
 * the rest (§12.5, §15.4).
 *
 * Nothing here reads or writes anything: it takes the bars, the links and the
 * working days, and answers two questions — which links the dates break, and
 * where everything would sit if one task moved. That is what lets the same
 * answer be worked out in the browser for the **ghost bars** shown before the
 * drop and on the server for the write, which is the only way the preview can
 * be the truth rather than a guess (`src/utils/schedule.ts` is the same
 * algorithm, with the same tests).
 *
 * The four temporal relationships are RFC 9253's, and each one constrains one
 * end of the successor:
 *
 * | `RELTYPE` | the successor may not begin before |
 * |---|---|
 * | `FINISHTOSTART` | the predecessor's end, plus the gap |
 * | `STARTTOSTART` | the predecessor's start, plus the gap |
 * | `FINISHTOFINISH` | — its *end* may not fall before the predecessor's end, plus the gap |
 * | `STARTTOFINISH` | — its *end* may not fall before the predecessor's start, plus the gap |
 *
 * Every constraint is "not before", never "exactly at": a task may always sit
 * later than its dependencies require, because slack is not an error. That is
 * also why pushing a bar onto a working day can never create a conflict.
 */
final class Schedule {

	/** @var array<string, Bar> */
	private array $bars;

	/** @var list<Dependency> */
	private array $links;

	/** @var array<string, list<Dependency>> predecessor UID → its links */
	private array $outgoing = [];

	/**
	 * The links that close a loop, by `predecessor>successor`, worked out once.
	 *
	 * @var array<string, Conflict>|null
	 */
	private ?array $loops = null;

	/**
	 * @param array<string, Bar> $bars by task UID
	 * @param list<Dependency> $links
	 */
	public function __construct(
		array $bars,
		array $links,
		private PlanDates $dates,
		private WorkingDays $working,
	) {
		$this->bars = $bars;
		$this->links = $links;

		foreach ($links as $link) {
			if ($link->isTemporal()) {
				$this->outgoing[$link->predecessorUid][] = $link;
			}
		}
	}

	/**
	 * Every link the current dates break, plus every loop in the graph.
	 *
	 * @return list<Conflict>
	 */
	public function conflicts(): array {
		return $this->conflictsIn($this->bars);
	}

	/**
	 * Where everything sits once one task has moved (§15.4).
	 *
	 * In **cascade** mode the dependents follow: each one is pushed just far
	 * enough to satisfy the link that moved, keeping its own length, and then
	 * onto a working day. A task is only ever pushed *later* — a cascade never
	 * pulls work forward into the space a predecessor left behind, because
	 * dates people have set are not the scheduler's to tighten.
	 *
	 * In **warn** mode nothing follows and the broken links come back as
	 * conflicts, which is what the warning is about.
	 *
	 * @param int $limit the most tasks a cascade may move, the moved one included
	 * @return Rescheduling
	 */
	public function reschedule(Bar $moved, bool $cascade, int $limit): Rescheduling {
		$bars = $this->bars;
		$bars[$moved->uid] = $moved;
		$changed = [$moved->uid => $moved];

		if (!$cascade) {
			return new Rescheduling($changed, $this->conflictsIn($bars), false);
		}

		$queue = [$moved->uid];
		$overflowed = false;

		// Every push moves a task strictly later, so on an acyclic graph this
		// finishes; the step count is the guard against a graph that is not
		// one in a way the loop detection missed.
		$steps = 0;
		$ceiling = max(8, $limit * 8);

		while ($queue !== [] && $steps++ < $ceiling) {
			$uid = array_shift($queue);
			$predecessor = $bars[$uid] ?? null;
			if ($predecessor === null) {
				continue;
			}

			foreach ($this->outgoing[$uid] ?? [] as $link) {
				$successor = $bars[$link->successorUid] ?? null;
				// A link that closes a loop is reported as a conflict and
				// never followed: following it would push its own predecessor
				// for ever, and no dates can satisfy it anyway.
				if ($successor === null || $this->closesALoop($link)) {
					continue;
				}

				$pushed = $this->satisfy($link, $predecessor, $successor);
				if ($pushed === null) {
					continue;
				}

				if (!isset($changed[$pushed->uid]) && count($changed) >= $limit) {
					// A chain longer than one request should carry is not half
					// moved: the caller is told, and refuses the whole thing.
					$overflowed = true;
					continue;
				}

				$bars[$pushed->uid] = $pushed;
				$changed[$pushed->uid] = $pushed;
				$queue[] = $pushed->uid;
			}
		}

		return new Rescheduling($changed, $this->conflictsIn($bars), $overflowed || $queue !== []);
	}

	/**
	 * The successor moved just far enough to satisfy one link, or null when it
	 * already does.
	 */
	private function satisfy(Dependency $link, Bar $predecessor, Bar $successor): ?Bar {
		$required = $this->earliest($link, $predecessor);

		$pushed = match ($link->reltype) {
			'FINISHTOSTART', 'STARTTOSTART' => $successor->startAt >= $required
				? null
				: $this->dates->startingAt($successor, $required),
			'FINISHTOFINISH', 'STARTTOFINISH' => $successor->endAt >= $required
				? null
				: $this->dates->endingAt($successor, $required),
			default => null,
		};

		if ($pushed === null) {
			return null;
		}

		// A task does not begin on a day the project does not work (§15.4).
		// Pushing it later can never break the link it was pushed for, because
		// every constraint here is "not before".
		$working = $this->working->nextWorking($pushed->startAt, $pushed->startIsDate);
		if ($working !== $pushed->startAt) {
			$pushed = $this->dates->startingAt($pushed, $working);
		}

		return $pushed->sameAs($successor) ? null : $pushed;
	}

	/**
	 * The instant one link measures from: an end for the "finish" forms, a
	 * start for the "start" forms, plus the gap either way.
	 */
	private function earliest(Dependency $link, Bar $predecessor): int {
		$from = match ($link->reltype) {
			'FINISHTOSTART', 'FINISHTOFINISH' => $predecessor->endAt,
			default => $predecessor->startAt,
		};

		return $from + $link->gapSeconds;
	}

	/**
	 * @param array<string, Bar> $bars
	 * @return list<Conflict>
	 */
	private function conflictsIn(array $bars): array {
		$conflicts = [];

		foreach ($this->links as $link) {
			if (!$link->isTemporal()) {
				continue;
			}

			$predecessor = $bars[$link->predecessorUid] ?? null;
			$successor = $bars[$link->successorUid] ?? null;
			if ($predecessor === null || $successor === null) {
				continue;
			}

			$required = $this->earliest($link, $predecessor);
			$actual = match ($link->reltype) {
				'FINISHTOFINISH', 'STARTTOFINISH' => $successor->endAt,
				default => $successor->startAt,
			};

			if ($actual < $required) {
				$conflicts[] = new Conflict(
					Conflict::TOO_EARLY,
					$link->predecessorUid,
					$link->successorUid,
					$link->reltype,
					$required,
					$actual,
				);
			}
		}

		return array_merge($conflicts, array_values($this->loops()));
	}

	private function closesALoop(Dependency $link): bool {
		return isset($this->loops()[$link->predecessorUid . '>' . $link->successorUid]);
	}

	/**
	 * The links that close a loop in the graph, by `predecessor>successor`.
	 *
	 * Chronos refuses to create one, but another client can write one, and a
	 * loop would otherwise be a cascade that never finished: each push moves a
	 * task later, and around a loop there is always another push to make. So
	 * the loop is found once, reported as a conflict of its own, and the link
	 * that closes it is never followed.
	 *
	 * @return array<string, Conflict>
	 */
	private function loops(): array {
		if ($this->loops !== null) {
			return $this->loops;
		}

		$state = [];
		$found = [];

		foreach (array_keys($this->outgoing) as $uid) {
			$this->walk($uid, $state, $found);
		}

		return $this->loops = $found;
	}

	/**
	 * @param array<string, int> $state 1 = on the current path, 2 = done
	 * @param array<string, Conflict> $found
	 */
	private function walk(string $uid, array &$state, array &$found): void {
		if (($state[$uid] ?? 0) !== 0) {
			return;
		}

		$state[$uid] = 1;

		foreach ($this->outgoing[$uid] ?? [] as $link) {
			$next = $link->successorUid;
			if (($state[$next] ?? 0) === 1) {
				$found[$link->predecessorUid . '>' . $next] = new Conflict(
					Conflict::CYCLE,
					$link->predecessorUid,
					$next,
					$link->reltype,
				);
				continue;
			}
			$this->walk($next, $state, $found);
		}

		$state[$uid] = 2;
	}
}
