<?php

declare(strict_types=1);

namespace OCA\Chronos\Tests\Unit\Board\Service;

use OCA\Chronos\Board\Db\BoardRank;
use OCA\Chronos\Board\Db\BoardRankMapper;
use OCA\Chronos\Board\Service\BoardService;
use OCA\Chronos\Core\Dav\CalDavGateway;
use OCA\Chronos\Core\Dav\CalendarRef;
use OCA\Chronos\Core\Dav\ParsedTask;
use OCA\Chronos\Core\Error\ForbiddenException;
use OCA\Chronos\Core\Error\ValidationFailedException;
use OCA\Chronos\Core\Index\Task;
use OCA\Chronos\Core\Index\TaskLinkMapper;
use OCA\Chronos\Core\Index\TaskMapper;
use OCA\Chronos\Core\Task\StatusColumn;
use OCA\Chronos\Core\Task\StatusColumns;
use OCA\Chronos\Core\Task\StatusGateway;
use OCA\Chronos\Core\Task\TaskService;
use OCA\Chronos\Workflow\Db\StatusChangeMapper;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * What a drop does, and what it refuses to do (§15.6).
 *
 * The database work is covered against a real one in the integration suite;
 * what matters here is the decisions: which writes a drop makes, which ones it
 * does not make, and that a refused move changes nothing at all.
 */
class BoardServiceTest extends TestCase {

	private const USER = 'alice';
	private const REF = 'principals/users/alice/work';
	private const CALENDAR_ID = 11;

	private CalDavGateway&MockObject $calendars;
	private TaskMapper&MockObject $tasks;
	private BoardRankMapper&MockObject $ranks;
	private StatusGateway&MockObject $statuses;
	private TaskService&MockObject $taskService;
	private StatusChangeMapper&MockObject $history;
	private TaskLinkMapper&MockObject $linkMapper;
	private BoardService $board;

	/** @var list<Task> */
	private array $index = [];

	/** @var array<string, BoardRank> */
	private array $stored = [];

	protected function setUp(): void {
		parent::setUp();

		$this->calendars = $this->createMock(CalDavGateway::class);
		$this->tasks = $this->createMock(TaskMapper::class);
		$this->ranks = $this->createMock(BoardRankMapper::class);
		$this->statuses = $this->createMock(StatusGateway::class);
		$this->taskService = $this->createMock(TaskService::class);

		$this->calendars->method('findTaskCalendarByRef')->willReturn($this->calendar());
		$this->statuses->method('columnsFor')->willReturn($this->columns());
		$this->tasks->method('findForCalendars')->willReturnCallback(fn (): array => $this->index);
		$this->tasks->method('findByUid')->willReturnCallback(
			fn (int $calendarId, string $uid): ?Task => $this->find($uid),
		);
		$this->ranks->method('findForCalendar')->willReturnCallback(fn (): array => $this->stored);
		$this->ranks->method('place')->willReturnCallback(
			function (int $calendarId, string $uid, string $columnKey, string $rank): BoardRank {
				return $this->stored[$uid] = $this->rank($uid, $columnKey, $rank);
			},
		);

		$this->history = $this->createMock(StatusChangeMapper::class);
		$this->history->method('findArrivals')->willReturn([]);
		$this->linkMapper = $this->createMock(TaskLinkMapper::class);
		$this->linkMapper->method('findForCalendar')->willReturn([]);

		$this->board = new BoardService(
			$this->calendars,
			$this->tasks,
			$this->ranks,
			$this->statuses,
			$this->taskService,
			$this->history,
			$this->linkMapper,
			$this->createMock(IDBConnection::class),
		);
	}

	public function testABoardIsItsColumnsWithTheCardsInThem(): void {
		$this->index = [
			$this->task('one', 1, 'NEEDS-ACTION'),
			$this->task('two', 2, 'IN-PROCESS'),
			$this->task('three', 2, 'IN-PROCESS'),
		];

		$board = $this->board->board(self::USER, self::REF);

		self::assertSame(['one'], $this->uids($board->column('todo')));
		self::assertSame(['two', 'three'], $this->uids($board->column('doing')));
		self::assertSame([], $board->column('done'));
	}

