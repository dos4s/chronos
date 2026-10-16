<?php

declare(strict_types=1);

namespace OCA\Chronos\Projects\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Project>
 */
class ProjectMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chronos_projects', Project::class);
	}

	/**
	 * @return list<Project>
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

	public function findByUri(string $userId, string $projectUri): ?Project {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('project_uri', $qb->createNamedParameter($projectUri)));

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function find(string $userId, int $id): ?Project {
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
	 * Deleting an area leaves its projects alone, in no area (§6).
	 */
	public function clearArea(string $userId, int $areaId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('area_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('area_id', $qb->createNamedParameter($areaId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Deleting a workflow leaves its projects alone, with no workflow: the
	 * tasks keep their standard `STATUS`, which never depended on it (§12.1).
	 */
	public function clearWorkflow(string $userId, int $workflowId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('workflow_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('workflow_id', $qb->createNamedParameter($workflowId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Projects whose parent is this one, which is what stops a chain of
	 * promoted subprojects from forming a loop.
	 *
	 * @return list<Project>
	 */
	public function findChildren(string $userId, int $parentProjectId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('parent_project_id', $qb->createNamedParameter($parentProjectId, IQueryBuilder::PARAM_INT)));

		return $this->findEntities($qb);
	}
}
