<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Index;

use DateTimeZone;
use OCA\Chronos\Core\Dav\ParsedTask;
use OCA\Chronos\Core\Dav\VTodoParser;
use OCA\Chronos\Core\Event\TaskAssignedEvent;
use OCA\Chronos\Core\Event\TaskDatesChangedEvent;
use OCA\Chronos\Core\Event\TaskDeletedEvent;
use OCA\Chronos\Core\Event\TaskIndexedEvent;
use OCA\Chronos\Core\Event\TaskSource;
use OCA\Chronos\Core\Event\TaskStatusChangedEvent;
use OCA\Chronos\Core\Task\StatusGateway;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDateTimeZone;

/**
 * Keeps the index in step with CalDAV, and tells the rest of the app what
 * changed (§11.4).
 *
 * This is the writer of `chronos_tasks` for everything a VTODO says. It
 * compares what a task said before with what it says now, so modules get a
 * `TaskStatusChanged` rather than having to diff rows themselves. The only
 * writes that do not come through here are the two bulk status updates on
 * `TaskMapper`, used when a project gains or loses a workflow.
 */
class TaskIndexer {

	public function __construct(
		private TaskMapper $tasks,
		private TaskLinkMapper $links,
		private VTodoParser $parser,
		private StatusGateway $statuses,
		private IEventDispatcher $dispatcher,
		private ITimeFactory $timeFactory,
		private IDateTimeZone $dateTimeZone,
	) {
	}

	/**
	 * Reads one calendar object into the index.
	 *
	 * @param array<array-key, mixed> $objectData the row Nextcloud hands over
	 *                                            with the event, which already holds everything needed — so the
	 *                                            listener makes no further reads (N5)
	 * @return Task|null null when the object is not a task
	 */
	public function index(
		string $userId,
		int $calendarId,
		string $calendarUri,
		array $objectData,
		TaskSource $source,
	): ?Task {
		$objectUri = (string)($objectData['uri'] ?? '');
		if ($objectUri === '') {
			return null;
		}

		$component = strtolower((string)($objectData['component'] ?? ''));
		$existing = $this->tasks->findByObject($calendarId, $objectUri);

		if ($component !== '' && $component !== 'vtodo') {
			// An event, or a journal: not ours. If it used to be a task, it has
			// stopped being one.
			if ($existing !== null) {
				$this->forget($existing, $source);
			}
			return null;
		}

		$parsed = $this->parser->parse((string)($objectData['calendardata'] ?? ''), $this->timezone());
		if ($parsed === null) {
			if ($existing !== null) {
				$this->forget($existing, $source);
			}
			return null;
		}

		$now = $this->timeFactory->getTime();
		$task = $existing ?? new Task();
		$previous = $existing === null ? null : [
			'status' => $existing->getVtodoStatus(),
			'statusId' => $existing->getStatusId(),
			'startAt' => $existing->getStartAt(),
			'dueAt' => $existing->getDueAt(),
			'assignee' => $existing->getAssignee(),
		];

		$this->apply($task, $parsed, $userId, $calendarId, $calendarUri, $objectUri, (string)($objectData['etag'] ?? ''), $now);

		// Which workflow status the key means is the workflow module's to say,
		// and it is also the module that knows what to do when a client has
		// dropped the property (§7, §12.1).
		$task->setStatusId($this->statuses->resolveForIndex(
			$userId,
			$calendarId,
			$parsed->statusKey,
			$parsed->vtodoStatus,
			$existing?->getStatusId(),
		));

		$task = $existing === null ? $this->tasks->insert($task) : $this->tasks->update($task);

		$this->links->replaceForSource($userId, $calendarId, $parsed->uid, $parsed->links);

		$this->announce($task, $previous, $source);

		return $task;
	}

	/**
	 * Marks the task behind a calendar object deleted (§10.1). Called for a
	 * delete and for a move to the trash, which to Chronos are the same thing:
	 * the task is not there any more, but what is recorded about it stays.
	 */
	public function markDeleted(int $calendarId, string $objectUri, TaskSource $source): void {
		$task = $this->tasks->findByObject($calendarId, $objectUri);
		if ($task === null || $task->getDeletedAt() !== null) {
			return;
		}

		$this->forget($task, $source);
	}

