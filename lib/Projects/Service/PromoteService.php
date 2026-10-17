<?php

declare(strict_types=1);

namespace OCA\Chronos\Projects\Service;

use OCA\Chronos\Core\Dav\CalDavGateway;
use OCA\Chronos\Core\Dav\CalendarRef;
use OCA\Chronos\Core\Dav\ParsedTask;
use OCA\Chronos\Core\Dav\VTodoWriter;
use OCA\Chronos\Core\Error\ForbiddenException;
use OCA\Chronos\Core\Error\NotFoundException;
use OCA\Chronos\Core\Error\ValidationFailedException;
use OCA\Chronos\Core\Index\Task;
use OCA\Chronos\Core\Index\TaskMapper;
use OCA\Chronos\Core\Task\TaskWrite;
use OCA\Chronos\Projects\ProjectView;
use OCA\Chronos\Projects\PromoteResult;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Promoting a subproject to a list of its own (F2, §6).
 *
 * A subproject is a container VTODO inside a list, which is the right shape
 * until it is not: once it holds forty tasks it wants to be a list, with its
 * own board, its own timeline and its own place in the sidebar. This moves it
 * there and records where it came from (`chronos_projects.parent_project_id`),
 * so the hierarchy survives the move.
 *
 * What it is honest about: **there is no transaction.** CalDAV is one object at
 * a time, so this is one copy and one delete per task, and if something fails
 * half way the tasks that moved have moved. Three things make that safe rather
 * than frightening:
 *
 * - every task is read and checked **before** anything is written, so a
 *   failure half way is a failure of the storage rather than of the data;
 * - the answer says how many moved and how many are left, and running it again
 *   moves the rest — the operation is repeatable;
 * - if nothing moved at all, the empty list it created is removed again, so a
 *   failed promotion leaves no litter.
 *
 * The tasks keep their UIDs, so their history, their dependencies and their
 * time sessions follow them. Only the direct children of the subproject change
 * at all: they lose the parent link that pointed at the container, because the
 * container has become the list they are in.
 */
class PromoteService {

	/** A list this big is a different operation, done in the Tasks app. */
	private const MAX_TASKS = 200;

