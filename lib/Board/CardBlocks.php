<?php

declare(strict_types=1);

namespace OCA\Chronos\Board;

use OCA\Chronos\Core\Dav\ParsedLink;
use OCA\Chronos\Core\Index\Task;
use OCA\Chronos\Core\Index\TaskLink;

/**
 * What a card is waiting for (§12.9, §12.1).
 *
 * Board tools mark blocked work with a flag somebody sets by hand. Chronos is
 * the one that does not have to ask: the timeline already stores the
 * dependencies, in the VTODO itself (§12.5), so the board can say that a card
 * cannot start yet without anyone remembering to tick a box.
 *
 * What counts as blocked follows the relationship, not a single rule — the same
 * reading the scheduler uses (§12.5):
 *
 * | Relationship | Blocked while the predecessor |
 * |---|---|
 * | `FINISHTOSTART`, `FINISHTOFINISH` | is not finished |
 * | `STARTTOSTART`, `STARTTOFINISH` | has not started |
 * | `DEPENDS-ON` | is not finished |
 *
 * A finished card is never blocked, whatever it depends on: the work is done,
 * and saying otherwise on the board would be noise rather than information.
 *
 * Pure, like `CardAges`: rows in, answers out, tested without a database (§18).
 */
final class CardBlocks {

	private const FINISHED = ['COMPLETED', 'CANCELLED'];

	/**
	 * @param array<string, list<array{uid: string, summary: ?string}>> $waitingOn
	 */
	private function __construct(
		private readonly array $waitingOn,
	) {
	}

	/**
	 * `$tasks` is every task in the list, because a predecessor may be one the
	 * board does not draw — a subtask in the rolled-up view, or a subproject.
	 *
	 * @param list<Task> $tasks
	 * @param list<TaskLink> $links
	 */
	public static function from(array $tasks, array $links): self {
		$byUid = [];
		foreach ($tasks as $task) {
			$byUid[$task->getUid()] = $task;
		}

		$waiting = [];
		foreach ($links as $link) {
			$successor = $byUid[$link->getSuccessorUid()] ?? null;
			$predecessor = $byUid[$link->getPredecessorUid()] ?? null;
			if ($successor === null || $predecessor === null) {
				// A dependency on a task in another list is real but not
				// something this board can speak for (§12.5).
				continue;
			}
			if (self::isFinished($successor) || !self::blocks($link, $predecessor)) {
				continue;
			}

			$uid = $predecessor->getUid();
			$already = array_column($waiting[$successor->getUid()] ?? [], 'uid');
			if (in_array($uid, $already, true)) {
				continue;
			}

			$waiting[$successor->getUid()][] = [
				'uid' => $uid,
				'summary' => $predecessor->getSummary(),
			];
		}

		return new self($waiting);
	}

	private static function blocks(TaskLink $link, Task $predecessor): bool {
		$needsStart = in_array(
			strtoupper($link->getReltype()),
			[ParsedLink::RELTYPE_START_TO_START, ParsedLink::RELTYPE_START_TO_FINISH],
			true,
		);

		return $needsStart ? !self::hasStarted($predecessor) : !self::isFinished($predecessor);
	}

	private static function isFinished(Task $task): bool {
		return in_array($task->getVtodoStatus(), self::FINISHED, true);
	}

	/**
	 * Started means anything but the status every task begins in. A task with
	 * no `STATUS` at all has not been touched, which is the same thing (D31).
	 */
	private static function hasStarted(Task $task): bool {
		$status = $task->getVtodoStatus();

		return $status !== null && $status !== 'NEEDS-ACTION';
	}

	/**
	 * @return list<array{uid: string, summary: ?string}>
	 */
	public function waitingOn(string $uid): array {
		return $this->waitingOn[$uid] ?? [];
	}
}
