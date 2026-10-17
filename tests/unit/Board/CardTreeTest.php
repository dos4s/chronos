<?php

declare(strict_types=1);

namespace OCA\Chronos\Tests\Unit\Board;

use OCA\Chronos\Board\CardTree;
use OCA\Chronos\Core\Dav\ParsedTask;
use OCA\Chronos\Core\Index\Task;
use OCA\Chronos\Core\Task\StatusColumn;
use OCA\Chronos\Core\Task\StatusColumns;
use PHPUnit\Framework\TestCase;

/**
 * What a card says about the tree it came out of (§15.9).
 *
 * The board is flat and a task list is not, so these are the facts that let a
 * flat board tell the truth: whose subtask a card is, how much of its own
 * branch is done, and which part of that branch a drop should take with it.
 */
class CardTreeTest extends TestCase {

	public function testACardSaysWhoseSubtaskItIs(): void {
		$tree = $this->tree([
			$this->task('parent', 'todo'),
			$this->task('child', 'todo', parentUid: 'parent'),
		]);

		$facts = $tree->factsFor('child');

		self::assertSame('parent', $facts['parent']['uid'] ?? null);
		self::assertSame('Card parent', $facts['parent']['summary'] ?? null);
		self::assertSame(ParsedTask::TYPE_TASK, $facts['parent']['type'] ?? null);
		self::assertSame('todo', $facts['parent']['column'] ?? null);
	}

	/**
	 * A task inside a subproject is not a subtask of anything: a subproject is
	 * a container for work rather than a piece of it (§6). The card still says
	 * where it lives, because that is worth knowing, but it is not folded into
	 * it and it is not counted as progress on it.
	 */
	public function testATaskInASubprojectIsNotASubtask(): void {
		$tasks = [
			$this->task('container', 'todo', type: ParsedTask::TYPE_SUBPROJECT),
			$this->task('inside', 'todo', parentUid: 'container'),
		];
		$tree = $this->tree($tasks);

		self::assertFalse($tree->isSubtask($tasks[1]));

		$facts = $tree->factsFor('inside');
		self::assertSame(ParsedTask::TYPE_SUBPROJECT, $facts['parent']['type'] ?? null);
		// A subproject is not a card, so it is in no column.
		self::assertArrayHasKey('column', $facts['parent'] ?? []);
		self::assertNull($facts['parent']['column']);
		self::assertNull($tree->factsFor('container')['subtasks']);
	}

	/**
	 * A parent in another list, or one the user cannot see, is no parent here
	 * — the same choice the task tree makes rather than hiding the card.
	 */
	public function testAParentNobodyCanSeeIsNoParent(): void {
		$tasks = [$this->task('orphan', 'todo', parentUid: 'somewhere-else')];
		$tree = $this->tree($tasks);

		self::assertNull($tree->factsFor('orphan')['parent']);
		self::assertFalse($tree->isSubtask($tasks[0]));
	}

	public function testAParentCountsItsWholeBranchAndWhereTheOpenPartIs(): void {
		$tree = $this->tree([
			$this->task('parent', 'todo'),
			$this->task('one', 'done', parentUid: 'parent'),
			$this->task('two', 'doing', parentUid: 'parent'),
			$this->task('three', 'todo', parentUid: 'parent'),
			// A subtask of a subtask is part of the same branch: that is what
			// the top of it has to stand for when the board is rolled up.
			$this->task('deep', 'doing', parentUid: 'three'),
		]);

		self::assertSame(
			['total' => 4, 'done' => 1, 'open' => ['doing' => 2, 'todo' => 1]],
			$tree->factsFor('parent')['subtasks'],
		);
		self::assertSame(
			['total' => 1, 'done' => 0, 'open' => ['doing' => 1]],
			$tree->factsFor('three')['subtasks'],
		);
	}

	public function testACardWithNoSubtasksSaysNothingAboutThem(): void {
		$tree = $this->tree([$this->task('alone', 'todo')]);

		self::assertNull($tree->factsFor('alone')['subtasks']);
	}

	/** Cancelled is as finished as completed: both columns are final (§12.1). */
	public function testACancelledSubtaskCountsAsDone(): void {
		$tree = $this->tree([
			$this->task('parent', 'todo'),
			$this->task('dropped', 'dropped', parentUid: 'parent'),
		]);

		self::assertSame(
			['total' => 1, 'done' => 1, 'open' => []],
			$tree->factsFor('parent')['subtasks'],
		);
	}

