<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Event;

use OCA\Chronos\Core\Index\Task;
use OCP\EventDispatcher\Event;

/**
 * A task has been read into the index. Modules that keep their own data about
 * tasks listen for this rather than reading VTODOs themselves (§5).
 */
class TaskIndexedEvent extends Event {

	public function __construct(
		private Task $task,
		private bool $isNew,
		private TaskSource $source,
	) {
		parent::__construct();
	}

	public function getTask(): Task {
		return $this->task;
	}

	public function isNew(): bool {
		return $this->isNew;
	}

	public function getSource(): TaskSource {
		return $this->source;
	}
}
