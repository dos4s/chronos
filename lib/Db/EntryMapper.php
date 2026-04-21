<?php

declare(strict_types=1);

namespace OCA\Chronos\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
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
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		return $this->findEntity($qb);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findActive(string $userId): Entry {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->isNull('end_time'))
			->orderBy('start_time', 'DESC')
			->setMaxResults(1);
		return $this->findEntity($qb);
	}

	/**
	 * @return Entry[]
	 */
	public function findAllForUser(string $userId, int $limit = 100, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('start_time', 'DESC')
			->setMaxResults($limit)
			->setFirstResult($offset);
		return $this->findEntities($qb);
	}
}
