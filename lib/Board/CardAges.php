<?php

declare(strict_types=1);

namespace OCA\Chronos\Board;

use OCA\Chronos\Core\Index\Task;
use OCA\Chronos\Core\Task\StatusColumn;

/**
 * When each card arrived in the column it is in now (§12.9).
 *
 * Work item age is one of the four measures the Kanban Guide makes mandatory,
 * and the only one a board can show on the card itself. It is the elapsed time
 * since the item *entered the state it is in*, not since it was created: a card
 * made six months ago and started yesterday is a day old, and a card that has
 * sat in *Doing* for three weeks is three weeks old however often somebody has
 * edited it.
 *
 * The arrival is read out of status history, which records both statuses since
 * 0.5.8 — the workflow's where there is one and the VTODO's `STATUS` always
 * (§10.3). A card with no row that matches its column has **no** age rather
 * than a guessed one: history before 0.5.8 only covered workflow moves, and an
 * age measured from the wrong moment would be worse than a blank, because a
 * blank is obviously a blank.
 *
 * Pure on purpose: it takes rows and gives a map, so the arithmetic is tested
 * without a database (§18).
 */
final class CardAges {

	/**
	 * @param array<string, int> $enteredAt card UID → when it entered its column
	 */
	private function __construct(
		private readonly array $enteredAt,
	) {
	}

	/**
	 * `$columnOf` says which column each card is in, by UID.
	 *
	 * Rows come in any order; `$history` is every status change of those cards.
	 *
	 * @param list<Task> $cards
	 * @param array<string, StatusColumn> $columnOf
	 * @param list<array{taskUid: string, toKey: ?string, toStandard: ?string, changedAt: int}> $history
	 */
	public static function from(array $cards, array $columnOf, array $history): self {
		$byUid = [];
		foreach ($history as $row) {
			$byUid[$row['taskUid']][] = $row;
		}

		$entered = [];
		foreach ($cards as $card) {
			$uid = $card->getUid();
			$column = $columnOf[$uid] ?? null;
			if ($column === null) {
				continue;
			}

			$at = self::arrival($byUid[$uid] ?? [], $column);
			if ($at !== null) {
				$entered[$uid] = $at;
			}
		}

		return new self($entered);
	}

	/**
	 * The most recent row that moved this card *into* this column.
	 *
	 * A workflow board matches on the status key, because two of its columns
	 * can share a standard status — "In progress" and "Review" are both
	 * `IN-PROCESS`, and a card that moved between them did move column. A board
	 * without a workflow has nothing but the standard status to match on.
	 *
	 * @param list<array{taskUid: string, toKey: ?string, toStandard: ?string, changedAt: int}> $rows
	 */
	private static function arrival(array $rows, StatusColumn $column): ?int {
		$latest = null;
		foreach ($rows as $row) {
			$matches = $row['toKey'] !== null
				? $row['toKey'] === $column->key
				: $row['toStandard'] !== null && $row['toStandard'] === $column->vtodoStatus;

			if ($matches && ($latest === null || $row['changedAt'] > $latest)) {
				$latest = $row['changedAt'];
			}
		}

		return $latest;
	}

	public function enteredAt(string $uid): ?int {
		return $this->enteredAt[$uid] ?? null;
	}

	/**
	 * @return array<string, int>
	 */
	public function all(): array {
		return $this->enteredAt;
	}
}
