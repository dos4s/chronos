<?php

declare(strict_types=1);

namespace OCA\Chronos\Board\Service;

use OCA\Chronos\Board\BoardView;
use OCA\Chronos\Board\CardAges;
use OCA\Chronos\Board\CardOrder;
use OCA\Chronos\Board\CardTree;
use OCA\Chronos\Board\Db\BoardRankMapper;
use OCA\Chronos\Board\MoveResult;
use OCA\Chronos\Board\Rank;
use OCA\Chronos\Core\Dav\CalDavGateway;
use OCA\Chronos\Core\Dav\CalendarRef;
use OCA\Chronos\Core\Dav\ParsedTask;
use OCA\Chronos\Core\Error\ForbiddenException;
use OCA\Chronos\Core\Error\NotFoundException;
use OCA\Chronos\Core\Error\ValidationFailedException;
use OCA\Chronos\Core\Index\Task;
use OCA\Chronos\Core\Index\TaskMapper;
use OCA\Chronos\Core\Task\StatusColumn;
use OCA\Chronos\Core\Task\StatusColumns;
use OCA\Chronos\Core\Task\StatusGateway;
use OCA\Chronos\Core\Task\TaskService;
use OCA\Chronos\Core\Task\TaskWrite;
use OCA\Chronos\Workflow\Db\StatusChangeMapper;
use OCP\IDBConnection;
use Throwable;

/**
 * The kanban board: reading one, and moving cards on it (§12.1, §15).
 *
 * A board is the project's statuses as columns and its tasks as cards, so the
 * only thing here that is the board's own is the **order within a column**,
 * which lives in `chronos_board_ranks` (§15.5). Everything else is read from
 * the index and written through the task layer, which means a drop is the same
 * write as any other status change: it goes to CalDAV, the workflow refuses a
 * move it does not allow, and the history records who moved it.
 *
 * Which columns exist is asked of `StatusGateway` (§5), so this class never
 * learns whether the project has a workflow: with one, the columns are its
 * statuses; without, the four standard ones.
 *
 * The one thing a board is not is flat, and a task list is a tree. A subtask
 * has a status of its own, so it is a card of its own; what `CardTree` adds is
 * that every card says whose subtask it is and how much of its own branch is
 * done, that a column nobody has arranged reads as a tree, and that a drop can
 * take a card's unfinished subtasks with it (§15.9).
 */
class BoardService {

	/** More than this in one drop is a client doing something else (§15.6). */
	private const MAX_CARDS_PER_MOVE = 100;

	public function __construct(
		private CalDavGateway $calendars,
		private TaskMapper $tasks,
		private BoardRankMapper $ranks,
		private StatusGateway $statuses,
		private TaskService $taskService,
		private StatusChangeMapper $history,
		private IDBConnection $db,
	) {
	}

	/**
	 * One project's board.
	 *
	 * `$rolled` is the subtask view (§15.9): with it, only the top of each
	 * branch is a card and a parent stands for the work under it, which is
	 * what makes a board of fifty tasks and two hundred subtasks readable.
	 * The choice belongs here rather than in the browser because the column
	 * counts, the WIP warnings and the ranks all come from this method — a
	 * board that hid cards afterwards would show counts that no longer
	 * described what was on the screen.
	 *
	 * @throws NotFoundException when the project is not one this user can see
	 */
	public function board(string $userId, string $projectRef, bool $rolled = false): BoardView {
		$calendar = $this->requireCalendar($userId, $projectRef);
		$columns = $this->statuses->columnsFor($userId, $projectRef);
		$ranks = $this->ranks->findForCalendar($calendar->id);

		$all = $this->tasks->findForCalendars([$calendar->id]);
		$tree = new CardTree($all, $columns);

		$grouped = [];
		foreach ($columns->columns as $column) {
			$grouped[$column->key] = [];
		}

		$columnOf = [];
		$onBoard = [];
		foreach ($this->cards($all, $tree, $rolled) as $card) {
			$column = $columns->columnFor($card);
			if ($column === null) {
				continue;
			}
			$grouped[$column->key][] = $card;
			$columnOf[$card->getUid()] = $column;
			$onBoard[] = $card;
		}

		foreach ($grouped as $key => $cards) {
			// Nesting first and ranking second is what keeps an arrangement an
			// arrangement: the ranks decide where a dragged card sits, and the
			// tree only decides the order of the cards nobody has dragged
			// (§15.9).
			$grouped[$key] = CardOrder::sort($tree->nest($cards), $ranks, $key);
		}

		// How long each card has been where it is (§12.9), in one more query
		// for the whole board.
		$ages = CardAges::from(
			$onBoard,
			$columnOf,
			$this->history->findArrivals(array_keys($columnOf)),
		);

		return new BoardView($calendar, $columns, $grouped, $tree, $ages);
	}

