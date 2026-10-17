<?php

declare(strict_types=1);

namespace OCA\Chronos\Planning;

use JsonSerializable;
use OCA\Chronos\Core\Index\Task;

/**
 * One line of a timeline: the task, where it sits in the tree, and the bar it
 * draws — if it draws one at all (§12.5).
 *
 * A Gantt is a tree table with a chart beside it, so a row is a task *and* a
 * depth. A task with no dates is still a row: it is listed, with nothing to
 * drag, which is how a timeline of a real list stops being an empty screen.
 */
final class PlanRow implements JsonSerializable {

	public function __construct(
		public readonly Task $task,
		public readonly int $depth,
		public readonly ?Bar $bar,
		/**
		 * True when the bar is the span of what is underneath rather than the
		 * task's own dates. A summary is derived, so it is not dragged: its
		 * children are (§12.5).
		 */
		public readonly bool $isSummary,
		/** 0–100, from `PERCENT-COMPLETE` or from how much of a branch is done. */
		public readonly ?int $progress,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return $this->task->jsonSerialize() + [
			'depth' => $this->depth,
			'bar' => $this->bar?->jsonSerialize(),
			'isSummary' => $this->isSummary,
			'progress' => $this->progress,
		];
	}
}
