<?php

declare(strict_types=1);

namespace OCA\Chronos\Planning;

use JsonSerializable;

/**
 * A dependency the dates do not satisfy (§15.3).
 *
 * Shown before the drop — the link turns red — and reported with every move,
 * because in *warn* mode the move still happens and the conflict is the thing
 * the user is being warned about.
 */
final class Conflict implements JsonSerializable {

	/** The successor sits earlier than the link allows. */
	public const TOO_EARLY = 'too_early';

	/** The links form a loop, so no order of the tasks can satisfy them. */
	public const CYCLE = 'cycle';

	public function __construct(
		public readonly string $kind,
		public readonly string $predecessorUid,
		public readonly string $successorUid,
		public readonly string $reltype,
		/** Where the link says the successor may sit from; 0 for a loop. */
		public readonly int $requiredAt = 0,
		/** Where it actually sits. */
		public readonly int $actualAt = 0,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'kind' => $this->kind,
			'predecessorUid' => $this->predecessorUid,
			'successorUid' => $this->successorUid,
			'reltype' => $this->reltype,
			'requiredAt' => $this->requiredAt,
			'actualAt' => $this->actualAt,
		];
	}
}