	/**
	 * A subproject is a container for other tasks rather than a piece of work
	 * (§6), so it is not a card; the tasks inside it are.
	 */
	public function testASubprojectIsNotACard(): void {
		$this->index = [
			$this->task('container', 1, 'NEEDS-ACTION', ParsedTask::TYPE_SUBPROJECT),
			$this->task('real', 1, 'NEEDS-ACTION'),
		];

		self::assertSame(['real'], $this->uids($this->board->board(self::USER, self::REF)->column('todo')));
	}

	/**
	 * Reordering a column is the board's own business: the task has not
	 * changed, so nothing is written to CalDAV and no history row is made.
	 */
	public function testReorderingWithinAColumnDoesNotTouchCalDav(): void {
		$this->index = [$this->task('a', 2, 'IN-PROCESS'), $this->task('b', 2, 'IN-PROCESS')];
		$this->taskService->expects(self::never())->method('update');

		$result = $this->board->move(self::USER, self::REF, [['uid' => 'b']], 'doing', null, 'a');

		self::assertSame(['b'], $this->uids($result->cards));
		self::assertArrayHasKey('b', $result->ranks);
		self::assertLessThan(0, strcmp($result->ranks['b'], $this->stored['a']->getCardRank()));
	}

	public function testADropIntoAnotherColumnWritesTheNewStatus(): void {
		$this->index = [$this->task('a', 1, 'NEEDS-ACTION')];

		$this->taskService->expects(self::once())
			->method('update')
			->willReturnCallback(function (string $userId, string $uid, $write, ?string $etag): Task {
				self::assertSame(self::USER, $userId);
				self::assertSame('a', $uid);
				// The status is named by key, because the project has a
				// workflow; which standard status that key means is the
				// workflow's to decide, so the write still carries the one the
				// task has and the gateway replaces it (§12.1).
				self::assertSame('doing', $write->statusKey);
				self::assertSame('NEEDS-ACTION', $write->status);
				// A move changes the status and nothing else (§15.6).
				self::assertSame('Card a', $write->summary);
				self::assertSame('etag-a', $etag);

				return $this->task('a', 2, 'IN-PROCESS');
			});

		$result = $this->board->move(self::USER, self::REF, [['uid' => 'a']], 'doing');

		self::assertSame(['a'], $this->uids($result->cards));
		self::assertSame('doing', $this->stored['a']->getColumnKey());
	}

	public function testTheEtagTheClientSawIsTheOneTheWriteCarries(): void {
		$this->index = [$this->task('a', 1, 'NEEDS-ACTION')];

		$this->taskService->expects(self::once())
			->method('update')
			->willReturnCallback(function (string $userId, string $uid, $write, ?string $etag): Task {
				self::assertSame('from-the-page', $etag);

				return $this->task('a', 2, 'IN-PROCESS');
			});

		$this->board->move(self::USER, self::REF, [['uid' => 'a', 'etag' => 'from-the-page']], 'doing');
	}

	/**
	 * The acceptance criterion for M3: a column the workflow does not allow is
	 * refused by the server, not only dimmed in the board.
	 */
	public function testAMoveTheRulesDoNotAllowIsRefusedAndWritesNothing(): void {
		$this->statuses = $this->createMock(StatusGateway::class);
		$this->statuses->method('columnsFor')->willReturn(new StatusColumns($this->columns()->columns, [
			['from' => 'todo', 'to' => 'doing', 'requiresComment' => false],
		], 42));
		$this->board = new BoardService(
			$this->calendars,
			$this->tasks,
			$this->ranks,
			$this->statuses,
			$this->taskService,
			$this->history,
			$this->linkMapper,
			$this->createMock(IDBConnection::class),
		);

		$this->index = [$this->task('a', 1, 'NEEDS-ACTION')];
		$this->taskService->expects(self::never())->method('update');
		$this->ranks->expects(self::never())->method('place');

		$this->expectException(ValidationFailedException::class);
		$this->expectExceptionMessage('transition_not_allowed');

		$this->board->move(self::USER, self::REF, [['uid' => 'a']], 'done');
	}

