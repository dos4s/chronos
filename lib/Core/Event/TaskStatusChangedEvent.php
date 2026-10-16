<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Event;

use OCA\Chronos\Core\Index\Task;
use OCP\EventDispatcher\Event;

/**
 * The standard `STATUS` of a task changed. The workflow module turns this into
 * a history row and, from M6, into an SLA clock change.
 */
class TaskStatusChangedEvent extends Event {

	public function __construct(
		private Task $task,
		private ?string $previousStatus,
		private TaskSource $source,
	) {
		parent::__construct();
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
