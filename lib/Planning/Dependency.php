<?php

declare(strict_types=1);

namespace OCA\Chronos\Planning;

use JsonSerializable;
use OCA\Chronos\Core\Dav\ParsedLink;
use OCA\Chronos\Core\Index\TaskLink;

/**
 * One dependency, as the schedule sees it (§7, RFC 9253).
 *
 * The index already normalises direction — a temporal link is written on the
 * predecessor and `DEPENDS-ON` on the dependent task, and both are stored
 * predecessor → successor — so this is the row with the scheduling question
 * answered: does it constrain dates, and by how much?
 *
 * `gapSeconds` is RFC 9253's `GAP`: positive is lag (wait this long after the
 * predecessor), negative is lead (the successor may start that much early).
 */
final class Dependency implements JsonSerializable {

	public function __construct(
		public readonly int $id,
		public readonly string $predecessorUid,
		public readonly string $successorUid,
		public readonly string $reltype,
		public readonly int $gapSeconds = 0,
	) {
	}

	public static function fromRow(TaskLink $row): self {
		return new self(
			$row->getId(),
			$row->getPredecessorUid(),
			$row->getSuccessorUid(),
			strtoupper($row->getReltype()),
			$row->getGapSeconds(),
		);
	}

	/**
	 * Whether this link decides dates. `DEPENDS-ON` says a task is blocked by
	 * another without saying when either happens (RFC 9253 §5), so it is drawn
	 * and never scheduled.
	 */
	public function isTemporal(): bool {
		return in_array($this->reltype, ParsedLink::TEMPORAL_RELTYPES, true);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'predecessorUid' => $this->predecessorUid,
			'successorUid' => $this->successorUid,
			'reltype' => $this->reltype,
			'gapSeconds' => $this->gapSeconds,
			'temporal' => $this->isTemporal(),
		];
	}
}
