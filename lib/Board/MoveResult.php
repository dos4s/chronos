<?php

declare(strict_types=1);

namespace OCA\Chronos\Board;

use JsonSerializable;
use OCA\Chronos\Core\Index\Task;

/**
 * What a drop changed (§15.6).
 *
 * The client has already applied the move optimistically, so this is what it
 * needs to reconcile: the cards as they now are — above all their new etags,
 * without which the next write of the same card would be refused — the rank
 * each one was given, so an undo knows where it came from, and the size of
 * every column, so a WIP warning appears without re-reading the board.
 *
 * Two of those cards may be ones the client never dragged: a drop changes how
 * much of its parent's branch is done, and a cascade moves a card's unfinished
 * subtasks as well (§15.9). Each card therefore says which column it came
 * from, which is what lets an undo put back something the page never saw.
 */
final class MoveResult implements JsonSerializable {

	/**
	 * @param list<Task> $cards
	 * @param array<string, string> $ranks task UID → its new rank
	 * @param array<string, int> $counts column key → how many cards it holds
	 * @param array<string, string> $from task UID → the column it came from
	 */
	public function __construct(
		public readonly array $cards,
		public readonly array $ranks,
		public readonly array $counts,
		public readonly array $from,
		public readonly CardTree $tree,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'cards' => array_map(
				fn (Task $card): array => $card->jsonSerialize() + $this->tree->factsFor($card->getUid()) + [
					'rank' => $this->ranks[$card->getUid()] ?? null,
					'fromColumn' => $this->from[$card->getUid()] ?? null,
				],
				$this->cards,
			),
			'counts' => $this->counts,
		];
	}
}
