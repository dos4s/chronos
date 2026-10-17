<?php

declare(strict_types=1);

namespace OCA\Chronos\Workflow\Service;

use OCA\Chronos\Core\Dav\CalDavGateway;
use OCA\Chronos\Core\Error\ValidationFailedException;
use OCA\Chronos\Core\Index\Task;
use OCA\Chronos\Core\Task\StandardStatuses;
use OCA\Chronos\Core\Task\StatusColumn;
use OCA\Chronos\Core\Task\StatusColumns;
use OCA\Chronos\Core\Task\StatusGateway;
use OCA\Chronos\Core\Task\StatusWrite;
use OCA\Chronos\Workflow\Db\Status;
use OCA\Chronos\Workflow\WorkflowView;

/**
 * The workflow module's answers to the task layer's questions (§12.1).
 *
 * Two rules run through all of it. A status always maps to one standard
 * `STATUS`, so every other client sees something sensible; and the Chronos
 * tables are the authority on which workflow status a task is in, so a client
 * that drops `X-CHRONOS-STATUS` loses nothing.
 */
class WorkflowStatusGateway implements StatusGateway {

	/** @var array<int, WorkflowView|null> calendar id → its project's workflow */
	private array $byCalendar = [];

	/**
	 * The note for the status change this request is making, and the task it
	 * belongs to — null while the task is being created, since its UID does
	 * not exist yet. Nextcloud writes and indexes within one request, so this
	 * reaches the history listener and nothing else.
	 */
	private ?string $note = null;
	private ?string $noteUid = null;

	public function __construct(
		private WorkflowService $workflows,
		private CalDavGateway $calendars,
	) {
	}

	public function resolveForIndex(
		string $userId,
		int $calendarId,
		?string $statusKey,
		?string $vtodoStatus,
		?int $currentStatusId,
	): ?int {
		$view = $this->forCalendar($userId, $calendarId);
		if ($view === null) {
			return null;
		}

		if ($statusKey !== null) {
			$named = $view->statusByKey($statusKey);
			if ($named !== null) {
				return $named->getId();
			}
		}

		// No key, or one this workflow does not know: the index is the
		// fallback source of truth, as long as it still agrees with the
		// standard status another client may have changed (§7).
		$current = $view->statusById($currentStatusId);
		if ($current !== null
			&& ($vtodoStatus === null || $current->getVtodoStatus() === $vtodoStatus)) {
			return $current->getId();
		}

		if ($vtodoStatus !== null) {
			return $view->statusForVtodo($vtodoStatus)?->getId();
		}

		return $view->initialStatus()?->getId();
	}

	public function columnsFor(string $userId, string $projectRef): StatusColumns {
		$view = $this->workflows->forProject($userId, $projectRef);
		if ($view === null) {
			// The module is on, but this project has no workflow: its board is
			// the four standard statuses, like every project before M2.
			return new StatusColumns(StandardStatuses::columns());
		}

		$columns = array_map(
			static fn (Status $status): StatusColumn => new StatusColumn(
				$status->getId(),
				$status->getStatusKey(),
				$status->getName(),
				$status->getColor(),
				$status->getVtodoStatus(),
				$status->getIsInitial(),
				$status->getIsFinal(),
				$status->getWipLimit(),
			),
			$view->statuses,
		);

		$rules = [];
		foreach ($view->transitions as $transition) {
			$from = $view->statusById($transition->getFromStatusId());
			$to = $view->statusById($transition->getToStatusId());
			if ($from === null || $to === null) {
				continue;
			}

			$rules[] = [
				'from' => $from->getStatusKey(),
				'to' => $to->getStatusKey(),
				'requiresComment' => $transition->getRequiresComment(),
			];
		}

		return new StatusColumns($columns, $rules, $view->id());
	}

	public function prepareWrite(
		string $userId,
		string $projectRef,
		?Task $current,
		?string $statusKey,
		?string $requestedVtodoStatus,
		?string $comment,
	): StatusWrite {
		$view = $this->workflows->forProject($userId, $projectRef);
		if ($view === null) {
			if ($statusKey !== null) {
				throw new ValidationFailedException('project_has_no_workflow');
			}

			return new StatusWrite();
		}

		$from = $view->statusById($current?->getStatusId());
		$target = $this->target($view, $from, $statusKey, $requestedVtodoStatus);
		if ($target === null) {
			throw new ValidationFailedException('unknown_status');
		}

		$this->assertAllowed($view, $from, $target, $current, $comment);

		$this->note = $comment;
		$this->noteUid = $current?->getUid();

		return new StatusWrite($target->getStatusKey(), $target->getVtodoStatus());
	}

	/**
	 * The note recorded with this request's status change, taken once.
	 *
	 * A note made while creating a task has no UID to match yet, so it belongs
	 * to the first task this request records.
	 */
	public function takeNote(string $taskUid): ?string {
		if ($this->note === null) {
			return null;
		}
		if ($this->noteUid !== null && $this->noteUid !== $taskUid) {
			return null;
		}

		$note = $this->note;
		$this->note = null;
		$this->noteUid = null;

		return $note;
	}

	/**
	 * Which status a write ends up in: the one asked for by key, or the one
	 * that fits the standard status the caller asked for — which is how the
	 * Chronos checkbox still completes a task under a workflow — or the one it
	 * is already in, or the initial one.
	 */
	private function target(
		WorkflowView $view,
		?Status $from,
		?string $statusKey,
		?string $requestedVtodoStatus,
	): ?Status {
		if ($statusKey !== null) {
			return $view->statusByKey($statusKey);
		}

		if ($requestedVtodoStatus !== null
			&& ($from === null || $from->getVtodoStatus() !== $requestedVtodoStatus)) {
			return $view->statusForVtodo($requestedVtodoStatus) ?? $from;
		}

		return $from ?? $view->initialStatus();
	}

	/**
	 * @throws ValidationFailedException
	 */
	private function assertAllowed(
		WorkflowView $view,
		?Status $from,
		Status $target,
		?Task $current,
		?string $comment,
	): void {
		if ($from !== null && $from->getId() !== $target->getId()) {
			if (!$view->allows($from->getId(), $target->getId())) {
				throw new ValidationFailedException('transition_not_allowed');
			}

			$transition = $view->transition($from->getId(), $target->getId());
			if ($transition?->getRequiresComment() === true && ($comment === null || $comment === '')) {
				throw new ValidationFailedException('comment_required');
			}
		}

		if ($target->getRequiresAssignee() && ($current?->getAssignee() ?? '') === '') {
			throw new ValidationFailedException('assignee_required');
		}
	}

	/**
	 * The workflow of the project a calendar is, cached for the request: the
	 * listener indexes one object and must not turn that into a query per
	 * task (§16, N5).
	 */
	private function forCalendar(string $userId, int $calendarId): ?WorkflowView {
		if (array_key_exists($calendarId, $this->byCalendar)) {
			return $this->byCalendar[$calendarId];
		}

		$calendar = $this->calendars->getCalendar($calendarId);
		$view = $calendar === null ? null : $this->workflows->forProject($userId, $calendar->ref);

		return $this->byCalendar[$calendarId] = $view;
	}
}
