<?php

declare(strict_types=1);

namespace OCA\Chronos\Workflow\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<StatusChange>
 */
class StatusChangeMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chronos_status_history', StatusChange::class);
	}

	/**
	 * A task's history, newest first.
	 *
	 * @return list<StatusChange>
	 */
	public function findForTask(string $taskUid, int $limit = 100): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('task_uid', $qb->createNamedParameter($taskUid)))
			->orderBy('changed_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults(max(1, min(500, $limit)));

		return $this->findEntities($qb);
	}

	/**
	 * Every change recorded for a task, oldest first: what time in a state is
	 * worked out from (§12.3).
	 *
	 * @return list<StatusChange>
	 */
	public function findTimeline(string $taskUid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('task_uid', $qb->createNamedParameter($taskUid)))
			->orderBy('changed_at', 'ASC')
			->addOrderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Where a set of cards has arrived, for the whole board in one query (§16).
	 *
	 * Only the four fields the age arithmetic needs, because a board of fifty
	 * cards can have hundreds of changes behind it and none of the rest of a
	 * row is read (§12.9).
	 *
	 * @param list<string> $taskUids
	 * @return list<array{taskUid: string, toKey: ?string, toStandard: ?string, changedAt: int}>
	 */
	public function findArrivals(array $taskUids): array {
		if ($taskUids === []) {
			return [];
		}

		$rows = [];
		// A list longer than this is more parameters than some engines take.
		foreach (array_chunk(array_values(array_unique($taskUids)), 900) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('task_uid', 'to_key', 'to_standard', 'changed_at')
				->from($this->getTableName())
				->where($qb->expr()->in(
					'task_uid',
					$qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY),
				));

			$result = $qb->executeQuery();
			/** @var list<array<string, mixed>> $found */
			$found = $result->fetchAll();
			$result->closeCursor();

			foreach ($found as $row) {
				$rows[] = [
					'taskUid' => (string)$row['task_uid'],
					'toKey' => $row['to_key'] === null ? null : (string)$row['to_key'],
					'toStandard' => $row['to_standard'] === null ? null : (string)$row['to_standard'],
					'changedAt' => (int)$row['changed_at'],
				];
			}
		}

		return $rows;
	}

	/**
	 * History outlives the workflow it was recorded under, so a delete only
	 * forgets the ids and keeps the keys (§10.3).
	 */
	public function forgetStatuses(array $statusIds): void {
		if ($statusIds === []) {
			return;
		}

		foreach (['from_status_id', 'to_status_id'] as $column) {
			$qb = $this->db->getQueryBuilder();
			$qb->update($this->getTableName())
				->set($column, $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
				->where($qb->expr()->in(
					$column,
					$qb->createNamedParameter($statusIds, IQueryBuilder::PARAM_INT_ARRAY),
				));
			$qb->executeStatement();
		}
	}
}
