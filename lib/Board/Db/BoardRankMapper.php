<?php

declare(strict_types=1);

namespace OCA\Chronos\Board\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<BoardRank>
 */
class BoardRankMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chronos_board_ranks', BoardRank::class);
	}

	/**
	 * Every rank in a project, by task UID: one query for a whole board, so
	 * drawing a column costs nothing per card (§16, N4).
	 *
	 * @return array<string, BoardRank>
	 */
	public function findForCalendar(int $calendarId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('calendar_id', $qb->createNamedParameter($calendarId, IQueryBuilder::PARAM_INT)));

		$ranks = [];
		foreach ($this->findEntities($qb) as $rank) {
			$ranks[$rank->getTaskUid()] = $rank;
		}

		return $ranks;
	}

	public function find(int $calendarId, string $taskUid): ?BoardRank {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('calendar_id', $qb->createNamedParameter($calendarId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('task_uid', $qb->createNamedParameter($taskUid)));

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Puts a card at a rank in a column, whether or not it had one.
	 */
	public function place(int $calendarId, string $taskUid, string $columnKey, string $cardRank): void {
		$existing = $this->find($calendarId, $taskUid);
		if ($existing !== null) {
			$existing->setColumnKey($columnKey);
			$existing->setCardRank($cardRank);
			$this->update($existing);

			return;
		}

		$rank = new BoardRank();
		$rank->setCalendarId($calendarId);
		$rank->setTaskUid($taskUid);
		$rank->setColumnKey($columnKey);
		$rank->setCardRank($cardRank);

		$this->insert($rank);
	}

	/**
	 * One column's ranks, in the order they put the cards.
	 *
	 * @return list<BoardRank>
	 */
	public function findForColumn(int $calendarId, string $columnKey): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('calendar_id', $qb->createNamedParameter($calendarId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('column_key', $qb->createNamedParameter($columnKey)))
			->orderBy('card_rank', 'ASC')
			->addOrderBy('task_uid', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * The columns whose ranks have grown long enough to be worth rewriting,
	 * for the rebalance job (§15.5).
	 *
	 * @return list<array{calendarId: int, columnKey: string}>
	 */
	public function findLongColumns(int $minLength, int $limit = 50): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('calendar_id')
			->addSelect('column_key')
			->from($this->getTableName())
			->where($qb->expr()->gt(
				$qb->func()->charLength('card_rank'),
				$qb->createNamedParameter($minLength, IQueryBuilder::PARAM_INT),
			))
			->setMaxResults($limit);

		$result = $qb->executeQuery();
		/** @var list<array<string, mixed>> $rows */
		$rows = $result->fetchAll();
		$result->closeCursor();

		return array_map(
			static fn (array $row): array => [
				'calendarId' => (int)$row['calendar_id'],
				'columnKey' => (string)$row['column_key'],
			],
			$rows,
		);
	}

	public function deleteForCalendar(int $calendarId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('calendar_id', $qb->createNamedParameter($calendarId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Forgets a card's place, for a task that no longer exists.
	 */
	public function deleteForTask(int $calendarId, string $taskUid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('calendar_id', $qb->createNamedParameter($calendarId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('task_uid', $qb->createNamedParameter($taskUid)));
		$qb->executeStatement();
	}
}
