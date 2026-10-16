<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Event;

use OCA\Chronos\Core\Index\Task;
use OCP\EventDispatcher\Event;

/**
 * A task is gone from CalDAV. Its index row stays, marked deleted, so history
 * and links that point at it keep their meaning (§10.1).
 */
class TaskDeletedEvent extends Event {

	public function __construct(
		private Task $task,
		private TaskSource $source,
	) {
		parent::__construct();
	}

	public function getTask(): Task {
		return $this->task;
	}

	public function getSource(): TaskSource {
		return $this->source;
	}
}