	/**
	 * One card of a bulk move that may not go where it was dropped stops the
	 * whole drop: a board half moved is worse than one not moved at all.
	 */
	public function testABulkMoveIsCheckedBeforeAnyCardIsWritten(): void {
		$this->statuses = $this->createMock(StatusGateway::class);
		$this->statuses->method('columnsFor')->willReturn(new StatusColumns($this->columns()->columns, [
			['from' => 'todo', 'to' => 'done', 'requiresComment' => false],
		], 42));
		$this->board = new BoardService(
			$this->calendars,
			$this->tasks,
			$this->ranks,
			$this->statuses,
			$this->taskService,
			$this->history,
			$this->linkMapper,
			$this->createMock(IDBConnection::class),
		);

		$this->index = [$this->task('a', 1, 'NEEDS-ACTION'), $this->task('b', 2, 'IN-PROCESS')];
		$this->taskService->expects(self::never())->method('update');

		$this->expectException(ValidationFailedException::class);

		$this->board->move(self::USER, self::REF, [['uid' => 'a'], ['uid' => 'b']], 'done');
	}

	public function testCardsDroppedTogetherKeepTheOrderTheyWereSentIn(): void {
		$this->index = [
			$this->task('a', 2, 'IN-PROCESS'),
			$this->task('b', 2, 'IN-PROCESS'),
			$this->task('c', 2, 'IN-PROCESS'),
		];

		$result = $this->board->move(
			self::USER,
			self::REF,
			[['uid' => 'c'], ['uid' => 'b']],
			'doing',
			null,
			'a',
		);

		self::assertLessThan(0, strcmp($result->ranks['c'], $result->ranks['b']));
		self::assertLessThan(0, strcmp($result->ranks['b'], $this->stored['a']->getCardRank()));
	}

	public function testADropOntoAListSharedReadOnlyIsRefused(): void {
		$this->calendars = $this->createMock(CalDavGateway::class);
		$this->calendars->method('findTaskCalendarByRef')->willReturn($this->calendar(writable: false));
		$this->board = new BoardService(
			$this->calendars,
			$this->tasks,
			$this->ranks,
			$this->statuses,
			$this->taskService,
			$this->history,
			$this->linkMapper,
			$this->createMock(IDBConnection::class),
		);

		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage('project_read_only');

		$this->board->move(self::USER, self::REF, [['uid' => 'a']], 'doing');
	}

	public function testADropIntoAColumnThatDoesNotExistIsRefused(): void {
		$this->expectException(ValidationFailedException::class);
		$this->expectExceptionMessage('unknown_status');

		$this->board->move(self::USER, self::REF, [['uid' => 'a']], 'nowhere');
	}

	public function testADropWithNoCardsIsRefused(): void {
		$this->expectException(ValidationFailedException::class);
		$this->expectExceptionMessage('no_cards');

		$this->board->move(self::USER, self::REF, [], 'doing');
	}

	public function testTheSameCardTwiceInOneDropIsRefused(): void {
		$this->index = [$this->task('a', 2, 'IN-PROCESS')];

		$this->expectException(ValidationFailedException::class);
		$this->expectExceptionMessage('invalid_cards');

		$this->board->move(self::USER, self::REF, [['uid' => 'a'], ['uid' => 'a']], 'doing');
	}

	/**
	 * The first drop into a column gives every card in it a rank, in the order
	 * the column was already being shown, so there is something to drop
	 * between. After that a drop writes one row per moved card.
	 */
	public function testTheFirstDropRanksTheWholeColumnAndTheNextOneDoesNot(): void {
		$this->index = [
			$this->task('a', 2, 'IN-PROCESS'),
			$this->task('b', 2, 'IN-PROCESS'),
			$this->task('c', 2, 'IN-PROCESS'),
		];

		$this->board->move(self::USER, self::REF, [['uid' => 'c']], 'doing', 'a', 'b');

		self::assertSame(['a', 'b', 'c'], array_keys($this->stored));
		self::assertLessThan(0, strcmp($this->stored['a']->getCardRank(), $this->stored['c']->getCardRank()));
		self::assertLessThan(0, strcmp($this->stored['c']->getCardRank(), $this->stored['b']->getCardRank()));

		$before = array_map(static fn (BoardRank $rank): string => $rank->getCardRank(), $this->stored);
		$this->board->move(self::USER, self::REF, [['uid' => 'a']], 'doing', 'b', null);

		self::assertSame($before['b'], $this->stored['b']->getCardRank());
		self::assertSame($before['c'], $this->stored['c']->getCardRank());
		self::assertGreaterThan(0, strcmp($this->stored['a']->getCardRank(), $before['b']));
	}

