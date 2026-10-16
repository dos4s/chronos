<?php

declare(strict_types=1);

namespace OCA\Chronos\Tests\Integration;

use OCP\DB\Exception as DbException;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Checks that the migrations actually landed on this instance's database.
 *
 * Nextcloud migrations cannot be rolled back, and the seconds migration is the
 * one irreversible thing Chronos does, so this is the first thing to run after
 * an upgrade: it says in one command whether the schema is what the app
 * expects, on the engine this instance really uses.
 */
class SchemaTest extends TestCase {

	private IDBConnection $db;

	protected function setUp(): void {
		parent::setUp();
		$this->db = Server::get(IDBConnection::class);
	}

	public function testEveryTableExists(): void {
		foreach ([
			'chronos_entries',
			'chronos_pauses',
			'chronos_tasks',
			'chronos_task_links',
			'chronos_areas',
			'chronos_projects',
			'chronos_workflows',
			'chronos_statuses',
			'chronos_transitions',
			'chronos_status_history',
		] as $table) {
			self::assertTrue($this->db->tableExists($table), $table . ' is missing');
		}
	}

	/**
	 * @dataProvider secondColumns
	 */
	public function testTheSecondBasedColumnsAreThere(string $table, string $column): void {
		self::assertTrue($this->canSelect($table, $column), $table . '.' . $column . ' is missing');
	}

	/**
	 * @return list<array{0: string, 1: string}>
	 */
	public static function secondColumns(): array {
		return [
			['chronos_entries', 'start_at'],
			['chronos_entries', 'end_at'],
			['chronos_entries', 'paused_since_at'],
			['chronos_entries', 'paused_seconds'],
			['chronos_entries', 'active_user_id'],
			['chronos_pauses', 'entry_id'],
			['chronos_pauses', 'start_at'],
			['chronos_pauses', 'end_at'],
			['chronos_tasks', 'calendar_id'],
			['chronos_tasks', 'object_uri'],
			['chronos_tasks', 'etag'],
			['chronos_tasks', 'due_is_date'],
			['chronos_task_links', 'source_uid'],
			['chronos_task_links', 'gap_seconds'],
			['chronos_areas', 'sort'],
			['chronos_projects', 'project_uri'],
			['chronos_projects', 'area_id'],
			['chronos_projects', 'parent_project_id'],
			['chronos_projects', 'workflow_id'],
			['chronos_projects', 'archived'],
			['chronos_statuses', 'status_key'],
			['chronos_statuses', 'vtodo_status'],
			['chronos_statuses', 'is_initial'],
			['chronos_statuses', 'auto_timer'],
			['chronos_statuses', 'wip_limit'],
			['chronos_transitions', 'requires_comment'],
			['chronos_status_history', 'task_uid'],
			['chronos_status_history', 'from_key'],
			['chronos_status_history', 'to_key'],
			['chronos_status_history', 'changed_at'],
		];
	}

	/**
	 * The millisecond columns are gone, and so is the pause blob that replaced
	 * them for one release (§8.7).
	 *
	 * @dataProvider droppedColumns
	 */
	public function testTheOldColumnsAreGone(string $column): void {
		self::assertFalse(
			$this->canSelect('chronos_entries', $column),
			'chronos_entries.' . $column . ' should have been dropped',
		);
	}

	/**
	 * @return list<array{0: string}>
	 */
	public static function droppedColumns(): array {
		return [['start_time'], ['end_time'], ['paused_at'], ['paused_duration'], ['pauses_blob']];
	}

	/**
	 * No session may still be recorded in milliseconds. Anything after the
	 * year 5138 in seconds is one.
	 */
	public function testNoSessionIsStillInMilliseconds(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'rows'))
			->from('chronos_entries')
			->where($qb->expr()->gt('start_at', $qb->createNamedParameter(100_000_000_000)));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		self::assertSame(0, $count, 'some sessions still hold millisecond timestamps');
	}

	/**
	 * Every pause belongs to a session that exists.
	 */
	public function testNoPauseIsOrphaned(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('p.id', 'rows'))
			->from('chronos_pauses', 'p')
			->leftJoin('p', 'chronos_entries', 'e', 'p.entry_id = e.id')
			->where($qb->expr()->isNull('e.id'));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		self::assertSame(0, $count, 'some pauses point at a session that no longer exists');
	}

	private function canSelect(string $table, string $column): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select($column)->from($table)->setMaxResults(1);

		try {
			$result = $qb->executeQuery();
			$result->closeCursor();
			return true;
		} catch (DbException) {
			return false;
		}
	}
}
