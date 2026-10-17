<?php

declare(strict_types=1);

namespace OCA\Chronos\Tests\Unit\Board;

use OCA\Chronos\Board\CardOrder;
use OCA\Chronos\Board\Db\BoardRank;
use OCA\Chronos\Core\Index\Task;
use PHPUnit\Framework\TestCase;

/**
 * The order of the cards in a column (§15.5): arranged cards where they were
 * put, everything else after them in the order the list itself uses.
 */
class CardOrderTest extends TestCase {

	public function testArrangedCardsComeOutInRankOrder(): void {
		$cards = [$this->card('a'), $this->card('b'), $this->card('c')];
		$ranks = $this->ranks(['a' => 'r', 'b' => '9', 'c' => 'i'], 'doing');

		self::assertSame(['b', 'c', 'a'], $this->uids(CardOrder::sort($cards, $ranks, 'doing')));
	}

	/**
	 * A card nobody has dragged yet — one just created, or one a phone wrote —
	 * goes after the arranged ones, keeping the list's own order.
	 */
	public function testCardsWithNoRankFollowInTheOrderTheyCameIn(): void {
		$cards = [$this->card('new1'), $this->card('a'), $this->card('new2')];
		$ranks = $this->ranks(['a' => 'i'], 'doing');

		self::assertSame(['a', 'new1', 'new2'], $this->uids(CardOrder::sort($cards, $ranks, 'doing')));
	}

	/**
	 * A rank belongs to the column it was given in. A card whose status was
	 * changed in the Tasks app arrives in a different column, and ordering it
	 * there by its old rank would put it somewhere arbitrary.
	 */
	public function testARankFromAnotherColumnDoesNotCount(): void {
		$cards = [$this->card('moved'), $this->card('a')];
		$ranks = $this->ranks(['moved' => '0i'], 'todo') + $this->ranks(['a' => 'i'], 'doing');

		self::assertSame(['a', 'moved'], $this->uids(CardOrder::sort($cards, $ranks, 'doing')));
		self::assertNull(CardOrder::rankForUid($ranks, 'moved', 'doing'));
		self::assertSame('0i', CardOrder::rankForUid($ranks, 'moved', 'todo'));
	}

	public function testTwoCardsAtTheSameRankStillComeOutInAStableOrder(): void {
		$cards = [$this->card('b'), $this->card('a')];
		$ranks = $this->ranks(['a' => 'i', 'b' => 'i'], 'doing');

		self::assertSame(['a', 'b'], $this->uids(CardOrder::sort($cards, $ranks, 'doing')));
	}

	public function testAnEmptyColumnStaysEmpty(): void {
		self::assertSame([], CardOrder::sort([], [], 'doing'));
	}

	private function card(string $uid): Task {
		$task = new Task();
		$task->setUid($uid);
		$task->setSummary($uid);

		return $task;
	}

	/**
	 * @param array<string, string> $byUid
	 * @return array<string, BoardRank>
	 */
	private function ranks(array $byUid, string $columnKey): array {
		$ranks = [];
		foreach ($byUid as $uid => $value) {
			$rank = new BoardRank();
			$rank->setTaskUid($uid);
			$rank->setColumnKey($columnKey);
			$rank->setCardRank($value);
			$ranks[$uid] = $rank;
		}

		return $ranks;
	}

	/**
	 * @param list<Task> $cards
	 * @return list<string>
	 */
	private function uids(array $cards): array {
		return array_map(static fn (Task $card): string => $card->getUid(), $cards);
	}
}
