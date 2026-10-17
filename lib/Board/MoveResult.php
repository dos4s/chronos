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
 */
final class MoveResult implements JsonSerializable {

	/**
	 * @param list<Task> $cards
	 * @param array<string, string> $ranks task UID → its new rank
	 * @param array<string, int> $counts column key → how many cards it holds
	 */
	public function __construct(
		public readonly array $cards,
		public readonly array $ranks,
		public readonly array $counts,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'cards' => array_map(
				fn (Task $card): array => $card->jsonSerialize() + [
					'rank' => $this->ranks[$card->getUid()] ?? null,
				],
				$this->cards,
			),
			'counts' => $this->counts,
		];
	}
}