	/**
	 * Naming the card above a drop means landing *directly* below it. Reading
	 * the missing side as "and nothing below it" put the card halfway down
	 * everything that followed, with a rank that could equal one already in
	 * the column (D35).
	 */
	public function testACardDroppedBelowAnotherLandsDirectlyBelowIt(): void {
		$this->index = [
			$this->task('a', 2, 'IN-PROCESS'),
			$this->task('b', 2, 'IN-PROCESS'),
			$this->task('c', 2, 'IN-PROCESS'),
			$this->task('mover', 1, 'NEEDS-ACTION'),
		];
		$this->taskService->method('update')->willReturnCallback(
			fn (string $userId, string $uid): Task => $this->task($uid, 2, 'IN-PROCESS'),
		);

		$result = $this->board->move(self::USER, self::REF, [['uid' => 'mover']], 'doing', 'a', null);

		$rank = $result->ranks['mover'] ?? '';
		self::assertGreaterThan(0, strcmp($rank, $this->stored['a']->getCardRank()));
		self::assertLessThan(0, strcmp($rank, $this->stored['b']->getCardRank()));
	}

	public function testACardDroppedAboveAnotherLandsDirectlyAboveIt(): void {
		$this->index = [
			$this->task('a', 2, 'IN-PROCESS'),
			$this->task('b', 2, 'IN-PROCESS'),
			$this->task('c', 2, 'IN-PROCESS'),
		];

		$result = $this->board->move(self::USER, self::REF, [['uid' => 'c']], 'doing', null, 'b');

		$rank = $result->ranks['c'] ?? '';
		self::assertGreaterThan(0, strcmp($rank, $this->stored['a']->getCardRank()));
		self::assertLessThan(0, strcmp($rank, $this->stored['b']->getCardRank()));
	}

	/**
	 * A drop that names no neighbour at all — an API caller rather than the
	 * board — goes to the end of the column, which is the one position that is
	 * always meant and always free.
	 */
	public function testACardDroppedWithNoNeighboursLandsAtTheEnd(): void {
		$this->index = [
			$this->task('a', 2, 'IN-PROCESS'),
			$this->task('b', 2, 'IN-PROCESS'),
			$this->task('mover', 1, 'NEEDS-ACTION'),
		];
		$this->taskService->method('update')->willReturnCallback(
			fn (string $userId, string $uid): Task => $this->task($uid, 2, 'IN-PROCESS'),
		);

		$result = $this->board->move(self::USER, self::REF, [['uid' => 'mover']], 'doing');

		$rank = $result->ranks['mover'] ?? '';
		self::assertGreaterThan(0, strcmp($rank, $this->stored['b']->getCardRank()));
		self::assertNotSame($this->stored['a']->getCardRank(), $rank);
		self::assertNotSame($this->stored['b']->getCardRank(), $rank);
	}

	/**
	 * Two cards cannot share a rank: a rank is a position, and a position two
	 * cards both claim is decided by the query plan.
	 */
	public function testNoTwoCardsInAColumnEndUpAtTheSameRank(): void {
		$this->index = [
			$this->task('a', 2, 'IN-PROCESS'),
			$this->task('b', 2, 'IN-PROCESS'),
			$this->task('c', 2, 'IN-PROCESS'),
			$this->task('d', 2, 'IN-PROCESS'),
		];

		// The same card dropped over and over, each time just below the first.
		for ($round = 0; $round < 20; $round++) {
			$this->board->move(self::USER, self::REF, [['uid' => 'd']], 'doing', 'a', null);
		}

		$ranks = array_map(static fn (BoardRank $rank): string => $rank->getCardRank(), $this->stored);

		self::assertSame(count($ranks), count(array_unique($ranks)));
	}

