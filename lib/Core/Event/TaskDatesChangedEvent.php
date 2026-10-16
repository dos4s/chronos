<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Event;

use OCA\Chronos\Core\Index\Task;
use OCP\EventDispatcher\Event;

/**
 * A task's start or due date changed, which the Gantt and the SLA clock care
 * about.
 */
class TaskDatesChangedEvent extends Event {

	public function __construct(
		private Task $task,
		private ?int $previousStartAt,
		private ?int $previousDueAt,
		private TaskSource $source,
	) {
		parent::__construct();
	}

	public function getTask(): Task {
		return $this->task;
	}

	public function getPreviousStartAt(): ?int {
		return $this->previousStartAt;
	}

	public function getPreviousDueAt(): ?int {
		return $this->previousDueAt;
	}

	public function getSource(): TaskSource {
		return $this->source;
	}
}
