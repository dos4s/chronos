<?php

declare(strict_types=1);

namespace OCA\Chronos\Tests\Unit\Board;

use OCA\Chronos\Board\CardAges;
use OCA\Chronos\Core\Index\Task;
use OCA\Chronos\Core\Task\StatusColumn;
use PHPUnit\Framework\TestCase;

/**
 * How long a card has been where it is (§12.9): the elapsed time since it
 * entered its column, which is the Kanban Guide's work item age.
 */
class CardAgesTest extends TestCase {

	public function testACardIsAsOldAsItsLastArrivalInThisColumn(): void {
		$ages = CardAges::from(
			[$this->card('a')],
			['a' => $this->column('doing', 'IN-PROCESS')],
			[
				$this->arrival('a', 'doing', 'IN-PROCESS', 100),
				// Sent back to the previous column and pulled again: the age
				// runs from the second arrival, not the first.
				$this->arrival('a', 'todo', 'NEEDS-ACTION', 200),
				$this->arrival('a', 'doing', 'IN-PROCESS', 300),
			],
		);

		self::assertSame(300, $ages->enteredAt('a'));
	}

	/**
	 * Two columns of a workflow can share a standard status — "In progress"
	 * and "Review" are both `IN-PROCESS` — so a board with a workflow matches
	 * on the key, or a move between them would not count as a move.
	 */
	public function testAWorkflowBoardMatchesOnTheKeyRatherThanTheStandardStatus(): void {
		$ages = CardAges::from(
			[$this->card('a')],
			['a' => $this->column('review', 'IN-PROCESS')],
			[
				$this->arrival('a', 'doing', 'IN-PROCESS', 100),
				$this->arrival('a', 'review', 'IN-PROCESS', 500),
			],
		);

		self::assertSame(500, $ages->enteredAt('a'));
	}

	/**
	 * Without a workflow there is no key to match on, and the rows written for
	 * such a project carry the standard status alone.
	 */
	public function testABoardWithoutAWorkflowMatchesOnTheStandardStatus(): void {
		$ages = CardAges::from(
			[$this->card('a')],
			['a' => $this->column('IN-PROCESS', 'IN-PROCESS')],
			[
				$this->arrival('a', null, 'NEEDS-ACTION', 100),
				$this->arrival('a', null, 'IN-PROCESS', 400),
			],
		);

		self::assertSame(400, $ages->enteredAt('a'));
	}

	/**
	 * A card whose arrival nothing recorded has no age at all. Every row
	 * written before 0.5.8 is a workflow move, so most cards on an existing
	 * instance are in exactly this position, and an age measured from the
	 * wrong moment would read as fact.
	 */
	public function testACardWithNothingRecordedHasNoAge(): void {
		$ages = CardAges::from(
			[$this->card('a'), $this->card('b')],
			['a' => $this->column('doing', 'IN-PROCESS'), 'b' => $this->column('doing', 'IN-PROCESS')],
			[$this->arrival('a', 'doing', 'IN-PROCESS', 100)],
		);

		self::assertSame(100, $ages->enteredAt('a'));
		self::assertNull($ages->enteredAt('b'));
		self::assertSame(['a' => 100], $ages->all());
	}

	public function testRowsForAnotherColumnDoNotCount(): void {
		$ages = CardAges::from(
			[$this->card('a')],
			['a' => $this->column('doing', 'IN-PROCESS')],
			[
				$this->arrival('a', 'todo', 'NEEDS-ACTION', 100),
				$this->arrival('a', 'done', 'COMPLETED', 900),
			],
		);

		self::assertNull($ages->enteredAt('a'));
	}

	public function testABoardWithNoCardsAsksNothing(): void {
		self::assertSame([], CardAges::from([], [], [])->all());
	}

	private function card(string $uid): Task {
		$task = new Task();
		$task->setUid($uid);

		return $task;
	}

	private function column(string $key, string $vtodoStatus): StatusColumn {
		return new StatusColumn(null, $key, $key, null, $vtodoStatus, false, false);
	}

	/**
	 * @return array{taskUid: string, toKey: ?string, toStandard: ?string, changedAt: int}
	 */
	private function arrival(string $uid, ?string $key, ?string $standard, int $at): array {
		return ['taskUid' => $uid, 'toKey' => $key, 'toStandard' => $standard, 'changedAt' => $at];
	}
}