	/**
	 * A card named as the neighbour but moved away since — a board read from a
	 * stale page — must not make the drop fail or land on another card's rank.
	 */
	public function testANeighbourThatIsNoLongerInTheColumnStillGivesAPosition(): void {
		$this->index = [$this->task('a', 2, 'IN-PROCESS'), $this->task('b', 2, 'IN-PROCESS')];

		$result = $this->board->move(self::USER, self::REF, [['uid' => 'b']], 'doing', 'gone-elsewhere', null);

		self::assertNotSame('', $result->ranks['b'] ?? '');
		self::assertGreaterThan(0, strcmp($result->ranks['b'] ?? '', $this->stored['a']->getCardRank()));
	}

	/**
	 * A subtask has a status of its own, and a column *is* a status (AD7), so
	 * it is a card of its own — sitting wherever its status puts it, which is
	 * not necessarily where its parent is (§15.9).
	 */
	public function testASubtaskIsACardOfItsOwnAndSaysWhoseItIs(): void {
		$this->index = [
			$this->task('parent', 1, 'NEEDS-ACTION'),
			$this->task('child', 2, 'IN-PROCESS', parentUid: 'parent'),
		];

		$board = $this->board->board(self::USER, self::REF);

		self::assertSame(['parent'], $this->uids($board->column('todo')));
		self::assertSame(['child'], $this->uids($board->column('doing')));
		self::assertSame('parent', $board->tree->factsFor('child')['parent']['uid'] ?? null);
		self::assertSame(
			['total' => 1, 'done' => 0, 'open' => ['doing' => 1]],
			$board->tree->factsFor('parent')['subtasks'],
		);
	}

	/**
	 * Dragging a subtask changes which column it is in and nothing else: it
	 * must not quietly stop being a subtask. The write carries the parent, so
	 * `RELATED-TO;RELTYPE=PARENT` survives a drop.
	 */
	public function testDraggingASubtaskKeepsItsParent(): void {
		$this->index = [
			$this->task('parent', 1, 'NEEDS-ACTION'),
			$this->task('child', 1, 'NEEDS-ACTION', parentUid: 'parent'),
		];

		$this->taskService->expects(self::once())
			->method('update')
			->willReturnCallback(function (string $userId, string $uid, $write): Task {
				self::assertSame('parent', $write->parentUid);

				return $this->task($uid, 2, 'IN-PROCESS', parentUid: 'parent');
			});

		$this->board->move(self::USER, self::REF, [['uid' => 'child']], 'doing');
	}

	/**
	 * Rolled up, the top of a branch stands for the work under it: fifty tasks
	 * with two hundred subtasks is a readable board again (§15.9). The counts
	 * are counted the same way, which is why the choice is made here rather
	 * than in the browser.
	 */
	public function testARolledUpBoardShowsOnlyTheTopOfEachBranch(): void {
		$this->index = [
			$this->task('parent', 1, 'NEEDS-ACTION'),
			$this->task('child', 2, 'IN-PROCESS', parentUid: 'parent'),
			$this->task('grandchild', 2, 'IN-PROCESS', parentUid: 'child'),
			$this->task('other', 2, 'IN-PROCESS'),
		];

		$board = $this->board->board(self::USER, self::REF, rolled: true);

		self::assertSame(['parent'], $this->uids($board->column('todo')));
		self::assertSame(['other'], $this->uids($board->column('doing')));
	}

	/**
	 * A subtask whose parent is in another list has nothing to be folded into,
	 * so a rolled-up board still shows it rather than hiding the work.
	 */
	public function testARolledUpBoardKeepsASubtaskWhoseParentIsNotHere(): void {
		$this->index = [$this->task('orphan', 1, 'NEEDS-ACTION', parentUid: 'in-another-list')];

		$board = $this->board->board(self::USER, self::REF, rolled: true);

		self::assertSame(['orphan'], $this->uids($board->column('todo')));
	}

