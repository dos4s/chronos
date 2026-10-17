<?php

declare(strict_types=1);

namespace OCA\Chronos\Board;

use OCA\Chronos\Core\Dav\ParsedTask;
use OCA\Chronos\Core\Index\Task;
use OCA\Chronos\Core\Task\StatusColumns;

/**
 * What the board knows about the hierarchy the cards came out of (§6, §15.9).
 *
 * A board is flat and a task list is a tree, and the board used to simply drop
 * the tree on the floor: a subtask was a card like any other, with nothing to
 * say whose it was. It cannot be hidden instead — a subtask has a status of
 * its own, and a column *is* a status (AD7), so a subtask is real work with a
 * real place on the board. What it needs is to say where it belongs.
 *
 * This answers that, once per board read, for every card:
 *
 * - **`parent`** — the task or subproject the card hangs under, if the user
 *   can see it. A parent that is in another list, or one the user cannot see,
 *   is no parent at all here, exactly as in the task tree.
 * - **`subtasks`** — how much of the branch under a card is finished, and
 *   which columns the unfinished part is sitting in. Counted over the whole
 *   branch rather than the direct children, because that is what a card has
 *   to stand for when the board is rolled up (§15.9).
 *
 * It also supplies the two orders the board needs from the tree: `nest()` for
 * the fallback order of a column nobody has arranged, and
 * `openDescendantsFor()` for the cascade of a drop that takes a card's
 * unfinished subtasks with it.
 *
 * Nothing here trusts the tree to be a tree: a `RELATED-TO` loop written by
 * hand or by another client must not hang a page, so every walk carries the
 * set of UIDs it has already seen.
 */
final class CardTree {

	/** @var array<string, Task> */
	private array $byUid = [];

	/** @var array<string, list<string>> parent UID → its children, in list order */
	private array $children = [];

	/** @var array<string, string> task UID → the column its status puts it in */
	private array $columnOf = [];

	/** @var array<string, bool> column key → whether work in it is finished */
	private array $isFinal = [];

	/**
	 * @param list<Task> $tasks every task of the project, subprojects included
	 */
	public function __construct(array $tasks, StatusColumns $columns) {
		foreach ($columns->columns as $column) {
			$this->isFinal[$column->key] = $column->isFinal;
		}

		foreach ($tasks as $task) {
			$this->byUid[$task->getUid()] = $task;
			$column = $columns->columnFor($task);
			if ($column !== null) {
				$this->columnOf[$task->getUid()] = $column->key;
			}
		}

		foreach ($tasks as $task) {
			$parentUid = $task->getParentUid();
			if ($parentUid === null || $parentUid === $task->getUid() || !isset($this->byUid[$parentUid])) {
				continue;
			}
			$this->children[$parentUid][] = $task->getUid();
		}
	}

	public function task(string $uid): ?Task {
		return $this->byUid[$uid] ?? null;
	}

	/**
	 * Whether this card is a subtask of another card.
	 *
	 * A task inside a subproject is **not** one: a subproject is a container
	 * for work rather than a piece of it (§6), so the tasks in it are the
	 * project's own cards and the subproject is never a card at all. This is
	 * the line a rolled-up board folds along.
	 */
	public function isSubtask(Task $task): bool {
		$parent = $this->parentOf($task->getUid());

		return $parent !== null && $parent->getType() !== ParsedTask::TYPE_SUBPROJECT;
	}

	/**
	 * The cards of one column with each card's children right behind it.
	 *
	 * This is a **fallback order, not a rule**: it is applied before the ranks
	 * are, so an arranged card keeps where it was put and only the cards
	 * nobody has dragged are nested (§15.9). A column arranged by hand is the
	 * user's arrangement, and pulling a card back under its parent would
	 * silently undo a drag — which is also why a subtask that sits away from
	 * its parent says whose it is on the card itself.
	 *
	 * @param list<Task> $cards in the list's own order
	 * @return list<Task>
	 */
	public function nest(array $cards): array {
		$present = [];
		foreach ($cards as $card) {
			$present[$card->getUid()] = $card;
		}

		$nested = [];
		$emitted = [];

		foreach ($cards as $card) {
			$parentUid = $card->getParentUid();
			// A card whose parent is in this column is emitted by its parent.
			if ($parentUid !== null && isset($present[$parentUid]) && $parentUid !== $card->getUid()) {
				continue;
			}
			$this->emit($card, $present, $nested, $emitted);
		}

		// A loop in the tree reaches nothing from a root, so whatever is left
		// keeps the place the list gave it rather than disappearing.
		foreach ($cards as $card) {
			if (!isset($emitted[$card->getUid()])) {
				$emitted[$card->getUid()] = true;
				$nested[] = $card;
			}
		}

		return $nested;
	}