	public function __construct(
		private CalDavGateway $calendars,
		private TaskMapper $tasks,
		private VTodoWriter $writer,
		private ProjectService $projects,
		private ITimeFactory $timeFactory,
		private IDateTimeZone $dateTimeZone,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @throws NotFoundException|ForbiddenException|ValidationFailedException
	 */
	public function promote(string $userId, string $projectRef, string $uid, ?string $name): PromoteResult {
		$source = $this->requireCalendar($userId, $projectRef);
		if (!$source->isWritable) {
			throw new ForbiddenException('project_read_only');
		}

		$all = $this->tasks->findForCalendars([$source->id]);
		$subproject = $this->requireSubproject($all, $uid);
		$branch = $this->branchOf($uid, $all);

		if (count($branch) > self::MAX_TASKS) {
			throw new ValidationFailedException('too_many_tasks');
		}

		// Everything is read before anything is written: a promotion that
		// cannot read one of its tasks has not started yet.
		$objects = [];
		foreach ($branch as $task) {
			$object = $this->calendars->getObject($source->id, $task->getObjectUri());
			if ($object === null) {
				throw new NotFoundException('task_not_found');
			}
			$objects[$task->getUid()] = $object['calendarData'];
		}

		$target = $this->calendars->createTaskCalendar(
			$userId,
			$this->nameFor($name, $subproject),
			$source->color,
		);

		$moved = 0;
		$failure = null;

		foreach ($branch as $task) {
			try {
				$this->moveOne($source, $target, $task, $objects[$task->getUid()] ?? '', $uid);
				$moved++;
			} catch (Throwable $e) {
				// Stop at the first failure rather than hammering on: what is
				// left is still a valid list, and running this again finishes
				// the job.
				$failure = $e->getMessage();
				$this->logger->warning('Chronos: promoting a subproject stopped part way: ' . $e->getMessage(), [
					'app' => 'chronos',
					'exception' => $e,
				]);
				break;
			}
		}

		if ($moved === 0) {
			// Nothing moved, so the list it would have moved into is litter —
			// and litter created seconds ago, so it goes for good rather than
			// into the calendar trash.
			$this->calendars->deleteCalendar($target->id, true);

			throw new ForbiddenException($failure === null ? 'nothing_to_promote' : 'promote_failed');
		}

		// The container itself has become the list, so it goes — but only once
		// its children are out of it, or they would be orphaned on the way.
		if ($failure === null) {
			$this->forget($source, $subproject);
		}

		return new PromoteResult(
			$this->link($userId, $source, $target),
			$moved,
			count($branch) - $moved,
			$failure,
		);
	}

	/**
	 * One task into the new list: copied first, then removed from the old one,
	 * so a failure between the two leaves a duplicate rather than a hole.
	 *
	 * @throws NotFoundException|ForbiddenException
	 */
	private function moveOne(
		CalendarRef $source,
		CalendarRef $target,
		Task $task,
		string $calendarData,
		string $containerUid,
	): void {
		$data = $calendarData;

		// A direct child of the container loses the parent link: the container
		// has become the list it is in (§6).
		if ($task->getParentUid() === $containerUid) {
			$rewritten = $this->writer->apply(
				$data,
				TaskWrite::withoutParent($task),
				$this->timeFactory->getTime(),
				$this->dateTimeZone->getTimeZone(),
			);
			if ($rewritten !== null) {
				$data = $rewritten;
			}
		}

		$this->calendars->createObject($target->id, $task->getObjectUri(), $data);
		$this->calendars->deleteObject($source->id, $task->getObjectUri());
	}

	/**
	 * The new list, with its settings: the area the old one was in, and the
	 * old one as its parent, which is what keeps the hierarchy (§6).
	 */
	private function link(string $userId, CalendarRef $source, CalendarRef $target): ProjectView {
		return $this->projects->configure(
			$userId,
			$target->ref,
			$this->projects->findRow($userId, $source->ref)?->getAreaId(),
			$source->ref,
			false,
			0,
		);
	}

	private function forget(CalendarRef $source, Task $subproject): void {
		try {
			$this->calendars->deleteObject($source->id, $subproject->getObjectUri());
		} catch (Throwable $e) {
			// The tasks are where they should be; a container left behind is
			// an empty subproject, which is untidy rather than wrong.
			$this->logger->warning('Chronos: could not remove the promoted subproject: ' . $e->getMessage(), [
				'app' => 'chronos',
				'exception' => $e,
			]);
		}
	}

	/**
	 * Everything under the container, however deep, in an order that moves a
	 * parent before its children.
	 *
	 * @param list<Task> $all
	 * @return list<Task>
	 */
	private function branchOf(string $uid, array $all): array {
		$children = [];
		foreach ($all as $task) {
			$parentUid = $task->getParentUid();
			if ($parentUid !== null && $parentUid !== $task->getUid()) {
				$children[$parentUid][] = $task;
			}
		}

		$branch = [];
		$seen = [$uid => true];
		$queue = [$uid];

		while ($queue !== []) {
			$current = array_shift($queue);
			foreach ($children[$current] ?? [] as $child) {
				if (isset($seen[$child->getUid()])) {
					continue;
				}
				$seen[$child->getUid()] = true;
				$branch[] = $child;
				$queue[] = $child->getUid();
			}
		}

		return $branch;
	}

	/**
	 * @param list<Task> $all
	 * @throws NotFoundException|ValidationFailedException
	 */
	private function requireSubproject(array $all, string $uid): Task {
		foreach ($all as $task) {
			if ($task->getUid() !== $uid) {
				continue;
			}
			if ($task->getType() !== ParsedTask::TYPE_SUBPROJECT) {
				// Only a container becomes a list: a task is work, and work
				// does not have a sidebar entry (§6).
				throw new ValidationFailedException('not_a_subproject');
			}

			return $task;
		}

		throw new NotFoundException('task_not_found');
	}

	private function nameFor(?string $name, Task $subproject): string {
		$given = trim($name ?? '');
		if ($given !== '') {
			return mb_substr($given, 0, 100);
		}

		$summary = trim($subproject->getSummary() ?? '');

		return $summary === '' ? 'Promoted list' : mb_substr($summary, 0, 100);
	}

	/**
	 * @throws NotFoundException
	 */
	private function requireCalendar(string $userId, string $projectRef): CalendarRef {
		$calendar = $this->calendars->findTaskCalendarByRef($userId, $projectRef);
		if ($calendar === null) {
			throw new NotFoundException('project_not_found');
		}

		return $calendar;
	}
}
