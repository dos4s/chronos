<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Index;

use OCA\Chronos\Core\Dav\ParsedLink;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<TaskLink>
 */
class TaskLinkMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chronos_task_links', TaskLink::class);
	}

	/**
	 * Replaces the links a task declares with the ones it declares now.
	 *
	 * Keyed by the task that carried the property, so a `RELATED-TO` the user
	 * removed really disappears instead of lingering.
	 *
	 * @param list<ParsedLink> $links
	 */
	public function replaceForSource(string $userId, int $calendarId, string $sourceUid, array $links): void {
		$this->deleteForSource($calendarId, $sourceUid);

		foreach ($links as $link) {
			$row = new TaskLink();
			$row->setUserId($userId);
			$row->setCalendarId($calendarId);
			$row->setSourceUid($sourceUid);
			$row->setPredecessorUid($link->predecessorUid);
			$row->setSuccessorUid($link->successorUid);
			$row->setReltype($link->reltype);
			$row->setGapSeconds($link->gapSeconds);
			$this->insert($row);
		}
	}

	public function deleteForSource(int $calendarId, string $sourceUid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('calendar_id', $qb->createNamedParameter($calendarId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('source_uid', $qb->createNamedParameter($sourceUid)));
		$qb->executeStatement();
	}

	/**
	 * Every link that touches a task, in either direction.
	 *
	 * @return list<TaskLink>
	 */
	public function findForTask(string $userId, string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere(
				$qb->expr()->orX(
					$qb->expr()->eq('predecessor_uid', $qb->createNamedParameter($uid)),
					$qb->expr()->eq('successor_uid', $qb->createNamedParameter($uid)),
				),
			);

		return $this->findEntities($qb);
	}

	/**
	 * Every link in one task list — what a timeline draws (§12.5).
	 *
	 * Scoped by calendar rather than by user, like every other read of the
	 * index: a shared list's rows belong to its owner, and a colleague has to
	 * see them too (§10.1).
	 *
	 * @return list<TaskLink>
	 */
	public function findForCalendar(int $calendarId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('calendar_id', $qb->createNamedParameter($calendarId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * The links one task declared, which are the ones its own VTODO carries
	 * and therefore the ones a write of that task replaces (§10.1).
	 *
	 * @return list<TaskLink>
	 */
	public function findForSource(int $calendarId, string $sourceUid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('calendar_id', $qb->createNamedParameter($calendarId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('source_uid', $qb->createNamedParameter($sourceUid)))
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	public function deleteForCalendar(int $calendarId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('calendar_id', $qb->createNamedParameter($calendarId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
