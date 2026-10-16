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

		// The pause survived as a row, and the total agrees with it.
		$stored = $this->pauses->findForEntry((int)$ended['id']);
		self::assertCount(1, $stored);
		self::assertNotNull($stored[0]->getEndAt());
		self::assertSame(
			$stored[0]->getEndAt() - $stored[0]->getStartAt(),
			$ended['pausedSeconds'],
		);
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

		$this->sessions->checkOutIfRunning(self::USER);

		$sessions = $this->sessions->listSessions(self::USER, 10, 0);
		self::assertCount(1, $sessions);
		self::assertCount(1, $sessions[0]['pauses']);
		self::assertNotNull($sessions[0]['pauses'][0]['endAt']);
		self::assertNull($sessions[0]['pausedSinceAt']);
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