	/** A task in a subproject is not a subtask, so rolling up does not fold it. */
	public function testRollingUpDoesNotFoldATaskIntoItsSubproject(): void {
		$this->index = [
			$this->task('container', 1, 'NEEDS-ACTION', ParsedTask::TYPE_SUBPROJECT),
			$this->task('inside', 1, 'NEEDS-ACTION', parentUid: 'container'),
		];

		$board = $this->board->board(self::USER, self::REF, rolled: true);

		self::assertSame(['inside'], $this->uids($board->column('todo')));
	}

	/** A column nobody has arranged reads as a tree (§15.9). */
	public function testAColumnNobodyHasArrangedReadsAsATree(): void {
		$this->index = [
			$this->task('a', 2, 'IN-PROCESS'),
			$this->task('a2', 2, 'IN-PROCESS', parentUid: 'a'),
			$this->task('b', 2, 'IN-PROCESS'),
			$this->task('a1', 2, 'IN-PROCESS', parentUid: 'a'),
		];

		$board = $this->board->board(self::USER, self::REF);

		self::assertSame(['a', 'a2', 'a1', 'b'], $this->uids($board->column('doing')));
	}

	/**
	 * Nesting is the order of a column nobody has arranged, never a rule: a
	 * card that was dragged somewhere stays there, because pulling it back
	 * under its parent would silently undo the drag (§15.9).
	 */
	public function testAnArrangedCardStaysWhereItWasPutAwayFromItsParent(): void {
		$this->index = [
			$this->task('parent', 2, 'IN-PROCESS'),
			$this->task('child', 2, 'IN-PROCESS', parentUid: 'parent'),
			$this->task('other', 2, 'IN-PROCESS'),
		];

		// The child is dragged to the top of the column, above its parent.
		$this->board->move(self::USER, self::REF, [['uid' => 'child']], 'doing', null, 'parent');

		$board = $this->board->board(self::USER, self::REF);

		self::assertSame(['child', 'parent', 'other'], $this->uids($board->column('doing')));
	}

	/**
	 * A cascading drop takes the unfinished part of the branch with it, in one
	 * request, and leaves finished subtasks finished: dragging a parent must
	 * never reopen work that is done (§15.9).
	 */
	public function testACascadingDropTakesTheUnfinishedSubtasksWithIt(): void {
		$this->index = [
			$this->task('parent', 1, 'NEEDS-ACTION'),
			$this->task('open', 1, 'NEEDS-ACTION', parentUid: 'parent'),
			$this->task('finished', 3, 'COMPLETED', parentUid: 'parent'),
		];

		$written = [];
		$this->taskService->method('update')->willReturnCallback(
			function (string $userId, string $uid) use (&$written): Task {
				$written[] = $uid;

				return $this->task($uid, 2, 'IN-PROCESS');
			},
		);

		$result = $this->board->move(
			self::USER,
			self::REF,
			[['uid' => 'parent']],
			'doing',
			cascade: true,
		);

		self::assertSame(['parent', 'open'], $written);
		// The subtask lands behind its parent, so the branch stays together.
		self::assertLessThan(0, strcmp($result->ranks['parent'], $result->ranks['open']));
		// The page never saw the subtask, so its undo needs to be told where
		// it came from (§15.6).
		self::assertSame(['parent' => 'todo', 'open' => 'todo'], $result->from);
	}

	/**
	 * The cascade is checked like any other bulk move: a subtask the rules
	 * will not let out of its column stops the parent's drop as well, rather
	 * than moving half a branch.
	 */
	public function testACascadeTheRulesRefuseMovesNothingAtAll(): void {
		$this->statuses = $this->createMock(StatusGateway::class);
		$this->statuses->method('columnsFor')->willReturn(new StatusColumns($this->columns()->columns, [
			['from' => 'todo', 'to' => 'doing', 'requiresComment' => false],
		], 42));
		$this->board = new BoardService(
			$this->calendars,
			$this->tasks,
			$this->ranks,
			$this->statuses,
			$this->taskService,
			$this->history,
			$this->linkMapper,
			$this->createMock(IDBConnection::class),
		);

		$this->index = [
			$this->task('parent', 1, 'NEEDS-ACTION'),
			// Its subtask is already in progress, and nothing may leave there.
			$this->task('child', 2, 'IN-PROCESS', parentUid: 'parent'),
		];
		$this->taskService->expects(self::never())->method('update');

		$this->expectException(ValidationFailedException::class);
		$this->expectExceptionMessage('transition_not_allowed');

		$this->board->move(self::USER, self::REF, [['uid' => 'parent']], 'done', cascade: true);
	}