	/**
	 * Moves cards to a column and to a position in it: one drop, one request,
	 * however many cards were dragged (§15.6).
	 *
	 * `$afterUid` is the card the drop landed below and `$beforeUid` the one it
	 * landed above; either being null means an end of the column. They are
	 * sent rather than an index because they are what the user actually
	 * pointed at: a card that someone else has moved in the meantime changes
	 * what position 3 means, but not what "under this card" means.
	 *
	 * The status is written first, card by card, because that is the part that
	 * can be refused — a transition the workflow does not allow, a status that
	 * needs an assignee, an etag that has moved on. The ranks follow in one
	 * transaction once every status has been written.
	 *
	 * `$cascade` takes each card's unfinished subtasks along with it, which is
	 * what a rolled-up card means and what the board offers when a parent is
	 * dropped into a final column (§15.9). The server works out which cards
	 * those are, because the page may not be showing them.
	 *
	 * @param list<array{uid: string, etag?: string|null}> $cards in the order they should end up
	 * @throws NotFoundException|ForbiddenException|ValidationFailedException
	 */
	public function move(
		string $userId,
		string $projectRef,
		array $cards,
		string $columnKey,
		?string $afterUid = null,
		?string $beforeUid = null,
		?string $comment = null,
		bool $rolled = false,
		bool $cascade = false,
	): MoveResult {
		$calendar = $this->requireCalendar($userId, $projectRef);
		if (!$calendar->isWritable) {
			throw new ForbiddenException('project_read_only');
		}

		$columns = $this->statuses->columnsFor($userId, $projectRef);
		$target = $columns->byKey($columnKey);
		if ($target === null) {
			throw new ValidationFailedException('unknown_status');
		}

		$moved = $this->requireCards($calendar->id, $cards);
		if ($cascade) {
			$moved = $this->withSubtasks($calendar->id, $columns, $moved, $columnKey);
		}

		// Every card is checked before any of them is written: a drop the
		// workflow refuses should leave the board as it was, not half moved.
		// A cascade is checked the same way, so a subtask the rules will not
		// let out of its column stops the parent's drop too.
		foreach ($moved as $card) {
			$this->assertAllowed($columns, $target, $card['task']);
		}

		// Where each card was, before anything is written: an undo of a card
		// the page never dragged has nothing else to go on (§15.6).
		$from = [];
		foreach ($moved as $uid => $card) {
			$was = $columns->columnFor($card['task']);
			if ($was !== null && $was->key !== $columnKey) {
				$from[$uid] = $was->key;
			}
		}

		$written = [];
		foreach ($moved as $uid => $card) {
			$written[$uid] = $this->writeStatus($userId, $target, $card['task'], $card['etag'], $comment);
		}

		// Read once, after the statuses have been written, so the column holds
		// what the ranks are about to describe and the counts are the ones the
		// client should show.
		$board = $this->board($userId, $calendar->ref, $rolled);
		$movedUids = array_keys($written);
		$others = array_values(array_filter(
			$board->column($columnKey),
			static fn (Task $card): bool => !in_array($card->getUid(), $movedUids, true),
		));

		$ranks = $this->rankCards($calendar->id, $columnKey, $others, $movedUids, $afterUid, $beforeUid);

		$counts = [];
		foreach ($columns->columns as $column) {
			$counts[$column->key] = count($board->column($column->key));
		}

		return new MoveResult(
			array_merge(array_values($written), $this->progressChanged($board, $written)),
			$ranks,
			$counts,
			$from,
			$board->tree,
		);
	}