	/**
	 * The UIDs above a card, nearest first.
	 *
	 * Moving a card changes how much of its parent's branch is done, so these
	 * are the cards a drop has to send back besides the ones it moved (§15.6).
	 *
	 * @return list<string>
	 */
	public function ancestorsOf(string $uid): array {
		$seen = [$uid => true];
		$ancestors = [];

		for ($parent = $this->parentOf($uid); $parent !== null; $parent = $this->parentOf($parent->getUid())) {
			if (isset($seen[$parent->getUid()])) {
				break;
			}
			$seen[$parent->getUid()] = true;
			$ancestors[] = $parent->getUid();
		}

		return $ancestors;
	}

	/**
	 * The unfinished part of a card's branch that a cascading drop takes with
	 * it (§15.9).
	 *
	 * Two kinds of subtask are left alone: one that is already finished, which
	 * dragging its parent must never reopen, and one that is already in the
	 * column the drop is going to, which has nowhere to go.
	 *
	 * @return list<string> in tree order, so each one lands behind its parent
	 */
	public function openDescendantsFor(string $uid, string $targetColumn): array {
		$open = [];

		foreach ($this->descendantsOf($uid) as $descendant) {
			$column = $this->columnOf[$descendant] ?? null;
			if ($column === null || $column === $targetColumn || ($this->isFinal[$column] ?? false)) {
				continue;
			}
			$open[] = $descendant;
		}

		return $open;
	}

	/**
	 * What one card says about its place in the tree, as the board sends it.
	 *
	 * @return array{
	 *     parent: array{uid: string, summary: string|null, type: string, column: string|null}|null,
	 *     subtasks: array{total: int, done: int, open: array<string, int>}|null,
	 * }
	 */
	public function factsFor(string $uid): array {
		return [
			'parent' => $this->parentFact($uid),
			'subtasks' => $this->subtaskFact($uid),
		];
	}

	/**
	 * @return array{uid: string, summary: string|null, type: string, column: string|null}|null
	 */
	private function parentFact(string $uid): ?array {
		$parent = $this->parentOf($uid);
		if ($parent === null) {
			return null;
		}

		$isSubproject = $parent->getType() === ParsedTask::TYPE_SUBPROJECT;

		return [
			'uid' => $parent->getUid(),
			'summary' => $parent->getSummary(),
			'type' => $parent->getType(),
			// A subproject is not a card, so it is in no column (§6).
			'column' => $isSubproject ? null : ($this->columnOf[$parent->getUid()] ?? null),
		];
	}

	/**
	 * @return array{total: int, done: int, open: array<string, int>}|null
	 */
	private function subtaskFact(string $uid): ?array {
		$total = 0;
		$done = 0;
		$open = [];

		foreach ($this->descendantsOf($uid) as $descendant) {
			$total++;
			$column = $this->columnOf[$descendant] ?? null;
			if ($column !== null && ($this->isFinal[$column] ?? false)) {
				$done++;
				continue;
			}
			if ($column !== null) {
				$open[$column] = ($open[$column] ?? 0) + 1;
			}
		}

		return $total === 0 ? null : ['total' => $total, 'done' => $done, 'open' => $open];
	}

	/**
	 * Everything under a card, depth first, in list order.
	 *
	 * A subproject is not part of anybody's work: the tasks in one are the
	 * project's own cards (§6), so a subproject has no branch of its own and
	 * the walk stops at one from either side.
	 *
	 * @return list<string>
	 */
	private function descendantsOf(string $uid): array {
		$task = $this->byUid[$uid] ?? null;
		if ($task === null || $task->getType() === ParsedTask::TYPE_SUBPROJECT) {
			return [];
		}

		$found = [];
		$seen = [$uid => true];
		$this->walk($uid, $seen, $found);

		return $found;
	}

	/**
	 * @param array<string, bool> $seen
	 * @param list<string> $found
	 */
	private function walk(string $uid, array &$seen, array &$found): void {
		foreach ($this->children[$uid] ?? [] as $child) {
			if (isset($seen[$child])) {
				continue;
			}
			$seen[$child] = true;

			$task = $this->byUid[$child] ?? null;
			if ($task === null || $task->getType() === ParsedTask::TYPE_SUBPROJECT) {
				continue;
			}

			$found[] = $child;
			$this->walk($child, $seen, $found);
		}
	}

	/**
	 * @param array<string, Task> $present
	 * @param list<Task> $nested
	 * @param array<string, bool> $emitted
	 */
	private function emit(Task $card, array $present, array &$nested, array &$emitted): void {
		$uid = $card->getUid();
		if (isset($emitted[$uid])) {
			return;
		}

		$emitted[$uid] = true;
		$nested[] = $card;

		foreach ($this->children[$uid] ?? [] as $child) {
			$inColumn = $present[$child] ?? null;
			if ($inColumn !== null) {
				$this->emit($inColumn, $present, $nested, $emitted);
			}
		}
	}

	private function parentOf(string $uid): ?Task {
		$parentUid = ($this->byUid[$uid] ?? null)?->getParentUid();
		if ($parentUid === null || $parentUid === $uid) {
			return null;
		}

		return $this->byUid[$parentUid] ?? null;
	}
}
