<?php

declare(strict_types=1);

namespace OCA\Chronos\Tests\Integration;

use OCA\Chronos\Core\Error\ConflictException;
use OCA\Chronos\Time\Db\EntryMapper;
use OCA\Chronos\Time\Db\PauseMapper;
use OCA\Chronos\Time\Service\SessionService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * A session's whole life, against the real database: the rules that unit tests
 * check against mocks, checked again where the transactions, the constraints
 * and the column types are the ones this instance actually has.
 */
class SessionLifecycleTest extends TestCase {

	private const USER = 'chronos-itest-session';

	private IDBConnection $db;
	private SessionService $sessions;
	private EntryMapper $entries;
	private PauseMapper $pauses;

	protected function setUp(): void {
		parent::setUp();

		$this->db = Server::get(IDBConnection::class);
		$this->sessions = Server::get(SessionService::class);
		$this->entries = Server::get(EntryMapper::class);
		$this->pauses = Server::get(PauseMapper::class);

		$this->cleanUp();
	}

	protected function tearDown(): void {
		$this->cleanUp();
		parent::tearDown();
	}

	public function testASessionRunsPausesAndEnds(): void {
		$started = $this->sessions->checkIn(self::USER, 'integration run', null, null);
		self::assertNull($started['endAt']);
		self::assertSame('integration run', $started['note']);

		$paused = $this->sessions->pause(self::USER);
		self::assertNotNull($paused['pausedSinceAt']);
		self::assertCount(1, $paused['pauses']);

		$resumed = $this->sessions->resume(self::USER);
		self::assertNull($resumed['pausedSinceAt']);

		$ended = $this->sessions->checkOut(self::USER);
		self::assertNotNull($ended['endAt']);
		self::assertNull($ended['pausedSinceAt']);

		// The cached total always agrees with the rows, which is the invariant
		// that matters. The count is deliberately not asserted here: all of
		// this happens inside one second, and a pause that starts and ends in
		// the same second is not a pause at one-second resolution (§8.1).
		$total = 0;
		foreach ($this->pauses->findForEntry((int)$ended['id']) as $pause) {
			self::assertNotNull($pause->getEndAt(), 'a finished session has no open pause');
			$total += $pause->getEndAt() - $pause->getStartAt();
		}
		self::assertSame($total, $ended['pausedSeconds']);
	}

	public function testASecondCheckInIsRefused(): void {
		$this->sessions->checkIn(self::USER);

		$this->expectException(ConflictException::class);
		$this->sessions->checkIn(self::USER);
	}

	/**
	 * The bug a quick check-out used to have (D2): the pause was added to the
	 * total but never recorded.
	 */
	public function testAQuickCheckOutRecordsItsPause(): void {
		$this->sessions->checkIn(self::USER);
		$this->sessions->pause(self::USER);

		// Long enough for the pause to exist at one-second resolution; without
		// this the pause would open and close in the same second and be
		// dropped, which would hide what this test is about.
		sleep(2);

		$this->sessions->checkOutIfRunning(self::USER);

		$sessions = $this->sessions->listSessions(self::USER, 10, 0);
		self::assertCount(1, $sessions);
		self::assertCount(1, $sessions[0]['pauses']);
		self::assertNotNull($sessions[0]['pauses'][0]['endAt']);
		self::assertNull($sessions[0]['pausedSinceAt']);
		self::assertGreaterThanOrEqual(1, $sessions[0]['pausedSeconds']);
	}

	/**
	 * The gesture the whole of M5 is about: the clock moves from one task to
	 * the next in one call, against the real database and its unique index
	 * over `active_user_id` (D5) — which is what would reject this if the
	 * switch were two calls rather than one transaction.
	 */
	public function testTheClockMovesFromOneTaskToTheNext(): void {
		$first = $this->sessions->switchTo(self::USER, null, null, null, 'itest-task-1', 'The first task');
		self::assertSame('itest-task-1', $first['taskUid']);
		self::assertSame('The first task', $first['taskSummary']);
		self::assertNull($first['endAt']);

		sleep(1);

		$second = $this->sessions->switchTo(self::USER, null, null, null, 'itest-task-2', 'The second task');
		self::assertSame('itest-task-2', $second['taskUid']);
		self::assertNull($second['endAt']);

		// Exactly one session is running, and it is the new one.
		$active = $this->entries->findActive(self::USER);
		self::assertNotNull($active);
		self::assertSame('itest-task-2', $active->getTaskUid());

		// The first one was closed on the way, not left open or deleted.
		$sessions = $this->sessions->listSessions(self::USER, 10, 0);
		self::assertCount(2, $sessions);
		$closed = array_values(array_filter(
			$sessions,
			static fn (array $session): bool => $session['taskUid'] === 'itest-task-1',
		));
		self::assertCount(1, $closed);
		self::assertNotNull($closed[0]['endAt']);
	}

	/**
	 * What a task cost, read back from the column the UI shows it from (§16).
	 */
	public function testWhatATaskCostIsCountedPerTask(): void {
		$this->sessions->switchTo(self::USER, null, null, null, 'itest-task-1', 'The first task');
		sleep(2);
		$this->sessions->checkOut(self::USER);

		$totals = [];
		foreach ($this->sessions->taskTotals(self::USER) as $total) {
			$totals[$total['taskUid']] = $total;
		}

		self::assertArrayHasKey('itest-task-1', $totals);
		self::assertGreaterThanOrEqual(2, $totals['itest-task-1']['seconds']);
		self::assertSame(1, $totals['itest-task-1']['sessionCount']);
	}

	public function testASessionWithoutATaskIsNotCountedAgainstOne(): void {
		$this->sessions->checkIn(self::USER, 'no task here');
		$this->sessions->checkOut(self::USER);

		self::assertSame([], $this->sessions->taskTotals(self::USER));
	}

	public function testEditingASessionMergesOverlappingPauses(): void {
		$session = $this->sessions->checkIn(self::USER);
		$id = (int)$session['id'];
		$start = (int)$session['startAt'];

		$edited = $this->sessions->updateSession(
			self::USER,
			$id,
			$start,
			$start + 3600,
			[
				['start' => $start + 600, 'end' => $start + 1200],
				['start' => $start + 1100, 'end' => $start + 1800],
			],
			'edited in an integration test',
		);

		self::assertCount(1, $edited['pauses']);
		self::assertSame(1200, $edited['pausedSeconds']);
	}

	public function testDeletingASessionTakesItsPausesWithIt(): void {
		$session = $this->sessions->checkIn(self::USER);
		$this->sessions->pause(self::USER);
		$id = (int)$session['id'];

		$this->sessions->deleteSession(self::USER, $id);

		self::assertSame([], $this->pauses->findForEntry($id));
		self::assertSame([], $this->entries->findAllForUser(self::USER, 10, 0));
	}

	private function cleanUp(): void {
		$ids = [];
		foreach ($this->entries->findAllForUser(self::USER, 500, 0) as $entry) {
			$ids[] = $entry->getId();
		}

		foreach ($ids as $id) {
			$this->pauses->deleteForEntry($id);
		}

		if ($ids !== []) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chronos_entries')
				->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}
	}
}
