<?php

declare(strict_types=1);

namespace OCA\Chronos\Board\Service;

use OCA\Chronos\Board\BoardView;
use OCA\Chronos\Board\CardOrder;
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
		private IDBConnection $db,
	) {
	}

	/**
	 * One project's board.
	 *
	 * @throws NotFoundException when the project is not one this user can see
	 */
	public function board(string $userId, string $projectRef): BoardView {
		$calendar = $this->requireCalendar($userId, $projectRef);
		$columns = $this->statuses->columnsFor($userId, $projectRef);
		$ranks = $this->ranks->findForCalendar($calendar->id);

		$grouped = [];
		foreach ($columns->columns as $column) {
			$grouped[$column->key] = [];
		}

		foreach ($this->cards($calendar->id) as $card) {
			$column = $columns->columnFor($card);
			if ($column === null) {
				continue;
			}
			$grouped[$column->key][] = $card;
		}

		foreach ($grouped as $key => $cards) {
			$grouped[$key] = CardOrder::sort($cards, $ranks, $key);
		}

		return new BoardView($calendar, $columns, $grouped);
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

		// Every card is checked before any of them is written: a drop the
		// workflow refuses should leave the board as it was, not half moved.
		foreach ($moved as $card) {
			$this->assertAllowed($columns, $target, $card['task']);
		}

		$written = [];
		foreach ($moved as $uid => $card) {
			$written[$uid] = $this->writeStatus($userId, $target, $card['task'], $card['etag'], $comment);
		}

		// Read once, after the statuses have been written, so the column holds
		// what the ranks are about to describe and the counts are the ones the
		// client should show.
		$board = $this->board($userId, $calendar->ref);
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

		return new MoveResult(array_values($written), $ranks, $counts);
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
	 * (§6), so it is not a card; its children are.
	 *
	 * @return list<Task>
	 */
	private function cards(int $calendarId): array {
		return array_values(array_filter(
			$this->tasks->findForCalendars([$calendarId]),
			static fn (Task $task): bool => $task->getType() !== ParsedTask::TYPE_SUBPROJECT,
		));
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

		$after = $afterUid === null ? null : ($ranks[$afterUid] ?? null);
		$before = $beforeUid === null ? null : ($ranks[$beforeUid] ?? null);

		$assigned = $this->sequenceBetween($movedUids, $after, $before);

		// A rank long enough to need rewriting is rewritten now rather than
		// left for the job: sixty drops into the same gap is an afternoon's
		// work on one card, not a year's (§15.5).
		foreach ($assigned as $rank) {
			if (!Rank::isLong($rank)) {
				continue;
			}

			$this->rebalance($calendarId, $columnKey);
			$fresh = $this->ranks->findForCalendar($calendarId);
			$after = $afterUid === null ? null : CardOrder::rankForUid($fresh, $afterUid, $columnKey);
			$before = $beforeUid === null ? null : CardOrder::rankForUid($fresh, $beforeUid, $columnKey);
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
