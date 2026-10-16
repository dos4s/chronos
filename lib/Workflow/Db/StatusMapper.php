<?php

declare(strict_types=1);

namespace OCA\Chronos\Workflow\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Status>
 */
class StatusMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chronos_statuses', Status::class);
	}

	/**
	 * The statuses of a workflow, in the order they are shown and in the order
	 * a kanban board puts its columns (§12.1).
	 *
	 * @return list<Status>
	 */
	public function findForWorkflow(int $workflowId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('workflow_id', $qb->createNamedParameter($workflowId, IQueryBuilder::PARAM_INT)))
			->orderBy('sort', 'ASC')
			->addOrderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * @param list<int> $workflowIds
	 * @return list<Status>
	 */
	public function findForWorkflows(array $workflowIds): array {
		if ($workflowIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in(
				'workflow_id',
				$qb->createNamedParameter($workflowIds, IQueryBuilder::PARAM_INT_ARRAY),
			))
			->orderBy('workflow_id', 'ASC')
			->addOrderBy('sort', 'ASC');

		return $this->findEntities($qb);
	}

	public function find(int $id): ?Status {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function deleteForWorkflow(int $workflowId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('workflow_id', $qb->createNamedParameter($workflowId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
