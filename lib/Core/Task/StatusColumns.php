<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Task;

use JsonSerializable;
use OCA\Chronos\Core\Index\Task;

/**
 * The columns a project's board has, and the moves its workflow allows (§12.1).
 *
 * This is the whole answer to "what can a task in this project be", asked once
 * per board: the task layer gets it from `StatusGateway`, which the workflow
 * module answers when it is switched on and `NoWorkflow` when it is not. The
 * board itself therefore has one code path, and nothing outside the workflow
 * module knows whether a workflow exists.
 *
 * `rules` empty means every move is allowed, exactly as an empty transition
 * table does (§10.3). The board uses it to dim the columns a card may not go
 * to, which is where transition rules finally become visible (D28).
 */
final class StatusColumns implements JsonSerializable {

	/**
	 * @param list<StatusColumn> $columns in the order a board shows them
	 * @param list<array{from: string, to: string, requiresComment: bool}> $rules
	 */
	public function __construct(
		public readonly array $columns,
		public readonly array $rules = [],
		/** The workflow these columns come from, or null for the standard four. */
		public readonly ?int $workflowId = null,
	) {
	}

	public function byKey(string $key): ?StatusColumn {
		foreach ($this->columns as $column) {
			if ($column->key === $key) {
				return $column;
			}
		}

		return null;
	}

	/**
	 * The column a task belongs in.
	 *
	 * A workflow status wins, because the Chronos tables are the authority on
	 * it (§7). A task that has none — one written by another client before the
	 * workflow was attached, or any task at all when there is no workflow —
	 * falls back to the standard status it carries, and a task with no `STATUS`
	 * at all starts in the initial column, which is where a client that writes
	 * none means it to be (D31).
	 */
	public function columnFor(Task $task): ?StatusColumn {
		$statusId = $task->getStatusId();
		if ($statusId !== null) {
			foreach ($this->columns as $column) {
				if ($column->statusId === $statusId) {
					return $column;
				}
			}
		}

		$vtodoStatus = $task->getVtodoStatus();
		if ($vtodoStatus === null) {
			return $this->initial();
		}

		$final = $vtodoStatus === 'COMPLETED' || $vtodoStatus === 'CANCELLED';
		$fallback = null;
		foreach ($this->columns as $column) {
			if ($column->vtodoStatus !== $vtodoStatus) {
				continue;
			}
			// The same preference the workflow uses when it reads a standard
			// status from another client, so a board and the index agree.
			if ($final ? $column->isFinal : $column->isInitial) {
				return $column;
			}
			$fallback ??= $column;
		}

		return $fallback ?? $this->initial();
	}

	public function initial(): ?StatusColumn {
		foreach ($this->columns as $column) {
			if ($column->isInitial) {
				return $column;
			}
		}

		return $this->columns[0] ?? null;
	}

	/**
	 * Whether a card may be moved from one column to another. No rules means
	 * every move is allowed, and a card dropped where it already is is not a
	 * move at all.
	 */
	public function allows(string $from, string $to): bool {
		if ($this->rules === [] || $from === $to) {
			return true;
		}

		return $this->rule($from, $to) !== null;
	}

	/**
	 * @return array{from: string, to: string, requiresComment: bool}|null
	 */
	public function rule(string $from, string $to): ?array {
		foreach ($this->rules as $rule) {
			if ($rule['from'] === $from && $rule['to'] === $to) {
				return $rule;
			}
		}

		return null;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'workflowId' => $this->workflowId,
			'columns' => array_map(
				static fn (StatusColumn $column): array => $column->jsonSerialize(),
				$this->columns,
			),
			'rules' => $this->rules,
		];
	}
}
