<?php

declare(strict_types=1);

namespace OCA\Chronos\Planning;

use JsonSerializable;

/**
 * What a drag on a timeline changed (§15.6).
 *
 * The browser has already drawn it — the bar it moved, and the ghost bars of
 * whatever a cascade would take with it — so this is what it needs to agree
 * with the server again: every row that was written, with the etags without
 * which the next write would be refused, and the conflicts that are left.
 *
 * In *warn* mode there will usually be conflicts and the move still happened:
 * that is what the warning is.
 */
final class MoveOutcome implements JsonSerializable {

	/**
	 * @param list<PlanRow> $rows every row the move wrote
	 * @param list<Conflict> $conflicts what the dates still break
	 */
	public function __construct(
		public readonly array $rows,
		public readonly array $conflicts,
		public readonly bool $cascaded,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'rows' => array_map(
				static fn (PlanRow $row): array => $row->jsonSerialize(),
				$this->rows,
			),
			'conflicts' => array_map(
				static fn (Conflict $conflict): array => $conflict->jsonSerialize(),
				$this->conflicts,
			),
			'cascaded' => $this->cascaded,
		];
	}
}
