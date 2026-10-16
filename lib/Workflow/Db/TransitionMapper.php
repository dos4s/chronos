<?php

declare(strict_types=1);

namespace OCA\Chronos\Workflow\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Transition>
 */
class TransitionMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chronos_transitions', Transition::class);
	}

	/**
	 * @return list<Transition>
	 */
	public function findForWorkflow(int $workflowId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('workflow_id', $qb->createNamedParameter($workflowId, IQueryBuilder::PARAM_INT)))
			->orderBy('from_status_id', 'ASC')
			->addOrderBy('to_status_id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * @param list<int> $workflowIds
	 * @return list<Transition>
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
			));

		return $this->findEntities($qb);
	}

	public function deleteForWorkflow(int $workflowId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('workflow_id', $qb->createNamedParameter($workflowId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
