<?php

declare(strict_types=1);

namespace OCA\Chronos\Tests\Integration;

use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Proves, on this instance's own database, that two simultaneous check-ins
 * cannot both open a session (D5, AD13).
 *
 * A transaction and a re-check are not enough under MySQL's default isolation,
 * so the guarantee rests on a unique index over `active_user_id`. That index
 * also has to allow many NULLs — one per finished session — which MySQL,
 * PostgreSQL and SQLite all do, but which is worth proving here rather than
 * assuming.
 */
class ActiveSessionConstraintTest extends TestCase {

	private const USER = 'chronos-itest-constraint';

	private IDBConnection $db;

	protected function setUp(): void {
		parent::setUp();
		$this->db = Server::get(IDBConnection::class);
		$this->cleanUp();
	}

	protected function tearDown(): void {
		$this->cleanUp();
		parent::tearDown();
	}

	public function testOneRunningSessionPerUserIsAllowed(): void {
		$id = $this->insert(active: true);

		self::assertGreaterThan(0, $id);
	}

	public function testASecondRunningSessionIsRefusedByTheDatabase(): void {
		$this->insert(active: true);

		try {
			$this->insert(active: true);
			self::fail('the database accepted a second running session');
		} catch (DbException $e) {
			self::assertSame(DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION, $e->getReason());
		}
	}

	public function testFinishedSessionsDoNotCollide(): void {
		// Every finished session has active_user_id NULL; a unique index must
		// allow any number of those.
		$this->insert(active: false);
		$this->insert(active: false);
		$this->insert(active: false);

		self::assertSame(3, $this->count());
	}

	public function testCheckingOutFreesTheUserToCheckInAgain(): void {
		$first = $this->insert(active: true);

		$update = $this->db->getQueryBuilder();
		$update->update('chronos_entries')
			->set('active_user_id', $update->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('end_at', $update->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->where($update->expr()->eq('id', $update->createNamedParameter($first, IQueryBuilder::PARAM_INT)));
		$update->executeStatement();

		$second = $this->insert(active: true);

		self::assertGreaterThan(0, $second);
	}

	private function insert(bool $active): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('chronos_entries')
			->values([
				'user_id' => $qb->createNamedParameter(self::USER),
				'start_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
				'paused_seconds' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'active_user_id' => $active
					? $qb->createNamedParameter(self::USER)
					: $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			]);
		$qb->executeStatement();

		return $qb->getLastInsertId();
	}

	private function count(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'rows'))
			->from('chronos_entries')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::USER)));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	private function cleanUp(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('chronos_entries')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::USER)));
		$qb->executeStatement();
	}
}
