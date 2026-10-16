<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Event;

use OCA\Chronos\Core\Index\Task;
use OCP\EventDispatcher\Event;

/**
 * A task's status changed: the standard `STATUS`, the workflow status, or both.
 *
 * The two move independently — "In progress" and "Review" are both
 * `IN-PROCESS` — so this fires for either, and the workflow module turns it
 * into a history row and, from M6, into an SLA clock change.
 */
class TaskStatusChangedEvent extends Event {

	public function __construct(
		private Task $task,
		private ?string $previousStatus,
		private TaskSource $source,
		private ?int $previousStatusId = null,
	) {
		parent::__construct();
	}

	/**
	 * The workflow status the task had before, if it had one.
	 */
	public function getPreviousStatusId(): ?int {
		return $this->previousStatusId;
	}

	public function getTask(): Task {
		return $this->task;
	}

	public function getPreviousStatus(): ?string {
		return $this->previousStatus;
	}

	public function getSource(): TaskSource {
		return $this->source;
	}
}
