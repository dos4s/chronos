<?php

declare(strict_types=1);

namespace OCA\Chronos\Planning;

/**
 * The answer to "what if this moved": the bars that end up somewhere new, and
 * the links the result still breaks (§15.4).
 *
 * The browser works this out to draw the ghost bars before the drop and the
 * server works out the same thing to write it, so this shape is what both of
 * them mean by an answer.
 */
final class Rescheduling {

	/**
	 * @param array<string, Bar> $bars by UID, the moved task included
	 * @param list<Conflict> $conflicts
	 * @param bool $overflowed true when the cascade reached more tasks than it
	 *                         was allowed to move, so nothing should be written
	 */
	public function __construct(
		public readonly array $bars,
		public readonly array $conflicts,
		public readonly bool $overflowed,
	) {
	}

	/**
	 * @return list<string>
	 */
	public function movedUids(): array {
		return array_keys($this->bars);
	}
}