	/**
	 * Moves the index row with the object when it changes calendar, so a task
	 * dragged into another list in the Tasks app lands in the right project.
	 *
	 * @param array<array-key, mixed> $objectData
	 */
	public function move(
		string $userId,
		int $sourceCalendarId,
		int $targetCalendarId,
		string $targetCalendarUri,
		array $objectData,
		TaskSource $source,
	): void {
		$objectUri = (string)($objectData['uri'] ?? '');
		if ($objectUri === '') {
			return;
		}

		$old = $this->tasks->findByObject($sourceCalendarId, $objectUri);
		if ($old !== null) {
			$this->links->deleteForSource($sourceCalendarId, $old->getUid());
			$this->tasks->delete($old);
		}

		$this->index($userId, $targetCalendarId, $targetCalendarUri, $objectData, $source);
	}

	private function forget(Task $task, TaskSource $source): void {
		$this->links->deleteForSource($task->getCalendarId(), $task->getUid());
		$deleted = $this->tasks->softDelete($task, $this->timeFactory->getTime());
		$this->dispatcher->dispatchTyped(new TaskDeletedEvent($deleted, $source));
	}

	private function apply(
		Task $task,
		ParsedTask $parsed,
		string $userId,
		int $calendarId,
		string $calendarUri,
		string $objectUri,
		string $etag,
		int $now,
	): void {
		$task->setUserId($userId);
		$task->setCalendarId($calendarId);
		$task->setCalendarUri($calendarUri);
		$task->setObjectUri($objectUri);
		$task->setEtag($etag);
		$task->setUid($parsed->uid);
		$task->setType($parsed->type);
		$task->setParentUid($parsed->parentUid);
		$task->setSummary($this->clip($parsed->summary, 512));
		$task->setVtodoStatus($parsed->vtodoStatus);
		$task->setPriority($parsed->priority);
		$task->setPercent($parsed->percent);
		$task->setAssignee($this->clip($parsed->assignee, 64));
		$task->setStartAt($parsed->startAt);
		$task->setStartIsDate($parsed->startIsDate);
		$task->setDueAt($parsed->dueAt);
		$task->setDueIsDate($parsed->dueIsDate);
		$task->setCompletedAt($parsed->completedAt);
		$task->setUpdatedAt($now);
		// A task that comes back from the trash is live again.
		$task->setDeletedAt(null);
	}

	/**
	 * @param array{status: string|null, statusId: int|null, startAt: int|null, dueAt: int|null, assignee: string|null}|null $previous
	 */
	private function announce(Task $task, ?array $previous, TaskSource $source): void {
		$this->dispatcher->dispatchTyped(new TaskIndexedEvent($task, $previous === null, $source));

		if ($previous === null) {
			return;
		}

		if ($previous['status'] !== $task->getVtodoStatus() || $previous['statusId'] !== $task->getStatusId()) {
			$this->dispatcher->dispatchTyped(new TaskStatusChangedEvent(
				$task,
				$previous['status'],
				$source,
				$previous['statusId'],
			));
		}

		if ($previous['startAt'] !== $task->getStartAt() || $previous['dueAt'] !== $task->getDueAt()) {
			$this->dispatcher->dispatchTyped(
				new TaskDatesChangedEvent($task, $previous['startAt'], $previous['dueAt'], $source),
			);
		}

		if ($previous['assignee'] !== $task->getAssignee()) {
			$this->dispatcher->dispatchTyped(new TaskAssignedEvent($task, $previous['assignee'], $source));
		}
	}

	private function clip(?string $value, int $length): ?string {
		if ($value === null) {
			return null;
		}
		return mb_substr($value, 0, $length);
	}

	private function timezone(): DateTimeZone {
		return $this->dateTimeZone->getTimeZone();
	}
}