	/**
	 * Moving a subtask changes how much of its parent's branch is done, so the
	 * parent comes back with the drop: *2 of 5 done* is right without reading
	 * the board again (§15.6).
	 */
	public function testADropSendsBackTheParentWhoseProgressItChanged(): void {
		$this->index = [
			$this->task('parent', 1, 'NEEDS-ACTION'),
			$this->task('child', 1, 'NEEDS-ACTION', parentUid: 'parent'),
		];
		$this->taskService->method('update')->willReturnCallback(
			fn (string $userId, string $uid): Task => $this->task($uid, 3, 'COMPLETED', parentUid: 'parent'),
		);

		$result = $this->board->move(self::USER, self::REF, [['uid' => 'child']], 'done');

		self::assertSame(['child', 'parent'], $this->uids($result->cards));
		// Only the card that moved has a rank and a column it came from.
		self::assertSame(['child'], array_keys($result->from));
	}

	/**
	 * A cascade of more cards than one drop may carry is refused, the same as
	 * a selection that large: a branch that big is a different operation.
	 */
	public function testACascadeBiggerThanOneDropAllowsIsRefused(): void {
		$this->index = [$this->task('parent', 1, 'NEEDS-ACTION')];
		for ($i = 0; $i < 100; $i++) {
			$this->index[] = $this->task('child' . $i, 1, 'NEEDS-ACTION', parentUid: 'parent');
		}
		$this->taskService->expects(self::never())->method('update');

		$this->expectException(ValidationFailedException::class);
		$this->expectExceptionMessage('too_many_cards');

		$this->board->move(self::USER, self::REF, [['uid' => 'parent']], 'doing', cascade: true);
	}

	private function calendar(bool $writable = true): CalendarRef {
		return new CalendarRef(
			self::CALENDAR_ID,
			'work',
			self::REF,
			'Work',
			'#0082c9',
			true,
			false,
			$writable,
		);
	}

	private function columns(): StatusColumns {
		return new StatusColumns([
			new StatusColumn(1, 'todo', 'To do', null, 'NEEDS-ACTION', true, false),
			new StatusColumn(2, 'doing', 'Doing', null, 'IN-PROCESS', false, false),
			new StatusColumn(3, 'done', 'Done', null, 'COMPLETED', false, true),
		], [], 42);
	}

	private function task(
		string $uid,
		?int $statusId,
		?string $vtodoStatus,
		string $type = ParsedTask::TYPE_TASK,
		?string $parentUid = null,
	): Task {
		$task = new Task();
		$task->setUid($uid);
		$task->setSummary('Card ' . $uid);
		$task->setEtag('etag-' . $uid);
		$task->setCalendarId(self::CALENDAR_ID);
		$task->setStatusId($statusId);
		$task->setVtodoStatus($vtodoStatus);
		$task->setType($type);
		$task->setParentUid($parentUid);

		return $task;
	}

	private function find(string $uid): ?Task {
		foreach ($this->index as $task) {
			if ($task->getUid() === $uid) {
				return $task;
			}
		}

		return null;
	}

	private function rank(string $uid, string $columnKey, string $value): BoardRank {
		$rank = new BoardRank();
		$rank->setTaskUid($uid);
		$rank->setColumnKey($columnKey);
		$rank->setCardRank($value);

		return $rank;
	}

	/**
	 * @param list<Task> $cards
	 * @return list<string>
	 */
	private function uids(array $cards): array {
		return array_map(static fn (Task $card): string => $card->getUid(), $cards);
	}
}
