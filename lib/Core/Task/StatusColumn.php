<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Task;

use JsonSerializable;

/**
 * One status a task can be in, as a board column (§12.1).
 *
 * Kanban columns *are* the statuses (AD7), so there is no column model: this
 * is a status seen from the board's side, and it describes a workflow status
 * and a standard `STATUS` in the same shape. With no workflow there are four
 * of these and `statusId` is null; with one there is a column per status.
 */
final class StatusColumn implements JsonSerializable {

	public function __construct(
		/** Null for a standard status, which is not a row in any table. */
		public readonly ?int $statusId,
		/** How a write names this column: a workflow key, or a standard status. */
		public readonly string $key,
		public readonly string $name,
		public readonly ?string $color,
		/** What every other CalDAV client sees for a card in this column. */
		public readonly string $vtodoStatus,
		public readonly bool $isInitial,
		public readonly bool $isFinal,
		/** Advisory: the board warns, the server does not refuse (§15.3). */
		public readonly ?int $wipLimit = null,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'statusId' => $this->statusId,
			'key' => $this->key,
			'name' => $this->name,
			'color' => $this->color,
			'vtodoStatus' => $this->vtodoStatus,
			'isInitial' => $this->isInitial,
			'isFinal' => $this->isFinal,
			'wipLimit' => $this->wipLimit,
		];
	}
}