	/**
	 * Rewrites a column's ranks evenly, keeping the order they describe.
	 *
	 * Dropping card after card into the same gap makes each rank a character
	 * longer than the last; this is what stops that from mattering, both from
	 * the background job (§15.5) and in the middle of a drop that would
	 * otherwise write a very long rank.
	 *
	 * @return int how many ranks were rewritten
	 */
	public function rebalance(int $calendarId, string $columnKey): int {
		$rows = $this->ranks->findForColumn($calendarId, $columnKey);
		if ($rows === []) {
			return 0;
		}

		$fresh = Rank::sequence(count($rows));

		$this->db->beginTransaction();
		try {
			foreach ($rows as $index => $row) {
				$row->setCardRank($fresh[$index]);
				$this->ranks->update($row);
			}
			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		return count($rows);
	}

	/**
	 * The cards of a project: its tasks, as the board shows them.
	 *
	 * A subproject is a container for other tasks rather than a piece of work
	 * (§6), so it is not a card; its children are. A subtask *is* a card,
	 * because it has a status of its own — unless the board is rolled up, when
	 * the top of each branch stands for the whole of it (§15.9). A subtask
	 * whose parent is not in this list has nothing to be folded into and stays
	 * a card either way.
	 *
	 * @param list<Task> $all every task of the project, subprojects included
	 * @return list<Task>
	 */
	private function cards(array $all, CardTree $tree, bool $rolled): array {
		return array_values(array_filter(
			$all,
			static fn (Task $task): bool => $task->getType() !== ParsedTask::TYPE_SUBPROJECT
				&& !($rolled && $tree->isSubtask($task)),
		));
	}

	/**
	 * The cards a drop changed without moving them: the parents whose branch
	 * is now more, or less, finished than it was (§15.9).
	 *
	 * Sending them back with the moved ones is what keeps *2 of 5 done* right
	 * without reading the board again, which is the one request a drop is
	 * allowed (§15.6).
	 *
	 * @param array<string, Task> $written the cards the drop moved, by UID
	 * @return list<Task>
	 */
	private function progressChanged(BoardView $board, array $written): array {
		$also = [];

		foreach (array_keys($written) as $uid) {
			foreach ($board->tree->ancestorsOf($uid) as $ancestor) {
				$task = $board->tree->task($ancestor);
				if (isset($written[$ancestor]) || isset($also[$ancestor]) || $task === null) {
					continue;
				}
				if ($task->getType() === ParsedTask::TYPE_SUBPROJECT) {
					continue;
				}
				$also[$ancestor] = $task;
			}
		}

		return array_values($also);
	}

	/**
	 * A drop with its cards' unfinished subtasks added behind them (§15.9).
	 *
	 * The page does not have to know what they are — in a rolled-up board it
	 * is not even showing them — so the branch is read here. A subtask that is
	 * already finished stays finished: dragging a parent must not reopen work
	 * that is done.
	 *
	 * @param array<string, array{task: Task, etag: string|null}> $moved
	 * @return array<string, array{task: Task, etag: string|null}>
	 * @throws ValidationFailedException
	 */
	private function withSubtasks(
		int $calendarId,
		StatusColumns $columns,
		array $moved,
		string $columnKey,
	): array {
		$tree = new CardTree($this->tasks->findForCalendars([$calendarId]), $columns);

		$withBranch = [];
		foreach ($moved as $uid => $card) {
			$withBranch[$uid] = $card;

			foreach ($tree->openDescendantsFor($uid, $columnKey) as $descendant) {
				$task = $tree->task($descendant);
				if ($task === null || isset($moved[$descendant]) || isset($withBranch[$descendant])) {
					continue;
				}
				// The page never saw these cards, so there is no etag of its
				// own to carry: the write uses the one the server holds.
				$withBranch[$descendant] = ['task' => $task, 'etag' => null];
			}
		}

		if (count($withBranch) > self::MAX_CARDS_PER_MOVE) {
			throw new ValidationFailedException('too_many_cards');
		}

		return $withBranch;
	}

	/**
	 * Whether this card may go to this column at all.
	 *
	 * The workflow is the authority and says so again when the write is
	 * prepared; asking here is what makes a refused bulk move change nothing.
	 *
	 * @throws ValidationFailedException
	 */
	private function assertAllowed(StatusColumns $columns, StatusColumn $target, Task $task): void {
		$from = $columns->columnFor($task);
		if ($from === null || $from->key === $target->key) {
			return;
		}

		if (!$columns->allows($from->key, $target->key)) {
			throw new ValidationFailedException('transition_not_allowed');
		}
	}

	/**
	 * Writes one card's new status, or leaves the task alone when the drop did
	 * not change which column it is in — reordering a column is the board's
	 * own business and has no business touching CalDAV.
	 *
	 * @throws NotFoundException|ForbiddenException|ValidationFailedException
	 */
	private function writeStatus(
		string $userId,
		StatusColumn $target,
		Task $task,
		?string $etag,
		?string $comment,
	): Task {
		if ($task->getStatusId() === $target->statusId
			&& ($target->statusId !== null || $task->getVtodoStatus() === $target->vtodoStatus)) {
			return $task;
		}

		// With a workflow the status is named by key; without one there are no
		// keys to name, and the standard status is the status (§12.1).
		$write = TaskWrite::fromTask(
			$task,
			$target->statusId === null ? $target->vtodoStatus : null,
			$target->statusId === null ? null : $target->key,
			$comment,
		);

		return $this->taskService->update($userId, $task->getUid(), $write, $etag ?? $task->getEtag());
	}

	/**
	 * Gives the moved cards their place in the column, in one transaction.
	 *
	 * The cards already in the column need ranks of their own before anything
	 * can be put between two of them, so the first drop into a column ranks
	 * all of it, in the order it was already being shown. After that a drop
	 * writes one row per moved card and nothing else.
	 *
	 * @param list<Task> $others the column without the moved cards, in order
	 * @param list<string> $movedUids
	 * @return array<string, string> task UID → rank
	 */
	private function rankCards(
		int $calendarId,
		string $columnKey,
		array $others,
		array $movedUids,
		?string $afterUid,
		?string $beforeUid,
	): array {
		$ranks = $this->materialise($calendarId, $columnKey, $others);
		$order = array_map(static fn (Task $card): string => $card->getUid(), $others);

		[$after, $before] = $this->gap($order, $ranks, $afterUid, $beforeUid);

		$assigned = $this->sequenceBetween($movedUids, $after, $before);

		// A rank long enough to need rewriting is rewritten now rather than
		// left for the job: sixty drops into the same gap is an afternoon's
		// work on one card, not a year's (§15.5).
		foreach ($assigned as $rank) {
			if (!Rank::isLong($rank)) {
				continue;
			}

			$this->rebalance($calendarId, $columnKey);
			$stored = $this->ranks->findForCalendar($calendarId);
			$fresh = [];
			foreach ($order as $uid) {
				$rank = CardOrder::rankForUid($stored, $uid, $columnKey);
				if ($rank !== null) {
					$fresh[$uid] = $rank;
				}
			}

			[$after, $before] = $this->gap($order, $fresh, $afterUid, $beforeUid);
			$assigned = $this->sequenceBetween($movedUids, $after, $before);
			break;
		}

		$this->db->beginTransaction();
		try {
			foreach ($assigned as $uid => $rank) {
				$this->ranks->place($calendarId, $uid, $columnKey, $rank);
			}
			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		return $assigned;
	}

	/**
	 * The gap a drop landed in, as two ranks.
	 *
	 * The client names the cards above and below the drop, but it does not
	 * have to name both, and a card it named may have been moved away since.
	 * The missing side is taken from the column itself, because the column is
	 * right here: naming the card above a drop means landing *directly* below
	 * it, and naming neither means the end of the column.
	 *
	 * Reading one side as "and nothing below it" instead would put the card
	 * halfway down everything that follows — which is where the live check
	 * found it, with a rank that could equal a rank already in the column
	 * (D35).
	 *
	 * @param list<string> $order the column's cards, in order, without the moved ones
	 * @param array<string, string> $ranks their ranks
	 * @return array{0: string|null, 1: string|null}
	 */
	private function gap(array $order, array $ranks, ?string $afterUid, ?string $beforeUid): array {
		$at = $afterUid === null ? false : array_search($afterUid, $order, true);
		if ($at !== false) {
			return [$ranks[$order[$at]] ?? null, $ranks[$order[$at + 1] ?? ''] ?? null];
		}

		$at = $beforeUid === null ? false : array_search($beforeUid, $order, true);
		if ($at !== false) {
			return [$at === 0 ? null : ($ranks[$order[$at - 1]] ?? null), $ranks[$order[$at]] ?? null];
		}

		// Neither card is in this column: the one named has been moved away,
		// or the caller named none at all. Either way the end of the column is
		// the one position that is always meant and always free.
		$last = $order === [] ? null : ($ranks[$order[count($order) - 1]] ?? null);

		return [$last, null];
	}

	/**
	 * Ranks for the moved cards, in order, inside one gap.
	 *
	 * @param list<string> $uids
	 * @return array<string, string>
	 */
	private function sequenceBetween(array $uids, ?string $after, ?string $before): array {
		$assigned = [];
		$previous = $after;

		foreach ($uids as $uid) {
			$rank = Rank::between($previous, $before);
			$assigned[$uid] = $rank;
			$previous = $rank;
		}

		return $assigned;
	}

	/**
	 * Makes sure every card in a column has a rank, and returns them all.
	 *
	 * @param list<Task> $cards in the order the column is shown in
	 * @return array<string, string> task UID → rank
	 */
	private function materialise(int $calendarId, string $columnKey, array $cards): array {
		$stored = $this->ranks->findForCalendar($calendarId);

		$ranks = [];
		$missing = false;
		foreach ($cards as $card) {
			$rank = CardOrder::rankOf($card, $stored, $columnKey);
			if ($rank === null) {
				$missing = true;
				break;
			}
			$ranks[$card->getUid()] = $rank;
		}

		if (!$missing) {
			return $ranks;
		}

		$fresh = Rank::sequence(count($cards));
		$ranks = [];

		$this->db->beginTransaction();
		try {
			foreach ($cards as $index => $card) {
				$this->ranks->place($calendarId, $card->getUid(), $columnKey, $fresh[$index]);
				$ranks[$card->getUid()] = $fresh[$index];
			}
			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		return $ranks;
	}

	/**
	 * The tasks named by a move, by UID, in the order they were sent.
	 *
	 * @param list<array{uid: string, etag?: string|null}> $cards
	 * @return array<string, array{task: Task, etag: string|null}>
	 * @throws NotFoundException|ValidationFailedException
	 */
	private function requireCards(int $calendarId, array $cards): array {
		if ($cards === []) {
			throw new ValidationFailedException('no_cards');
		}
		if (count($cards) > self::MAX_CARDS_PER_MOVE) {
			throw new ValidationFailedException('too_many_cards');
		}

		$found = [];
		foreach ($cards as $card) {
			$uid = trim($card['uid']);
			if ($uid === '' || isset($found[$uid])) {
				throw new ValidationFailedException('invalid_cards');
			}

			$task = $this->tasks->findByUid($calendarId, $uid);
			if ($task === null) {
				throw new NotFoundException('task_not_found');
			}

			$etag = $card['etag'] ?? null;
			$found[$uid] = ['task' => $task, 'etag' => $etag === '' ? null : $etag];
		}

		return $found;
	}

	/**
	 * @throws NotFoundException
	 */
	private function requireCalendar(string $userId, string $projectRef): CalendarRef {
		$calendar = $this->calendars->findTaskCalendarByRef($userId, $projectRef);
		if ($calendar === null) {
			throw new NotFoundException('project_not_found');
		}

		return $calendar;
	}
}
