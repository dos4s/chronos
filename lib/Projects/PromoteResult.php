<?php

declare(strict_types=1);

namespace OCA\Chronos\Projects;

use JsonSerializable;

/**
 * What promoting a subproject to a list did (F2, §6).
 *
 * The count matters because the move is not one write: it is one copy and one
 * delete per task, and CalDAV has no transaction to wrap them in. If something
 * fails half way the tasks that moved have moved, so the answer says how many
 * — and the operation can simply be run again for the rest.
 */
final class PromoteResult implements JsonSerializable {

	public function __construct(
		/** The list the tasks are now in. */
		public readonly ProjectView $project,
		public readonly int $moved,
		/** Tasks that were meant to move and did not, if anything went wrong. */
		public readonly int $left,
		public readonly ?string $failure = null,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'project' => $this->project->jsonSerialize(),
			'moved' => $this->moved,
			'left' => $this->left,
			'failure' => $this->failure,
		];
	}
}
