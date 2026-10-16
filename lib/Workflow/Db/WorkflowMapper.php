<?php

declare(strict_types=1);

namespace OCA\Chronos\Workflow\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Workflow>
 */
class WorkflowMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chronos_workflows', Workflow::class);
	}

	/**
	 * @return list<Workflow>
	 */
	public function findAll(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('name', 'ASC');

		return $this->findEntities($qb);
	}

	public function find(string $userId, int $id): ?Workflow {
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
}
