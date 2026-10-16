<?php

declare(strict_types=1);

namespace OCA\Chronos\Projects\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Area>
 */
class AreaMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chronos_areas', Area::class);
	}

	/**
	 * @return list<Area>
	 */
	public function findAll(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('sort', 'ASC')
			->addOrderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	public function find(string $userId, int $id): ?Area {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * The sort value a new area gets: last, so creating one never reshuffles
	 * the ones already there.
	 */
	public function nextSort(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('sort'))
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		$result = $qb->executeQuery();
		/** @var mixed $max */
		$max = $result->fetchOne();
		$result->closeCursor();

		return is_numeric($max) ? ((int)$max) + 1 : 0;
	}
}
