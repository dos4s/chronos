<?php

declare(strict_types=1);

namespace OCA\Chronos\Board;

use OCA\Chronos\Board\Db\BoardRank;
use OCA\Chronos\Core\Index\Task;

/**
 * The order of the cards in one column (§15.5).
 *
 * A card that has been dropped somewhere has a rank and sits where it was put.
 * A card that has never been dropped — one just created, or one a phone wrote
 * — has none, and goes after the ranked ones in the order the list itself uses
 * (due date, then name). That way a board nobody has arranged reads like the
 * list, arranging one card does not renumber the column, and a new card turns
 * up somewhere predictable rather than in the middle of yesterday's order.
 *
 * A rank only counts in the column it was given in: a card whose status was
 * changed in the Tasks app carries a rank from the column it has left, and
 * ordering the new column by it would put the card in an arbitrary place.
 */
final class CardOrder {

	/**
	 * @param list<Task> $cards in the list's own order
	 * @param array<string, BoardRank> $ranks by task UID
	 * @return list<Task>
	 */
	public static function sort(array $cards, array $ranks, string $columnKey): array {
		$ranked = [];
		$unranked = [];
		$rankOf = [];

		foreach ($cards as $card) {
			$rank = self::rankOf($card, $ranks, $columnKey);
			if ($rank === null) {
				$unranked[] = $card;
				continue;
			}

			$ranked[] = $card;
			$rankOf[$card->getUid()] = $rank;
		}

		usort($ranked, static function (Task $a, Task $b) use ($rankOf): int {
			$order = strcmp($rankOf[$a->getUid()], $rankOf[$b->getUid()]);

			// Two cards at the same rank cannot happen through this app, but a
			// restore or a hand-edited row should still come out in a stable
			// order rather than whatever the query returned.
			return $order === 0 ? strcmp($a->getUid(), $b->getUid()) : $order;
		});

		return array_merge($ranked, $unranked);
	}

	/**
	 * The rank a card has in this column, or null if it has none that counts.
	 *
	 * @param array<string, BoardRank> $ranks
	 */
	public static function rankOf(Task $card, array $ranks, string $columnKey): ?string {
		return self::rankForUid($ranks, $card->getUid(), $columnKey);
	}

	/**
	 * The same, for a card named only by its UID — which is how a drop names
	 * the two cards it landed between.
	 *
	 * @param array<string, BoardRank> $ranks
	 */
	public static function rankForUid(array $ranks, string $uid, string $columnKey): ?string {
		$rank = $ranks[$uid] ?? null;
		if ($rank === null || $rank->getColumnKey() !== $columnKey) {
			return null;
		}

		$value = $rank->getCardRank();

		return $value === '' ? null : $value;
	}
}