	public function testNestingPutsEachCardsChildrenBehindIt(): void {
		$cards = [
			$this->task('a', 'todo'),
			$this->task('a2', 'todo', parentUid: 'a'),
			$this->task('b', 'todo'),
			$this->task('a1', 'todo', parentUid: 'a'),
			$this->task('a1a', 'todo', parentUid: 'a1'),
		];

		self::assertSame(
			['a', 'a2', 'a1', 'a1a', 'b'],
			$this->uids($this->tree($cards)->nest($cards)),
		);
	}

	/**
	 * A subtask whose parent is in another column has nothing to sit behind,
	 * so it keeps the place the list gave it — and says whose it is on the
	 * card instead.
	 */
	public function testASubtaskWhoseParentIsElsewhereKeepsItsPlace(): void {
		$all = [
			$this->task('parent', 'doing'),
			$this->task('first', 'todo'),
			$this->task('child', 'todo', parentUid: 'parent'),
		];
		$column = [$all[1], $all[2]];

		self::assertSame(['first', 'child'], $this->uids($this->tree($all)->nest($column)));
	}

	/**
	 * A `RELATED-TO` loop — written by hand, or by a client with a bug — must
	 * not hang a page or lose a card.
	 */
	public function testALoopInTheTreeStillProducesEveryCardOnce(): void {
		$cards = [
			$this->task('one', 'todo', parentUid: 'two'),
			$this->task('two', 'todo', parentUid: 'one'),
		];
		$tree = $this->tree($cards);

		self::assertSame(['one', 'two'], $this->uids($tree->nest($cards)));
		self::assertSame(['two'], $tree->ancestorsOf('one'));
		self::assertSame(['total' => 1, 'done' => 0, 'open' => ['todo' => 1]], $tree->factsFor('one')['subtasks']);
	}

	public function testTheAncestorsOfACardAreTheCardsItsProgressBelongsTo(): void {
		$tree = $this->tree([
			$this->task('top', 'todo'),
			$this->task('middle', 'todo', parentUid: 'top'),
			$this->task('leaf', 'todo', parentUid: 'middle'),
		]);

		self::assertSame(['middle', 'top'], $tree->ancestorsOf('leaf'));
		self::assertSame([], $tree->ancestorsOf('top'));
	}

	/**
	 * What a cascading drop takes with it: the unfinished part of the branch,
	 * minus whatever is already where the drop is going.
	 */
	public function testACascadeTakesTheUnfinishedSubtasksAndLeavesTheRest(): void {
		$tree = $this->tree([
			$this->task('parent', 'todo'),
			$this->task('open', 'todo', parentUid: 'parent'),
			$this->task('already-there', 'doing', parentUid: 'parent'),
			$this->task('finished', 'done', parentUid: 'parent'),
			$this->task('cancelled', 'dropped', parentUid: 'parent'),
		]);

		self::assertSame(['open'], $tree->openDescendantsFor('parent', 'doing'));
	}

	public function testACascadeReachesTheWholeBranchInTreeOrder(): void {
		$tree = $this->tree([
			$this->task('parent', 'todo'),
			$this->task('child', 'todo', parentUid: 'parent'),
			$this->task('grandchild', 'todo', parentUid: 'child'),
		]);

		self::assertSame(['child', 'grandchild'], $tree->openDescendantsFor('parent', 'doing'));
	}

	/**
	 * @param list<Task> $tasks
	 */
	private function tree(array $tasks): CardTree {
		return new CardTree($tasks, new StatusColumns([
			new StatusColumn(1, 'todo', 'To do', null, 'NEEDS-ACTION', true, false),
			new StatusColumn(2, 'doing', 'Doing', null, 'IN-PROCESS', false, false),
			new StatusColumn(3, 'done', 'Done', null, 'COMPLETED', false, true),
			new StatusColumn(4, 'dropped', 'Dropped', null, 'CANCELLED', false, true),
		], [], 42));
	}

	private function task(
		string $uid,
		string $column,
		?string $parentUid = null,
		string $type = ParsedTask::TYPE_TASK,
	): Task {
		$ids = ['todo' => 1, 'doing' => 2, 'done' => 3, 'dropped' => 4];

		$task = new Task();
		$task->setUid($uid);
		$task->setSummary('Card ' . $uid);
		$task->setStatusId($ids[$column]);
		$task->setVtodoStatus('NEEDS-ACTION');
		$task->setType($type);
		$task->setParentUid($parentUid);

		return $task;
	}

	/**
	 * @param list<Task> $cards
	 * @return list<string>
	 */
	private function uids(array $cards): array {
		return array_map(static fn (Task $card): string => $card->getUid(), $cards);
	}
}
