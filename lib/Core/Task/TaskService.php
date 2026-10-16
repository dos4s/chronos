<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Task;

use OCA\Chronos\Core\Dav\CalDavGateway;
use OCA\Chronos\Core\Dav\CalendarRef;
use OCA\Chronos\Core\Dav\VTodoWriter;
use OCA\Chronos\Core\Error\ForbiddenException;
use OCA\Chronos\Core\Error\NotFoundException;
use OCA\Chronos\Core\Error\ValidationFailedException;
use OCA\Chronos\Core\Event\TaskSource;
use OCA\Chronos\Core\Index\Task;
use OCA\Chronos\Core\Index\TaskIndexer;
use OCA\Chronos\Core\Index\TaskMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ISecureRandom;

/**
 * Reading and writing tasks (§11.1).
 *
 * Reads come from the index, which is fast and already scoped by project.
 * A status change goes through the workflow first, which says what to write
 * and refuses a move the workflow does not allow — or, with no workflow
 * module, leaves the four standard statuses as they are (§12.1).
 *
 * Writes go to CalDAV through the gateway and carry the etag the client based
 * its change on, so two people editing the same task cannot silently overwrite
 * each other. The index then updates itself from the listener, which makes the
 * written task the one every view sees.
 */
class TaskService {

	private const UID_LENGTH = 32;

	public function __construct(
		private CalDavGateway $gateway,
		private VTodoWriter $writer,
		private TaskIndexer $indexer,
		private TaskMapper $tasks,
		private StatusGateway $statuses,
		private ITimeFactory $timeFactory,
		private ISecureRandom $random,
	) {
	}

	/**
	 * The tasks in one project.
	 *
	 * @return list<Task>
	 * @throws NotFoundException when the project is not one this user can see
	 */
	public function listForProject(string $userId, string $projectRef): array {
		$calendar = $this->requireCalendar($userId, $projectRef);
		return $this->tasks->findForCalendars([$calendar->id]);
	}

	/**
	 * One task, by the UID the API refers to it by. Reading it is also what
	 * says whether this user may see it at all.
	 *
	 * @throws NotFoundException
	 */
	public function get(string $userId, string $uid): Task {
		[$task] = $this->requireTask($userId, $uid);

		return $task;
	}

	/**
	 * @throws NotFoundException|ForbiddenException
	 */
	public function create(string $userId, string $projectRef, TaskWrite $write): Task {
		$calendar = $this->requireCalendar($userId, $projectRef);
		$this->requireWritable($calendar);

		$write = $write->withStatus($this->statuses->prepareWrite(
			$userId,
			$calendar->ref,
			null,
			$write->statusKey,
			$write->status,
			$write->comment,
		));

		$uid = $this->random->generate(self::UID_LENGTH, ISecureRandom::CHAR_ALPHANUMERIC);
		$objectUri = $uid . '.ics';
		$now = $this->timeFactory->getTime();
		$calendarData = $this->writer->create($uid, $write, $now);

		$etag = $this->gateway->createObject($calendar->id, $objectUri, $calendarData);

		return $this->indexedTask($userId, $calendar, $objectUri, $etag, $calendarData);
	}

	/**
	 * @param string|null $etag the etag the client's change is based on; the
	 *                          indexed one is used when the client does not say
	 * @throws NotFoundException|ForbiddenException
	 */
	public function update(string $userId, string $uid, TaskWrite $write, ?string $etag = null): Task {
		[$task, $calendar] = $this->requireTask($userId, $uid);
		$this->requireWritable($calendar);

		$current = $this->gateway->getObject($calendar->id, $task->getObjectUri());
		if ($current === null) {
			throw new NotFoundException('task_not_found');
		}

		// The workflow decides what the status becomes, and refuses a move it
		// does not allow, before anything is written (§12.1).
		$write = $write->withStatus($this->statuses->prepareWrite(
			$userId,
			$calendar->ref,
			$task,
			$write->statusKey,
			$write->status,
			$write->comment,
		));

		$calendarData = $this->writer->apply($current['calendarData'], $write, $this->timeFactory->getTime());
		if ($calendarData === null) {
			throw new ValidationFailedException('task_unreadable');
		}

		$newEtag = $this->gateway->updateObject(
			$calendar->id,
			$task->getObjectUri(),
			$calendarData,
			$etag ?? $task->getEtag(),
		);

		return $this->indexedTask($userId, $calendar, $task->getObjectUri(), $newEtag, $calendarData);
	}

	/**
	 * @throws NotFoundException|ForbiddenException
	 */
	public function delete(string $userId, string $uid, ?string $etag = null): void {
		[$task, $calendar] = $this->requireTask($userId, $uid);
		$this->requireWritable($calendar);

		$this->gateway->deleteObject($calendar->id, $task->getObjectUri(), $etag ?? $task->getEtag());

		// The delete event marks the index row deleted; doing it here as well
		// keeps the index right even if that event never arrives.
		$this->indexer->markDeleted($calendar->id, $task->getObjectUri(), TaskSource::Chronos);
	}

	/**
	 * The index row for a task Chronos just wrote.
	 *
	 * The listener indexes the write as it happens, so this usually just reads
	 * the row back. If the event did not arrive, the task is indexed here, so
	 * a write is never invisible to the app that made it.
	 */
	private function indexedTask(
		string $userId,
		CalendarRef $calendar,
		string $objectUri,
		string $etag,
		string $calendarData,
	): Task {
		$task = $this->tasks->findByObject($calendar->id, $objectUri);
		if ($task !== null && $task->getEtag() === $etag && $task->getDeletedAt() === null) {
			return $task;
		}

		$task = $this->indexer->index(
			$userId,
			$calendar->id,
			$calendar->uri,
			[
				'uri' => $objectUri,
				'etag' => $etag,
				'calendardata' => $calendarData,
				'component' => 'vtodo',
			],
			TaskSource::Chronos,
		);

		if ($task === null) {
			throw new ValidationFailedException('task_unreadable');
		}

		return $task;
	}

	/**
	 * @return array{0: Task, 1: CalendarRef}
	 * @throws NotFoundException
	 */
	private function requireTask(string $userId, string $uid): array {
		$calendars = $this->gateway->getTaskCalendars($userId);
		$byId = [];
		foreach ($calendars as $calendar) {
			$byId[$calendar->id] = $calendar;
		}

		$task = $this->tasks->findByUidInCalendars(array_keys($byId), $uid);
		if ($task === null) {
			throw new NotFoundException('task_not_found');
		}

		return [$task, $byId[$task->getCalendarId()]];
	}

	/**
	 * @throws NotFoundException
	 */
	private function requireCalendar(string $userId, string $projectRef): CalendarRef {
		$calendar = $this->gateway->findTaskCalendarByRef($userId, $projectRef);
		if ($calendar === null) {
			throw new NotFoundException('project_not_found');
		}

		return $calendar;
	}

	/**
	 * A list shared read-only is a project to look at, not to write to (AD11).
	 *
	 * @throws ForbiddenException
	 */
	private function requireWritable(CalendarRef $calendar): void {
		if (!$calendar->isWritable) {
			throw new ForbiddenException('project_read_only');
		}
	}
}
