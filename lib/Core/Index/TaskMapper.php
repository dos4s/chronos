<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Index;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Task>
 */
class TaskMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chronos_tasks', Task::class);
	}

	/**
	 * The index row for a CalDAV object. This is the lookup the listener uses,
	 * because an event gives it the calendar and the object URI.
	 */
	/**
	 * Gives the tasks of a calendar the workflow status that matches the
	 * standard status they already have, for the ones that have none yet.
	 *
	 * This and `clearStatuses()` are the only writes of `chronos_tasks` that
	 * are not the indexer's: attaching a workflow to a project would otherwise
	 * leave every existing task without a status until the hourly reconcile
	 * noticed. Nothing that already has a status is touched.
	 *
	 * @param string|null $vtodoStatus null for the tasks that carry no
	 *                                 `STATUS` at all, which most clients
	 *                                 write only once a task is started or
	 *                                 finished — 40 of 229 on the first real
	 *                                 instance, and all of them missed before
	 *                                 (D31)
	 */
	public function fillStatusWhereUnset(int $calendarId, ?string $vtodoStatus, int $statusId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status_id', $qb->createNamedParameter($statusId, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('calendar_id', $qb->createNamedParameter($calendarId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('status_id'));

		$qb->andWhere($vtodoStatus === null
			? $qb->expr()->isNull('vtodo_status')
			: $qb->expr()->eq('vtodo_status', $qb->createNamedParameter($vtodoStatus)));

		$qb->executeStatement();
	}

	/**
	 * Forgets the workflow statuses of a calendar's tasks, for a project whose
	 * workflow has been taken away. The standard status never depended on it.
	 */
	public function clearStatuses(int $calendarId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('calendar_id', $qb->createNamedParameter($calendarId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function findByObject(int $calendarId, string $objectUri): ?Task {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('calendar_id', $qb->createNamedParameter($calendarId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('object_uri', $qb->createNamedParameter($objectUri)));

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * A task by UID within a calendar, which is how tasks refer to each other
	 * (§7): `RELATED-TO` carries a UID, not a URI.
	 */
	public function findByUid(int $calendarId, string $uid): ?Task {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('calendar_id', $qb->createNamedParameter($calendarId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * A task by UID among the projects a user can see, which is how the API
	 * refers to a task: a UID is stable, while an object URI is CalDAV's.
	 *
	 * @param list<int> $calendarIds
	 */
	public function findByUidInCalendars(array $calendarIds, string $uid): ?Task {
		if ($calendarIds === []) {
			return null;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('calendar_id', $qb->createNamedParameter($calendarIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Every live task in the given projects.
	 *
	 * Reads are scoped by calendar rather than by `user_id`, because a shared
	 * list has one index row per task owned by the list's owner, and a
	 * colleague has to see those too (AD11). The caller decides which calendars
	 * the user may read, from the calendars CalDAV reports for them.
	 *
	 * @param list<int> $calendarIds
	 * @return list<Task>
	 */
	public function findForCalendars(array $calendarIds): array {
		if ($calendarIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('calendar_id', $qb->createNamedParameter($calendarIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->orderBy('due_at', 'ASC')
			->addOrderBy('summary', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * The children of a task or subproject, for the tree and for rollups.
	 *
	 * @return list<Task>
	 */
	public function findChildren(int $calendarId, string $parentUid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('calendar_id', $qb->createNamedParameter($calendarId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('parent_uid', $qb->createNamedParameter($parentUid)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->orderBy('due_at', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Object URI → etag for a whole calendar, which is all the reconcile job
	 * needs to spot drift without parsing anything (§11.4).
	 *
	 * @return array<string, string>
	 */
	public function etagsForCalendar(int $calendarId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('object_uri', 'etag')
			->from($this->getTableName())
			->where($qb->expr()->eq('calendar_id', $qb->createNamedParameter($calendarId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'));

		$result = $qb->executeQuery();
		/** @var list<array<string, mixed>> $rows */
		$rows = $result->fetchAll();
		$result->closeCursor();

		$etags = [];
		foreach ($rows as $row) {
			$etags[(string)$row['object_uri']] = (string)$row['etag'];
		}

		return $etags;
	}

	/**
	 * Whether this user has anything in the index yet.
	 *
	 * The reconcile job repairs the users Chronos already knows; a user who
	 * has never opened the app is onboarded by ImportTasksJob instead, so an
	 * instance does not index task lists nobody asked it to.
	 */
	public function hasAnyForUser(string $userId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->setMaxResults(1);

		$result = $qb->executeQuery();
		/** @var array<string, mixed>|false $row */
		$row = $result->fetch();
		$result->closeCursor();

		return $row !== false;
	}

	/**
	 * Marks a task deleted, keeping the row so history and links that point at
	 * it stay meaningful (§10.1).
	 */
	public function softDelete(Task $task, int $deletedAt): Task {
		$task->setDeletedAt($deletedAt);
		$task->setUpdatedAt($deletedAt);
		return $this->update($task);
	}

	/**
	 * Removes every row for a calendar, for a project that no longer exists.
	 */
	public function deleteForCalendar(int $calendarId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('calendar_id', $qb->createNamedParameter($calendarId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
