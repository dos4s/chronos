<?php

declare(strict_types=1);

namespace OCA\Chronos\Workflow;

use JsonSerializable;
use OCA\Chronos\Workflow\Db\Status;
use OCA\Chronos\Workflow\Db\Transition;
use OCA\Chronos\Workflow\Db\Workflow;

/**
 * A workflow with everything that belongs to it (§10.3): its statuses in
 * order, and its transitions — of which none at all means every move is
 * allowed.
 */
final class WorkflowView implements JsonSerializable {

	/**
	 * @param list<Status> $statuses
	 * @param list<Transition> $transitions
	 */
	public function __construct(
		public readonly Workflow $workflow,
		public readonly array $statuses,
		public readonly array $transitions,
	) {
	}

	public function id(): int {
		return $this->workflow->getId();
	}

	public function statusByKey(string $key): ?Status {
		foreach ($this->statuses as $status) {
			if ($status->getStatusKey() === $key) {
				return $status;
			}
		}

		return null;
	}

	public function statusById(?int $id): ?Status {
		if ($id === null) {
			return null;
		}

		foreach ($this->statuses as $status) {
			if ($status->getId() === $id) {
				return $status;
			}
		}

		return null;
	}

	/**
	 * The status a new task starts in: the one marked initial, or the first.
	 */
	public function initialStatus(): ?Status {
		foreach ($this->statuses as $status) {
			if ($status->getIsInitial()) {
				return $status;
			}
		}

		return $this->statuses[0] ?? null;
	}

	/**
	 * The status that best stands for a standard `STATUS`, which is how a
	 * change made in another client — or the Chronos checkbox — is understood.
	 *
	 * A completed or cancelled task prefers a final status, an open one
	 * prefers the initial status; otherwise the first with that mapping wins.
	 */
	public function statusForVtodo(string $vtodoStatus): ?Status {
		$candidates = array_values(array_filter(
			$this->statuses,
			static fn (Status $status): bool => $status->getVtodoStatus() === $vtodoStatus,
		));

		if ($candidates === []) {
			return null;
		}

		$prefersFinal = $vtodoStatus === 'COMPLETED' || $vtodoStatus === 'CANCELLED';
		foreach ($candidates as $status) {
			if ($prefersFinal ? $status->getIsFinal() : $status->getIsInitial()) {
				return $status;
			}
		}

		return $candidates[0];
	}

	/**
	 * Whether the workflow allows this move. A workflow with no transition
	 * rows allows every one of them (§10.3).
	 */
	public function allows(int $fromStatusId, int $toStatusId): bool {
		if ($this->transitions === [] || $fromStatusId === $toStatusId) {
			return true;
		}

		return $this->transition($fromStatusId, $toStatusId) !== null;
	}

	public function transition(int $fromStatusId, int $toStatusId): ?Transition {
		foreach ($this->transitions as $transition) {
			if ($transition->getFromStatusId() === $fromStatusId
				&& $transition->getToStatusId() === $toStatusId) {
				return $transition;
			}
		}

		return null;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->workflow->getId(),
			'name' => $this->workflow->getName(),
			'isTemplate' => $this->workflow->getIsTemplate(),
			'statuses' => array_map(
				static fn (Status $status): array => $status->jsonSerialize(),
				$this->statuses,
			),
			'transitions' => array_map(
				static fn (Transition $transition): array => $transition->jsonSerialize(),
				$this->transitions,
			),
		];
	}
}
