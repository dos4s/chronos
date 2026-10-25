<?php

declare(strict_types=1);

namespace OCA\Chronos\Time\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Entry>
 */
class EntryMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chronos_entries', Entry::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function find(int $id): Entry {
		$qb = $this->select();
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * The user's running session, if any: the one without an end.
	 */
	public function findActive(string $userId): ?Entry {
		$qb = $this->select();
		$qb->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->isNull('end_at'))
			->orderBy('start_at', 'DESC')
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Every session the user has, newest first.
	 *
	 * @return list<Entry>
	 */
	public function findAllForUser(string $userId, int $limit = 100, int $offset = 0): array {
		$qb = $this->select();
		$qb->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('start_at', 'DESC')
			->setMaxResults($limit)
			->setFirstResult($offset);
		return $this->findEntities($qb);
	}

	/**
	 * Every session overlapping [$fromAt, $toAt), oldest first.
	 *
	 * A running session has no end, so it counts as overlapping whenever it
	 * started before $toAt. This is what analytics aggregate over, instead of
	 * the newest 100 rows (D1).
	 *
	 * @return list<Entry>
	 */
	public function findOverlapping(string $userId, int $fromAt, int $toAt): array {
		$qb = $this->select();
		$qb->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->lt('start_at', $qb->createNamedParameter($toAt, IQueryBuilder::PARAM_INT)))
			->andWhere(
				$qb->expr()->orX(
					$qb->expr()->isNull('end_at'),
					$qb->expr()->gt('end_at', $qb->createNamedParameter($fromAt, IQueryBuilder::PARAM_INT)),
				),
			)
			->orderBy('start_at', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Every session that names a task, by task.
	 *
	 * Worked seconds come from the row's own cached totals rather than from the
	 * pause rows, which `replacePauses` keeps in step with them: there is no
	 * range to clip a pause against here, so the sum is exact and this stays
	 * one query instead of one per task (§16). A running session counts up to
	 * `$now`, minus the pause it is sitting in.
	 *
	 * @return list<array{taskUid: string, seconds: int, sessionCount: int, lastAt: int}>
	 */
	public function totalsByTask(string $userId, int $now): array {
		$qb = $this->db->getQueryBuilder();
		$at = $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT);

		$worked = 'SUM(COALESCE(end_at, ' . $at . ') - start_at - paused_seconds'
			. ' - CASE WHEN paused_since_at IS NULL THEN 0 ELSE ' . $at . ' - paused_since_at END)';

		$qb->select('task_uid')
			->selectAlias($qb->createFunction($worked), 'seconds')
			->selectAlias($qb->createFunction('COUNT(*)'), 'session_count')
			->selectAlias($qb->createFunction('MAX(start_at)'), 'last_at')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->isNotNull('task_uid'))
			->groupBy('task_uid');

		$result = $qb->executeQuery();
		/** @var list<array<string, mixed>> $rows */
		$rows = $result->fetchAll();
		$result->closeCursor();

		$totals = [];
		foreach ($rows as $row) {
			$totals[] = [
				'taskUid' => (string)$row['task_uid'],
				// Never negative: a clock skew or a hand-edited session must
				// not make a task look like it gave time back.
				'seconds' => max(0, (int)$row['seconds']),
				'sessionCount' => (int)$row['session_count'],
				'lastAt' => (int)$row['last_at'],
			];
		}

		return $totals;
	}

	/**
	 * Selects the columns this app knows about, so a column left over from an
	 * earlier schema (such as `pauses_blob`) can never break hydration.
	 */
	private function select(): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->select(...Entry::COLUMNS)->from($this->getTableName());
		return $qb;
	}
}
