<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Event;

use OCA\Chronos\Core\Index\Task;
use OCP\EventDispatcher\Event;

/**
 * A task's assignee changed. With shared lists this is how work moves between
 * people (AD11), and M6 reports time in state per assignee from it.
 */
class TaskAssignedEvent extends Event {

	public function __construct(
		private Task $task,
		private ?string $previousAssignee,
		private TaskSource $source,
	) {
		parent::__construct();
	}

	public function getTask(): Task {
		return $this->task;
	}

	public function getPreviousAssignee(): ?string {
		return $this->previousAssignee;
	}

	public function getSource(): TaskSource {
		return $this->source;
	}
}
