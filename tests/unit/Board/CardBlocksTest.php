<?php

declare(strict_types=1);

namespace OCA\Chronos\Tests\Unit\Board;

use OCA\Chronos\Board\CardBlocks;
use OCA\Chronos\Core\Dav\ParsedLink;
use OCA\Chronos\Core\Index\Task;
use OCA\Chronos\Core\Index\TaskLink;
use PHPUnit\Framework\TestCase;

/**
 * What a card is waiting for (§12.1). The rule follows the relationship, the
 * same reading the scheduler uses (§12.5): a finish-to-start successor waits
 * for its predecessor to *finish*, a start-to-start one only for it to start.
 */
class CardBlocksTest extends TestCase {

	public function testACardWaitsForAnUnfinishedPredecessor(): void {
		$blocks = CardBlocks::from(
			[$this->task('a', 'IN-PROCESS', 'Lay the foundations'), $this->task('b', 'NEEDS-ACTION')],
			[$this->link('a', 'b', ParsedLink::RELTYPE_FINISH_TO_START)],
		);

		self::assertSame([['uid' => 'a', 'summary' => 'Lay the foundations']], $blocks->waitingOn('b'));
		self::assertSame([], $blocks->waitingOn('a'));
	}

	public function testAFinishedPredecessorBlocksNothing(): void {
		$blocks = CardBlocks::from(
			[$this->task('a', 'COMPLETED'), $this->task('b', 'NEEDS-ACTION')],
			[$this->link('a', 'b', ParsedLink::RELTYPE_FINISH_TO_START)],
		);

		self::assertSame([], $blocks->waitingOn('b'));
	}

	/** Cancelled is finished as far as anything waiting on it is concerned. */
	public function testACancelledPredecessorBlocksNothingEither(): void {
		$blocks = CardBlocks::from(
			[$this->task('a', 'CANCELLED'), $this->task('b', 'NEEDS-ACTION')],
			[$this->link('a', 'b', ParsedLink::RELTYPE_FINISH_TO_START)],
		);

		self::assertSame([], $blocks->waitingOn('b'));
	}

	/**
	 * A start-to-start successor is waiting to be *allowed to start*, so a
	 * predecessor that is under way has already unblocked it.
	 */
	public function testAStartToStartSuccessorOnlyWaitsForTheStart(): void {
		$started = CardBlocks::from(
			[$this->task('a', 'IN-PROCESS'), $this->task('b', 'NEEDS-ACTION')],
			[$this->link('a', 'b', ParsedLink::RELTYPE_START_TO_START)],
		);
		self::assertSame([], $started->waitingOn('b'));

		$notStarted = CardBlocks::from(
			[$this->task('a', 'NEEDS-ACTION'), $this->task('b', 'NEEDS-ACTION')],
			[$this->link('a', 'b', ParsedLink::RELTYPE_START_TO_START)],
		);
		self::assertSame(['a'], array_column($notStarted->waitingOn('b'), 'uid'));
	}

	/** A task with no `STATUS` at all has not been started (D31). */
	public function testATaskWithNoStatusCountsAsNotStarted(): void {
		$blocks = CardBlocks::from(
			[$this->task('a', null), $this->task('b', 'NEEDS-ACTION')],
			[$this->link('a', 'b', ParsedLink::RELTYPE_START_TO_START)],
		);

		self::assertSame(['a'], array_column($blocks->waitingOn('b'), 'uid'));
	}

	public function testAFinishedCardIsNeverBlocked(): void {
		$blocks = CardBlocks::from(
			[$this->task('a', 'NEEDS-ACTION'), $this->task('b', 'COMPLETED')],
			[$this->link('a', 'b', ParsedLink::RELTYPE_FINISH_TO_START)],
		);

		self::assertSame([], $blocks->waitingOn('b'));
	}

	public function testEveryUnfinishedPredecessorIsNamedOnce(): void {
		$blocks = CardBlocks::from(
			[
				$this->task('a', 'NEEDS-ACTION', 'One'),
				$this->task('b', 'IN-PROCESS', 'Two'),
				$this->task('c', 'NEEDS-ACTION'),
			],
			[
				$this->link('a', 'c', ParsedLink::RELTYPE_FINISH_TO_START),
				$this->link('b', 'c', ParsedLink::RELTYPE_FINISH_TO_FINISH),
				// The same pair twice, which a re-index can leave behind.
				$this->link('a', 'c', ParsedLink::RELTYPE_FINISH_TO_START),
			],
		);

		self::assertSame(['a', 'b'], array_column($blocks->waitingOn('c'), 'uid'));
	}

	/**
	 * A dependency on a task in another list is real, but this board cannot
	 * speak for it: the predecessor is not on it and its status is unknown.
	 */
	public function testAPredecessorInAnotherListIsNotSpokenFor(): void {
		$blocks = CardBlocks::from(
			[$this->task('b', 'NEEDS-ACTION')],
			[$this->link('elsewhere', 'b', ParsedLink::RELTYPE_FINISH_TO_START)],
		);

		self::assertSame([], $blocks->waitingOn('b'));
	}

	public function testAPlainDependsOnWaitsForTheFinish(): void {
		$blocks = CardBlocks::from(
			[$this->task('a', 'IN-PROCESS'), $this->task('b', 'NEEDS-ACTION')],
			[$this->link('a', 'b', ParsedLink::RELTYPE_DEPENDS_ON)],
		);

		self::assertSame(['a'], array_column($blocks->waitingOn('b'), 'uid'));
	}

	private function task(string $uid, ?string $status, ?string $summary = null): Task {
		$task = new Task();
		$task->setUid($uid);
		$task->setVtodoStatus($status);
		$task->setSummary($summary);

		return $task;
	}

	private function link(string $predecessor, string $successor, string $reltype): TaskLink {
		$link = new TaskLink();
		$link->setPredecessorUid($predecessor);
		$link->setSuccessorUid($successor);
		$link->setReltype($reltype);

		return $link;
	}
}
